import { Controller } from '@hotwired/stimulus';
import { SuggestInput, remoteSource } from '@pumukit/new-admin/suggest';

const FRAME = 0.04;
const TICK_STEPS = [1, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600];

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function round(value) {
    return Math.round(value * 1000) / 1000;
}

function formatTime(seconds) {
    const milliseconds = Math.round(Math.max(0, seconds) * 1000);
    const hours = Math.floor(milliseconds / 3600000);
    const minutes = Math.floor((milliseconds % 3600000) / 60000);
    const secs = Math.floor((milliseconds % 60000) / 1000);
    const pad = (value, length = 2) => String(value).padStart(length, '0');
    const clock = `${pad(minutes)}:${pad(secs)}.${pad(milliseconds % 1000, 3)}`;

    return hours > 0 ? `${hours}:${clock}` : clock;
}

function parseTime(text) {
    const value = String(text).trim().replace(',', '.');
    if (!/^\d+(:\d{1,2}){0,2}(\.\d+)?$/.test(value)) {
        return null;
    }

    return value.split(':').reduce((total, part) => total * 60 + parseFloat(part), 0);
}

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'video', 'playIcon', 'currentTime', 'totalTime', 'timeline', 'ruler', 'outsideStart', 'outsideEnd', 'startHandle', 'endHandle', 'playhead',
        'start', 'end', 'startText', 'endText', 'length', 'picture', 'picturePreview', 'pictureRemove', 'canvas',
        'newPerson', 'existingPerson', 'createPerson', 'personSearch', 'person', 'output', 'invalid', 'submitButton',
    ];

    static values = { duration: Number, messages: Object };

    connect() {
        this.duration = this.durationValue > 0 ? this.durationValue : 0;
        this.range = { start: 0, end: this.duration };
        this.previewEnd = null;
        if (this.hasPersonSearchTarget) {
            this.suggest = new SuggestInput(this.personSearchTarget, {
                source: remoteSource(this.personSearchTarget.dataset.autocompleteUrl, (item) => ({ id: item.id, text: item.label, description: String(item.desc ?? '').trim(), value: item.value })),
                minLength: 2,
                select: (item) => {
                    this.personTarget.value = item.id;
                },
            });
        }
        this.renderRuler();
        this.render();
    }

    disconnect() {
        this.suggest?.destroy();
        this.suggest = null;
        window.cancelAnimationFrame(this.frame);
        this.videoTarget.pause();
        this.revokePicture();
    }

    loaded() {
        const duration = this.videoTarget.duration;
        if (!Number.isFinite(duration) || duration <= 0) {
            return;
        }
        const wholeVideo = this.range.end >= this.duration;
        this.duration = duration;
        this.range.end = wholeVideo ? duration : clamp(this.range.end, 0, duration);
        this.range.start = clamp(this.range.start, 0, this.range.end);
        this.renderRuler();
        this.render();
    }

    get currentTime() {
        return this.videoTarget.currentTime;
    }

    seek(time) {
        this.videoTarget.currentTime = clamp(time, 0, this.duration);
        this.updateClock();
    }

    togglePlay() {
        if (this.videoTarget.paused) {
            this.previewEnd = null;
            this.videoTarget.play();
        } else {
            this.videoTarget.pause();
        }
    }

    preview() {
        this.seek(this.range.start);
        this.previewEnd = this.range.end;
        this.videoTarget.play();
    }

    back() {
        this.seek(this.currentTime - 1);
    }

    forward() {
        this.seek(this.currentTime + 1);
    }

    previousFrame() {
        this.videoTarget.pause();
        this.seek(this.currentTime - FRAME);
    }

    nextFrame() {
        this.videoTarget.pause();
        this.seek(this.currentTime + FRAME);
    }

    played() {
        this.playIconTarget.classList.replace('fa-play', 'fa-pause');
        const tick = () => {
            if (null !== this.previewEnd && this.currentTime >= this.previewEnd) {
                this.videoTarget.pause();
                this.seek(this.previewEnd);
                this.previewEnd = null;

                return;
            }
            this.updateClock();
            this.frame = window.requestAnimationFrame(tick);
        };
        tick();
    }

    paused() {
        this.playIconTarget.classList.replace('fa-pause', 'fa-play');
        window.cancelAnimationFrame(this.frame);
        this.updateClock();
    }

    timeUpdate() {
        this.updateClock();
    }

    shortcut(event) {
        if ('Enter' === event.key && 'INPUT' === event.target.tagName) {
            event.preventDefault();
            if (event.target === this.startTextTarget || event.target === this.endTextTarget) {
                this.editRange();
            }

            return;
        }
        if (event.target.closest('input, select, textarea, button')) {
            return;
        }
        if (' ' === event.key) {
            event.preventDefault();
            this.togglePlay();
        } else if ('ArrowLeft' === event.key || 'ArrowRight' === event.key) {
            event.preventDefault();
            const step = event.shiftKey ? 5 : 1;
            this.seek(this.currentTime + ('ArrowLeft' === event.key ? -step : step));
        } else if ('i' === event.key.toLowerCase()) {
            this.startHere();
        } else if ('o' === event.key.toLowerCase()) {
            this.endHere();
        }
    }

    pointerDown(event) {
        if (!this.duration || 0 !== event.button) {
            return;
        }
        const handle = event.target.closest('[data-drag]');
        const kind = handle ? handle.dataset.drag : 'playhead';
        this.timelineTarget.setPointerCapture(event.pointerId);
        const move = (moveEvent) => this.drag(kind, this.timeAt(moveEvent.clientX));
        const up = () => {
            this.timelineTarget.removeEventListener('pointermove', move);
            this.timelineTarget.removeEventListener('pointerup', up);
            this.timelineTarget.removeEventListener('pointercancel', up);
        };
        this.timelineTarget.addEventListener('pointermove', move);
        this.timelineTarget.addEventListener('pointerup', up);
        this.timelineTarget.addEventListener('pointercancel', up);
        event.preventDefault();
        this.drag(kind, this.timeAt(event.clientX));
    }

    drag(kind, time) {
        if ('start' === kind) {
            this.range.start = Math.min(round(time), round(this.range.end - FRAME));
        } else if ('end' === kind) {
            this.range.end = Math.max(round(time), round(this.range.start + FRAME));
        }
        this.seek(time);
        if ('playhead' !== kind) {
            this.render();
        }
    }

    timeAt(clientX) {
        const rect = this.timelineTarget.getBoundingClientRect();

        return clamp((clientX - rect.left) / rect.width, 0, 1) * this.duration;
    }

    startHere() {
        if (this.currentTime < this.range.end) {
            this.range.start = round(this.currentTime);
            this.render();
        }
    }

    endHere() {
        if (this.currentTime > this.range.start) {
            this.range.end = round(this.currentTime);
            this.render();
        }
    }

    editRange() {
        const start = parseTime(this.startTextTarget.value);
        const end = parseTime(this.endTextTarget.value);
        const valid = null !== start && null !== end && clamp(end, 0, this.duration) > clamp(start, 0, this.duration);
        this.startTextTarget.classList.toggle('is-invalid', !valid);
        this.endTextTarget.classList.toggle('is-invalid', !valid);
        if (valid) {
            this.range = { start: round(clamp(start, 0, this.duration)), end: round(clamp(end, 0, this.duration)) };
            this.seek(this.range.start);
            this.render();
        }
    }

    capture() {
        const { videoWidth: width, videoHeight: height } = this.videoTarget;
        try {
            this.canvasTarget.width = width;
            this.canvasTarget.height = height;
            this.canvasTarget.getContext('2d').drawImage(this.videoTarget, 0, 0, width, height);
            this.canvasTarget.toBlob((blob) => {
                if (!blob) {
                    window.no_success?.(this.messagesValue.capture);

                    return;
                }
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], `frame-${Math.round(this.currentTime * 1000)}.jpg`, { type: 'image/jpeg' }));
                this.pictureTarget.files = transfer.files;
                this.pictureChanged();
            }, 'image/jpeg', 0.9);
        } catch {
            window.no_success?.(this.messagesValue.capture);
        }
    }

    pictureChanged() {
        this.revokePicture();
        const file = this.pictureTarget.files[0];
        if (file) {
            this.pictureUrl = URL.createObjectURL(file);
            this.picturePreviewTarget.src = this.pictureUrl;
        } else {
            this.picturePreviewTarget.removeAttribute('src');
        }
        this.picturePreviewTarget.classList.toggle('d-none', !file);
        this.pictureRemoveTarget.classList.toggle('d-none', !file);
    }

    removePicture() {
        this.pictureTarget.value = '';
        this.pictureChanged();
    }

    revokePicture() {
        if (this.pictureUrl) {
            URL.revokeObjectURL(this.pictureUrl);
            this.pictureUrl = null;
        }
    }

    togglePerson() {
        const creating = this.newPersonTarget.checked;
        this.existingPersonTarget.classList.toggle('d-none', creating);
        this.createPersonTarget.classList.toggle('d-none', !creating);
        if (creating) {
            this.clearPerson();
        }
    }

    clearPerson() {
        this.personTarget.value = '';
    }

    submit(event) {
        if (this.submitting || null !== this.error()) {
            event.preventDefault();
            this.render();

            return;
        }
        this.submitting = true;
        this.submitButtonTargets.forEach((button) => {
            button.disabled = true;
        });
    }

    async cutAndContinue() {
        if (this.submitting || null !== this.error()) {
            this.render();

            return;
        }
        this.submitting = true;
        this.submitButtonTargets.forEach((button) => {
            button.disabled = true;
        });
        try {
            const response = await fetch(this.element.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: new FormData(this.element),
            });
            if (!response.ok) {
                throw new Error(response.statusText);
            }
            window.success?.(this.messagesValue.done);
            this.removePicture();
        } catch {
            window.no_success?.(this.messagesValue.error);
        } finally {
            this.submitting = false;
            this.render();
        }
    }

    error() {
        if (this.range.end <= this.range.start) {
            return 'range';
        }
        if (!this.outputTargets.some((output) => output.checked)) {
            return 'outputs';
        }

        return null;
    }

    percent(time) {
        return this.duration > 0 ? `${(clamp(time, 0, this.duration) / this.duration) * 100}%` : '0%';
    }

    updateClock() {
        this.currentTimeTarget.textContent = formatTime(this.currentTime);
        this.playheadTarget.style.left = this.percent(this.currentTime);
    }

    render() {
        const { start, end } = this.range;
        this.totalTimeTarget.textContent = formatTime(this.duration);
        this.lengthTarget.textContent = formatTime(Math.max(0, end - start));
        this.outsideStartTarget.style.width = this.percent(start);
        this.outsideEndTarget.style.left = this.percent(end);
        this.outsideEndTarget.style.right = '0';
        this.startHandleTarget.style.left = this.percent(start);
        this.endHandleTarget.style.left = this.percent(end);
        this.startTarget.value = String(round(start));
        this.endTarget.value = String(round(end));
        for (const [input, time] of [[this.startTextTarget, start], [this.endTextTarget, end]]) {
            if (document.activeElement !== input) {
                input.value = formatTime(time);
                input.classList.remove('is-invalid');
            }
        }
        this.updateClock();

        const error = this.error();
        this.invalidTarget.classList.toggle('d-none', null === error);
        this.invalidTarget.lastElementChild.textContent = error ? this.messagesValue[error] : '';
        this.submitButtonTargets.forEach((button) => {
            button.disabled = this.submitting || null !== error;
        });
    }

    renderRuler() {
        if (!this.duration) {
            this.rulerTarget.replaceChildren();

            return;
        }
        const step = TICK_STEPS.find((candidate) => this.duration / candidate <= 12) ?? TICK_STEPS[TICK_STEPS.length - 1];
        const ticks = [];
        for (let time = 0; time < this.duration - step / 2; time += step) {
            const tick = document.createElement('span');
            tick.className = 'hve-tick';
            tick.style.left = this.percent(time);
            tick.textContent = formatTime(time).replace(/\.000$/, '');
            ticks.push(tick);
        }
        this.rulerTarget.replaceChildren(...ticks);
    }
}
