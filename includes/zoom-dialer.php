<?php
/**
 * Floating Zoom Phone Smart Embed dialer (ZOOM_SMART_EMBED). Any [data-zoom-call="+1..."] button opens it and
 * dials through Zoom's iframe (postMessage zp-make-call). The iframe loads on the first call; after that it is
 * reloaded on every page in this tab so the user stays signed in and the next call is instant.
 * Audio runs through the Zoom desktop app; see .env.example for the Zoom-side setup.
 */
$zoomEmbedUrl = 'https://applications.zoom.us/integration/phone/embeddablephone/home';
?>
<div x-data="zoomDialer(<?= e(json_encode($zoomEmbedUrl)) ?>)" @zoom-call.window="call($event.detail)" x-cloak
     class="fixed bottom-4 right-4 z-50 flex flex-col items-end gap-2">
    <div x-show="open" x-transition.opacity class="w-[360px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-slate-900/10">
        <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-emerald-700"><?= icon('phone', 'h-3.5 w-3.5') ?></span>
            <div class="min-w-0 flex-1">
                <div class="text-xs font-semibold text-slate-800">Zoom Phone</div>
                <div class="truncate text-[11px] text-slate-500" x-text="label ? `${label} · ${number}` : (number || 'Sign in, then click a call button')"></div>
            </div>
            <button type="button" x-show="number" class="btn-ghost btn-sm !px-2 text-xs" @click="dial(number)" title="Send the number to the dialer again (e.g. after signing in)">Dial again</button>
            <button type="button" class="btn-icon !p-1" @click="open = false" aria-label="Minimise"><?= icon('chevron-down', 'h-4 w-4') ?></button>
        </div>
        <template x-if="src">
            <iframe x-ref="frame" id="zoom-embeddable-phone-iframe" :src="src" @load="onLoad()"
                    allow="clipboard-read; clipboard-write https://applications.zoom.us"
                    class="block h-[600px] max-h-[calc(100vh-7rem)] w-full border-0" title="Zoom Phone"></iframe>
        </template>
    </div>
    <button type="button" x-show="src && !open" @click="open = true"
            class="flex items-center gap-2 rounded-full bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white shadow-lg hover:bg-emerald-700">
        <?= icon('phone', 'h-4 w-4') ?> <span x-text="inCall ? 'On a call' : 'Zoom Phone'"></span>
    </button>
</div>
<script>
    function zoomDialer(embedUrl) {
        const ORIGIN = 'https://applications.zoom.us';
        const KEY = 'zoomDialerLoaded';
        return {
            open: false, src: '', loaded: false, pending: null, number: '', label: '', inCall: false,
            init() {
                try { if (sessionStorage.getItem(KEY)) this.src = embedUrl; } catch (e) {}
                document.addEventListener('click', (ev) => {
                    const el = ev.target.closest('[data-zoom-call]');
                    if (!el) return;
                    ev.preventDefault();
                    this.call({ number: el.dataset.zoomCall, label: el.dataset.zoomCallLabel || '' });
                });
                window.addEventListener('message', (ev) => {
                    if (ev.origin !== ORIGIN || !ev.data || typeof ev.data.type !== 'string') return;
                    if (ev.data.type === 'zp-call-connected-event') { this.inCall = true; }
                    if (ev.data.type === 'zp-call-ended-event') {
                        this.inCall = false;
                        const result = ev.data.data?.result;
                        window.toast(result && result !== 'ended' ? `Call ${result}` : 'Call ended', 'info');
                    }
                });
            },
            onLoad() {
                this.loaded = true;
                try { sessionStorage.setItem(KEY, '1'); } catch (e) {}
                // Zoom's app needs a moment after the iframe's load event before it listens for messages
                if (this.pending) setTimeout(() => this.dial(this.pending), 1500);
            },
            call({ number, label }) {
                if (!number) return;
                this.number = number;
                this.label = label;
                this.open = true;
                if (this.loaded) {
                    this.dial(number);
                } else {
                    this.pending = number;
                    this.src = embedUrl;
                }
            },
            dial(number) {
                this.pending = null;
                const frame = document.getElementById('zoom-embeddable-phone-iframe');
                if (!frame || !this.loaded) { this.pending = number; return; }
                frame.contentWindow.postMessage({ type: 'zp-make-call', data: { number, autoDial: true } }, ORIGIN);
            },
        };
    }
</script>
