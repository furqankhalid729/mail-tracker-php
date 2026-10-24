<?php
/**
 * "Test on one customer": research + write one email right now with the campaign's current settings.
 * Shows the result only — nothing is saved or sent. Costs one OpenRouter request.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
$ws = ws_id();
$campaign = find_or_404('ai_campaigns', input_int('id'));
$cid = (int) $campaign['id'];

$customerId = input_int('customer_id');
$customer = $customerId ? q_one('SELECT * FROM customers WHERE id = ? AND workspace_id = ?', [$customerId, $ws]) : null;
$contacts = q_all(
    "SELECT c.id, c.first_name, c.last_name, c.full_name, c.email, c.company, c.website FROM ai_campaign_contacts a
     JOIN customers c ON c.id = a.customer_id WHERE a.ai_campaign_id = ? ORDER BY a.id LIMIT 200",
    [$cid]
);

$result = null;
$error = null;
if (is_post()) {
    verify_csrf();
    if (!$customer) {
        $error = 'Choose a customer to test with.';
    } elseif (trim((string) $customer['website']) === '' && trim((string) $customer['company']) === '') {
        $error = 'This customer has no website or company name, so there is nothing to research.';
    } else {
        set_time_limit(180);
        try {
            $result = ai_draft_email($customer, ai_campaign_opts($campaign));
        } catch (RuntimeException $e) { // OpenRouterException included
            $error = $e->getMessage();
        }
    }
}

$page_title = 'Test · ' . $campaign['name'];
$active_nav = 'ai-campaigns';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('ai-campaigns/view.php', ['id' => $cid])) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= e($campaign['name']) ?></a>
        <h1 class="page-title">Test on one customer</h1>
        <p class="page-subtitle">Runs the research and writes the email with the current settings (<?= e($campaign['model']) ?><?= $campaign['web_search'] ? ', web search on' : ', web search off' ?>). Nothing is saved or sent.</p>
    </div>
    <a href="<?= e(url('ai-campaigns/edit.php', ['id' => $cid])) ?>" class="btn-secondary"><?= icon('pencil', 'h-4 w-4') ?> Edit prompt &amp; template</a>
</div>

<form method="post" class="card mb-6" x-data="{ busy: false }" @submit="busy = true">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>">
    <div class="card-body flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label class="label" for="customer_id">Customer</label>
            <select class="input" id="customer_id" name="customer_id" required>
                <option value="">Choose a contact of this campaign…</option>
                <?php foreach ($contacts as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $customer && (int) $customer['id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e(customer_name($c)) ?> · <?= e($c['company'] ?: '—') ?> · <?= e($c['website'] ?: 'no website') ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$contacts): ?><p class="help"><a class="text-indigo-600" href="<?= e(url('ai-campaigns/contacts.php', ['id' => $cid])) ?>">Add contacts</a> first.</p><?php endif; ?>
        </div>
        <button class="btn-primary" :disabled="busy"><span x-show="!busy"><?= icon('sparkles', 'h-4 w-4') ?> Research &amp; write</span><span x-show="busy" x-cloak>Working… (up to a minute)</span></button>
    </div>
</form>

<?php if ($error): ?>
    <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
<div class="grid gap-6 lg:grid-cols-5">
    <div class="card lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Research</h2></div>
        <div class="card-body space-y-3 text-sm">
            <p class="whitespace-pre-line text-slate-700"><?= e($result['research'] ?: 'The model returned no research summary.') ?></p>
            <div class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                Cost $<?= number_format($result['usage']['cost'], 4) ?> ·
                <?= number_format($result['usage']['prompt_tokens']) ?> in / <?= number_format($result['usage']['completion_tokens']) ?> out tokens ·
                <?= (int) $result['usage']['web_requests'] ?> web searches
            </div>
        </div>
    </div>
    <div class="card lg:col-span-3">
        <div class="card-header"><div><div class="text-xs text-slate-500">Subject</div><h2 class="card-title"><?= e($result['subject']) ?></h2></div></div>
        <div class="card-body">
            <div class="prose prose-sm max-w-none"><?= $result['html_body'] /* sanitised by ai_parse_reply() */ ?></div>
            <details class="mt-4 text-sm"><summary class="cursor-pointer text-slate-500">Plain-text version</summary><pre class="mt-2 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-xs"><?= e($result['text_body']) ?></pre></details>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
