<?php
/**
 * Download an audit as a branded PDF: GET ?id=<site_audits.id>[&inline=1].
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('inspector.use');

$audit = find_audit_or_404(input_int('id'));
if ($audit['status'] !== 'done') {
    abort(409, 'This audit has not finished yet.');
}
$result = json_decode((string) $audit['result'], true);
$crawl = json_decode((string) $audit['crawl'], true);
$psi = ['mobile' => json_decode((string) $audit['psi_mobile'], true), 'desktop' => json_decode((string) $audit['psi_desktop'], true)];
session_write_close();
@set_time_limit(120);

$logo = report_brand_logo();

ob_start();
require __DIR__ . '/_pdf.php';
$html = (string) ob_get_clean();

// Footer on every page except the cover
$host = (string) parse_url($audit['final_url'] ?: $audit['url'], PHP_URL_HOST);
$pdf = render_branded_pdf($html, function (int $page, int $pages, $canvas, $fontMetrics) use ($host) {
    if ($page === 1) {
        return;
    }
    $font = $fontMetrics->getFont('Poppins') ?: $fontMetrics->getFont('DejaVu Sans');
    $w = $canvas->get_width();
    $h = $canvas->get_height();
    $canvas->line(42, $h - 40, $w - 42, $h - 40, [0.655, 0.788, 0.325], 0.8); // #A7C953
    $canvas->text(42, $h - 32, REPORT_BRAND_NAME . '  ·  Website audit  ·  ' . $host, $font, 7, [0.42, 0.45, 0.5]);
    $label = "Page $page of $pages";
    $canvas->text($w - 42 - $fontMetrics->getTextWidth($label, $font, 7), $h - 32, $label, $font, 7, [0.42, 0.45, 0.5]);
});

$name = 'website-audit-' . preg_replace('/[^a-z0-9.-]+/i', '-', $host) . '-' . date('Y-m-d', strtotime($audit['completed_at'] ?: $audit['created_at'])) . '.pdf';
send_pdf($pdf, $name, (bool) input('inline'));
