<?php
/**
 * Serve a wiki image: GET ?f=<name>. Any signed-in member of the workspace can view them.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();

$name = (string) input('f');
$path = UPLOAD_PATH . '/wiki/' . ws_id() . '/' . $name;
if (!preg_match('/^[a-f0-9]{32}\.(png|jpe?g|gif|webp)$/', $name, $m) || !is_file($path)) {
    abort(404, 'Image not found.');
}
session_write_close();
header('Content-Type: ' . WIKI_IMAGE_TYPES[$m[1]]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
