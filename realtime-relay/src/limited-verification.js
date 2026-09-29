import crypto from 'node:crypto';
import fs from 'node:fs';
import https from 'node:https';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import WebSocket, { WebSocketServer } from 'ws';
import { buildRequestProjection, createDeepgramSession, startDeepgramSession } from './deepgram-port.js';

export const APPROVED_SOURCE_SHA256 = '396F978F02BB43D22BA69BACB01F13B59F36BA11FF729AFA144549D60CC5141A';
export const APPROVED_DURATION_SECONDS = 18.9016875;
export const APPROVED_COST_LIMIT_USD = 0.01;
export const CONSERVATIVE_COST_USD_PER_MINUTE = 0.0097;
export const CHUNK_SAMPLES = 1600;

function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex').toUpperCase();
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  promise.catch(() => {});
  return { promise, resolve, reject };
}

async function within(promise, milliseconds, reason) {
  let timer;
  try {
    return await Promise.race([
      promise,
      new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(reason)), milliseconds); }),
    ]);
  } finally {
    clearTimeout(timer);
  }
}

export function inspectApprovedWav(buffer) {
  if (buffer.length < 44 || buffer.toString('ascii', 0, 4) !== 'RIFF' || buffer.toString('ascii', 8, 12) !== 'WAVE') {
    throw new Error('approved_audio_not_wav');
  }
  let offset = 12;
  let format = null;
  let data = null;
  while (offset + 8 <= buffer.length) {
    const id = buffer.toString('ascii', offset, offset + 4);
    const size = buffer.readUInt32LE(offset + 4);
    const body = offset + 8;
    if (body + size > buffer.length) throw new Error('approved_audio_truncated');
    if (id === 'fmt ') {
      format = {
        audioFormat: buffer.readUInt16LE(body), channels: buffer.readUInt16LE(body + 2),
        sampleRate: buffer.readUInt32LE(body + 4), blockAlign: buffer.readUInt16LE(body + 12),
        bitsPerSample: buffer.readUInt16LE(body + 14),
      };
    }
    if (id === 'data') data = { offset: body, bytes: size };
    offset = body + size + (size % 2);
  }
  if (!format || !data || format.audioFormat !== 1 || format.sampleRate !== 16000 || format.bitsPerSample !== 16 || format.channels !== 1 || format.blockAlign !== 2) {
    throw new Error('approved_audio_format_mismatch');
  }
  const sampleFrames = data.bytes / format.blockAlign;
  const durationSeconds = sampleFrames / format.sampleRate;
  if (sha256(buffer) !== APPROVED_SOURCE_SHA256 || Math.abs(durationSeconds - APPROVED_DURATION_SECONDS) > 0.000001) {
    throw new Error('approved_audio_identity_mismatch');
  }
  return { ...format, ...data, sampleFrames, durationSeconds };
}

export function assertLimitedPolicy(env, durationSeconds = APPROVED_DURATION_SECONDS) {
  const limit = Number(env.COMPANY_OS_P1_I_COST_LIMIT_USD);
  const estimatedCostUsd = durationSeconds / 60 * CONSERVATIVE_COST_USD_PER_MINUTE;
  if (env.COMPANY_OS_P1_I_APPROVED !== 'true' || env.COMPANY_OS_REALTIME_ENABLED !== 'true'
    || env.COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED !== 'true' || env.COMPANY_OS_P1_I_ATTEMPT_MAX !== '1'
    || env.COMPANY_OS_P1_I_RETRY_MAX !== '0' || env.COMPANY_OS_P1_I_RECONNECT_MAX !== '0') {
    throw new Error('limited_verification_fence_closed');
  }
  if (!Number.isFinite(limit) || limit > APPROVED_COST_LIMIT_USD || estimatedCostUsd > limit) {
    throw new Error('limited_verification_cost_fence_failed');
  }
  return { estimatedCostUsd, limit };
}

export function validateFrame(metadata, binary, expectedStartSample) {
  if (metadata?.type !== 'audio_frame' || typeof metadata.lease_id !== 'string' || metadata.lease_id.length < 16
    || typeof metadata.stream_id !== 'string' || metadata.stream_id.length < 8 || metadata.generation !== 1
    || !Number.isInteger(metadata.sequence) || metadata.sequence < 1 || !crypto.randomUUID || typeof metadata.client_event_id !== 'string'
    || metadata.start_sample !== expectedStartSample || metadata.end_sample <= metadata.start_sample
    || metadata.sample_count !== metadata.end_sample - metadata.start_sample || metadata.sample_rate !== 16000
    || metadata.bit_depth !== 16 || metadata.channels !== 1 || metadata.format !== 'pcm_s16le'
    || binary.length !== metadata.sample_count * 2 || sha256(binary).toLowerCase() !== String(metadata.content_sha256).toLowerCase()) {
    throw new Error('canonical_audio_frame_failed_closed');
  }
  return metadata.end_sample;
}

function loadDpapiSecret(credentialPath, helperPath) {
  const secret = execFileSync('powershell.exe', [
    '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', helperPath, '-CredentialPath', credentialPath,
  ], { encoding: 'utf8', windowsHide: true, maxBuffer: 16384 }).trim();
  if (secret.length < 16) throw new Error('server_credential_unavailable');
  return secret;
}

function safeProviderEvent(event, receiveOrder, samplesSent, estimatedCostMicrounits) {
  const alternative = event.channel?.alternatives?.[0] ?? {};
  const result = {
    type: event.type ?? null,
    request_id: event.request_id ?? event.metadata?.request_id ?? null,
    sequence: Number.isInteger(event.sequence) ? event.sequence : null,
    is_final: event.is_final === true,
    speech_final: event.speech_final === true,
    start: Number.isFinite(event.start) ? event.start : null,
    duration: Number.isFinite(event.duration) ? event.duration : null,
    channel: {
      alternatives: [{
        transcript: typeof alternative.transcript === 'string' ? alternative.transcript : '',
        words: Array.isArray(alternative.words) ? alternative.words.map(word => ({
          start: Number.isFinite(word.start) ? word.start : null,
          end: Number.isFinite(word.end) ? word.end : null,
          confidence: Number.isFinite(word.confidence) ? word.confidence : null,
          speaker: Number.isInteger(word.speaker) ? word.speaker : null,
        })) : [],
      }],
    },
    _company_os: { receive_order: receiveOrder, samples_sent_at_receive: samplesSent },
  };
  if (event.type === 'Metadata' && Number.isFinite(event.duration)) {
    result.usage = {
      quantity: event.duration,
      unit: 'duration_seconds',
      price_version: 'deepgram-conservative-2026-09',
      estimated_cost_microunits: estimatedCostMicrounits,
    };
  }
  return result;
}

export function safeFailureReason(error) {
  let value = 'unknown_failure';
  if (error instanceof Error && error.message) {
    value = error.message;
  } else if (typeof error === 'string' && error.trim()) {
    value = error;
  } else if (error && typeof error === 'object') {
    if (typeof error.message === 'string' && error.message.trim()) {
      value = error.message;
    } else if (error.error instanceof Error && error.error.message) {
      value = error.error.message;
    } else if (typeof error.error?.message === 'string' && error.error.message.trim()) {
      value = error.error.message;
    } else if (typeof error.reason === 'string' && error.reason.trim()) {
      value = error.reason;
    } else {
      value = 'unknown_object_failure';
    }
  }
  return value
    .replace(/(authorization|api[-_ ]?key|token|credential)\s*[:=]\s*\S+/gi, '$1=[REDACTED]')
    .replace(/Bearer\s+\S+/gi, 'Bearer [REDACTED]')
    .slice(0, 240);
}

export function buildFailureEvidence(state = {}, error = new Error('unknown_failure')) {
  const reason = safeFailureReason(error);
  const samplesSent = Number.isInteger(state.samplesSent) ? state.samplesSent : 0;
  const sampleRate = Number.isInteger(state.sampleRate) ? state.sampleRate : 16000;
  const providerRequestCount = Number.isInteger(state.providerRequestCount) ? state.providerRequestCount : 0;
  const actualRequest = state.actualRequest ?? null;
  const diagnosticErrors = Array.isArray(state.diagnosticErrors)
    ? state.diagnosticErrors.map(diagnostic => ({
      classification: typeof diagnostic?.classification === 'string' ? diagnostic.classification : 'runtime',
      safe_reason: safeFailureReason(diagnostic?.safe_reason ?? diagnostic?.message ?? diagnostic),
    }))
    : [];
  return {
    status: 'INCONCLUSIVE_EVIDENCE_FAILURE',
    safe_exit_state: 'fail_closed',
    safe_reason: reason,
    checks: {
      provider_connection_attempted: providerRequestCount === 1,
      actual_request_projection_captured: actualRequest !== null,
      actual_mip_opt_out_true: actualRequest?.mip_opt_out === 'true',
      provider_accepted: state.providerOpened === true,
      audio_send_started: state.audioSendStarted === true,
      audio_send_completed: state.audioSendCompleted === true,
      normal_provider_close: state.providerCloseCode === 1000,
      automatic_retry_zero: true,
      automatic_reconnect_zero: true,
      manual_reconnect_zero: true,
    },
    request: actualRequest,
    source: {
      sha256: state.sourceSha256 ?? APPROVED_SOURCE_SHA256,
      duration_seconds: state.durationSeconds ?? APPROVED_DURATION_SECONDS,
      sample_rate_hz: sampleRate,
      bits_per_sample: 16,
      channels: 1,
      chunk_ms: 100,
      samples_sent: samplesSent,
      bytes_sent: samplesSent * 2,
      duration_sent_seconds: samplesSent / sampleRate,
    },
    cost: {
      estimated_usd: state.estimatedCostUsd ?? null,
      actual_usd: null,
      limit_usd: state.costLimitUsd ?? APPROVED_COST_LIMIT_USD,
      price_version: 'deepgram-conservative-2026-09',
    },
    counts: {
      provider_requests: providerRequestCount,
      frames: Number.isInteger(state.frameCount) ? state.frameCount : 0,
      partials: Number.isInteger(state.partialCount) ? state.partialCount : 0,
      finals: Number.isInteger(state.finalCount) ? state.finalCount : 0,
      metadata: Number.isInteger(state.metadataCount) ? state.metadataCount : 0,
    },
    connection: {
      loopback_listener_opened: state.loopbackListenerOpened === true,
      provider_accepted: state.providerOpened === true,
      provider_close_code: state.providerCloseCode ?? null,
      provider_close_reason: safeFailureReason(state.providerCloseReason ?? ''),
      loopback_close_code: state.localCloseCode ?? null,
      loopback_close_reason: safeFailureReason(state.localCloseReason ?? ''),
    },
    provider_events: [],
    frame_receipts: [],
    browser_messages: [],
    errors: diagnosticErrors.length > 0
      ? diagnosticErrors
      : [{ classification: state.classification ?? 'runtime', safe_reason: reason }],
  };
}

export function encodeEvidenceFrame(result, frameId) {
  if (!/^[a-f0-9]{32}$/.test(String(frameId ?? ''))) throw new Error('evidence_frame_id_invalid');
  const begin = `@@COMPANY_OS_EVIDENCE_V1:${frameId}:BEGIN@@`;
  const end = `@@COMPANY_OS_EVIDENCE_V1:${frameId}:END@@`;
  return `${begin}\n${JSON.stringify(result)}\n${end}\n`;
}

export async function runLimitedVerification(env = process.env) {
  const audioPath = path.resolve(env.COMPANY_OS_P1_I_AUDIO_PATH ?? '');
  const credentialPath = path.resolve(env.COMPANY_OS_P1_I_CREDENTIAL_PATH ?? '');
  const helperPath = path.resolve(env.COMPANY_OS_P1_I_DPAPI_HELPER_PATH ?? '');
  const tlsKeyPath = path.resolve(env.COMPANY_OS_P1_I_TLS_KEY_PATH ?? '');
  const tlsCertPath = path.resolve(env.COMPANY_OS_P1_I_TLS_CERT_PATH ?? '');
  for (const required of [audioPath, credentialPath, helperPath, tlsKeyPath, tlsCertPath]) {
    if (!required || !fs.existsSync(required)) throw new Error('limited_verification_required_file_missing');
  }
  const wav = fs.readFileSync(audioPath);
  const format = inspectApprovedWav(wav);
  const policy = assertLimitedPolicy(env, format.durationSeconds);
  const estimatedCostMicrounits = Math.round(policy.estimatedCostUsd * 1_000_000);
  const pcm = wav.subarray(format.offset, format.offset + format.bytes);
  const projection = buildRequestProjection({}, env);
  const expectedLease = crypto.randomUUID();
  const expectedStream = crypto.randomUUID();
  const expectedOrigin = 'https://127.0.0.1';
  const abortController = new AbortController();
  const providerClosed = deferred();
  const clientReady = deferred();
  const clientClosed = deferred();
  const serverDone = deferred();
  const events = [];
  const browserMessages = [];
  const frameReceipts = [];
  const errors = [];
  let secret = '';
  let actualRequest = null;
  let provider = null;
  let providerOpened = false;
  let providerCloseCode = null;
  let providerCloseReason = null;
  let providerRequestCount = 0;
  let samplesSent = 0;
  let receiveOrder = 0;
  let acceptedConnections = 0;
  let pendingMetadata = null;
  let stopReceived = false;
  let loopbackListenerOpened = false;
  let audioSendStarted = false;
  let audioSendCompleted = false;
  let localCloseCode = null;
  let localCloseReason = '';
  let outcome = null;

  const server = https.createServer({ key: fs.readFileSync(tlsKeyPath), cert: fs.readFileSync(tlsCertPath) }, (_request, response) => {
    response.writeHead(404, { 'content-type': 'text/plain' });
    response.end('not found');
  });
  const wss = new WebSocketServer({ noServer: true, clientTracking: true, perMessageDeflate: false, maxPayload: CHUNK_SAMPLES * 2 + 4096 });
  server.on('upgrade', (request, socket, head) => {
    const remote = request.socket.remoteAddress;
    const local = request.socket.localAddress;
    if (acceptedConnections >= 1 || request.url !== '/realtime-relay' || request.headers.origin !== expectedOrigin
      || !['127.0.0.1', '::ffff:127.0.0.1'].includes(remote) || !['127.0.0.1', '::ffff:127.0.0.1'].includes(local)) {
      socket.destroy();
      return;
    }
    acceptedConnections += 1;
    wss.handleUpgrade(request, socket, head, ws => wss.emit('connection', ws, request));
  });

  wss.on('connection', async ws => {
    try {
      secret = loadDpapiSecret(credentialPath, helperPath);
      providerRequestCount += 1;
      if (providerRequestCount !== 1) throw new Error('provider_request_limit_exceeded');
      provider = await createDeepgramSession({
        apiKey: secret, signal: abortController.signal, env, projection,
        onRequestProjection: request => { actualRequest = request; },
      });
      provider.on('open', () => { providerOpened = true; });
      provider.on('message', event => {
        receiveOrder += 1;
        const safe = safeProviderEvent(event, receiveOrder, samplesSent, estimatedCostMicrounits);
        events.push(safe);
        if (safe.type === 'Results') {
          const content = safe.channel.alternatives[0].transcript;
          const message = safe.is_final
            ? { type: 'final_candidate', provider_session_id: expectedStream, expected_final_receive_order: receiveOrder, content }
            : { type: 'partial', provider_session_id: expectedStream, expected_final_receive_order: receiveOrder, content };
          if (ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(message));
        }
      });
      provider.on('error', error => {
        const reason = safeFailureReason(error);
        errors.push({ classification: providerOpened ? 'provider' : 'session', safe_reason: reason });
        providerClosed.reject(new Error(reason));
      });
      provider.on('close', event => {
        providerCloseCode = event?.code ?? null;
        providerCloseReason = typeof event?.reason === 'string' ? event.reason : '';
        providerClosed.resolve();
      });
      startDeepgramSession(provider);
      try {
        await within(provider.waitForOpen(), 10000, 'provider_open_timeout');
      } catch (error) {
        throw new Error(safeFailureReason(error));
      }
      if (!providerOpened) throw new Error('provider_open_event_missing');
      ws.send(JSON.stringify({ type: 'provider_state', state: 'ready' }));
      clientReady.resolve();

      ws.on('message', async (data, isBinary) => {
        try {
          if (!isBinary) {
            const message = JSON.parse(data.toString('utf8'));
            if (message.type === 'stop') {
              if (pendingMetadata || stopReceived) throw new Error('relay_stop_sequence_invalid');
              stopReceived = true;
              provider.sendFinalize({ type: 'Finalize' });
              await sleep(4000);
              provider.sendCloseStream({ type: 'CloseStream' });
              await within(providerClosed.promise, 8000, 'provider_normal_close_timeout');
              if (ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: 'provider_state', state: 'closed' }));
                ws.close(1000, 'limited verification complete');
              }
              serverDone.resolve();
              return;
            }
            if (pendingMetadata || message.type !== 'audio_frame' || message.lease_id !== expectedLease || message.stream_id !== expectedStream) {
              throw new Error('relay_metadata_failed_closed');
            }
            pendingMetadata = message;
            return;
          }
          if (!pendingMetadata || stopReceived) throw new Error('relay_binary_sequence_invalid');
          const binary = Buffer.from(data);
          const next = validateFrame(pendingMetadata, binary, samplesSent);
          frameReceipts.push({
            sequence: pendingMetadata.sequence, client_event_id: pendingMetadata.client_event_id,
            start_sample: pendingMetadata.start_sample, end_sample: pendingMetadata.end_sample,
            content_sha256: pendingMetadata.content_sha256,
          });
          samplesSent = next;
          pendingMetadata = null;
          audioSendStarted = true;
          provider.sendMedia(binary);
          if (samplesSent === format.sampleFrames) audioSendCompleted = true;
        } catch (error) {
          errors.push({ classification: 'relay', message: error.message });
          abortController.abort();
          ws.close(1011, 'relay fail closed');
          serverDone.reject(error);
        }
      });
      ws.on('close', (code, reason) => clientClosed.resolve({ code, reason: reason.toString('utf8') }));
      ws.on('error', error => clientClosed.reject(error));
    } catch (error) {
      errors.push({ classification: 'session', safe_reason: safeFailureReason(error) });
      abortController.abort();
      if (ws.readyState === WebSocket.OPEN) ws.close(1011, 'session fail closed');
      clientReady.reject(error);
      serverDone.reject(error);
    }
  });

  try {
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });
    const address = server.address();
    if (!address || address.address !== '127.0.0.1') throw new Error('loopback_listener_guard_failed');
    loopbackListenerOpened = true;
    const client = new WebSocket(`wss://127.0.0.1:${address.port}/realtime-relay`, {
      origin: expectedOrigin, rejectUnauthorized: false, perMessageDeflate: false,
    });
    client.on('message', data => {
      const message = JSON.parse(data.toString('utf8'));
      browserMessages.push(message);
    });
    client.on('close', (code, reason) => clientClosed.resolve({ code, reason: reason.toString('utf8') }));
    client.on('error', error => clientClosed.reject(error));
    await within(new Promise((resolve, reject) => {
      client.once('open', resolve);
      client.once('error', reject);
    }), 5000, 'loopback_wss_open_timeout');
    await within(clientReady.promise, 12000, 'provider_ready_timeout');

    const startedAt = performance.now();
    let sequence = 0;
    for (let byteOffset = 0; byteOffset < pcm.length; byteOffset += CHUNK_SAMPLES * 2) {
      const target = sequence * 100;
      const wait = target - (performance.now() - startedAt);
      if (wait > 0) await sleep(wait);
      const binary = pcm.subarray(byteOffset, Math.min(pcm.length, byteOffset + CHUNK_SAMPLES * 2));
      const startSample = byteOffset / 2;
      const endSample = startSample + binary.length / 2;
      const metadata = {
        type: 'audio_frame', lease_id: expectedLease, stream_id: expectedStream, generation: 1,
        sequence: ++sequence, client_event_id: crypto.randomUUID(), start_sample: startSample,
        end_sample: endSample, sample_count: endSample - startSample, sample_rate: 16000,
        bit_depth: 16, channels: 1, format: 'pcm_s16le', content_sha256: sha256(binary).toLowerCase(),
      };
      client.send(JSON.stringify(metadata));
      client.send(binary);
    }
    client.send(JSON.stringify({ type: 'stop' }));
    await within(serverDone.promise, 15000, 'relay_completion_timeout');
    const localClose = await within(clientClosed.promise, 5000, 'loopback_client_close_timeout');
    localCloseCode = localClose.code;
    localCloseReason = localClose.reason;
    const finals = events.filter(event => event.type === 'Results' && event.is_final && event.channel.alternatives[0].transcript);
    const partials = events.filter(event => event.type === 'Results' && !event.is_final && event.channel.alternatives[0].transcript);
    const words = finals.flatMap(event => event.channel.alternatives[0].words);
    const metadata = events.filter(event => event.type === 'Metadata');
    const checks = {
      loopback_wss_only: address.address === '127.0.0.1', provider_request_count_one: providerRequestCount === 1,
      provider_connection_attempted: providerRequestCount === 1, provider_accepted: providerOpened,
      actual_request_projection_captured: actualRequest !== null,
      audio_send_started: audioSendStarted, audio_send_completed: audioSendCompleted,
      actual_mip_opt_out_true: actualRequest?.mip_opt_out === 'true', reconnect_attempts_zero: actualRequest?.reconnectAttempts === 0,
      server_held_credential: true, source_audio_complete: samplesSent === format.sampleFrames,
      source_cursor_complete: frameReceipts.length === Math.ceil(format.sampleFrames / CHUNK_SAMPLES),
      partial_received: partials.length > 0, final_received: finals.length > 0,
      word_timing_received: words.some(word => Number.isFinite(word.start) && Number.isFinite(word.end)),
      speaker_hint_received: words.some(word => Number.isInteger(word.speaker)),
      result_identity_received: events.some(event => event.type === 'Results' && Boolean(event.request_id)),
      provider_timing_received: finals.some(event => Number.isFinite(event.start) && Number.isFinite(event.duration)),
      usage_evidence_received: metadata.some(event => Number.isFinite(event.usage?.quantity)),
      estimated_cost_within_limit: policy.estimatedCostUsd <= policy.limit,
      normal_provider_close: providerCloseCode === 1000,
      normal_loopback_close: localClose.code === 1000,
      automatic_retry_zero: true, automatic_reconnect_zero: true, manual_reconnect_zero: true,
      error_count_zero: errors.length === 0,
    };
    outcome = {
      status: Object.values(checks).every(Boolean) ? 'PASS' : 'FAIL',
      checks,
      request: actualRequest,
      source: {
        sha256: APPROVED_SOURCE_SHA256, duration_seconds: format.durationSeconds, sample_rate_hz: 16000,
        bits_per_sample: 16, channels: 1, chunk_ms: 100, sample_frames: format.sampleFrames,
        samples_sent: samplesSent, bytes_sent: samplesSent * 2,
        duration_sent_seconds: samplesSent / format.sampleRate,
      },
      cost: { estimated_usd: policy.estimatedCostUsd, limit_usd: policy.limit, price_version: 'deepgram-conservative-2026-09' },
      counts: { provider_requests: providerRequestCount, frames: frameReceipts.length, partials: partials.length, finals: finals.length, metadata: metadata.length },
      connection: { provider_close_code: providerCloseCode, provider_close_reason: providerCloseReason, loopback_close_code: localClose.code },
      provider_events: events,
      frame_receipts: frameReceipts,
      browser_messages: browserMessages,
      errors,
    };
  } catch (error) {
    const partials = events.filter(event => event.type === 'Results' && !event.is_final && event.channel.alternatives[0].transcript);
    const finals = events.filter(event => event.type === 'Results' && event.is_final && event.channel.alternatives[0].transcript);
    const metadata = events.filter(event => event.type === 'Metadata');
    outcome = buildFailureEvidence({
      classification: errors.at(-1)?.classification ?? 'runtime', actualRequest, providerOpened,
      providerCloseCode, providerCloseReason, providerRequestCount, samplesSent,
      sampleRate: format.sampleRate, durationSeconds: format.durationSeconds,
      sourceSha256: APPROVED_SOURCE_SHA256, estimatedCostUsd: policy.estimatedCostUsd,
      costLimitUsd: policy.limit, frameCount: frameReceipts.length, partialCount: partials.length,
      finalCount: finals.length, metadataCount: metadata.length, loopbackListenerOpened,
      audioSendStarted, audioSendCompleted, localCloseCode, localCloseReason, diagnosticErrors: errors,
    }, error);
  } finally {
    secret = '';
    abortController.abort();
    for (const client of wss.clients) client.terminate();
    await new Promise(resolve => wss.close(() => resolve()));
    if (server.listening) await new Promise(resolve => server.close(() => resolve()));
  }
  outcome.safe_exit = {
    abort_signalled: abortController.signal.aborted,
    loopback_server_closed: !server.listening,
    active_wss_clients: wss.clients.size,
  };
  return outcome;
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : '';
if (invokedPath === fileURLToPath(import.meta.url)) {
  try {
    const result = await runLimitedVerification(process.env);
    process.stdout.write(encodeEvidenceFrame(result, process.env.COMPANY_OS_EVIDENCE_FRAME_ID));
    if (result.status !== 'PASS') process.exitCode = 1;
  } catch (error) {
    const failure = buildFailureEvidence({ classification: 'preflight_boundary' }, error);
    try {
      process.stdout.write(encodeEvidenceFrame(failure, process.env.COMPANY_OS_EVIDENCE_FRAME_ID));
    } catch (frameError) {
      process.stderr.write(`${safeFailureReason(frameError)}\n`);
    }
    process.exitCode = 1;
  }
}
