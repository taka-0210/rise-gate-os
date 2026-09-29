import test from 'node:test';
import assert from 'node:assert/strict';
import { assertNetworkFence, buildRequestProjection, SDK_VERSION } from '../src/deepgram-port.js';
import { ConnectionRegistry } from '../src/connection-registry.js';

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
