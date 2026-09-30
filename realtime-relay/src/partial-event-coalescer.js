export const PARTIAL_FORWARD_INTERVAL_MS = 2000;

export function isEphemeralPartial(event) {
  const transcript = event?.channel?.alternatives?.[0]?.transcript;
  return event?.type === 'Results'
    && event?.is_final !== true
    && typeof transcript === 'string'
    && transcript.trim() !== '';
}

export class PartialEventCoalescer {
  #intervalMs;
  #lastForwardedAt = null;
  #pending = null;

  constructor(intervalMs = PARTIAL_FORWARD_INTERVAL_MS) {
    if (!Number.isInteger(intervalMs) || intervalMs < 0) throw new Error('partial_forward_interval_invalid');
    this.#intervalMs = intervalMs;
  }

  offer(item, nowMs) {
    this.#assertTime(nowMs);
    if (this.#lastForwardedAt === null || nowMs - this.#lastForwardedAt >= this.#intervalMs) {
      this.#lastForwardedAt = nowMs;
      return item;
    }
    this.#pending = item;
    return null;
  }

  takeDue(nowMs, force = false) {
    this.#assertTime(nowMs);
    if (!this.#pending) return null;
    if (!force && nowMs - this.#lastForwardedAt < this.#intervalMs) return null;
    const item = this.#pending;
    this.#pending = null;
    this.#lastForwardedAt = nowMs;
    return item;
  }

  nextDelay(nowMs) {
    this.#assertTime(nowMs);
    if (!this.#pending || this.#lastForwardedAt === null) return null;
    return Math.max(0, this.#intervalMs - (nowMs - this.#lastForwardedAt));
  }

  discardPending() {
    this.#pending = null;
  }

  get hasPending() {
    return this.#pending !== null;
  }

  #assertTime(nowMs) {
    if (!Number.isFinite(nowMs) || nowMs < 0) throw new Error('partial_event_time_invalid');
  }
}
