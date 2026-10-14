<?php
/**
 * Runs one step of an audit (POST, JSON): step=crawl | psi_mobile | psi_desktop | finish, id=<site_audits.id>.
 * The report page calls the first three in parallel, then finish. Each step writes only its own column.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
require_permission('inspector.use');
session_write_close(); // let the parallel steps run side by side
@set_time_limit(150);
ignore_user_abort(true);

$ws = ws_id();
$audit = find_audit_or_404(input_int('id'));
$step = (string) input('step');
$where = ['id = ? AND workspace_id = ?', [$audit['id'], $ws]];

try {
    switch ($step) {
        case 'crawl':
            $crawl = inspector_crawl($audit['url']);
            db_update('site_audits', ['crawl' => json_encode($crawl, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'final_url' => mb_substr($crawl['final_url'], 0, 2048)], ...$where);
            json_response(['ok' => true]);
        case 'psi_mobile':
        case 'psi_desktop':
            $psi = inspector_pagespeed($audit['url'], $step === 'psi_mobile' ? 'mobile' : 'desktop');
            db_update('site_audits', [$step => json_encode($psi, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)], ...$where);
            json_response(['ok' => true, 'error' => $psi['error'] ?? null]);
        case 'finish':
            $audit = find_audit_or_404((int) $audit['id']);
            $crawl = json_decode((string) $audit['crawl'], true);
            if (!$crawl) {
                db_update('site_audits', ['status' => 'failed', 'error' => $audit['error'] ?: 'The page could not be crawled.', 'completed_at' => now()], ...$where);
                json_response(['ok' => false, 'error' => $audit['error'] ?: 'The page could not be crawled.']);
            }
            $mobile = json_decode((string) $audit['psi_mobile'], true) ?: null;
            $desktop = json_decode((string) $audit['psi_desktop'], true) ?: null;
            [$result, $scores] = inspector_finish($crawl, $mobile, $desktop);
            db_update('site_audits', [
                'status' => 'done', 'error' => null, 'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'score' => $scores['overall'], 'score_performance' => $scores['performance'], 'score_seo' => $scores['seo'],
                'score_schema' => $scores['schema'], 'score_technical' => $scores['technical'], 'completed_at' => now(),
            ], ...$where);
            json_response(['ok' => true]);
        default:
            json_response(['ok' => false, 'error' => 'Unknown step.'], 400);
    }
} catch (Throwable $e) {
    app_log('warning', 'Inspector step failed: ' . $e->getMessage(), ['audit' => $audit['id'], 'step' => $step]);
    if ($step === 'crawl') {
        db_update('site_audits', ['error' => mb_substr($e->getMessage(), 0, 500)], ...$where);
    }
    json_response(['ok' => false, 'error' => $e->getMessage()]);
}
