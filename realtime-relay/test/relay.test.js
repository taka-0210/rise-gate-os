import test from 'node:test';
import assert from 'node:assert/strict';
import { assertNetworkFence, buildRequestProjection, createDeepgramSession, SDK_VERSION } from '../src/deepgram-port.js';
import { ConnectionRegistry } from '../src/connection-registry.js';
import { assertLimitedPolicy, buildFailureEvidence, encodeEvidenceFrame, validateFrame } from '../src/limited-verification.js';
import crypto from 'node:crypto';

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

test('P1-I child emits a run-specific deterministic evidence frame', () => {
  const frameId = '0123456789abcdef0123456789abcdef';
  const frame = encodeEvidenceFrame({ status: 'PASS' }, frameId);
  assert.match(frame, new RegExp(`^@@COMPANY_OS_EVIDENCE_V1:${frameId}:BEGIN@@`));
  assert.match(frame, new RegExp(`@@COMPANY_OS_EVIDENCE_V1:${frameId}:END@@\\n$`));
  assert.throws(() => encodeEvidenceFrame({ status: 'PASS' }, 'predictable'), /frame_id_invalid/);
});

test('SDK v5 lifecycle starts one socket without explicit connect or reconnect', async () => {
  const lifecycle = {
    sdk_socket_created: 0,
    initial_connection_started: 0,
    explicit_provider_connect: 0,
    reconnect: 0,
    retry: 0,
    duplicate_socket: 0,
  };
  const socket = {
    connect() { lifecycle.explicit_provider_connect += 1; },
    reconnect() { lifecycle.reconnect += 1; },
  };
  const session = await createDeepgramSession({
    apiKey: 'synthetic-server-side-key',
    env: { COMPANY_OS_REALTIME_ENABLED: 'true', COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED: 'true' },
    clientFactory: () => ({ listen: { v1: { connect: request => {
      lifecycle.sdk_socket_created += 1;
      lifecycle.initial_connection_started += 1;
      lifecycle.retry = request.reconnectAttempts;
      lifecycle.duplicate_socket = Math.max(0, lifecycle.sdk_socket_created - 1);
      return socket;
    } } } }),
  });

  assert.equal(session, socket);
  assert.deepEqual(lifecycle, {
    sdk_socket_created: 1,
    initial_connection_started: 1,
    explicit_provider_connect: 0,
    reconnect: 0,
    retry: 0,
    duplicate_socket: 0,
  });
});
