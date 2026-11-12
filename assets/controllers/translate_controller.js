import { Controller } from '@hotwired/stimulus';

/*
 * The translate page (templates/translate/index.html.twig): translates as you type, a short pause after the last key,
 * the way LibreTranslate's page does. Talks to TranslateController's LibreTranslate-shaped JSON.
 */
export default class extends Controller {
    static targets = ['engine', 'source', 'target', 'swap', 'input', 'output', 'status', 'error', 'count', 'copy'];
    static values = { url: String, engines: Object, names: Object };

    connect() {
        this.timer = null;
        this.request = 0;
        this.fillLanguages();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    get engine() {
        return this.enginesValue[this.engineTarget.value];
    }

    engineChanged() {
        this.fillLanguages();
        this.translateSoon();
    }

    /* Each engine has its own languages: rebuild both lists, keeping the current choice where the engine has it. */
    fillLanguages() {
        const engine = this.engine;
        const fill = (select, codes, wanted, auto) => {
            select.replaceChildren();
            if (auto) {
                select.add(new Option('Detect language', 'auto'));
            }
            for (const code of codes) {
                select.add(new Option(this.namesValue[code] || code, code));
            }
            select.value = [...select.options].some((o) => o.value === wanted) ? wanted : select.options[0].value;
        };
        fill(this.sourceTarget, engine.sources, this.sourceTarget.value || 'auto', engine.auto);
        fill(this.targetTarget, engine.targets, this.targetTarget.value || 'en', false);
        // Swapping needs the target to be readable and the source writable by this engine.
        this.swapTarget.disabled = engine.targets.length < 2;
    }

    swap() {
        const [from, to] = [this.sourceTarget.value, this.targetTarget.value];
        if (from === 'auto' || ![...this.targetTarget.options].some((o) => o.value === from)) {
            return;
        }
        this.sourceTarget.value = to;
        this.targetTarget.value = from;
        if (this.outputTarget.textContent) {
            this.inputTarget.value = this.outputTarget.textContent;
        }
        this.translateSoon();
    }

    clear() {
        this.inputTarget.value = '';
        this.inputTarget.focus();
        this.translateSoon();
    }

    async copy() {
        await navigator.clipboard.writeText(this.outputTarget.textContent);
        this.copyTarget.textContent = 'Copied';
        setTimeout(() => (this.copyTarget.textContent = 'Copy'), 1200);
    }

    translateSoon() {
        this.countTarget.textContent = `${this.inputTarget.value.length} / ${this.inputTarget.maxLength}`;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.translate(), 350);
    }

    async translate() {
        const q = this.inputTarget.value;
        const mine = ++this.request;
        this.errorTarget.textContent = '';
        if (q.trim() === '') {
            this.outputTarget.textContent = '';
            this.statusTarget.textContent = '';
            return;
        }
        this.statusTarget.textContent = 'Translating…';
        let data;
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ q, source: this.sourceTarget.value, target: this.targetTarget.value, engine: this.engineTarget.value }),
            });
            data = await response.json();
        } catch (e) {
            data = { error: 'The server could not be reached' };
        }
        if (mine !== this.request) {
            return; // a newer keystroke has already asked again
        }
        if (data.error) {
            this.errorTarget.textContent = data.error;
            this.statusTarget.textContent = '';
            return;
        }
        this.outputTarget.textContent = data.translatedText;
        this.outputTarget.lang = this.targetTarget.value;
        const tokens = data.meta && data.meta.tokens ? `, ${data.meta.tokens} tokens` : '';
        this.statusTarget.textContent = `${data.engine}: ${data.ms} ms${tokens}`;
    }
}
