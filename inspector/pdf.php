<?php
/**
 * Download an audit as a branded PDF: GET ?id=<site_audits.id>[&inline=1].
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('inspector.use');

use Dompdf\Dompdf;
use Dompdf\Options;

$audit = find_audit_or_404(input_int('id'));
if ($audit['status'] !== 'done') {
    abort(409, 'This audit has not finished yet.');
}
$result = json_decode((string) $audit['result'], true);
$crawl = json_decode((string) $audit['crawl'], true);
$psi = ['mobile' => json_decode((string) $audit['psi_mobile'], true), 'desktop' => json_decode((string) $audit['psi_desktop'], true)];
session_write_close();
@set_time_limit(120);

// Brand logo (a file inside the app folder), embedded as a data URI
$logo = null;
if (REPORT_BRAND_LOGO !== '') {
    $path = realpath(APP_ROOT . '/' . ltrim(REPORT_BRAND_LOGO, '/\\'));
    $mime = $path ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($path) : '';
    if ($path && str_starts_with($path, realpath(APP_ROOT)) && in_array($mime, ['image/png', 'image/jpeg'], true)) {
        $logo = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}

ob_start();
require __DIR__ . '/_pdf.php';
$html = (string) ob_get_clean();

$cacheDir = STORAGE_PATH . '/dompdf';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}
$options = new Options([
    'chroot' => [APP_ROOT],
    'fontDir' => $cacheDir,
    'fontCache' => $cacheDir,
    'tempDir' => $cacheDir,
    'isRemoteEnabled' => false,
    'isPhpEnabled' => false,
    'isFontSubsettingEnabled' => true,
    'defaultFont' => 'Poppins',
    'dpi' => 96,
]);
$dompdf = new Dompdf($options);
// Register Poppins from its files (an @font-face url() breaks on paths with spaces).
// dompdf only tells normal from bold, so SemiBold is its own family.
$fonts = APP_ROOT . '/assets/fonts/poppins/';
foreach ([['Poppins', 'normal', 'Regular'], ['Poppins', 'bold', 'Bold'], ['Poppins SemiBold', 'normal', 'SemiBold']] as [$family, $weight, $file]) {
    $dompdf->getFontMetrics()->registerFont(['family' => $family, 'weight' => $weight, 'style' => 'normal'], $fonts . "Poppins-$file.ttf");
}
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Footer on every page except the cover
$host = (string) parse_url($audit['final_url'] ?: $audit['url'], PHP_URL_HOST);
$dompdf->getCanvas()->page_script(function (int $page, int $pages, $canvas, $fontMetrics) use ($host) {
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
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (input('inline') ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, no-store');
echo $dompdf->output();
exit;
