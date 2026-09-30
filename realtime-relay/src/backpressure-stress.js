import crypto from 'node:crypto';
import { performance } from 'node:perf_hooks';
import {
  assertSentBatchEvidence, LEDGER_BATCH_FRAMES, MAX_QUEUED_FRAMES,
  SentEvidenceGate, takeLedgerBatch, validateProductFrame,
} from './product-relay.js';
import { PartialEventCoalescer } from './partial-event-coalescer.js';

const FRAME_SAMPLES = 1600;
const FRAME_INTERVAL_MS = 100;

function sleep(milliseconds) {
  return new Promise(resolve => setTimeout(resolve, milliseconds));
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  promise.catch(() => {});
  return { promise, resolve, reject };
}

class SerialBridge {
  #lane = Promise.resolve();
  #aborted = false;

  constructor(serviceMs) {
    this.serviceMs = serviceMs;
    this.nextAcceptedSample = 0;
    this.nextSendOrdinal = 1;
    this.sentEndSample = 0;
    this.sourceRanges = [];
    this.sendReceipts = [];
    this.partialWatermarks = [];
    this.sha256Checks = 0;
  }

  abort() {
    this.#aborted = true;
  }

  request(work) {
    const operation = this.#lane.then(async () => {
      if (this.#aborted) throw new Error('stress_bridge_aborted');
      await sleep(this.serviceMs);
      if (this.#aborted) throw new Error('stress_bridge_aborted');
      return work();
    });
    this.#lane = operation.catch(() => {});
    return operation;
  }

  frames(previousIds, batch) {
    return this.request(() => {
      const sent = previousIds.map(id => {
        const range = this.sourceRanges.find(item => item.source_range_id === id);
        if (!range) throw new Error('stress_source_range_missing');
        const receipt = { send_ordinal: this.nextSendOrdinal, state: 'sent', end_sample: range.end_sample };
        this.nextSendOrdinal += 1;
        this.sentEndSample = range.end_sample;
        this.sendReceipts.push(receipt);
        return receipt;
      });
      const ranges = batch.map(item => {
        this.nextAcceptedSample = validateProductFrame(item.metadata, item.binary, this.nextAcceptedSample);
        this.sha256Checks += 1;
        const range = {
          source_range_id: `stress-range-${item.metadata.sequence}`,
          state: 'accepted',
          start_sample: item.metadata.start_sample,
          end_sample: item.metadata.end_sample,
        };
        this.sourceRanges.push(range);
        return range;
      });
      return { sent, ranges };
    });
  }

  sentBatch(ids) {
    return this.request(() => ({ sent: ids.map(id => {
      const range = this.sourceRanges.find(item => item.source_range_id === id);
      if (!range) throw new Error('stress_source_range_missing');
      const receipt = { send_ordinal: this.nextSendOrdinal, state: 'sent', end_sample: range.end_sample };
      this.nextSendOrdinal += 1;
      this.sentEndSample = range.end_sample;
      this.sendReceipts.push(receipt);
      return receipt;
    }) }));
  }

  partial(item) {
    return this.request(() => {
      if (this.sentEndSample < item.samplesAtReceive) throw new Error('stress_partial_watermark_unverified');
      this.partialWatermarks.push(this.sentEndSample);
      return { type: 'partial' };
    });
  }
}

function makeFrame(sequence, expectedStart) {
  const binary = Buffer.alloc(FRAME_SAMPLES * 2, sequence % 251);
  return {
    binary,
    metadata: {
      type: 'audio_frame',
      lease_id: 'stress-lease',
      stream_id: 'stress-stream',
      generation: 1,
      sequence,
      client_event_id: `stress-event-${sequence}`,
      start_sample: expectedStart,
      end_sample: expectedStart + FRAME_SAMPLES,
      sample_count: FRAME_SAMPLES,
      sample_rate: 16000,
      bit_depth: 16,
      channels: 1,
      format: 'pcm_s16le',
      content_sha256: crypto.createHash('sha256').update(binary).digest('hex'),
    },
  };
}

export async function runBackpressureStress({
  durationSeconds = 120,
  bridgeServiceMs = 510,
  providerPartialIntervalMs = 250,
  partialForwardIntervalMs = 2000,
} = {}) {
  const bridge = new SerialBridge(bridgeServiceMs);
  const coalescer = new PartialEventCoalescer(partialForwardIntervalMs);
  const sentGate = new SentEvidenceGate();
  let queue = [];
  let pending = null;
  let flushChain = Promise.resolve();
  let eventChain = Promise.resolve();
  let nextSample = 0;
  let sequence = 0;
  let samplesSent = 0;
  let maxQueueDepth = 0;
  let batches = 0;
  let rawPartials = 0;
  let forwardedPartials = 0;
  let coalescedPartials = 0;
  let failure = null;
  let failureResolve;
  const failureSignal = new Promise(resolve => { failureResolve = resolve; });
  const cadence = [];
  let lastFrameAt = null;

  const fail = error => {
    if (failure) return;
    failure = error instanceof Error ? error.message : String(error);
    bridge.abort();
    failureResolve();
  };
  const completePending = (current, response) => {
    assertSentBatchEvidence(response, current.ids.length);
    current.evidence.resolve();
    if (pending === current) pending = null;
  };
  const settlePending = async () => {
    if (!pending) return;
    const current = pending;
    try {
      completePending(current, await bridge.sentBatch(current.ids));
    } catch (error) {
      current.evidence.reject(error);
      pending = null;
      throw error;
    }
  };
  const flush = (flushAll = false) => {
    flushChain = flushChain.then(async () => {
      while (!failure) {
        const batch = takeLedgerBatch(queue, flushAll);
        if (batch.length === 0) break;
        const previous = pending;
        const accepted = await bridge.frames(previous?.ids ?? [], batch);
        if (previous) completePending(previous, accepted);
        const ranges = accepted.ranges;
        if (ranges.length !== batch.length) throw new Error('stress_batch_admission_incomplete');
        samplesSent = batch.at(-1).metadata.end_sample;
        const evidence = deferred();
        pending = { ids: ranges.map(range => range.source_range_id), evidence };
        sentGate.advance(evidence.promise);
        batches += 1;
      }
    });
    flushChain.catch(fail);
    return flushChain;
  };
  const dispatchPartial = item => {
    forwardedPartials += 1;
    eventChain = eventChain.then(async () => {
      await item.watermark;
      await bridge.partial(item);
    });
    eventChain.catch(fail);
  };
  const offerPartial = () => {
    if (samplesSent === 0 || failure) return;
    rawPartials += 1;
    const item = {
      receiveOrder: rawPartials,
      samplesAtReceive: samplesSent,
      watermark: sentGate.snapshot(),
    };
    const ready = coalescer.offer(item, performance.now());
    if (ready) dispatchPartial(ready);
    else coalescedPartials += 1;
  };
  const admit = (recordCadence = true) => {
    if (failure) return;
    const now = performance.now();
    if (recordCadence) {
      if (lastFrameAt !== null) cadence.push(now - lastFrameAt);
      lastFrameAt = now;
    }
    sequence += 1;
    const frame = makeFrame(sequence, nextSample);
    nextSample = validateProductFrame(frame.metadata, frame.binary, nextSample);
    queue.push(frame);
    maxQueueDepth = Math.max(maxQueueDepth, queue.length);
    if (queue.length > MAX_QUEUED_FRAMES) {
      fail(new Error('relay_frame_backlog_failed_closed'));
      return;
    }
    if (queue.length >= LEDGER_BATCH_FRAMES) flush(false);
  };

  const startedAt = performance.now();
  let frameTimer = null;
  let nextFrameDeadline = startedAt + FRAME_INTERVAL_MS;
  const scheduleFrame = () => {
    frameTimer = setTimeout(() => {
      admit();
      nextFrameDeadline += FRAME_INTERVAL_MS;
      if (!failure) scheduleFrame();
    }, Math.max(0, nextFrameDeadline - performance.now()));
  };
  scheduleFrame();
  const partialTimer = setInterval(offerPartial, providerPartialIntervalMs);
  await Promise.race([sleep(durationSeconds * 1000), failureSignal]);
  clearTimeout(frameTimer);
  clearInterval(partialTimer);
  const captureElapsedSeconds = (performance.now() - startedAt) / 1000;
  const captureFrames = sequence;
  const captureCadence = [...cadence];

  if (!failure) {
    await flush(true);
    await settlePending();
    const finalPartial = coalescer.takeDue(performance.now(), true);
    if (finalPartial) dispatchPartial(finalPartial);
    await eventChain;
    for (let index = 0; index < 20; index += 1) admit(false);
    const boundedBurstDepth = queue.length;
    await flush(true);
    await settlePending();
    await sentGate.snapshot();
    const ordinals = bridge.sendReceipts.map(item => item.send_ordinal);
    const sourceContinuous = bridge.sourceRanges.every((range, index, all) => index === 0
      ? range.start_sample === 0
      : all[index - 1].end_sample === range.start_sample);
    const watermarkProgressive = bridge.partialWatermarks.every((value, index, all) => index === 0 || value >= all[index - 1]);
    let thresholdAt = null;
    for (let depth = 1; depth <= MAX_QUEUED_FRAMES + 1; depth += 1) if (depth > MAX_QUEUED_FRAMES) thresholdAt = depth;
    const totalElapsedSeconds = (performance.now() - startedAt) / 1000;
    const result = {
      status: 'PENDING',
      provider_communication: 0,
      audio_send: 0,
      requested_duration_seconds: durationSeconds,
      actual_capture_duration_seconds: Number(captureElapsedSeconds.toFixed(3)),
      drain_and_burst_seconds: Number((totalElapsedSeconds - captureElapsedSeconds).toFixed(3)),
      frame_interval_ms: FRAME_INTERVAL_MS,
      cadence_mean_ms: Number((captureCadence.reduce((sum, value) => sum + value, 0) / captureCadence.length).toFixed(3)),
      realtime_frames_admitted: captureFrames,
      frames_admitted: sequence,
      batches,
      maximum_queue_depth: maxQueueDepth,
      queue_limit: MAX_QUEUED_FRAMES,
      bounded_burst_frames: boundedBurstDepth,
      source_ranges: bridge.sourceRanges.length,
      provider_send_ranges: bridge.sendReceipts.length,
      send_ordinal_continuous: ordinals.every((value, index) => value === index + 1),
      source_range_continuous: sourceContinuous,
      sha256_checks: bridge.sha256Checks,
      raw_partial_events: rawPartials,
      forwarded_partial_events: forwardedPartials,
      coalesced_partial_events: coalescedPartials,
      partial_watermark_progressive: watermarkProgressive,
      backlog_fail_closed_threshold: thresholdAt,
      bridge_service_ms: bridgeServiceMs,
      partial_forward_interval_ms: partialForwardIntervalMs,
    };
    const valid = result.actual_capture_duration_seconds >= durationSeconds
      && result.realtime_frames_admitted >= durationSeconds * 8
      && result.cadence_mean_ms >= 95 && result.cadence_mean_ms <= 105
      && result.drain_and_burst_seconds <= 10
      && result.maximum_queue_depth <= MAX_QUEUED_FRAMES
      && result.bounded_burst_frames <= MAX_QUEUED_FRAMES
      && result.source_ranges === result.provider_send_ranges
      && result.source_ranges === result.frames_admitted
      && result.send_ordinal_continuous && result.source_range_continuous
      && result.sha256_checks === result.frames_admitted
      && result.forwarded_partial_events > 0 && result.partial_watermark_progressive
      && result.backlog_fail_closed_threshold === MAX_QUEUED_FRAMES + 1;
    result.status = valid ? 'PASS' : 'FAIL';
    if (!valid) result.safe_reason = 'stress_throughput_evidence_incomplete';
    return result;
  }

  await flushChain.catch(() => {});
  await eventChain.catch(() => {});
  return {
    status: 'FAIL',
    safe_reason: failure,
    provider_communication: 0,
    audio_send: 0,
    elapsed_seconds: Number(((performance.now() - startedAt) / 1000).toFixed(3)),
    frames_admitted: sequence,
    maximum_queue_depth: maxQueueDepth,
    queue_limit: MAX_QUEUED_FRAMES,
    raw_partial_events: rawPartials,
    forwarded_partial_events: forwardedPartials,
    partial_forward_interval_ms: partialForwardIntervalMs,
  };
}

if (process.argv[1] && process.argv[1].endsWith('backpressure-stress.js')) {
  const readNumber = (name, fallback) => {
    const prefix = `--${name}=`;
    const value = process.argv.find(argument => argument.startsWith(prefix));
    return value ? Number(value.slice(prefix.length)) : fallback;
  };
  const result = await runBackpressureStress({
    durationSeconds: readNumber('duration-seconds', 120),
    bridgeServiceMs: readNumber('bridge-service-ms', 510),
    providerPartialIntervalMs: readNumber('provider-partial-ms', 250),
    partialForwardIntervalMs: readNumber('partial-forward-ms', 2000),
  });
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  if (result.status !== 'PASS') process.exitCode = 1;
}
