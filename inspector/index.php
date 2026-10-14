<?php
/**
 * Website inspector (developer tool, not in the navigation): start an audit and browse past audits.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('inspector.use');
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    [$url, $error] = inspector_normalize_url((string) input('url'));
    if ($error) {
        keep_old_input();
        flash('error', $error);
        redirect('inspector/index.php');
    }
    if (!rate_limit('inspector:' . $ws, 30, 3600)) {
        flash('error', 'That is a lot of audits this hour. Please wait a little.');
        redirect('inspector/index.php');
    }
    $id = db_insert('site_audits', ['workspace_id' => $ws, 'url' => $url, 'status' => 'running', 'created_by' => user_id(), 'created_at' => now()]);
    clear_old_input();
    redirect(url('inspector/report.php', ['id' => $id]));
}

$total = (int) q_val('SELECT COUNT(*) FROM site_audits WHERE workspace_id = ?', [$ws]);
$p = paginate($total, per_page(25));
$audits = q_all(
    'SELECT a.id, a.url, a.final_url, a.status, a.error, a.score, a.score_performance, a.score_seo, a.score_schema, a.score_technical, a.created_at, u.name creator_name
     FROM site_audits a LEFT JOIN users u ON u.id = a.created_by WHERE a.workspace_id = ? ORDER BY a.id DESC LIMIT ? OFFSET ?',
    [$ws, $p['per_page'], $p['offset']]
);
$pill = fn(?int $s) => '<span class="inline-flex min-w-[2.25rem] justify-center rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums text-white" style="background:' . inspector_score_color($s) . '">' . ($s ?? '—') . '</span>';

$page_title = 'Website inspector';
$active_nav = '';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Website inspector</h1><p class="page-subtitle">Speed, SEO, structured data and technical checks for any public page, with a branded PDF report.</p></div>
</div>

<form method="post" class="card mb-6 p-5" x-data="{ busy: false }" @submit="busy = true">
    <?= csrf_field() ?>
    <label class="label" for="url">Page to inspect</label>
    <div class="flex flex-col gap-2 sm:flex-row">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
            <input class="input !pl-9" id="url" name="url" value="<?= e(old('url')) ?>" required maxlength="2000" placeholder="https://www.example.com/" autofocus>
        </div>
        <button class="btn-primary" :disabled="busy"><?= icon('bolt', 'h-4 w-4') ?> <span x-text="busy ? 'Starting…' : 'Inspect'">Inspect</span></button>
    </div>
    <p class="help mt-2">Takes about 30–60 seconds: the page and its site files are crawled, and Google PageSpeed Insights runs for mobile and desktop.<?= PAGESPEED_API_KEY ? '' : ' <span class="text-amber-600">No PAGESPEED_API_KEY is set, so PageSpeed uses Google’s small shared quota and may be skipped.</span>' ?></p>
</form>

<div class="card overflow-hidden">
    <div class="card-header"><h2 class="card-title">Past audits</h2><span class="text-xs text-slate-400"><?= $total ?></span></div>
    <?php if ($audits): ?>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Page</th><th class="text-center">Overall</th><th class="text-center">Speed</th><th class="text-center">SEO</th><th class="text-center">Schema</th><th class="text-center">Technical</th><th>Run</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($audits as $a): ?>
                <tr>
                    <td class="max-w-md">
                        <a href="<?= e(url('inspector/report.php', ['id' => $a['id']])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e(preg_replace('#^https?://#', '', $a['final_url'] ?: $a['url'])) ?></a>
                        <?php if ($a['status'] === 'failed'): ?><span class="text-xs text-red-600"><?= e($a['error'] ?: 'Failed') ?></span>
                        <?php elseif ($a['status'] === 'running'): ?><span class="text-xs text-amber-600">Not finished — open to resume</span><?php endif; ?>
                    </td>
                    <td class="text-center"><?= $a['status'] === 'done' ? $pill($a['score'] !== null ? (int) $a['score'] : null) : '' ?></td>
                    <td class="text-center"><?= $a['status'] === 'done' ? $pill($a['score_performance'] !== null ? (int) $a['score_performance'] : null) : '' ?></td>
                    <td class="text-center"><?= $a['status'] === 'done' ? $pill($a['score_seo'] !== null ? (int) $a['score_seo'] : null) : '' ?></td>
                    <td class="text-center"><?= $a['status'] === 'done' ? $pill($a['score_schema'] !== null ? (int) $a['score_schema'] : null) : '' ?></td>
                    <td class="text-center"><?= $a['status'] === 'done' ? $pill($a['score_technical'] !== null ? (int) $a['score_technical'] : null) : '' ?></td>
                    <td class="whitespace-nowrap text-xs text-slate-500"><?= e(time_ago($a['created_at'])) ?><?= $a['creator_name'] ? ' · ' . e($a['creator_name']) : '' ?></td>
                    <td class="whitespace-nowrap text-right">
                        <?php if ($a['status'] === 'done'): ?><a href="<?= e(url('inspector/pdf.php', ['id' => $a['id']])) ?>" class="btn-ghost !py-1 text-xs"><?= icon('download', 'h-4 w-4') ?> PDF</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
    <?php else: ?>
        <div class="empty-state">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><?= icon('bolt', 'h-6 w-6') ?></span>
            <h3 class="mt-3 text-sm font-semibold">No audits yet</h3>
            <p class="mt-1 text-sm text-slate-500">Enter a page above to run the first one.</p>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
