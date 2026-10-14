<?php
/**
 * One audit. While running, the page drives the steps (crawl + PageSpeed mobile/desktop in parallel, then finish);
 * when done it shows the full report with a PDF download.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('inspector.use');
$audit = find_audit_or_404(input_int('id'));
$aid = (int) $audit['id'];

if (is_post() && input('action') === 'delete') {
    verify_csrf();
    q('DELETE FROM site_audits WHERE id = ? AND workspace_id = ?', [$aid, ws_id()]);
    flash('success', 'Audit deleted.');
    redirect('inspector/index.php');
}
if (is_post() && input('action') === 'rerun') {
    verify_csrf();
    $id = db_insert('site_audits', ['workspace_id' => ws_id(), 'url' => $audit['url'], 'status' => 'running', 'created_by' => user_id(), 'created_at' => now()]);
    redirect(url('inspector/report.php', ['id' => $id]));
}

$done = $audit['status'] === 'done';
$result = $done ? json_decode((string) $audit['result'], true) : null;
$crawl = $done ? json_decode((string) $audit['crawl'], true) : null;
$psi = ['mobile' => json_decode((string) $audit['psi_mobile'], true), 'desktop' => json_decode((string) $audit['psi_desktop'], true)];
$host = parse_url($audit['final_url'] ?: $audit['url'], PHP_URL_HOST);

$page_title = 'Audit · ' . $host;
$active_nav = '';
require __DIR__ . '/../includes/header.php';

$ring = function (?int $score, string $label, string $size = 'h-24 w-24 text-2xl') {
    $color = inspector_score_color($score);
    $deg = (int) round(($score ?? 0) * 3.6);
    echo '<div class="flex flex-col items-center gap-2">'
        . '<div class="relative flex ' . $size . ' items-center justify-center rounded-full" style="background: conic-gradient(' . $color . ' ' . $deg . 'deg, #e2e8f0 0)">'
        . '<div class="absolute inset-[7px] rounded-full bg-white"></div><span class="relative font-bold tabular-nums" style="color:' . $color . '">' . ($score ?? '—') . '</span></div>'
        . '<span class="text-center text-xs font-medium text-slate-600">' . e($label) . '</span></div>';
};
$statusDot = fn(string $s) => '<span class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white" style="background:'
    . ['pass' => '#16a34a', 'warn' => '#d97706', 'fail' => '#dc2626', 'info' => '#64748b'][$s] . '" title="' . e(inspector_status_label($s)) . '">'
    . ['pass' => '✓', 'warn' => '!', 'fail' => '✕', 'info' => 'i'][$s] . '</span>';
?>
<div class="page-header">
    <div class="min-w-0">
        <a href="<?= e(url('inspector/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Website inspector</a>
        <h1 class="page-title truncate"><?= e($host) ?></h1>
        <p class="page-subtitle truncate"><a href="<?= e($audit['final_url'] ?: $audit['url']) ?>" target="_blank" rel="noopener noreferrer" class="hover:underline"><?= e($audit['final_url'] ?: $audit['url']) ?></a> · <?= e(format_dt($audit['created_at'])) ?></p>
    </div>
    <div class="flex gap-2">
        <?php if ($done): ?><a href="<?= e(url('inspector/pdf.php', ['id' => $aid])) ?>" class="btn-primary"><?= icon('download', 'h-4 w-4') ?> Download PDF</a><?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rerun"><button class="btn-secondary"><?= icon('bolt', 'h-4 w-4') ?> Run again</button></form>
        <form method="post" data-confirm="Delete this audit?" data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn-secondary text-red-600" title="Delete audit" aria-label="Delete audit"><?= icon('trash', 'h-4 w-4') ?></button></form>
    </div>
</div>

<?php if (!$done): ?>
    <div class="card mx-auto max-w-xl p-6" x-data="auditRunner(<?= $aid ?>, <?= e(json_encode($audit['status'])) ?>, <?= e(json_encode((string) $audit['error'])) ?>)" x-init="start()">
        <h2 class="text-base font-semibold text-slate-900" x-text="failed ? 'The audit failed' : 'Inspecting the page…'"></h2>
        <p class="mt-1 text-sm text-slate-500" x-show="!failed">This takes 30–60 seconds. Keep this tab open.</p>
        <ul class="mt-5 space-y-3 text-sm">
            <template x-for="s in steps" :key="s.key">
                <li class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold"
                          :class="{ 'bg-slate-100 text-slate-400': s.state === 'waiting', 'animate-pulse bg-indigo-100 text-indigo-600': s.state === 'running', 'bg-emerald-600 text-white': s.state === 'done', 'bg-amber-500 text-white': s.state === 'skipped', 'bg-red-600 text-white': s.state === 'error' }"
                          x-text="{ waiting: '·', running: '…', done: '✓', skipped: '!', error: '✕' }[s.state]"></span>
                    <span class="min-w-0"><span class="font-medium text-slate-800" x-text="s.label"></span><span class="block break-words text-xs text-slate-500" x-show="s.note" x-text="s.note"></span></span>
                </li>
            </template>
        </ul>
        <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-show="failed" x-text="error"></p>
    </div>
    <script>
    window.auditRunner = (id, status, initialError) => ({
        failed: status === 'failed', error: initialError,
        steps: [
            { key: 'crawl', label: 'Crawl the page, robots.txt, sitemap, links and assets', state: 'waiting', note: '' },
            { key: 'psi_mobile', label: 'Google PageSpeed Insights — mobile', state: 'waiting', note: '' },
            { key: 'psi_desktop', label: 'Google PageSpeed Insights — desktop', state: 'waiting', note: '' },
            { key: 'finish', label: 'Score and build the report', state: 'waiting', note: '' },
        ],
        async call(step) {
            const fd = new FormData();
            fd.append('id', id); fd.append('step', step);
            return api(appUrl('inspector/run.php'), { method: 'POST', body: fd });
        },
        async run(s) {
            s.state = 'running';
            try {
                const r = await this.call(s.key);
                if (r.error) { s.state = 'skipped'; s.note = r.error; } else { s.state = 'done'; }
            } catch (e) { s.state = s.key === 'crawl' || s.key === 'finish' ? 'error' : 'skipped'; s.note = e.message; }
        },
        async start() {
            if (this.failed) return;
            const [crawl, mobile, desktop, finish] = this.steps;
            await Promise.all([this.run(crawl), this.run(mobile), this.run(desktop)]);
            if (crawl.state === 'error') { this.failed = true; this.error = crawl.note; await this.call('finish').catch(() => {}); return; }
            await this.run(finish);
            if (finish.state === 'done') location.reload(); else { this.failed = true; this.error = finish.note; }
        },
    });
    </script>
<?php else:
    $scores = $result['scores'];
    $counts = $result['counts'];
?>
    <div class="card mb-6 p-6">
        <div class="flex flex-col items-center gap-8 lg:flex-row">
            <div class="flex flex-col items-center"><?php $ring($result['overall'], 'Overall score', 'h-32 w-32 text-4xl'); ?></div>
            <div class="grid flex-1 grid-cols-2 gap-6 sm:grid-cols-4">
                <?php foreach (INSPECTOR_CATEGORIES as $k => $label) { $ring($scores[$k], $label); } ?>
            </div>
        </div>
        <div class="mt-6 flex flex-wrap justify-center gap-3 border-t border-slate-100 pt-4 text-sm">
            <span class="badge badge-red"><?= $counts['fail'] ?> failed</span>
            <span class="badge badge-amber"><?= $counts['warn'] ?> warnings</span>
            <span class="badge badge-green"><?= $counts['pass'] ?> passed</span>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <?php if ($result['priorities']): ?>
            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title">Fix these first</h2></div>
                <ol class="divide-y divide-slate-100">
                    <?php foreach ($result['priorities'] as $i => $ck): ?>
                        <li class="flex gap-3 px-5 py-3 text-sm">
                            <span class="w-5 shrink-0 font-semibold text-slate-400"><?= $i + 1 ?>.</span>
                            <?= $statusDot($ck['status']) ?>
                            <div class="min-w-0"><span class="font-medium text-slate-900"><?= e($ck['title']) ?></span> <span class="text-xs text-slate-400">· <?= e(INSPECTOR_CATEGORIES[$ck['category']]) ?> · <?= e($ck['impact']) ?> impact</span><p class="text-slate-600"><?= e($ck['message']) ?></p></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <?php endif; ?>

            <?php foreach (INSPECTOR_CATEGORIES as $cat => $label): ?>
                <div class="card overflow-hidden" id="cat-<?= e($cat) ?>">
                    <div class="card-header"><h2 class="card-title"><?= e($label) ?></h2><span class="rounded-md px-2 py-0.5 text-sm font-bold text-white" style="background: <?= inspector_score_color($scores[$cat]) ?>"><?= $scores[$cat] ?? '—' ?></span></div>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($result['checks'][$cat] as $ck): ?>
                            <li class="flex gap-3 px-5 py-3 text-sm" x-data="{ open: false }">
                                <?= $statusDot($ck['status']) ?>
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium text-slate-900"><?= e($ck['title']) ?></div>
                                    <p class="break-words text-slate-600"><?= e($ck['message']) ?></p>
                                    <?php if ($ck['details']): ?>
                                        <button type="button" class="mt-1 text-xs font-medium text-indigo-600 hover:underline" @click="open = !open" x-text="open ? 'Hide details' : 'Show <?= count($ck['details']) ?> detail<?= count($ck['details']) === 1 ? '' : 's' ?>'"></button>
                                        <ul x-show="open" x-cloak class="mt-2 space-y-1 rounded-lg bg-slate-50 p-3 font-mono text-xs text-slate-600">
                                            <?php foreach ($ck['details'] as $d): ?><li class="break-all"><?= e($d) ?></li><?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>

            <?php if ($crawl['schema']['blocks']): ?>
            <div class="card overflow-hidden" x-data="{ open: false }">
                <div class="card-header"><h2 class="card-title">JSON-LD found on the page</h2><button type="button" class="btn-ghost !py-1 text-xs" @click="open = !open" x-text="open ? 'Hide' : 'Show'"></button></div>
                <div x-show="open" x-cloak class="space-y-3 p-5">
                    <?php foreach ($crawl['schema']['blocks'] as $b): ?><pre class="max-h-96 overflow-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100"><?= e($b) ?></pre><?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="space-y-6">
            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title">Google Lighthouse</h2></div>
                <?php if (empty($psi['mobile']['scores']) && empty($psi['desktop']['scores'])): ?>
                    <p class="p-5 text-sm text-slate-500">PageSpeed Insights did not run: <?= e($psi['mobile']['error'] ?? 'no result') ?></p>
                <?php else: ?>
                    <table class="table text-sm">
                        <thead><tr><th></th><th class="text-center">Mobile</th><th class="text-center">Desktop</th></tr></thead>
                        <tbody>
                        <?php foreach (['performance' => 'Performance', 'accessibility' => 'Accessibility', 'best-practices' => 'Best practices', 'seo' => 'SEO'] as $k => $l): ?>
                            <tr><td><?= e($l) ?></td>
                                <?php foreach (['mobile', 'desktop'] as $s): $v = $psi[$s]['scores'][$k] ?? null; ?>
                                    <td class="text-center font-semibold tabular-nums" style="color: <?= inspector_score_color($v) ?>"><?= $v ?? '—' ?></td>
                                <?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        <?php foreach (['first-contentful-paint' => 'First Contentful Paint', 'largest-contentful-paint' => 'Largest Contentful Paint', 'total-blocking-time' => 'Total Blocking Time', 'cumulative-layout-shift' => 'Cumulative Layout Shift', 'speed-index' => 'Speed Index'] as $k => $l): ?>
                            <tr><td class="text-slate-500"><?= e($l) ?></td>
                                <?php foreach (['mobile', 'desktop'] as $s): $m = $psi[$s]['metrics'][$k] ?? null; ?>
                                    <td class="whitespace-nowrap text-center tabular-nums" style="color: <?= $m && $m['score'] !== null ? inspector_score_color((int) round($m['score'] * 100)) : '#64748b' ?>"><?= e($m['display'] ?? '—') ?></td>
                                <?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!empty($psi['mobile']['screenshot'])): ?>
                        <div class="border-t border-slate-100 p-5 text-center"><img src="<?= e($psi['mobile']['screenshot']) ?>" alt="Mobile screenshot" class="mx-auto max-h-80 rounded-lg ring-1 ring-slate-200"></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title">Page facts</h2></div>
                <dl class="divide-y divide-slate-100 text-sm">
                    <?php $f = $crawl['fetch']; foreach ([
                        'HTTP status' => $f['status'],
                        'Protocol' => $f['http_version'] ?: '—',
                        'Response time' => inspector_fmt_ms($f['time']['ttfb'] ?? 0) . ' TTFB · ' . inspector_fmt_ms($f['total_time']) . ' total',
                        'HTML size' => human_size((int) $f['html_size']) . ' (' . human_size((int) $f['transfer_size']) . ' transferred)',
                        'Compression' => $f['encoding'] ?: 'none',
                        'Server' => $f['server'] ?: '—',
                        'IP address' => $f['ip'] ?: '—',
                        'Words' => number_format($crawl['page']['word_count']),
                        'Images' => count($crawl['page']['images']),
                        'Links' => $crawl['page']['links_internal'] . ' internal · ' . $crawl['page']['links_external'] . ' external',
                        'Scripts / stylesheets' => $crawl['page']['scripts'] . ' / ' . $crawl['page']['stylesheets'],
                    ] as $k => $v): ?>
                        <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-slate-500"><?= e($k) ?></dt><dd class="break-all text-right text-slate-800"><?= e($v) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </div>

            <?php if ($f['headers']): ?>
            <div class="card overflow-hidden" x-data="{ open: false }">
                <div class="card-header"><h2 class="card-title">Response headers</h2><button type="button" class="btn-ghost !py-1 text-xs" @click="open = !open" x-text="open ? 'Hide' : 'Show'"></button></div>
                <dl x-show="open" x-cloak class="divide-y divide-slate-100 font-mono text-xs">
                    <?php foreach ($f['headers'] as $k => $v): ?><div class="px-5 py-2"><dt class="font-semibold text-slate-700"><?= e($k) ?></dt><dd class="break-all text-slate-500"><?= e($v) ?></dd></div><?php endforeach; ?>
                </dl>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
