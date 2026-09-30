import test from 'node:test';
import assert from 'node:assert/strict';
import {
  assertNetworkFence, buildRequestProjection, createDeepgramSession, SDK_VERSION, startDeepgramSession,
} from '../src/deepgram-port.js';
import { ConnectionRegistry } from '../src/connection-registry.js';
import {
  assertLimitedPolicy, buildFailureEvidence, encodeEvidenceFrame, safeFailureReason, validateFrame,
} from '../src/limited-verification.js';
import crypto from 'node:crypto';
import {
  assertHumanRuntimePolicy, buildSessionFailureEvidence, FAILURE_EVIDENCE_CONTRACT, HumanGateController,
  LEDGER_BATCH_FRAMES, MAX_QUEUED_FRAMES, normalizeProxyResponseHeaders, safeReason,
  assertSentBatchEvidence, sanitizeProviderEvent, SentEvidenceGate, stageBridgeError, stageRuntimeError,
  takeLedgerBatch, validateProductFrame,
} from '../src/product-relay.js';
import {
  isEphemeralPartial, PartialEventCoalescer, PARTIAL_FORWARD_INTERVAL_MS,
} from '../src/partial-event-coalescer.js';

test('SDK is pinned and request projection fixes MIP and retry policy', () => {
  assert.equal(SDK_VERSION, '5.10.0');
  assert.throws(() => buildRequestProjection(), /audio_send_disabled/);
  const env = { COMPANY_OS_REALTIME_ENABLED: 'true', COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED: 'true' };
  const projection = buildRequestProjection({}, env);
  assert.equal(projection.mip_opt_out, true);
  assert.equal(projection.automatic_retry, false);
  assert.equal(assertNetworkFence({ mip_opt_out: true, automatic_retry: false }, env), true);
  assert.throws(() => assertNetworkFence({ mip_opt_out: false, automatic_retry: false }, env), /mip_guard_failed/);
  assert.throws(() => assertNetworkFence({ mip_opt_out: true, automatic_retry: false, api_key: 'forbidden' }, env), /provider_secret/);
});

test('forced close stops frame acceptance and provider egress once', async () => {
  const registry = new ConnectionRegistry();
  let closes = 0;
  registry.register({ leaseId: 'lease', streamId: 'stream', generation: 1, expiresAt: Date.now() + 10_000, close: async () => { closes += 1; } });
  assert.equal(registry.acceptFrame({ leaseId: 'lease', streamId: 'stream', generation: 1 }).providerEgress, true);
  const first = await registry.forceClose({ streamId: 'stream', generation: 1, operationId: 'op-1', hardAbort: true });
  const second = await registry.forceClose({ streamId: 'stream', generation: 1, operationId: 'op-1', hardAbort: true });
  assert.equal(first.acknowledgement, 'provider_egress_stopped_and_socket_closed');
  assert.equal(second.acknowledgement, 'already_closed');
  assert.equal(closes, 1);
  assert.throws(() => registry.acceptFrame({ leaseId: 'lease', streamId: 'stream', generation: 1 }), /not_current/);
});

test('P1-I limited verification policy fails closed outside the single approved attempt and cost', () => {
  const approved = {
    COMPANY_OS_P1_I_APPROVED: 'true', COMPANY_OS_REALTIME_ENABLED: 'true',
    COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED: 'true', COMPANY_OS_P1_I_ATTEMPT_MAX: '1',
    COMPANY_OS_P1_I_RETRY_MAX: '0', COMPANY_OS_P1_I_RECONNECT_MAX: '0',
    COMPANY_OS_P1_I_COST_LIMIT_USD: '0.01',
  };
  assert.ok(assertLimitedPolicy(approved).estimatedCostUsd < 0.01);
  assert.throws(() => assertLimitedPolicy({ ...approved, COMPANY_OS_P1_I_ATTEMPT_MAX: '2' }), /fence_closed/);
  assert.throws(() => assertLimitedPolicy({ ...approved, COMPANY_OS_P1_I_RETRY_MAX: '1' }), /fence_closed/);
  assert.throws(() => assertLimitedPolicy({ ...approved, COMPANY_OS_P1_I_COST_LIMIT_USD: '0.003' }), /cost_fence/);
});

test('loopback relay frame validation requires contiguous PCM identity', () => {
  const binary = Buffer.alloc(3200, 1);
  const metadata = {
    type: 'audio_frame', lease_id: crypto.randomUUID(), stream_id: crypto.randomUUID(), generation: 1,
    sequence: 1, client_event_id: crypto.randomUUID(), start_sample: 0, end_sample: 1600,
    sample_count: 1600, sample_rate: 16000, bit_depth: 16, channels: 1, format: 'pcm_s16le',
    content_sha256: crypto.createHash('sha256').update(binary).digest('hex'),
  };
  assert.equal(validateFrame(metadata, binary, 0), 1600);
  assert.throws(() => validateFrame({ ...metadata, start_sample: 1 }, binary, 0), /failed_closed/);
  assert.throws(() => validateFrame({ ...metadata, content_sha256: '0'.repeat(64) }, binary, 0), /failed_closed/);
});

test('P1-I failure evidence is persisted before assertion without raw provider data', () => {
  const evidence = buildFailureEvidence({
    classification: 'provider', providerRequestCount: 1,
    actualRequest: { mip_opt_out: 'true', reconnectAttempts: 0 }, providerOpened: true,
    audioSendStarted: true, audioSendCompleted: false, samplesSent: 1600,
    frameCount: 1, providerCloseCode: 1011, loopbackListenerOpened: true,
  }, new Error('Authorization: secret-value provider_runtime_failure'));
  assert.equal(evidence.status, 'INCONCLUSIVE_EVIDENCE_FAILURE');
  assert.equal(evidence.checks.provider_connection_attempted, true);
  assert.equal(evidence.checks.provider_accepted, true);
  assert.equal(evidence.source.samples_sent, 1600);
  assert.equal(evidence.source.bytes_sent, 3200);
  assert.equal(evidence.connection.provider_close_code, 1011);
  assert.equal(evidence.provider_events.length, 0);
  assert.equal(evidence.frame_receipts.length, 0);
  assert.doesNotMatch(JSON.stringify(evidence), /secret-value/);
});

test('P1-I SDK ErrorEvent is normalized and diagnostic evidence remains sanitized', () => {
  const sdkEvent = {
    type: 'error',
    error: new Error('Unexpected server response: 401 Authorization: secret-value'),
  };
  assert.equal(safeFailureReason(sdkEvent), 'Unexpected server response: 401 Authorization=[REDACTED]');

  const evidence = buildFailureEvidence({
    classification: 'session',
    providerRequestCount: 1,
    actualRequest: { mip_opt_out: 'true', reconnectAttempts: 0 },
    diagnosticErrors: [{ classification: 'session', safe_reason: safeFailureReason(sdkEvent) }],
  }, sdkEvent);
  assert.equal(evidence.safe_reason, 'Unexpected server response: 401 Authorization=[REDACTED]');
  assert.deepEqual(evidence.errors, [{
    classification: 'session', safe_reason: 'Unexpected server response: 401 Authorization=[REDACTED]',
  }]);
  assert.doesNotMatch(JSON.stringify(evidence), /secret-value/);
  assert.doesNotMatch(JSON.stringify(evidence), /\[object Object\]/);
});

test('P1-I child emits a run-specific deterministic evidence frame', () => {
  const frameId = '0123456789abcdef0123456789abcdef';
  const frame = encodeEvidenceFrame({ status: 'PASS' }, frameId);
  assert.match(frame, new RegExp(`^@@COMPANY_OS_EVIDENCE_V1:${frameId}:BEGIN@@`));
  assert.match(frame, new RegExp(`@@COMPANY_OS_EVIDENCE_V1:${frameId}:END@@\\n$`));
  assert.throws(() => encodeEvidenceFrame({ status: 'PASS' }, 'predictable'), /frame_id_invalid/);
});

test('pinned SDK listen socket is startClosed before the initial start', async () => {
  const controller = new AbortController();
  const session = await createDeepgramSession({
    apiKey: 'synthetic-server-side-key',
    signal: controller.signal,
    env: { COMPANY_OS_REALTIME_ENABLED: 'true', COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED: 'true' },
  });

  assert.equal(session.readyState, 3);
  controller.abort();
});

test('pinned SDK request uses exactly one supported diarization control', async () => {
  const controller = new AbortController();
  const session = await createDeepgramSession({
    apiKey: 'synthetic-server-side-key',
    signal: controller.signal,
    env: { COMPANY_OS_REALTIME_ENABLED: 'true', COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED: 'true' },
  });

  const query = session.socket._queryParameters;
  assert.equal(query.diarize, undefined);
  assert.equal(query.diarize_model, 'latest');
  assert.equal(query.mip_opt_out, 'true');
  assert.equal(query.model, 'nova-3');
  assert.equal(query.language, 'ja');
  assert.equal(query.encoding, 'linear16');
  assert.equal(query.sample_rate, 16000);
  assert.equal(query.channels, 1);
  assert.equal(session.readyState, 3);
  controller.abort();
});

test('SDK v5 startClosed lifecycle performs one initial start and rejects duplicate start', async () => {
  const lifecycle = {
    sdk_socket_created: 0,
    initial_connection_started: 0,
    sdk_start_method_calls: 0,
    post_start_reconnect: 0,
    retry: 0,
    duplicate_socket: 0,
  };
  const socket = {
    readyState: 3,
    connect() {
      lifecycle.sdk_start_method_calls += 1;
      if (this.readyState !== 3) lifecycle.post_start_reconnect += 1;
      this.readyState = 0;
      lifecycle.initial_connection_started += 1;
      return this;
    },
  };
  const session = await createDeepgramSession({
    apiKey: 'synthetic-server-side-key',
    env: { COMPANY_OS_REALTIME_ENABLED: 'true', COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED: 'true' },
    clientFactory: () => ({ listen: { v1: { connect: request => {
      lifecycle.sdk_socket_created += 1;
      lifecycle.retry = request.reconnectAttempts;
      lifecycle.duplicate_socket = Math.max(0, lifecycle.sdk_socket_created - 1);
      return socket;
    } } } }),
  });

  assert.equal(session, socket);
  assert.equal(lifecycle.initial_connection_started, 0);
  assert.equal(startDeepgramSession(session), socket);
  assert.throws(() => startDeepgramSession(session), /provider_session_start_duplicate/);
  assert.deepEqual(lifecycle, {
    sdk_socket_created: 1,
    initial_connection_started: 1,
    sdk_start_method_calls: 1,
    post_start_reconnect: 0,
    retry: 0,
    duplicate_socket: 0,
  });
});
test('P1-J Product relay policy is loopback-bounded, zero-retry and cost-limited', () => {
  const approved = {
    COMPANY_OS_P1_J_APPROVED: 'true', COMPANY_OS_REALTIME_ENABLED: 'true', COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED: 'true',
    COMPANY_OS_REALTIME_RETRY_MAX: '0', COMPANY_OS_REALTIME_RECONNECT_MAX: '0', COMPANY_OS_REALTIME_MAX_PROVIDER_SESSIONS: '3',
    COMPANY_OS_REALTIME_MAX_AUDIO_SECONDS: '300', COMPANY_OS_REALTIME_COST_LIMIT_USD: '0.05', COMPANY_OS_REALTIME_BRIDGE_TOKEN: 'a'.repeat(32),
  };
  const policy = assertHumanRuntimePolicy(approved);
  assert.equal(policy.maxSessions, 3);
  assert.ok(policy.projectedCost < policy.costLimit);
  assert.throws(() => assertHumanRuntimePolicy({ ...approved, COMPANY_OS_REALTIME_RETRY_MAX: '1' }), /fence_closed/);
  assert.throws(() => assertHumanRuntimePolicy({ ...approved, COMPANY_OS_REALTIME_MAX_AUDIO_SECONDS: '301' }), /cost_or_attempt/);
  assert.throws(() => assertHumanRuntimePolicy({ ...approved, COMPANY_OS_REALTIME_BRIDGE_TOKEN: 'short' }), /bridge_token/);
});

test('P1-J HTTPS proxy rewrites only its own insecure absolute redirects', () => {
  assert.equal(
    normalizeProxyResponseHeaders({ location: 'http://localhost:8443/login' }, 'https://localhost:8443').location,
    'https://localhost:8443/login',
  );
  assert.equal(
    normalizeProxyResponseHeaders({ location: 'https://example.test/login' }, 'https://localhost:8443').location,
    'https://example.test/login',
  );
  assert.equal(
    normalizeProxyResponseHeaders({ location: '/login' }, 'https://localhost:8443').location,
    '/login',
  );
});

test('P1-J Product relay preserves sanitized SDK ErrorEvent diagnostics and completeness', () => {
  const evidence = buildSessionFailureEvidence({
    error: { type: 'error', error: new Error('Unexpected server response: 401 Authorization: secret-value') },
    failureStage: 'provider_open_wait', providerConnectionAttempted: true, providerAccepted: false, samplesSent: 0,
  });
  assert.deepEqual(evidence, {
    type: 'session_failure',
    evidence_contract: FAILURE_EVIDENCE_CONTRACT,
    reason: 'Unexpected server response: 401 Authorization=[REDACTED]',
    failure_stage: 'provider_open_wait',
    provider_connection_attempted: true,
    provider_accepted: false,
    samples_sent: 0,
    evidence_completeness: 'complete',
  });
  assert.equal(safeReason({ nested: 'unrecognized' }), 'unknown_object_failure');
  assert.equal(buildSessionFailureEvidence({ error: {}, failureStage: '', samplesSent: 0 }).evidence_completeness, 'incomplete');
  assert.doesNotMatch(JSON.stringify(evidence), /secret-value/);
});

test('P1-J Product relay validates live PCM identity and sanitizes Provider events', () => {
  const binary = Buffer.alloc(3200);
  const frame = {
    type: 'audio_frame', lease_id: crypto.randomUUID(), stream_id: crypto.randomUUID(), generation: 2, sequence: 1,
    client_event_id: crypto.randomUUID(), start_sample: 0, end_sample: 1600, sample_count: 1600,
    sample_rate: 16000, bit_depth: 16, channels: 1, format: 'pcm_s16le', content_sha256: crypto.createHash('sha256').update(binary).digest('hex'),
  };
  assert.equal(validateProductFrame(frame, binary, 0), 1600);
  assert.throws(() => validateProductFrame({ ...frame, start_sample: 1 }, binary, 0), /failed_closed/);
  assert.throws(() => validateProductFrame({ ...frame, content_sha256: 'bad' }, binary, 0), /failed_closed/);
  const event = sanitizeProviderEvent({
    type: 'Results', request_id: 'request', is_final: true, start: 0, duration: 0.1,
    channel: { alternatives: [{ transcript: 'テスト', words: [{ start: 0, end: 0.1, confidence: 0.9, speaker: 0 }] }] },
  }, 1600, 2);
  assert.equal(event.channel.alternatives[0].transcript, 'テスト');
  assert.equal(event.channel.alternatives[0].words[0].speaker, 0);
  assert.equal(Object.hasOwn(event, 'authorization'), false);
  const emptyFinal = sanitizeProviderEvent({
    type: 'Results', request_id: 'empty-final', is_final: true, start: 0, duration: 0.74,
  }, 11840, 2);
  assert.equal(emptyFinal.channel.alternatives[0].transcript, '');
  assert.deepEqual(emptyFinal.channel.alternatives[0].words, []);
});
test('P1-J bridge failures retain their action stage across concurrent work', () => {
  const eventFailure = stageBridgeError(new Error('bridge_event_rejected_500'), 'event');
  assert.equal(eventFailure.failureStage, 'bridge_event');
  assert.equal(eventFailure.message, 'bridge_event_rejected_500');
  const sentFailure = stageBridgeError(new Error('bridge_sent-batch_rejected_500'), 'sent-batch');
  assert.equal(sentFailure.failureStage, 'bridge_sent_batch');
  assert.equal(buildSessionFailureEvidence({
    error: eventFailure,
    failureStage: eventFailure.failureStage,
    providerConnectionAttempted: true,
    providerAccepted: true,
    samplesSent: 32000,
  }).failure_stage, 'bridge_event');
});
test('P1-J runtime failures retain an exact non-bridge stage', () => {
  const failure = stageRuntimeError(new Error('relay_frame_backlog_failed_closed'), 'frame_queue_admission');
  assert.equal(failure.failureStage, 'frame_queue_admission');
  assert.equal(failure.message, 'relay_frame_backlog_failed_closed');
});
test('P1-J piggyback send Evidence is complete and fail closed', () => {
  assert.deepEqual(assertSentBatchEvidence({sent: [
    {send_ordinal: 1, state: 'sent'},
    {send_ordinal: 2, state: 'sent'},
  ]}, 2).map(item => item.send_ordinal), [1, 2]);
  assert.throws(() => assertSentBatchEvidence({sent: [{send_ordinal: 1, state: 'sent'}]}, 2), /evidence_incomplete/);
  assert.throws(() => assertSentBatchEvidence({sent: [{send_ordinal: 1, state: 'accepted'}]}, 1), /evidence_incomplete/);
});
test('P1-J ephemeral Partials are latest-value coalesced without touching Finals', () => {
  const coalescer = new PartialEventCoalescer();
  const partial = sequence => ({
    type: 'Results', sequence, is_final: false,
    channel: {alternatives: [{transcript: `partial-${sequence}`}]},
  });
  assert.equal(PARTIAL_FORWARD_INTERVAL_MS, 2000);
  assert.equal(isEphemeralPartial(partial(1)), true);
  assert.equal(isEphemeralPartial({...partial(2), is_final: true}), false);
  assert.equal(coalescer.offer(partial(1), 1000).sequence, 1);
  assert.equal(coalescer.offer(partial(2), 1250), null);
  assert.equal(coalescer.offer(partial(3), 1500), null);
  assert.equal(coalescer.nextDelay(1500), 1500);
  assert.equal(coalescer.takeDue(2999), null);
  assert.equal(coalescer.takeDue(3000).sequence, 3);
  assert.equal(coalescer.hasPending, false);
});
test('P1-J pending Partial can be discarded before a Final or force-flushed at Normal End', () => {
  const item = {type: 'Results', is_final: false, channel: {alternatives: [{transcript: 'latest'}]}};
  const discarded = new PartialEventCoalescer();
  discarded.offer({...item, sequence: 1}, 1000);
  discarded.offer({...item, sequence: 2}, 1100);
  discarded.discardPending();
  assert.equal(discarded.takeDue(5000, true), null);

  const flushed = new PartialEventCoalescer();
  flushed.offer({...item, sequence: 1}, 1000);
  flushed.offer({...item, sequence: 2}, 1100);
  assert.equal(flushed.takeDue(1200, true).sequence, 2);
});
test('P1-J ledger batching preserves 100 ms frame identity with a bounded memory queue', () => {
  const queue = Array.from({length: LEDGER_BATCH_FRAMES * 2 + 3}, (_, index) => ({
    metadata: {sequence: index + 1, start_sample: index * 1600, end_sample: (index + 1) * 1600},
  }));
  const first = takeLedgerBatch(queue);
  const second = takeLedgerBatch(queue);
  const remainder = takeLedgerBatch(queue, true);

  assert.equal(LEDGER_BATCH_FRAMES, 10);
  assert.equal(MAX_QUEUED_FRAMES, 30);
  assert.equal(first.length, 10);
  assert.equal(second.length, 10);
  assert.equal(remainder.length, 3);
  assert.deepEqual(
    [...first, ...second, ...remainder].map(item => item.metadata.sequence),
    Array.from({length: 23}, (_, index) => index + 1),
  );
  assert.equal(queue.length, 0);
  assert.deepEqual(takeLedgerBatch([{metadata: {sequence: 24}}]), []);
  assert.throws(() => takeLedgerBatch(null), /queue_invalid/);
});
test('P1-J Provider events wait only for the sent Evidence watermark captured at arrival', async () => {
  const gate = new SentEvidenceGate();
  let resolveFirst;
  let resolveFuture;
  const first = new Promise(resolve => { resolveFirst = resolve; });
  const future = new Promise(resolve => { resolveFuture = resolve; });
  gate.advance(first);
  const eventWatermark = gate.snapshot();
  gate.advance(future);

  let eventReleased = false;
  eventWatermark.then(() => { eventReleased = true; });
  resolveFirst();
  await eventWatermark;
  assert.equal(eventReleased, true);

  let futureReleased = false;
  gate.snapshot().then(() => { futureReleased = true; });
  await Promise.resolve();
  assert.equal(futureReleased, false);
  resolveFuture();
  await gate.snapshot();
  assert.equal(futureReleased, true);
});
test('P1-J minimum Human Gate permits continuation only after a real Partial and halts after failure', () => {
  const failed = new HumanGateController();
  failed.markFailure();
  assert.throws(() => failed.assertStartAllowed(), /halted_after_failure/);

  const incomplete = new HumanGateController();
  incomplete.markSessionEnded();
  assert.throws(() => incomplete.assertStartAllowed(), /halted_after_failure/);

  const passed = new HumanGateController();
  passed.markPartial('確認できました');
  passed.markSessionEnded();
  assert.doesNotThrow(() => passed.assertStartAllowed());
});
