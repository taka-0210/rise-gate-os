export class ConnectionRegistry {
  #connections = new Map();

  register({ leaseId, streamId, generation, expiresAt, close }) {
    const key = this.#key(streamId, generation);
    if (this.#connections.has(key)) throw new Error('generation_already_registered');
    const entry = { leaseId, streamId, generation, expiresAt, close, acceptingFrames: true, providerEgress: true, closed: false };
    this.#connections.set(key, entry);
    return entry;
  }

  acceptFrame({ leaseId, streamId, generation, now = Date.now() }) {
    const entry = this.#connections.get(this.#key(streamId, generation));
    if (!entry || entry.closed || !entry.acceptingFrames || entry.leaseId !== leaseId || now >= entry.expiresAt) {
      throw new Error('lease_or_generation_not_current');
    }
    return entry;
  }

  async forceClose({ streamId, generation, operationId, hardAbort }) {
    const entry = this.#connections.get(this.#key(streamId, generation));
    if (!entry) return { operationId, acknowledgement: 'stale_generation' };
    if (entry.closed) return { operationId, acknowledgement: 'already_closed' };
    entry.acceptingFrames = false;
    entry.providerEgress = false;
    entry.closed = true;
    await entry.close({ hardAbort });
    return { operationId, acknowledgement: 'provider_egress_stopped_and_socket_closed' };
  }

  #key(streamId, generation) {
    return `${streamId}:${generation}`;
  }
}
