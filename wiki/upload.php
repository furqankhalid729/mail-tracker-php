<?php
/**
 * Image upload for the wiki editor (Editor.js image tool, field "image").
 * Responds in the format the tool expects: {success: 1, file: {url}} or {success: 0, message}.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
if (!is_post()) {
    abort(405, 'Method not allowed');
}
verify_csrf();
require_permission('wiki.manage');

$fail = fn(string $message) => json_response(['success' => 0, 'message' => $message]);
$f = $_FILES['image'] ?? null;
if (!$f || is_array($f['name']) || $f['error'] !== UPLOAD_ERR_OK) {
    $fail(in_array($f['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The image is too large.' : 'Upload failed.');
}
if ($f['size'] > UPLOAD_LIMIT_MB * 1024 * 1024) {
    $fail('Images must be under ' . UPLOAD_LIMIT_MB . ' MB.');
}
$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
$ext = array_search($mime, ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'], true);
if ($ext === false || !@getimagesize($f['tmp_name'])) {
    $fail('Only PNG, JPG, GIF and WebP images can be added.');
}
$name = random_token(16) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], upload_dir('wiki/' . ws_id()) . '/' . $name)) {
    $fail('The image could not be saved.');
}
json_response(['success' => 1, 'file' => ['url' => url('wiki/file.php', ['f' => $name])]]);
