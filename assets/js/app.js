/* Mail CRM — shared front-end helpers (vanilla JS + Alpine stores). */
(function () {
    'use strict';

    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content || '';

    /** fetch() wrapper: JSON in/out, CSRF header, consistent errors. */
    window.api = async function (url, options = {}) {
        const opts = { method: 'GET', headers: {}, ...options };
        opts.headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': meta('csrf-token'),
            ...opts.headers,
        };
        if (opts.body && !(opts.body instanceof FormData) && typeof opts.body !== 'string') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        const res = await fetch(url, opts);
        let data = null;
        try { data = await res.json(); } catch (e) { /* non-JSON */ }
        if (!res.ok || (data && data.ok === false)) {
            throw new Error((data && data.error) || `Request failed (${res.status})`);
        }
        return data;
    };

    window.appUrl = (path) => meta('app-url') + '/' + path.replace(/^\//, '');

    window.toast = (message, type = 'success') => {
        if (window.Alpine) Alpine.store('toasts').push(message, type);
    };

    /** Copy text to the clipboard; falls back to execCommand where the Clipboard API is unavailable (plain http). */
    window.copyText = async (text) => {
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            const ta = Object.assign(document.createElement('textarea'), { value: text });
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            ta.remove();
            if (!ok) throw e;
        }
    };

    /** Any element with data-copy="text" copies that text when clicked. */
    document.addEventListener('click', async (ev) => {
        const el = ev.target.closest('[data-copy]');
        if (!el) return;
        ev.preventDefault();
        try {
            await window.copyText(el.dataset.copy);
            window.toast(`Copied ${el.dataset.copy}`);
        } catch (e) {
            window.toast('Could not copy to the clipboard', 'error');
        }
    });

    window.escapeHtml =(s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    document.addEventListener('alpine:init', () => {
        Alpine.store('toasts', {
            items: [],
            push(message, type = 'success') {
                const id = Date.now() + Math.random();
                this.items.push({ id, message, type });
                setTimeout(() => this.remove(id), type === 'error' ? 8000 : 4500);
            },
            remove(id) { this.items = this.items.filter((t) => t.id !== id); },
        });

        Alpine.store('search', {
            isOpen: false,
            open() { this.isOpen = true; },
            close() { this.isOpen = false; },
            toggle() { this.isOpen = !this.isOpen; },
        });

        Alpine.store('confirm', {
            isOpen: false, title: '', message: '', button: 'Confirm', danger: true, _resolve: null,
            ask({ title = 'Are you sure?', message = '', button = 'Confirm', danger = true } = {}) {
                Object.assign(this, { title, message, button, danger, isOpen: true });
                return new Promise((resolve) => { this._resolve = resolve; });
            },
            accept() { this.isOpen = false; this._resolve && this._resolve(true); },
            cancel() { this.isOpen = false; this._resolve && this._resolve(false); },
        });

        (window.__flashes || []).forEach((f) => Alpine.store('toasts').push(f.message, f.type));
    });

    /** Global search palette. */
    window.globalSearch = () => ({
        q: '', results: [], loading: false, active: 0,
        async search() {
            if (this.q.trim().length < 2) { this.results = []; return; }
            this.loading = true;
            try {
                const data = await api(appUrl('api/search.php') + '?q=' + encodeURIComponent(this.q.trim()));
                this.results = data.results || [];
                this.active = 0;
            } catch (e) { this.results = []; }
            this.loading = false;
        },
        grouped() {
            const labels = { customer: 'Customers', company: 'Companies', campaign: 'Campaigns', email: 'Emails' };
            const groups = {};
            this.results.forEach((r) => { (groups[r.type] ||= { type: r.type, label: labels[r.type] || r.type, items: [] }).items.push(r); });
            return Object.values(groups);
        },
        move(d) { if (this.results.length) this.active = (this.active + d + this.results.length) % this.results.length; },
        go() { const r = this.results[this.active]; if (r) location.href = r.url; },
    });

    /** Forms/buttons with data-confirm="message" ask before submitting. */
    document.addEventListener('submit', async (e) => {
        const form = e.target;
        const msg = form.dataset.confirm;
        if (!msg || form.dataset.confirmed === '1') return;
        e.preventDefault();
        const ok = await Alpine.store('confirm').ask({
            title: form.dataset.confirmTitle || 'Are you sure?',
            message: msg,
            button: form.dataset.confirmButton || 'Confirm',
            danger: form.dataset.confirmDanger !== '0',
        });
        if (ok) {
            form.dataset.confirmed = '1';
            const submitter = e.submitter;
            if (submitter && submitter.name) {
                const h = document.createElement('input');
                h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
                form.appendChild(h);
            }
            form.submit();
        }
    }, true);

    /** Bulk selection for tables: <table x-data="bulkSelect(total)"> */
    window.bulkSelect = (totalMatching = 0) => ({
        selected: [],
        allMatching: false,
        totalMatching,
        pageIds() { return [...this.$root.querySelectorAll('input[data-row-id]')].map((el) => el.value); },
        get pageAllSelected() { const ids = this.pageIds(); return ids.length > 0 && ids.every((id) => this.selected.includes(id)); },
        togglePage(e) { this.selected = e.target.checked ? this.pageIds() : []; this.allMatching = false; },
        get count() { return this.allMatching ? this.totalMatching : this.selected.length; },
        clear() { this.selected = []; this.allMatching = false; },
    });

    /** Replace {{var}} / {{var|fallback}} using a vars object, mirroring PHP render_vars(). */
    window.renderVars = (text, vars, html = false) => String(text || '').replace(/\{\{\s*([a-zA-Z0-9_.-]+)\s*(?:\|\s*([^}]*?))?\s*\}\}/g, (m, key, fb) => {
        let v = (vars && vars[key] != null) ? String(vars[key]) : '';
        if (v === '' && fb !== undefined) v = fb;
        return html ? escapeHtml(v) : v;
    });
})();
