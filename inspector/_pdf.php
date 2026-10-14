<?php
defined('APP_ROOT') || exit;
/**
 * HTML for the PDF report (rendered by dompdf: tables for layout, no flexbox/grid).
 * Expects: $audit, $result, $crawl, $psi (['mobile' => ?array, 'desktop' => ?array]), $logo (?data URI).
 * Look: dark cover with lime accent and Poppins; white content pages; traffic-light colours for results.
 */
$brand = REPORT_BRAND_NAME;
$url = $audit['final_url'] ?: $audit['url'];
$host = (string) parse_url($url, PHP_URL_HOST);
$scores = $result['scores'];
$counts = $result['counts'];
$statusColor = ['pass' => '#16a34a', 'warn' => '#d97706', 'fail' => '#dc2626', 'info' => '#64748b'];
$statusText = ['pass' => 'PASS', 'warn' => 'WARN', 'fail' => 'FAIL', 'info' => 'INFO'];
$hasPsi = !empty($psi['mobile']['scores']) || !empty($psi['desktop']['scores']);
// Escape for the PDF; Poppins has no arrows, so those are drawn with DejaVu Sans (bundled with dompdf)
$t = fn($s) => preg_replace('/[←→↑↓]/u', '<span style="font-family: DejaVu Sans;">$0</span>', e($s));
?><!doctype html>
<html><head><meta charset="utf-8">
<style>
    /* Poppins is registered in pdf.php; dompdf only tells normal from bold, so SemiBold is its own family */
    .semi, h1, h2, h3, .title, .brand, .site, .kicker { font-family: 'Poppins SemiBold', 'Poppins', sans-serif; font-weight: normal; }
    @page { margin: 18mm 15mm 20mm 15mm; }
    @page :first { margin: 0; }
    * { box-sizing: border-box; }
    body { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.2; margin: 0; }
    h1, h2, h3 { margin: 0; color: #05070b; }
    table { border-collapse: collapse; width: 100%; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; color: #475569; }
    .muted { color: #6b7280; }
    .page-break { page-break-before: always; }

    /* Cover */
    .cover { background: #05070b; color: #f2f2f2; height: 252mm; position: relative; padding: 22mm 18mm; }
    .cover .brand { font-size: 11pt; color: #ffffff; letter-spacing: .5pt; }
    .cover .kicker { color: #A7C953; font-size: 9pt; letter-spacing: 2pt; text-transform: uppercase; margin-top: 48mm; }
    .cover h1 { font-family: 'Poppins'; font-weight: bold; color: #ffffff; font-size: 34pt; line-height: 0.95; margin-top: 4mm; }
    .cover .accent { width: 28mm; height: 1.6mm; background: #A7C953; margin: 8mm 0 6mm; }
    .cover .site { font-size: 15pt; color: #ffffff; }
    .cover .url { font-size: 9pt; color: #9ca3af; margin-top: 1mm; }
    .cover .scorebox { position: absolute; left: 18mm; right: 18mm; bottom: 34mm; background: #0d0d0d; border: 1px solid #1f2a12; border-radius: 4mm; padding: 7mm 6mm; }
    .cover .scorebox td { text-align: center; vertical-align: middle; color: #d1d5db; font-size: 8pt; }
    .cover .big { width: 30mm; height: 30mm; border-radius: 17.2mm; border: 2.2mm solid #A7C953; margin: 0 auto; }
    .cover .big td { height: 30mm; text-align: center; vertical-align: middle; font-size: 26pt; font-weight: bold; color: #ffffff; line-height: 1; }
    .cover .small { font-size: 18pt; font-weight: bold; line-height: 1.1; }
    .cover .footer { position: absolute; left: 18mm; right: 18mm; bottom: 14mm; font-size: 8pt; color: #7a7a7a; }

    /* Content */
    .section-title { background: #05070b; color: #ffffff; padding: 3.2mm 5mm; border-left: 2mm solid #A7C953; border-radius: 1.5mm; margin: 0 0 5mm; }
    .section-title h2 { color: #ffffff; font-size: 13pt; }
    .section-title .score { float: right; font-weight: bold; font-size: 13pt; }
    .card { border: 1px solid #e5e7eb; border-radius: 2mm; padding: 4mm 5mm; margin-bottom: 5mm; }
    .stat td { padding: 2.2mm 0; border-bottom: 1px solid #f1f5f9; }
    .bar { height: 2.6mm; background: #eef2f7; border-radius: 1.3mm; }
    .bar div { height: 2.6mm; border-radius: 1.3mm; }
    .chip { color: #fff; font-size: 6.5pt; font-weight: bold; padding: .6mm 1.6mm; border-radius: 1mm; letter-spacing: .3pt; }
    .checks td { padding: 2.4mm 2mm; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    .checks .title { color: #05070b; }
    .details { margin: 1mm 0 0; padding: 0; list-style: none; }
    .details li { font-family: 'DejaVu Sans Mono', monospace; font-size: 6.8pt; color: #64748b; word-wrap: break-word; }
    .metrics th { text-align: left; font-size: 7.5pt; color: #6b7280; font-weight: normal; padding: 1.8mm 2mm; border-bottom: 1px solid #e5e7eb; }
    .metrics td { padding: 1.8mm 2mm; border-bottom: 1px solid #f1f5f9; }
    .lime { color: #5f7a1d; }
    .note { background: #f7fbe9; border: 1px solid #dfeeb4; border-radius: 2mm; padding: 3mm 4mm; font-size: 8.5pt; color: #3f4f12; }
</style>
</head>
<body>

<!-- Cover -->
<div class="cover">
    <table><tr>
        <td style="vertical-align: middle;"><?php if ($logo): ?><img src="<?= $logo ?>" style="height: 12mm;"><?php else: ?><span class="brand"><?= e($brand) ?></span><?php endif; ?></td>
        <td style="text-align: right; vertical-align: middle; font-size: 8pt; color: #9ca3af;"><?= e(format_dt($audit['completed_at'] ?: $audit['created_at'], 'F j, Y')) ?></td>
    </tr></table>
    <div class="kicker">Website audit report</div>
    <h1>Speed, SEO &amp; Technical<br>Health Check</h1>
    <div class="accent"></div>
    <div class="site"><?= e($host) ?></div>
    <div class="url"><?= e($url) ?></div>

    <div class="scorebox">
        <table><tr>
            <td style="width: 34%;"><div class="big" style="border-color: <?= inspector_score_color($result['overall']) ?>;"><table><tr><td><?= $result['overall'] ?? '—' ?></td></tr></table></div><div style="margin-top: 2.5mm; color: #ffffff; font-weight: normal;" class="semi">Overall score</div></td>
            <?php foreach (INSPECTOR_CATEGORIES as $k => $label): ?>
                <td style="width: 16.5%;"><div class="small" style="color: <?= inspector_score_color($scores[$k]) ?>;"><?= $scores[$k] ?? '—' ?></div><div style="margin-top: 1.5mm;"><?= e($label) ?></div></td>
            <?php endforeach; ?>
        </tr></table>
    </div>
    <div class="footer">
        <table><tr>
            <td>Prepared by <span style="color: #A7C953; font-weight: normal;" class="semi"><?= e($brand) ?></span></td>
            <td style="text-align: right;"><?= e(implode('  ·  ', array_filter([REPORT_BRAND_URL ? preg_replace('#^https?://#', '', REPORT_BRAND_URL) : null, REPORT_BRAND_EMAIL ?: null]))) ?></td>
        </tr></table>
    </div>
</div>

<!-- Summary -->
<div class="page-break"></div>
<div class="section-title"><h2>Executive summary</h2></div>
<table><tr>
    <td style="width: 58%; vertical-align: top; padding-right: 5mm;">
        <div class="card">
            <h3 style="font-size: 10.5pt; margin-bottom: 2mm;">Scores</h3>
            <table class="stat">
                <?php foreach (INSPECTOR_CATEGORIES as $k => $label): $s = $scores[$k]; ?>
                    <tr>
                        <td style="width: 46%;"><?= e($label) ?></td>
                        <td style="width: 40%;"><div class="bar"><div style="width: <?= (int) ($s ?? 0) ?>%; background: <?= inspector_score_color($s) ?>;"></div></div></td>
                        <td style="width: 14%; text-align: right; font-weight: bold; color: <?= inspector_score_color($s) ?>;"><?= $s ?? '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </td>
    <td style="width: 42%; vertical-align: top;">
        <div class="card" style="text-align: center;">
            <h3 style="font-size: 10.5pt; margin-bottom: 3mm; text-align: left;">Results</h3>
            <table><tr>
                <td><div style="font-size: 20pt; font-weight: bold; color: #dc2626;"><?= $counts['fail'] ?></div><div class="muted" style="font-size: 8pt;">Failed</div></td>
                <td><div style="font-size: 20pt; font-weight: bold; color: #d97706;"><?= $counts['warn'] ?></div><div class="muted" style="font-size: 8pt;">Warnings</div></td>
                <td><div style="font-size: 20pt; font-weight: bold; color: #16a34a;"><?= $counts['pass'] ?></div><div class="muted" style="font-size: 8pt;">Passed</div></td>
            </tr></table>
        </div>
    </td>
</tr></table>

<?php if ($result['priorities']): ?>
<div class="card">
    <h3 style="font-size: 10.5pt; margin-bottom: 2mm;">Fix these first</h3>
    <table class="checks">
        <?php foreach ($result['priorities'] as $i => $ck): ?>
            <tr>
                <td style="width: 6mm; font-weight: bold;" class="lime"><?= $i + 1 ?></td>
                <td style="width: 13mm;"><span class="chip" style="background: <?= $statusColor[$ck['status']] ?>;"><?= $statusText[$ck['status']] ?></span></td>
                <td><span class="title"><?= $t($ck['title']) ?></span> <span class="muted" style="font-size: 7.5pt;">· <?= e(INSPECTOR_CATEGORIES[$ck['category']]) ?> · <?= e($ck['impact']) ?> impact</span><br><?= $t($ck['message']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<?php if ($hasPsi): ?>
<div class="card" style="page-break-inside: avoid;">
    <h3 style="font-size: 10.5pt; margin-bottom: 2mm;">Google Lighthouse (PageSpeed Insights)</h3>
    <table><tr>
        <td style="vertical-align: top; padding-right: 4mm;">
            <table class="metrics">
                <tr><th></th><th style="text-align: center;">Mobile</th><th style="text-align: center;">Desktop</th></tr>
                <?php foreach (['performance' => 'Performance', 'accessibility' => 'Accessibility', 'best-practices' => 'Best practices', 'seo' => 'SEO'] as $k => $l): ?>
                    <tr><td><?= e($l) ?></td>
                        <?php foreach (['mobile', 'desktop'] as $s): $v = $psi[$s]['scores'][$k] ?? null; ?><td style="text-align: center; font-weight: bold; color: <?= inspector_score_color($v) ?>;"><?= $v ?? '—' ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                <?php foreach (['first-contentful-paint' => 'First Contentful Paint', 'largest-contentful-paint' => 'Largest Contentful Paint', 'total-blocking-time' => 'Total Blocking Time', 'cumulative-layout-shift' => 'Cumulative Layout Shift', 'speed-index' => 'Speed Index'] as $k => $l): ?>
                    <tr><td class="muted"><?= e($l) ?></td>
                        <?php foreach (['mobile', 'desktop'] as $s): $m = $psi[$s]['metrics'][$k] ?? null; ?>
                            <td style="text-align: center; color: <?= $m && $m['score'] !== null ? inspector_score_color((int) round($m['score'] * 100)) : '#64748b' ?>;"><?= e($m['display'] ?? '—') ?></td>
                        <?php endforeach; ?></tr>
                <?php endforeach; ?>
            </table>
        </td>
        <?php if (!empty($psi['mobile']['screenshot']) && str_starts_with($psi['mobile']['screenshot'], 'data:image/')): ?>
            <td style="width: 36mm; vertical-align: top; text-align: center;"><img src="<?= e($psi['mobile']['screenshot']) ?>" style="width: 34mm; border: 1px solid #e5e7eb; border-radius: 1.5mm;"><div class="muted" style="font-size: 7pt; margin-top: 1mm;">Mobile view</div></td>
        <?php endif; ?>
    </tr></table>
</div>
<?php elseif (!empty($psi['mobile']['error'])): ?>
    <div class="note">Google PageSpeed Insights could not run for this audit (<?= e($psi['mobile']['error']) ?>). Speed results are based on our own measurements.</div>
<?php endif; ?>

<!-- Categories -->
<?php foreach (INSPECTOR_CATEGORIES as $cat => $label): ?>
    <div class="page-break"></div>
    <div class="section-title"><span class="score" style="color: <?= $scores[$cat] !== null && $scores[$cat] >= 90 ? '#A7C953' : inspector_score_color($scores[$cat]) ?>;"><?= $scores[$cat] ?? '—' ?>/100</span><h2><?= e($label) ?></h2></div>
    <table class="checks">
        <?php foreach ($result['checks'][$cat] as $ck): ?>
            <tr style="page-break-inside: avoid;">
                <td style="width: 13mm; padding-top: 2.8mm;"><span class="chip" style="background: <?= $statusColor[$ck['status']] ?>;"><?= $statusText[$ck['status']] ?></span></td>
                <td>
                    <div class="title"><?= $t($ck['title']) ?></div>
                    <div><?= $t($ck['message']) ?></div>
                    <?php if ($ck['details'] && $ck['status'] !== 'pass'): ?>
                        <ul class="details"><?php foreach (array_slice($ck['details'], 0, 8) as $d): ?><li><?= $t(mb_strimwidth((string) $d, 0, 140, '…')) ?></li><?php endforeach; ?><?php if (count($ck['details']) > 8): ?><li>… and <?= count($ck['details']) - 8 ?> more</li><?php endif; ?></ul>
                    <?php endif; ?>
                </td>
                <td style="width: 16mm; text-align: right; font-size: 7pt;" class="muted"><?= e($ck['status'] === 'info' ? '' : ucfirst($ck['impact'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($cat === 'schema' && $crawl['schema']['entities']): ?>
        <div class="card" style="margin-top: 5mm;">
            <h3 style="font-size: 10pt; margin-bottom: 1.5mm;">Schema types found</h3>
            <div class="mono"><?= e(implode(', ', array_unique(array_map(fn($x) => $x['type'], $crawl['schema']['entities'])))) ?></div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<!-- About -->
<div class="card" style="margin-top: 8mm; page-break-inside: avoid;">
    <h3 style="font-size: 10pt; margin-bottom: 1.5mm;">About this report</h3>
    <div class="muted" style="font-size: 8pt;">
        Checked <?= e(format_dt($audit['completed_at'] ?: $audit['created_at'])) ?> from <?= e($url) ?>. Scores weigh each check by impact (high, medium, low); a warning counts half.
        Speed metrics come from Google Lighthouse (lab data) and the Chrome UX Report where available; server timings are measured from our server, so they vary by location.
        Up to 30 links and 12 images were sampled.
    </div>
</div>
</body></html>
