<?php
declare(strict_types=1);

const CRM_STATUSES = ['New', 'Contacted', 'Interested', 'Qualified', 'Follow Up', 'Won', 'Lost'];
const SYSTEM_STATUSES = ['queued', 'sent', 'delivered', 'opened', 'clicked', 'replied', 'bounced', 'failed'];
const CUSTOMER_STATUSES = ['active', 'unsubscribed', 'bounced', 'archived'];
const CAMPAIGN_STATUSES = ['draft', 'active', 'paused', 'completed', 'archived'];
const EVENT_TYPES = ['queued', 'sent', 'delivered', 'opened', 'clicked', 'replied', 'bounced', 'failed', 'unsubscribed'];

// Engagement ranking: a contact's system status only moves forward (bounced/failed handled separately)
const SYSTEM_STATUS_RANK = [
    'queued' => 1, 'sent' => 2, 'delivered' => 3, 'opened' => 4, 'clicked' => 5, 'replied' => 6,
];

const CUSTOMER_FIELDS = [
    'first_name' => 'First name', 'last_name' => 'Last name', 'full_name' => 'Full name',
    'email' => 'Email', 'company' => 'Company', 'job_title' => 'Job title', 'phone' => 'Phone',
    'website' => 'Website', 'country' => 'Country', 'source' => 'Source', 'notes' => 'Notes',
];

const TEMPLATE_VARIABLES = ['firstName', 'lastName', 'fullName', 'company', 'email', 'website', 'jobTitle', 'country'];

// Retry delays (minutes) after attempt 1, 2, 3...
const RETRY_DELAYS = [5, 30, 120];
// A job stuck in "processing" longer than this is considered abandoned
const JOB_LOCK_TIMEOUT_MINUTES = 10;
// Hard ceiling on a single cron run (shared hosts kill long PHP processes)
const CRON_MAX_RUNTIME = 50;
// How many campaign contacts are turned into queued messages per request / cron run
const QUEUE_BUILD_CHUNK = 500;
// Bulk operations above this many customers are handed to cron (bulk_jobs) instead of the HTTP request
const BULK_SYNC_LIMIT = 5000;

const PER_PAGE_OPTIONS = [25, 50, 100];

const BLOCKED_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'exe', 'sh', 'bat', 'cmd',
    'com', 'cgi', 'pl', 'py', 'js', 'jsp', 'asp', 'aspx', 'htaccess', 'htm', 'html', 'svg', 'msi', 'vbs',
    'ps1', 'jar', 'scr',
];

// Allowed extension => acceptable detected MIME types
const ALLOWED_ATTACHMENT_TYPES = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/octet-stream', 'application/CDFV2'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'xls' => ['application/vnd.ms-excel', 'application/octet-stream', 'application/CDFV2'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream', 'application/CDFV2'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
    'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
    'txt' => ['text/plain'],
    'png' => ['image/png'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'gif' => ['image/gif'],
    'webp' => ['image/webp'],
    'zip' => ['application/zip', 'application/x-zip-compressed'],
];
