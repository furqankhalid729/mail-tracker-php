<?php
declare(strict_types=1);

/**
 * Private files (employee documents, expense receipts) and branded PDFs.
 * Uploads live under UPLOAD_PATH, which denies HTTP access; pages stream them after their own permission check.
 */

/**
 * Validate and store one uploaded file ($_FILES[$field]) under UPLOAD_PATH/$relDir.
 * Same rules as email attachments: size limit, extension allow-list (every extension segment, so "x.php.pdf"
 * is rejected), content sniffing. $types limits the allowed extensions (default: ALLOWED_ATTACHMENT_TYPES).
 * Returns [['filename', 'mime_type', 'size', 'storage_path'] | null, error | null]; [null, null] when no file was chosen.
 */
function store_private_upload(string $field, string $relDir, ?array $types = null): array
{
    $types ??= ALLOWED_ATTACHMENT_TYPES;
    $f = $_FILES[$field] ?? null;
    if (!$f || is_array($f['name']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $name = basename((string) $f['name']);
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? "$name is too large." : "$name: upload failed (code {$f['error']})."];
    }
    if ($f['size'] > UPLOAD_LIMIT_MB * 1024 * 1024) {
        return [null, "$name is larger than " . UPLOAD_LIMIT_MB . ' MB.'];
    }
    $parts = explode('.', strtolower($name));
    $ext = count($parts) > 1 ? end($parts) : '';
    if (array_intersect(array_slice($parts, 1), BLOCKED_EXTENSIONS) || !isset($types[$ext])) {
        return [null, "$name: file type not allowed."];
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, $types[$ext], true)) {
        return [null, "$name: content does not match its extension ($mime)."];
    }
    $rel = trim($relDir, '/') . '/' . random_token(16) . '.' . $ext;
    upload_dir(dirname($rel));
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_PATH . '/' . $rel)) {
        return [null, "$name could not be saved."];
    }
    return [[
        'filename' => mb_substr(preg_replace('/[^\w.\- ()]+/u', '_', $name), 0, 255),
        'mime_type' => $mime,
        'size' => (int) $f['size'],
        'storage_path' => $rel,
    ], null];
}

/** Whether $rel is a safe stored path inside the $prefix folder (e.g. "employees/"). */
function private_upload_path_ok(?string $rel, string $prefix): bool
{
    return $rel !== null && $rel !== '' && str_starts_with($rel, $prefix) && !str_contains($rel, '..') && is_file(UPLOAD_PATH . '/' . $rel);
}

/** Remove stored files from disk (after their rows are deleted); only paths inside $prefix are touched. */
function delete_private_uploads(array $storagePaths, string $prefix): void
{
    foreach (array_filter($storagePaths) as $rel) {
        if (private_upload_path_ok((string) $rel, $prefix)) {
            @unlink(UPLOAD_PATH . '/' . $rel);
        }
    }
}

/**
 * Stream a stored file. PDFs and images open in the browser unless $download; everything else downloads.
 * The caller has already checked permissions and private_upload_path_ok().
 */
function send_private_file(string $rel, string $name, string $mime, bool $download = false): never
{
    $path = UPLOAD_PATH . '/' . $rel;
    session_write_close();
    $inline = !$download && in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
    $ascii = preg_replace('/[^\x20\x21\x23-\x5B\x5D-\x7E]/', '_', $name); // printable ASCII minus " and \
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/** The report brand logo (REPORT_BRAND_LOGO, a PNG/JPG inside the app folder) as a data URI, or null. */
function report_brand_logo(): ?string
{
    if (REPORT_BRAND_LOGO === '') {
        return null;
    }
    $path = realpath(APP_ROOT . '/' . ltrim(REPORT_BRAND_LOGO, '/\\'));
    $mime = $path ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($path) : '';
    if (!$path || !str_starts_with($path, (string) realpath(APP_ROOT)) || !in_array($mime, ['image/png', 'image/jpeg'], true)) {
        return null;
    }
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
}

/**
 * Render HTML to an A4 PDF with dompdf and the Poppins fonts ("Poppins", bold, and "Poppins SemiBold").
 * $footer($page, $pages, $canvas, $fontMetrics) draws on every page after rendering. Returns the PDF bytes.
 */
function render_branded_pdf(string $html, ?callable $footer = null): string
{
    $cacheDir = STORAGE_PATH . '/dompdf';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0750, true);
    }
    $dompdf = new Dompdf\Dompdf(new Dompdf\Options([
        'chroot' => [APP_ROOT],
        'fontDir' => $cacheDir,
        'fontCache' => $cacheDir,
        'tempDir' => $cacheDir,
        'isRemoteEnabled' => false,
        'isPhpEnabled' => false,
        'isFontSubsettingEnabled' => true,
        'defaultFont' => 'Poppins',
        'dpi' => 96,
    ]));
    // Registered from their files (an @font-face url() breaks on paths with spaces).
    // dompdf only tells normal from bold, so SemiBold is its own family.
    $fonts = APP_ROOT . '/assets/fonts/poppins/';
    foreach ([['Poppins', 'normal', 'Regular'], ['Poppins', 'bold', 'Bold'], ['Poppins SemiBold', 'normal', 'SemiBold']] as [$family, $weight, $file]) {
        $dompdf->getFontMetrics()->registerFont(['family' => $family, 'weight' => $weight, 'style' => 'normal'], $fonts . "Poppins-$file.ttf");
    }
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    if ($footer) {
        $dompdf->getCanvas()->page_script($footer);
    }
    return (string) $dompdf->output();
}

/** Send PDF bytes to the browser as a download (or inline). */
function send_pdf(string $pdf, string $filename, bool $inline = false): never
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) . '"');
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}
