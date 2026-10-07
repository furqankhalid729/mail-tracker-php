            </div>
        </main>
    </div>
</div>

<!-- Global search (Ctrl+K) -->
<div x-data x-cloak x-show="$store.search.isOpen" @keydown.escape.window="$store.search.close()">
    <div class="modal-backdrop" @click="$store.search.close()"></div>
    <div class="modal-panel !max-w-xl overflow-hidden" x-data="globalSearch()" x-init="$watch('$store.search.isOpen', v => v && $nextTick(() => $refs.q.focus()))">
        <div class="flex items-center gap-2 border-b border-slate-200 px-4">
            <?= icon('search', 'h-5 w-5 text-slate-400') ?>
            <input x-ref="q" x-model="q" @input.debounce.250ms="search()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go()"
                   type="text" placeholder="Search customers, companies, campaigns, subjects…" class="w-full border-0 py-3.5 text-sm focus:ring-0">
            <kbd class="rounded border border-slate-200 px-1.5 text-[11px] text-slate-400">Esc</kbd>
        </div>
        <div class="max-h-[60vh] overflow-y-auto p-2">
            <template x-if="loading"><div class="px-3 py-6 text-center text-sm text-slate-400">Searching…</div></template>
            <template x-if="!loading && q.length >= 2 && results.length === 0"><div class="px-3 py-6 text-center text-sm text-slate-400">No results.</div></template>
            <template x-if="q.length < 2"><div class="px-3 py-6 text-center text-sm text-slate-400">Type at least 2 characters.</div></template>
            <template x-for="(group, gi) in grouped()" :key="group.type">
                <div class="mb-1">
                    <div class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400" x-text="group.label"></div>
                    <template x-for="item in group.items" :key="item.type + item.id">
                        <a :href="item.url" @mouseenter="active = results.indexOf(item)" :class="results.indexOf(item) === active ? 'bg-indigo-50 text-indigo-900' : 'text-slate-700'" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium" x-text="item.title"></span>
                                <span class="block truncate text-xs text-slate-500" x-text="item.subtitle"></span>
                            </span>
                        </a>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>

<!-- Confirm dialog (replaces browser confirm()) -->
<div x-data x-cloak x-show="$store.confirm.isOpen" @keydown.escape.window="$store.confirm.cancel()">
    <div class="modal-backdrop" @click="$store.confirm.cancel()"></div>
    <div class="modal-panel !max-w-md p-6">
        <h3 class="text-base font-semibold" x-text="$store.confirm.title"></h3>
        <p class="mt-2 text-sm text-slate-600" x-text="$store.confirm.message"></p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="$store.confirm.cancel()">Cancel</button>
            <button type="button" :class="$store.confirm.danger ? 'btn-danger' : 'btn-primary'" @click="$store.confirm.accept()" x-text="$store.confirm.button"></button>
        </div>
    </div>
</div>

<!-- Toasts -->
<div x-data class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-full max-w-sm flex-col gap-2">
    <template x-for="t in $store.toasts.items" :key="t.id">
        <div x-transition.opacity class="pointer-events-auto flex items-start gap-3 rounded-lg bg-white p-3.5 shadow-lg ring-1 ring-slate-900/10">
            <span class="mt-0.5 h-2 w-2 shrink-0 rounded-full" :class="{ 'bg-emerald-500': t.type === 'success', 'bg-red-500': t.type === 'error', 'bg-amber-500': t.type === 'warning', 'bg-indigo-500': t.type === 'info' }"></span>
            <p class="flex-1 text-sm text-slate-700" x-text="t.message"></p>
            <button type="button" class="text-slate-400 hover:text-slate-600" @click="$store.toasts.remove(t.id)">&times;</button>
        </div>
    </template>
</div>
<script>
    window.__flashes = <?= json_encode(get_flashes(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?= $extra_scripts ?? '' ?>
</body>
</html>
<?php clear_old_input(); ?>
