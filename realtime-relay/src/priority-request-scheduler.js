const PRIORITIES = ['critical', 'normal', 'background'];

export class PriorityRequestScheduler {
  #queues = new Map(PRIORITIES.map(priority => [priority, []]));
  #running = false;
  #metrics = {
    submitted: { critical: 0, normal: 0, background: 0 },
    completed: { critical: 0, normal: 0, background: 0 },
    maximum_queue_depth: 0,
  };

  run(priority, operation) {
    if (!this.#queues.has(priority) || typeof operation !== 'function') {
      return Promise.reject(new Error('upstream_scheduler_input_invalid'));
    }

    this.#metrics.submitted[priority] += 1;
    const promise = new Promise((resolve, reject) => {
      this.#queues.get(priority).push({ operation, resolve, reject, priority });
    });
    this.#metrics.maximum_queue_depth = Math.max(this.#metrics.maximum_queue_depth, this.depth);
    this.#drain();
    return promise;
  }

  get depth() {
    return PRIORITIES.reduce((sum, priority) => sum + this.#queues.get(priority).length, 0);
  }

  snapshot() {
    return {
      submitted: { ...this.#metrics.submitted },
      completed: { ...this.#metrics.completed },
      maximum_queue_depth: this.#metrics.maximum_queue_depth,
      current_queue_depth: this.depth,
      running: this.#running,
    };
  }

  async #drain() {
    if (this.#running) return;
    this.#running = true;
    try {
      while (this.depth > 0) {
        const item = this.#next();
        try {
          item.resolve(await item.operation());
        } catch (error) {
          item.reject(error);
        } finally {
          this.#metrics.completed[item.priority] += 1;
        }
      }
    } finally {
      this.#running = false;
      if (this.depth > 0) this.#drain();
    }
  }

  #next() {
    for (const priority of PRIORITIES) {
      const item = this.#queues.get(priority).shift();
      if (item) return item;
    }
    throw new Error('upstream_scheduler_queue_empty');
  }
}
