import { Controller } from '@hotwired/stimulus';

// Calls a batch endpoint in a loop until it answers `done`, and shows its progress. Used by the analysis
// (step 1) and the import (step 5). Leaving the page stops the loop: the import then counts as paused.
export default class extends Controller {
    static targets = ['bar', 'lines', 'valid', 'invalid', 'ref', 'created', 'updated', 'errors', 'error'];

    static values = { url: String, token: String, doneUrl: String };

    connect() {
        this.running = true;
        this.failures = 0;
        this.next();
    }

    disconnect() {
        this.running = false;
    }

    stop() {
        this.running = false;
    }

    async next() {
        if (!this.running) {
            return;
        }

        const body = new URLSearchParams({ _token: this.tokenValue });

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const state = await response.json();
            this.failures = 0;
            this.render(state);

            if (state.done) {
                this.running = false;
                window.location.assign(this.doneUrlValue);

                return;
            }
        } catch (error) {
            this.failures += 1;

            if (this.failures >= 3) {
                this.running = false;
                this.showError(error);

                return;
            }
        }

        window.setTimeout(() => this.next(), 100);
    }

    render(state) {
        if (this.hasBarTarget) {
            const percent = Math.max(0, Math.min(100, state.percent ?? 0));
            this.barTarget.style.width = `${percent}%`;
            this.barTarget.textContent = `${percent} %`;
        }

        const fields = { lines: 'lines', valid: 'valid', invalid: 'invalid', ref: 'current_ref', created: 'created', updated: 'updated', errors: 'errors' };

        Object.entries(fields).forEach(([target, key]) => {
            const property = `${target}Target`;
            const has = `has${target.charAt(0).toUpperCase()}${target.slice(1)}Target`;

            if (this[has] && state[key] !== undefined && state[key] !== null) {
                this[property].textContent = state[key];
            }
        });
    }

    showError(error) {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.textContent = `${error.message}. Reload the page to resume.`;
        this.errorTarget.classList.remove('d-none');
    }
}
