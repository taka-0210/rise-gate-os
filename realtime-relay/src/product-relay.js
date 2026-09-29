import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import WebSocket, { WebSocketServer } from 'ws';
import { buildRequestProjection, createDeepgramSession, startDeepgramSession } from './deepgram-port.js';
import { safeFailureReason } from './limited-verification.js';

export const CONSERVATIVE_COST_USD_PER_MINUTE = 0.0097;
export const FAILURE_EVIDENCE_CONTRACT = 'p1-j-failure-v2';
const SAMPLE_RATE = 16000;

function sleep(milliseconds) {
  return new Promise(resolve => setTimeout(resolve, milliseconds));
}

async function within(promise, milliseconds, reason) {
  let timer;
  try {
    return await Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(reason)), milliseconds); })]);
  } finally {
    clearTimeout(timer);
  }
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  promise.catch(() => {});
  return { promise, resolve, reject };
}

export class HumanGateController {
  minimumPassed = false;
  halted = false;

  assertStartAllowed() {
    if (this.halted) throw new Error('human_gate_halted_after_failure');
  }

  markPartial(content) {
    if (typeof content === 'string' && content.trim() !== '') this.minimumPassed = true;
  }

  markFailure() {
    this.halted = true;
  }

  markSessionEnded() {
    if (!this.minimumPassed) this.halted = true;
  }
}
export function buildSessionFailureEvidence({ error, failureStage, providerConnectionAttempted, providerAccepted, samplesSent }) {
  const reason = safeFailureReason(error);
  const stage = typeof failureStage === 'string' && failureStage !== '' ? failureStage : 'unknown';
  return {
    type: 'session_failure',
    evidence_contract: FAILURE_EVIDENCE_CONTRACT,
    reason,
    failure_stage: stage,
    provider_connection_attempted: providerConnectionAttempted === true,
    provider_accepted: providerAccepted === true,
    samples_sent: Number.isInteger(samplesSent) && samplesSent >= 0 ? samplesSent : 0,
    evidence_completeness: stage !== 'unknown' && !reason.startsWith('unknown_') ? 'complete' : 'incomplete',
  };
}

export const safeReason = safeFailureReason;

export function assertHumanRuntimePolicy(env = process.env) {
  const maxSessions = Number(env.COMPANY_OS_REALTIME_MAX_PROVIDER_SESSIONS);
  const maxAudioSeconds = Number(env.COMPANY_OS_REALTIME_MAX_AUDIO_SECONDS);
  const costLimit = Number(env.COMPANY_OS_REALTIME_COST_LIMIT_USD);
  const projectedCost = maxAudioSeconds / 60 * CONSERVATIVE_COST_USD_PER_MINUTE;
  if (env.COMPANY_OS_P1_J_APPROVED !== 'true' || env.COMPANY_OS_REALTIME_ENABLED !== 'true'
    || env.COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED !== 'true' || env.COMPANY_OS_REALTIME_RETRY_MAX !== '0'
    || env.COMPANY_OS_REALTIME_RECONNECT_MAX !== '0') throw new Error('p1_j_runtime_fence_closed');
  if (!Number.isInteger(maxSessions) || maxSessions < 1 || maxSessions > 3
    || !Number.isInteger(maxAudioSeconds) || maxAudioSeconds < 10 || maxAudioSeconds > 300
    || !Number.isFinite(costLimit) || costLimit <= 0 || costLimit > 0.05 || projectedCost > costLimit) {
    throw new Error('p1_j_cost_or_attempt_fence_failed');
  }
  if (!env.COMPANY_OS_REALTIME_BRIDGE_TOKEN || env.COMPANY_OS_REALTIME_BRIDGE_TOKEN.length < 32) throw new Error('bridge_token_unavailable');
  return { maxSessions, maxAudioSeconds, costLimit, projectedCost };
}

export function validateProductFrame(metadata, binary, expectedStartSample) {
  const hash = crypto.createHash('sha256').update(binary).digest('hex');
  const claimedHash = String(metadata?.content_sha256 ?? '').toLowerCase();
  if (metadata?.type !== 'audio_frame' || typeof metadata.lease_id !== 'string' || typeof metadata.stream_id !== 'string'
    || !Number.isInteger(metadata.generation) || metadata.generation < 1 || !Number.isInteger(metadata.sequence) || metadata.sequence < 1
    || typeof metadata.client_event_id !== 'string' || metadata.start_sample !== expectedStartSample
    || metadata.end_sample <= metadata.start_sample || metadata.sample_count !== metadata.end_sample - metadata.start_sample
    || metadata.sample_rate !== SAMPLE_RATE || metadata.bit_depth !== 16 || metadata.channels !== 1 || metadata.format !== 'pcm_s16le'
    || binary.length !== metadata.sample_count * 2 || !/^[a-f0-9]{64}$/.test(claimedHash)
    || !crypto.timingSafeEqual(Buffer.from(hash), Buffer.from(claimedHash))) {
    throw new Error('canonical_audio_frame_failed_closed');
  }
  return metadata.end_sample;
}

export function sanitizeProviderEvent(event, samplesSent, estimatedCostMicrounits) {
  const alternative = event?.channel?.alternatives?.[0] ?? {};
  const safe = {
    type: event?.type ?? null,
    request_id: event?.request_id ?? event?.metadata?.request_id ?? null,
    sequence: Number.isInteger(event?.sequence) ? event.sequence : null,
    is_final: event?.is_final === true,
    speech_final: event?.speech_final === true,
    start: Number.isFinite(event?.start) ? event.start : null,
    duration: Number.isFinite(event?.duration) ? event.duration : null,
    channel: { alternatives: [{
      transcript: typeof alternative.transcript === 'string' ? alternative.transcript : '',
      words: Array.isArray(alternative.words) ? alternative.words.map(word => ({
        start: Number.isFinite(word.start) ? word.start : null,
        end: Number.isFinite(word.end) ? word.end : null,
        confidence: Number.isFinite(word.confidence) ? word.confidence : null,
        speaker: Number.isInteger(word.speaker) ? word.speaker : null,
      })) : [],
    }] },
    _company_os: { samples_sent_at_receive: samplesSent },
  };
  if (safe.type === 'Metadata' && Number.isFinite(event.duration)) {
    safe.usage = { quantity: event.duration, unit: 'duration_seconds', price_version: 'deepgram-conservative-2026-09', estimated_cost_microunits: estimatedCostMicrounits };
  }
  return safe;
}

function loadDpapiSecret(credentialPath, helperPath) {
  const secret = execFileSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', helperPath, '-CredentialPath', credentialPath], {
    encoding: 'utf8', windowsHide: true, maxBuffer: 16384,
  }).trim();
  if (secret.length < 16) throw new Error('server_credential_unavailable');
  return secret;
}

function appendEvidence(file, record) {
  if (!file) return;
  fs.appendFileSync(file, `${JSON.stringify({ at: new Date().toISOString(), ...record })}\n`, { encoding: 'utf8' });
}

export function normalizeProxyResponseHeaders(headers, expectedOrigin) {
  const normalized = { ...headers };
  if (typeof normalized.location !== 'string') return normalized;

  try {
    const expected = new URL(expectedOrigin);
    const location = new URL(normalized.location);
    if (location.protocol === 'http:' && location.hostname === expected.hostname && location.port === expected.port) {
      location.protocol = expected.protocol;
      normalized.location = location.toString();
    }
  } catch {
    // Relative and malformed Location values are passed through unchanged.
  }

  return normalized;
}

export async function createProductRelay(env = process.env) {
  const policy = assertHumanRuntimePolicy(env);
  const host = env.COMPANY_OS_REALTIME_RELAY_HOST ?? '127.0.0.1';
  const port = Number(env.COMPANY_OS_REALTIME_RELAY_PORT ?? 8443);
  const upstream = new URL(env.COMPANY_OS_REALTIME_UPSTREAM_ORIGIN ?? 'http://127.0.0.1:8765');
  const expectedOrigin = env.COMPANY_OS_REALTIME_ALLOWED_ORIGIN ?? `https://localhost:${port}`;
  const bridgeToken = env.COMPANY_OS_REALTIME_BRIDGE_TOKEN;
  const credentialPath = path.resolve(env.COMPANY_OS_REALTIME_CREDENTIAL_PATH ?? '');
  const helperPath = path.resolve(env.COMPANY_OS_REALTIME_DPAPI_HELPER_PATH ?? '');
  const tlsKeyPath = path.resolve(env.COMPANY_OS_REALTIME_TLS_KEY_PATH ?? '');
  const tlsCertPath = path.resolve(env.COMPANY_OS_REALTIME_TLS_CERT_PATH ?? '');
  const evidencePath = env.COMPANY_OS_P1_J_EVIDENCE_PATH ? path.resolve(env.COMPANY_OS_P1_J_EVIDENCE_PATH) : '';
  for (const required of [credentialPath, helperPath, tlsKeyPath, tlsCertPath]) if (!required || !fs.existsSync(required)) throw new Error('p1_j_runtime_file_missing');
  if (host !== '127.0.0.1' || !Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('loopback_listener_guard_failed');

  let sessionCount = 0;
  let totalSamplesSent = 0;
  const humanGate = new HumanGateController();
  const bridge = async (action, payload) => {
    const response = await fetch(new URL(`/api/internal/realtime-relay/${action}`, upstream), {
      method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json', 'x-companyos-relay-token': bridgeToken },
      body: JSON.stringify(payload), signal: AbortSignal.timeout(5000),
    });
    if (!response.ok) throw new Error(`bridge_${action}_rejected_${response.status}`);
    return response.json();
  };

  const server = https.createServer({ key: fs.readFileSync(tlsKeyPath), cert: fs.readFileSync(tlsCertPath) }, (request, response) => {
    if (!['127.0.0.1', '::ffff:127.0.0.1'].includes(request.socket.remoteAddress)) { response.writeHead(404); response.end(); return; }
    const proxy = http.request({ hostname: upstream.hostname, port: upstream.port, path: request.url, method: request.method, headers: {
      ...request.headers, host: `localhost:${port}`, 'x-forwarded-proto': 'https', 'x-forwarded-host': `localhost:${port}`,
      'x-forwarded-port': String(port),
    } }, upstreamResponse => {
      response.writeHead(
        upstreamResponse.statusCode ?? 502,
        normalizeProxyResponseHeaders(upstreamResponse.headers, expectedOrigin),
      );
      upstreamResponse.pipe(response);
    });
    proxy.on('error', () => { if (!response.headersSent) response.writeHead(502); response.end('Local application unavailable.'); });
    request.pipe(proxy);
  });
  const wss = new WebSocketServer({ noServer: true, clientTracking: true, perMessageDeflate: false, maxPayload: 32768 });
  server.on('upgrade', (request, socket, head) => {
    if (request.url !== '/realtime-relay' || request.headers.origin !== expectedOrigin
      || !['127.0.0.1', '::ffff:127.0.0.1'].includes(request.socket.remoteAddress)
      || !['127.0.0.1', '::ffff:127.0.0.1'].includes(request.socket.localAddress)) { socket.destroy(); return; }
    wss.handleUpgrade(request, socket, head, ws => wss.emit('connection', ws));
  });

  wss.on('connection', ws => {
    let pendingMetadata = null;
    let samplesSent = 0;
    let receiveOrder = 0;
    let providerSessionId = null;
    let provider = null;
    let secret = '';
    let opened = false;
    let closing = false;
    let failureStage = 'browser_connected';
    let providerConnectionAttempted = false;
    let messageChain = Promise.resolve();
    let eventChain = Promise.resolve();
    const abortController = new AbortController();
    const providerClosed = deferred();
    const sendBrowser = message => { if (ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(message)); };
    const fail = async error => {
      if (closing) return;
      closing = true;
      const failureEvidence = buildSessionFailureEvidence({
        error, failureStage, providerConnectionAttempted, providerAccepted: opened, samplesSent,
      });
      const reason = failureEvidence.reason;
      humanGate.markFailure();
      appendEvidence(evidencePath, failureEvidence);
      abortController.abort();
      if (providerSessionId) await bridge('close', { provider_session_id: providerSessionId, normal: false, safe_reason_code: reason }).catch(() => {});
      sendBrowser({ type: 'rejected', safe_reason_code: reason });
      if (ws.readyState === WebSocket.OPEN) ws.close(1011, 'relay fail closed');
      secret = '';
    };
    const ensureProvider = async metadata => {
      if (provider) return;
      humanGate.assertStartAllowed();
      sessionCount += 1;
      if (sessionCount > policy.maxSessions) throw new Error('provider_session_limit_exceeded');
      failureStage = 'bridge_open';
      const openedRecord = await bridge('open', { lease_id: metadata.lease_id, stream_id: metadata.stream_id, generation: metadata.generation });
      providerSessionId = openedRecord.provider_session_id;
      failureStage = 'credential_load';
      secret = loadDpapiSecret(credentialPath, helperPath);
      failureStage = 'request_projection';
      const projection = buildRequestProjection({}, env);
      failureStage = 'provider_session_create';
      provider = await createDeepgramSession({ apiKey: secret, signal: abortController.signal, env, projection });
      provider.on('open', () => { opened = true; });
      provider.on('message', event => {
        receiveOrder += 1;
        const order = receiveOrder;
        const estimated = Math.round((totalSamplesSent / SAMPLE_RATE / 60 * CONSERVATIVE_COST_USD_PER_MINUTE) * 1_000_000);
        const safe = sanitizeProviderEvent(event, samplesSent, estimated);
        const framesSettled = messageChain;
        eventChain = eventChain.then(async () => {
          await framesSettled;
          const result = await bridge('event', { provider_session_id: providerSessionId, receive_order: order, event: safe });
          if (result.type === 'partial') humanGate.markPartial(result.content);
          if (['partial', 'durable_final', 'rejected'].includes(result.type)) sendBrowser(result);
          appendEvidence(evidencePath, { type: 'provider_event', event_type: result.type, receive_order: order, durable: result.type === 'durable_final' });
        }).catch(fail);
      });
      provider.on('error', error => providerClosed.reject(error));
      provider.on('close', event => providerClosed.resolve({ code: event?.code ?? null }));
      failureStage = 'provider_session_start';
      startDeepgramSession(provider);
      providerConnectionAttempted = true;
      failureStage = 'provider_open_wait';
      await within(provider.waitForOpen(), 10000, 'provider_open_timeout');
      if (!opened) throw new Error('provider_open_event_missing');
      failureStage = 'bridge_opened';
      await bridge('opened', { provider_session_id: providerSessionId, provider_session_reference: null });
      appendEvidence(evidencePath, { type: 'provider_accepted', provider_session_id: providerSessionId, mip_opt_out: true, reconnect_attempts: 0 });
      sendBrowser({ type: 'provider_state', state: 'ready' });
      failureStage = 'provider_ready';
      secret = '';
    };
    const stop = async normal => {
      if (closing) return;
      closing = true;
      try {
        if (provider && normal) {
          provider.sendFinalize({ type: 'Finalize' });
          await sleep(1500);
          await eventChain;
          provider.sendCloseStream({ type: 'CloseStream' });
          await within(providerClosed.promise, 8000, 'provider_normal_close_timeout');
          receiveOrder += 1;
          await bridge('event', {
            provider_session_id: providerSessionId, receive_order: receiveOrder,
            event: { type: 'Close', event_id: crypto.randomUUID() },
          });
          await eventChain;
        } else {
          abortController.abort();
        }
        if (providerSessionId) await bridge('close', { provider_session_id: providerSessionId, normal, safe_reason_code: normal ? 'normal_stop' : 'hard_abort' });
        humanGate.markSessionEnded();
        appendEvidence(evidencePath, { type: 'session_close', normal, samples_sent: samplesSent, duration_seconds: samplesSent / SAMPLE_RATE });
        sendBrowser({ type: 'provider_state', state: 'closed' });
        sendBrowser({ type: 'relay_stopped', normal });
        if (ws.readyState === WebSocket.OPEN) ws.close(1000, normal ? 'normal_stop' : 'hard_abort');
      } catch (error) { closing = false; await fail(error); }
      secret = '';
    };

    ws.on('message', (data, isBinary) => {
      messageChain = messageChain.then(async () => {
        if (!isBinary) {
          const message = JSON.parse(data.toString('utf8'));
          if (message.type === 'stop' || message.type === 'cancel') { await stop(message.type === 'stop'); return; }
          if (pendingMetadata || message.type !== 'audio_frame') throw new Error('relay_metadata_failed_closed');
          pendingMetadata = message;
          await ensureProvider(message);
          return;
        }
        if (!pendingMetadata || closing || !provider || !opened) throw new Error('relay_binary_sequence_invalid');
        const binary = Buffer.from(data);
        const nextSample = validateProductFrame(pendingMetadata, binary, samplesSent);
        if ((totalSamplesSent + pendingMetadata.sample_count) / SAMPLE_RATE > policy.maxAudioSeconds) throw new Error('audio_duration_limit_exceeded');
        const accepted = await bridge('frame', { provider_session_id: providerSessionId, frame: pendingMetadata, binary_hash_verified: true });
        provider.sendMedia(binary);
        await bridge('sent', { provider_session_id: providerSessionId, source_range_id: accepted.source_range_id });
        samplesSent = nextSample;
        totalSamplesSent += pendingMetadata.sample_count;
        pendingMetadata = null;
      }).catch(fail);
    });
    ws.on('close', () => { if (!closing) stop(false).catch(() => {}); });
    ws.on('error', fail);
  });

  return {
    server, wss, policy,
    async listen() {
      await new Promise((resolve, reject) => { server.once('error', reject); server.listen(port, host, resolve); });
      appendEvidence(evidencePath, {
        type: 'runtime_ready', host, port, provider_requests: 0, audio_seconds: 0,
        evidence_contract: FAILURE_EVIDENCE_CONTRACT,
        failure_fields: [
          'failure_stage', 'provider_connection_attempted', 'provider_accepted',
          'samples_sent', 'reason', 'evidence_completeness',
        ],
      });
      return { host, port, origin: expectedOrigin };
    },
    async close() {
      for (const client of wss.clients) client.terminate();
      await new Promise(resolve => wss.close(() => resolve()));
      if (server.listening) await new Promise(resolve => server.close(() => resolve()));
    },
  };
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : '';
if (invokedPath === fileURLToPath(import.meta.url)) {
  try {
    const runtime = await createProductRelay(process.env);
    const address = await runtime.listen();
    process.stdout.write(`${JSON.stringify({ status: 'READY', ...address })}\n`);
    const shutdown = async () => { await runtime.close(); process.exit(0); };
    process.once('SIGINT', shutdown);
    process.once('SIGTERM', shutdown);
  } catch (error) {
    process.stderr.write(`${safeReason(error)}\n`);
    process.exitCode = 1;
  }
}
