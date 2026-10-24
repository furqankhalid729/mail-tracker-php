<?php
/** AI campaign audience: add by tags/filters, pick individuals, remove contacts that have not been emailed yet. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
$ws = ws_id();
$campaign = find_or_404('ai_campaigns', input_int('id'));
$cid = (int) $campaign['id'];

if (is_post()) {
    verify_csrf();
    if ($campaign['status'] === 'archived') {
        flash('error', 'Archived AI campaigns cannot be changed.');
        redirect(url('ai-campaigns/view.php', ['id' => $cid]));
    }
    $action = (string) input('action');
    if ($action === 'add_filter') {
        $filters = customer_filters_from_request();
        unset($filters['campaign_id'], $filters['not_in_campaign']);
        if (input('exclude_suppressed') === '1') {
            $filters['status'] = 'active';
        }
        $hasRealFilter = (bool) array_diff_key($filters, ['status' => 1, 'tag_mode' => 1]);
        if (!$hasRealFilter && input('confirm_all') !== '1') {
            flash('error', 'Choose at least one tag or filter (or tick “all customers”).');
            redirect(url('ai-campaigns/contacts.php', ['id' => $cid]));
        }
        set_time_limit(120);
        $added = ai_add_contacts_by_filter($ws, $cid, $filters);
        flash('success', number_format($added) . ' contacts added' . ($added ? '' : ' (all matching customers were already in this AI campaign)') . '.'
            . ($campaign['status'] === 'active' && $added ? ' Cron will research and write to them shortly.' : ''));
        redirect(url('ai-campaigns/view.php', ['id' => $cid]));
    }
    if ($action === 'remove') {
        $ids = input_ids('contact_ids');
        $removed = 0;
        if ($ids) {
            // Contacts whose email is already queued or sent stay (the email is in their timeline)
            $removed = q(
                "DELETE FROM ai_campaign_contacts WHERE ai_campaign_id = ? AND id IN (" . placeholders($ids) . ") AND draft_status IN ('pending','ready','failed','skipped')",
                [$cid, ...$ids]
            )->rowCount();
        }
        flash('success', $removed . ' contact' . ($removed === 1 ? '' : 's') . ' removed.');
        redirect_back(url('ai-campaigns/view.php', ['id' => $cid]));
    }
}

$allTags = workspace_tags($ws);
$countries = q_col("SELECT DISTINCT country FROM customers WHERE workspace_id = ? AND country IS NOT NULL AND country <> '' ORDER BY country LIMIT 300", [$ws]);
$sources = q_col("SELECT DISTINCT source FROM customers WHERE workspace_id = ? AND source IS NOT NULL AND source <> '' ORDER BY source LIMIT 100", [$ws]);
$contactCount = (int) q_val('SELECT COUNT(*) FROM ai_campaign_contacts WHERE ai_campaign_id = ?', [$cid]);

$page_title = 'Add contacts · ' . $campaign['name'];
$active_nav = 'ai-campaigns';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('ai-campaigns/view.php', ['id' => $cid])) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= e($campaign['name']) ?></a>
        <h1 class="page-title">Add contacts</h1>
        <p class="page-subtitle"><?= number_format($contactCount) ?> contacts in this AI campaign. Customers already added are never duplicated. Customers need a website or company name to be researched.</p>
    </div>
    <a href="<?= e(url('ai-campaigns/view.php', ['id' => $cid])) ?>" class="btn-secondary">Done</a>
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <!-- By tags & filters -->
    <form method="post" class="card" x-data="audiencePreview()" x-init="refresh()" @change="refresh()" @input.debounce.400ms="refresh()">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_filter">
        <div class="card-header"><div><h2 class="card-title">By tags &amp; filters</h2><p class="text-xs text-slate-500">e.g. Tag = Shopify AND Tag = USA, Company not empty</p></div></div>
        <div class="card-body space-y-4">
            <div>
                <div class="flex items-center justify-between">
                    <label class="label">Tags</label>
                    <select name="tag_mode" class="mb-1.5 rounded border-0 bg-transparent py-0 text-xs font-medium text-indigo-600 focus:ring-0">
                        <option value="all">Must have ALL selected</option>
                        <option value="any">Has ANY selected</option>
                    </select>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($allTags as $t): ?>
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-slate-200 px-2.5 py-1 text-xs has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                            <input type="checkbox" class="checkbox !h-3.5 !w-3.5" name="tags[]" value="<?= (int) $t['id'] ?>">
                            <span class="h-2 w-2 rounded-full" style="background: <?= e($t['color']) ?>"></span><?= e($t['name']) ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$allTags): ?><p class="text-sm text-slate-400">No tags yet.</p><?php endif; ?>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">Country</label>
                    <select name="country" class="input"><option value="">Any</option><?php foreach ($countries as $co): ?><option><?= e($co) ?></option><?php endforeach; ?></select></div>
                <div><label class="label">Company</label>
                    <select name="has_company" class="input"><option value="">Any</option><option value="1">Not empty</option><option value="0">Empty</option></select></div>
                <div><label class="label">CRM stage</label>
                    <select name="crm_status" class="input"><option value="">Any</option><?php foreach (CRM_STATUSES as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select></div>
                <div><label class="label">Source</label>
                    <select name="source" class="input"><option value="">Any</option><?php foreach ($sources as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select></div>
                <div><label class="label">Company contains</label><input name="company" class="input" placeholder="Inc."></div>
                <div><label class="label">Search</label><input name="q" class="input" placeholder="name, email…"></div>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="checkbox" name="exclude_suppressed" value="1" checked> Exclude unsubscribed, bounced &amp; archived (status = active)</label>
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="checkbox" name="confirm_all" value="1"> Allow adding all customers when no other filter is set</label>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-3">
            <div class="text-sm">
                <template x-if="loading"><span class="text-slate-400">Counting…</span></template>
                <template x-if="!loading"><span><b class="text-slate-900" x-text="count.toLocaleString()"></b> <span class="text-slate-500">match · </span><b class="text-indigo-700" x-text="newCount.toLocaleString()"></b> <span class="text-slate-500">new to this AI campaign</span></span></template>
                <div class="truncate text-xs text-slate-400" x-text="sample.join(', ')"></div>
            </div>
            <button class="btn-primary shrink-0" :disabled="newCount === 0">Add <span x-text="newCount.toLocaleString()"></span> contacts</button>
        </div>
    </form>

    <!-- Individuals -->
    <div class="card" x-data="pickIndividuals()">
        <div class="card-header"><div><h2 class="card-title">Pick individuals</h2><p class="text-xs text-slate-500">Search customers not yet in this AI campaign.</p></div></div>
        <div class="card-body">
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
                <input type="search" x-model="q" @input.debounce.300ms="search()" class="input pl-9" placeholder="Search by name, email or company…">
            </div>
            <ul class="mt-3 max-h-96 divide-y divide-slate-100 overflow-y-auto">
                <template x-for="c in results" :key="c.id">
                    <li class="flex items-center gap-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium" x-text="c.name"></div>
                            <div class="truncate text-xs text-slate-500" x-text="[c.email, c.company].filter(Boolean).join(' · ')"></div>
                        </div>
                        <span x-show="c.status !== 'active'" class="badge badge-amber" x-text="c.status"></span>
                        <button type="button" class="btn-secondary btn-sm" :disabled="c.added || c.status !== 'active'" @click="add(c)" x-text="c.added ? 'Added ✓' : 'Add'"></button>
                    </li>
                </template>
            </ul>
            <p x-show="q.length >= 2 && !loading && results.length === 0" class="py-6 text-center text-sm text-slate-400">No matching customers outside this AI campaign.</p>
            <p x-show="q.length < 2" class="py-6 text-center text-sm text-slate-400">Type at least 2 characters.</p>
        </div>
    </div>
</div>

<script>
function audiencePreview() {
    return {
        count: 0, newCount: 0, sample: [], loading: false,
        async refresh() {
            this.loading = true;
            const params = new URLSearchParams(new FormData(this.$root));
            params.delete('_csrf'); params.delete('action');
            params.set('ai_campaign_id', '<?= $cid ?>');
            try {
                const d = await api(appUrl('api/ai-campaigns.php') + '?action=preview&' + params.toString());
                Object.assign(this, { count: d.count, newCount: d.new, sample: d.sample });
            } catch (e) { toast(e.message, 'error'); }
            this.loading = false;
        },
    };
}
function pickIndividuals() {
    return {
        q: '', results: [], loading: false,
        async search() {
            if (this.q.trim().length < 2) { this.results = []; return; }
            this.loading = true;
            try {
                const d = await api(appUrl('api/ai-campaigns.php') + '?action=search&ai_campaign_id=<?= $cid ?>&q=' + encodeURIComponent(this.q));
                this.results = d.customers;
            } catch (e) { toast(e.message, 'error'); }
            this.loading = false;
        },
        async add(c) {
            try {
                const d = await api(appUrl('api/ai-campaigns.php'), { method: 'POST', body: { action: 'add_contacts', ai_campaign_id: <?= $cid ?>, customer_ids: [c.id] } });
                if (!d.added) { toast(c.name + ' could not be added (not active)', 'error'); return; }
                c.added = true;
                toast(c.name + ' added');
            } catch (e) { toast(e.message, 'error'); }
        },
    };
}
</script>
<?php require __DIR__ . '/../includes/footer.php';
