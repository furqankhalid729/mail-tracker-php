<?php
/**
 * Every minute:  * * * * * php /home/USER/public_html/cron/process-email-queue.php
 * Builds queued messages for active campaigns, sends one small batch, then exits.
 * Also advances large background bulk operations (bulk_jobs) in bounded chunks.
 */
require __DIR__ . '/_bootstrap.php';

cron_run('email-queue', fn() => process_email_queue());
cron_run('bulk-jobs', fn() => ['customers_updated' => process_bulk_jobs(8)]);
