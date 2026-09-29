import { DeepgramClient } from '@deepgram/sdk';

export const SDK_VERSION = '5.10.0';

const startedSessions = new WeakSet();

export function buildRequestProjection(overrides = {}, env = process.env) {
  const projection = {
    model: 'nova-3',
    language: 'ja',
    encoding: 'linear16',
    sample_rate: 16000,
    channels: 1,
    diarize: true,
    diarize_model: 'latest',
    interim_results: true,
    punctuate: true,
    smart_format: true,
    mip_opt_out: true,
    automatic_retry: false,
    ...overrides,
  };
  assertNetworkFence(projection, env);
  return Object.freeze(projection);
}

export function assertNetworkFence(projection, env = process.env) {
  if (env.COMPANY_OS_REALTIME_ENABLED !== 'true' || env.COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED !== 'true') {
    throw new Error('audio_send_disabled');
  }
  if (projection.mip_opt_out !== true || projection.automatic_retry !== false) {
    throw new Error('mip_guard_failed');
  }
  for (const forbidden of ['api_key', 'authorization', 'credential', 'secret']) {
    if (Object.hasOwn(projection, forbidden)) throw new Error('provider_secret_crossed_request_boundary');
  }
  return true;
}

export async function createDeepgramSession({
  apiKey, signal, env = process.env, projection = null, onRequestProjection = null,
  clientFactory = options => new DeepgramClient(options),
}) {
  projection ??= buildRequestProjection({}, env);
  assertNetworkFence(projection, env);
  if (!apiKey || typeof apiKey !== 'string') throw new Error('server_credential_unavailable');
  const client = clientFactory({ apiKey });
  const request = {
    model: projection.model,
    language: projection.language,
    encoding: projection.encoding,
    sample_rate: projection.sample_rate,
    channels: projection.channels,
    diarize: String(projection.diarize),
    diarize_model: projection.diarize_model,
    interim_results: String(projection.interim_results),
    punctuate: String(projection.punctuate),
    smart_format: String(projection.smart_format),
    mip_opt_out: 'true',
    abortSignal: signal,
    reconnectAttempts: 0,
  };
  onRequestProjection?.(Object.freeze({ ...request, abortSignal: Boolean(request.abortSignal) }));
  return client.listen.v1.connect(request);
}

export function startDeepgramSession(session) {
  if (!session || typeof session.connect !== 'function') throw new Error('provider_session_start_unavailable');
  if (startedSessions.has(session)) throw new Error('provider_session_start_duplicate');
  startedSessions.add(session);
  return session.connect();
}
