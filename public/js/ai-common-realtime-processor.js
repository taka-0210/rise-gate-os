class CompanyOsRealtimeProcessor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.active = false;
        this.phase = 0;
        this.cursor = 0;
        this.frame = new Int16Array(1600);
        this.frameOffset = 0;
        this.ratio = 16000 / sampleRate;
        this.port.onmessage = event => {
            if (event.data?.type === 'active') {
                this.active = Boolean(event.data.value);
                if (this.active) {
                    this.phase = 0;
                    this.cursor = 0;
                    this.frameOffset = 0;
                }
            }
        };
    }

    process(inputs, outputs) {
        const input = inputs[0]?.[0];
        const output = outputs[0]?.[0];
        if (output) output.fill(0);
        if (!this.active || !input) return true;
        for (let index = 0; index < input.length; index += 1) {
            this.phase += this.ratio;
            if (this.phase < 1) continue;
            this.phase -= 1;
            const sample = Math.max(-1, Math.min(1, input[index]));
            this.frame[this.frameOffset++] = sample < 0 ? sample * 0x8000 : sample * 0x7fff;
            if (this.frameOffset === this.frame.length) {
                const pcm = this.frame.buffer;
                const startSample = this.cursor;
                this.cursor += this.frame.length;
                this.port.postMessage({type: 'frame', pcm, startSample, endSample: this.cursor}, [pcm]);
                this.frame = new Int16Array(1600);
                this.frameOffset = 0;
            }
        }
        return true;
    }
}

registerProcessor('company-os-realtime-processor', CompanyOsRealtimeProcessor);