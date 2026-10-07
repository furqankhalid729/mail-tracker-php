<?php
/**
 * CSV import wizard: Upload → Map columns → Validate (scan) → Import (batched) → Summary.
 * The file is read in byte-offset batches, so large files never sit in memory.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

const IMPORT_BATCH = 500;
$state = $_SESSION['import'] ?? null;
if ($state && (int) $state['ws'] !== $ws) {
    $state = null;
}

function import_path(array $state): string
{
    return UPLOAD_PATH . '/' . $state['file'];
}

function import_to_utf8(array $row): array
{
    return array_map(fn($v) => mb_check_encoding((string) $v, 'UTF-8') ? trim((string) $v) : trim(mb_convert_encoding((string) $v, 'UTF-8', 'Windows-1252')), $row);
}

/** Read up to $limit rows starting at byte $offset. Returns [rows, nextOffset, eof]. */
function import_read_batch(array $state, int $offset, int $limit): array
{
    $fh = fopen(import_path($state), 'r');
    fseek($fh, $offset);
    $rows = [];
    while (count($rows) < $limit && ($row = fgetcsv($fh, 0, $state['delimiter'], '"', '\\')) !== false) {
        if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue; // blank line
        }
        $rows[] = import_to_utf8($row);
    }
    $next = ftell($fh);
    $eof = feof($fh);
    fclose($fh);
    return [$rows, $next, $eof];
}

/** Map a CSV row to [customerData, customFields]. */
function import_map_row(array $state, array $row): array
{
    $in = [];
    $custom = [];
    foreach ($state['mapping'] as $i => $target) {
        $val = $row[$i] ?? '';
        if ($target === '' || $val === '') {
            continue;
        }
        if (str_starts_with($target, 'custom:')) {
            $custom[substr($target, 7)] = mb_substr($val, 0, 1000);
        } else {
            $in[$target] = $val;
        }
    }
    if (empty($in['source']) && !empty($state['source'])) {
        $in['source'] = $state['source'];
    }
    return [customer_data_from_input($in), $custom];
}

/* ---------------------------------------------------------------------------
 * AJAX batch endpoints
 * ------------------------------------------------------------------------- */
if (is_post() && in_array(input('action'), ['scan', 'process'], true)) {
    verify_csrf();
    if (!$state || empty($state['mapping'])) {
        json_response(['ok' => false, 'error' => 'Import session expired. Start again.'], 400);
    }
    set_time_limit(60);
    $offset = max((int) input('offset', $state['data_offset']), (int) $state['data_offset']);
    if ($offset === (int) $state['data_offset']) {
        unset($_SESSION['import'][input('action') === 'scan' ? 'scan' : 'result']); // first batch: fresh counters
    }
    if (input('action') === 'process' && !is_file(import_path($state))) {
        json_response(['ok' => false, 'error' => 'This file was already imported. Start a new import.'], 400);
    }
    [$rows, $next, $eof] = import_read_batch($state, $offset, IMPORT_BATCH);

    $mapped = [];
    $emails = [];
    foreach ($rows as $row) {
        [$data, $custom] = import_map_row($state, $row);
        $mapped[] = [$data, $custom];
        if (is_valid_email($data['email'])) {
            $emails[] = $data['email'];
        }
    }
    $existing = [];
    if ($emails) {
        foreach (q_all('SELECT id, email, custom_fields FROM customers WHERE workspace_id = ? AND email IN (' . placeholders(array_unique($emails)) . ')', [$ws, ...array_unique($emails)]) as $r) {
            $existing[strtolower($r['email'])] ??= $r;
        }
    }

    if (input('action') === 'scan') {
        $s = $_SESSION['import']['scan'] ?? ['rows' => 0, 'valid' => 0, 'invalid' => 0, 'existing' => 0, 'new' => 0, 'file_duplicates' => 0, 'seen' => [], 'invalid_samples' => []];
        foreach ($mapped as [$data]) {
            $s['rows']++;
            if (!is_valid_email($data['email'])) {
                $s['invalid']++;
                if (count($s['invalid_samples']) < 5) {
                    $s['invalid_samples'][] = $data['email'] === '' ? '(empty email)' : $data['email'];
                }
                continue;
            }
            $s['valid']++;
            $h = substr(md5($data['email']), 0, 12);
            if (isset($s['seen'][$h])) {
                $s['file_duplicates']++;
                continue;
            }
            $s['seen'][$h] = 1;
            isset($existing[$data['email']]) ? $s['existing']++ : $s['new']++;
        }
        $_SESSION['import']['scan'] = $s;
        $public = $s;
        unset($public['seen']);
        json_response(['ok' => true, 'offset' => $next, 'done' => $eof || !$rows, 'stats' => $public, 'progress' => min(100, round($next / max(1, $state['size']) * 100))]);
    }

    // process
    $s = $_SESSION['import']['result'] ?? ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0];
    $tagIds = $state['tags'];
    $now = now();
    transaction(function () use ($mapped, &$existing, $state, $ws, $tagIds, $now, &$s) {
        foreach ($mapped as [$data, $custom]) {
            if (!is_valid_email($data['email'])) {
                $s['invalid']++;
                continue;
            }
            $match = $existing[$data['email']] ?? null;
            if ($match && $state['duplicate'] === 'skip') {
                $s['skipped']++;
                continue;
            }
            if ($match && $state['duplicate'] === 'update') {
                // Only overwrite with non-empty CSV values; merge custom fields
                $update = array_filter($data, fn($v) => $v !== null && $v !== '');
                $mergedCustom = array_merge(json_decode((string) $match['custom_fields'], true) ?: [], $custom);
                $update['custom_fields'] = $mergedCustom ? json_encode($mergedCustom, JSON_UNESCAPED_UNICODE) : null;
                $update['updated_at'] = $now;
                db_update('customers', $update, 'id = ? AND workspace_id = ?', [$match['id'], $ws]);
                $id = (int) $match['id'];
                $s['updated']++;
            } else {
                $id = db_insert('customers', $data + [
                    'workspace_id' => $ws,
                    'custom_fields' => $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE) : null,
                    'status' => 'active',
                    'crm_status' => 'New',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_activity_at' => $now,
                ]);
                $existing[$data['email']] = ['id' => $id, 'email' => $data['email'], 'custom_fields' => null];
                db_insert('activity_log', ['workspace_id' => $ws, 'customer_id' => $id, 'user_id' => user_id(), 'type' => 'imported', 'description' => 'Imported from CSV', 'created_at' => $now]);
                $s['imported']++;
            }
            foreach ($tagIds as $t) {
                q('INSERT IGNORE INTO customer_tags (customer_id, tag_id, workspace_id, created_at) VALUES (?, ?, ?, ?)', [$id, $t, $ws, $now]);
            }
        }
    });
    $_SESSION['import']['result'] = $s;
    $done = $eof || !$rows;
    if ($done) {
        @unlink(import_path($state));
        $_SESSION['import']['finished'] = true;
    }
    json_response(['ok' => true, 'offset' => $next, 'done' => $done, 'stats' => $s, 'progress' => min(100, round($next / max(1, $state['size']) * 100))]);
}

/* ---------------------------------------------------------------------------
 * Wizard steps (form posts)
 * ------------------------------------------------------------------------- */
if (is_post() && input('action') === 'upload') {
    verify_csrf();
    $f = $_FILES['csv'] ?? null;
    $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = $f && $f['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed. Check the file size (max ' . UPLOAD_LIMIT_MB . ' MB unless your host allows more).');
    } elseif (!in_array($ext, ['csv', 'txt'], true) || !in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv'], true)) {
        flash('error', 'Please upload a .csv file.');
    } else {
        if ($state && is_file(import_path($state))) {
            @unlink(import_path($state));
        }
        $rel = 'imports/' . $ws . '/' . random_token(16) . '.csv';
        upload_dir('imports/' . $ws);
        move_uploaded_file($f['tmp_name'], UPLOAD_PATH . '/' . $rel);

        $fh = fopen(UPLOAD_PATH . '/' . $rel, 'r');
        $first = (string) fgets($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        rewind($fh);
        if (fread($fh, 3) !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $headers = import_to_utf8(fgetcsv($fh, 0, $delimiter, '"', '\\') ?: []);
        $dataOffset = ftell($fh);
        fclose($fh);

        if (count($headers) < 1 || implode('', $headers) === '') {
            flash('error', 'Could not read a header row from that file.');
        } else {
            $_SESSION['import'] = [
                'ws' => $ws,
                'file' => $rel,
                'name' => basename((string) $f['name']),
                'size' => filesize(UPLOAD_PATH . '/' . $rel),
                'delimiter' => $delimiter,
                'headers' => $headers,
                'data_offset' => $dataOffset,
            ];
            redirect(url('customers/import.php', ['step' => 'map']));
        }
    }
    redirect('customers/import.php');
}

if (is_post() && input('action') === 'map' && $state) {
    verify_csrf();
    $mapping = [];
    $allowed = array_keys(CUSTOMER_FIELDS);
    foreach ($state['headers'] as $i => $h) {
        $target = (string) ($_POST['map'][$i] ?? '');
        if ($target === 'custom') {
            $key = trim(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $h), '_') ?: 'field_' . $i;
            $target = 'custom:' . mb_substr($key, 0, 50);
        } elseif (!in_array($target, $allowed, true)) {
            $target = '';
        }
        $mapping[$i] = $target;
    }
    if (!in_array('email', $mapping, true)) {
        flash('error', 'Map one column to Email — it is required.');
        redirect(url('customers/import.php', ['step' => 'map']));
    }
    $_SESSION['import']['mapping'] = $mapping;
    $_SESSION['import']['duplicate'] = in_array(input('duplicate'), ['skip', 'update', 'create'], true) ? input('duplicate') : 'skip';
    $_SESSION['import']['tags'] = owned_ids('tags', input_ids('tags'));
    $_SESSION['import']['source'] = mb_substr(trim((string) input('source')), 0, 100);
    unset($_SESSION['import']['scan'], $_SESSION['import']['result'], $_SESSION['import']['finished']);
    redirect(url('customers/import.php', ['step' => 'validate']));
}

if (input('reset') === '1') {
    if ($state && is_file(import_path($state))) {
        @unlink(import_path($state));
    }
    unset($_SESSION['import']);
    redirect('customers/import.php');
}

$step = $state ? (string) input('step', 'map') : 'upload';
if ($step === 'validate' && empty($state['mapping'])) {
    $step = 'map';
}
if (!empty($state['finished'])) {
    $step = 'validate';
}

$page_title = 'Import customers';
$active_nav = 'customers';
require __DIR__ . '/../includes/header.php';
$steps = ['upload' => 'Upload', 'map' => 'Map columns', 'validate' => 'Validate & import'];
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('customers/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Customers</a>
        <h1 class="page-title">Import customers</h1>
    </div>
    <?php if ($state): ?><a href="<?= e(url('customers/import.php', ['reset' => 1])) ?>" class="btn-ghost">Start over</a><?php endif; ?>
</div>

<ol class="mb-6 flex items-center gap-2 text-sm">
    <?php $reached = true; foreach ($steps as $k => $label): $isCurrent = $k === $step; ?>
        <li class="flex items-center gap-2">
            <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold <?= $isCurrent ? 'bg-indigo-600 text-white' : ($reached ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200 text-slate-500') ?>"><?= array_search($k, array_keys($steps)) + 1 ?></span>
            <span class="<?= $isCurrent ? 'font-semibold text-slate-900' : 'text-slate-500' ?>"><?= e($label) ?></span>
            <?php if ($k !== 'validate'): ?><span class="mx-2 h-px w-8 bg-slate-300"></span><?php endif; ?>
        </li>
    <?php if ($isCurrent) $reached = false; endforeach; ?>
</ol>

<?php if ($step === 'upload'): ?>
    <form method="post" enctype="multipart/form-data" class="card max-w-2xl p-6" x-data="{ name: '' }">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-300 px-6 py-12 text-center hover:border-indigo-400 hover:bg-indigo-50/30">
            <span class="text-slate-400"><?= icon('upload', 'h-10 w-10') ?></span>
            <span class="mt-3 text-sm font-medium text-slate-700" x-text="name || 'Choose a CSV file'"></span>
            <span class="mt-1 text-xs text-slate-500">Comma, semicolon or tab separated · first row must be headers · max <?= UPLOAD_LIMIT_MB ?> MB</span>
            <input type="file" name="csv" accept=".csv,text/csv,.txt" class="sr-only" required @change="name = $event.target.files[0]?.name">
        </label>
        <div class="mt-5 flex items-center justify-between">
            <p class="text-xs text-slate-500">Example headers: First Name, Last Name, Email, Company, Website</p>
            <button class="btn-primary">Upload &amp; continue</button>
        </div>
    </form>

<?php elseif ($step === 'map'):
    [$sample] = import_read_batch($state, (int) $state['data_offset'], 3);
    $guess = function (string $h): string {
        $n = strtolower(preg_replace('/[^a-z]/i', '', $h));
        $map = [
            'firstname' => 'first_name', 'first' => 'first_name', 'givenname' => 'first_name', 'fname' => 'first_name',
            'lastname' => 'last_name', 'last' => 'last_name', 'surname' => 'last_name', 'familyname' => 'last_name', 'lname' => 'last_name',
            'name' => 'full_name', 'fullname' => 'full_name', 'contactname' => 'full_name',
            'email' => 'email', 'emailaddress' => 'email', 'mail' => 'email', 'workemail' => 'email',
            'company' => 'company', 'companyname' => 'company', 'organization' => 'company', 'organisation' => 'company', 'account' => 'company',
            'title' => 'job_title', 'jobtitle' => 'job_title', 'position' => 'job_title', 'role' => 'job_title',
            'phone' => 'phone', 'phonenumber' => 'phone', 'mobile' => 'phone', 'telephone' => 'phone',
            'website' => 'website', 'url' => 'website', 'domain' => 'website', 'site' => 'website', 'companywebsite' => 'website',
            'country' => 'country', 'source' => 'source', 'leadsource' => 'source', 'notes' => 'notes', 'note' => 'notes',
        ];
        return $map[$n] ?? 'custom';
    };
    $mapping = $state['mapping'] ?? null;
    $allTags = workspace_tags($ws);
?>
    <form method="post" class="space-y-6">
        <?= csrf_field() ?><input type="hidden" name="action" value="map">
        <div class="card overflow-hidden">
            <div class="card-header"><div><h2 class="card-title">Map columns</h2><p class="text-xs text-slate-500"><?= e($state['name']) ?> · unmapped columns can be kept as custom fields</p></div></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>CSV column</th><th>Sample values</th><th class="w-64">Import as</th></tr></thead>
                    <tbody>
                    <?php foreach ($state['headers'] as $i => $h):
                        $sel = $mapping ? ($mapping[$i] === '' ? '' : (str_starts_with($mapping[$i], 'custom:') ? 'custom' : $mapping[$i])) : $guess($h); ?>
                        <tr>
                            <td class="font-medium text-slate-900"><?= e($h ?: '(column ' . ($i + 1) . ')') ?></td>
                            <td class="max-w-xs truncate text-xs text-slate-500"><?= e(implode(' · ', array_filter(array_map(fn($r) => $r[$i] ?? '', $sample)))) ?></td>
                            <td>
                                <select name="map[<?= $i ?>]" class="input !py-1.5">
                                    <option value="">— Don't import —</option>
                                    <?php foreach (CUSTOMER_FIELDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $sel === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                                    <option value="custom" <?= $sel === 'custom' ? 'selected' : '' ?>>Custom field “<?= e(mb_strimwidth($h, 0, 24, '…')) ?>”</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="card p-5">
                <h3 class="card-title">If the email already exists</h3>
                <div class="mt-3 space-y-2">
                    <?php foreach (['skip' => ['Skip duplicates', 'Keep the existing customer unchanged.'], 'update' => ['Update existing', 'Fill in non-empty values from the CSV and merge custom fields.'], 'create' => ['Create new', 'Add another customer with the same email.']] as $k => [$l, $d]): ?>
                        <label class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50/40">
                            <input type="radio" name="duplicate" value="<?= $k ?>" class="mt-0.5 text-indigo-600" <?= ($state['duplicate'] ?? 'skip') === $k ? 'checked' : '' ?>>
                            <span><span class="block text-sm font-medium"><?= e($l) ?></span><span class="text-xs text-slate-500"><?= e($d) ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card p-5">
                <h3 class="card-title">Tag imported customers</h3>
                <div class="mt-3 flex flex-wrap gap-2">
                    <?php foreach ($allTags as $t): ?>
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-slate-200 px-2.5 py-1 text-xs has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                            <input type="checkbox" class="checkbox !h-3.5 !w-3.5" name="tags[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $state['tags'] ?? [], true) ? 'checked' : '' ?>>
                            <span class="h-2 w-2 rounded-full" style="background: <?= e($t['color']) ?>"></span><?= e($t['name']) ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$allTags): ?><p class="text-sm text-slate-400">No tags yet — <a class="text-indigo-600" href="<?= e(url('tags/index.php')) ?>" target="_blank">create some</a> and reload.</p><?php endif; ?>
                </div>
                <label class="label mt-5" for="source">Source <span class="font-normal text-slate-400">(for rows without one)</span></label>
                <input class="input" id="source" name="source" value="<?= e($state['source'] ?? 'CSV import') ?>" maxlength="100">
            </div>
        </div>
        <div class="flex justify-end"><button class="btn-primary">Continue to validation</button></div>
    </form>

<?php else: ?>
    <div class="card max-w-3xl p-6" x-data="importRunner(<?= !empty($state['finished']) ? 'true' : 'false' ?>, <?= json_encode($state['result'] ?? null) ?>)" x-init="init()">
        <div class="flex items-center justify-between">
            <h2 class="card-title" x-text="phase === 'scan' ? 'Validating file…' : (phase === 'scanned' ? 'Validation complete' : (phase === 'import' ? 'Importing…' : 'Import complete'))"></h2>
            <span class="text-xs text-slate-500"><?= e($state['name']) ?> · duplicates: <b><?= e($state['duplicate']) ?></b></span>
        </div>
        <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-600 transition-all" :style="`width: ${progress}%`"></div></div>

        <template x-if="scan">
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Rows</div><div class="text-xl font-semibold" x-text="scan.rows.toLocaleString()"></div></div>
                <div class="rounded-lg bg-emerald-50 p-3"><div class="text-xs text-emerald-700">New customers</div><div class="text-xl font-semibold text-emerald-700" x-text="scan.new.toLocaleString()"></div></div>
                <div class="rounded-lg bg-sky-50 p-3"><div class="text-xs text-sky-700">Existing customers</div><div class="text-xl font-semibold text-sky-700" x-text="scan.existing.toLocaleString()"></div></div>
                <div class="rounded-lg bg-amber-50 p-3"><div class="text-xs text-amber-700">Duplicates in file</div><div class="text-xl font-semibold text-amber-700" x-text="scan.file_duplicates.toLocaleString()"></div></div>
                <div class="rounded-lg bg-red-50 p-3"><div class="text-xs text-red-700">Invalid emails</div><div class="text-xl font-semibold text-red-700" x-text="scan.invalid.toLocaleString()"></div></div>
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Valid rows</div><div class="text-xl font-semibold" x-text="scan.valid.toLocaleString()"></div></div>
            </div>
        </template>
        <template x-if="scan && scan.invalid_samples.length"><p class="mt-3 text-xs text-slate-500">Invalid examples: <span class="text-red-600" x-text="scan.invalid_samples.join(', ')"></span></p></template>

        <template x-if="result">
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg bg-emerald-50 p-3"><div class="text-xs text-emerald-700">Imported</div><div class="text-xl font-semibold text-emerald-700" x-text="result.imported.toLocaleString()"></div></div>
                <div class="rounded-lg bg-sky-50 p-3"><div class="text-xs text-sky-700">Updated</div><div class="text-xl font-semibold text-sky-700" x-text="result.updated.toLocaleString()"></div></div>
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Skipped</div><div class="text-xl font-semibold" x-text="result.skipped.toLocaleString()"></div></div>
                <div class="rounded-lg bg-red-50 p-3"><div class="text-xs text-red-700">Invalid</div><div class="text-xl font-semibold text-red-700" x-text="result.invalid.toLocaleString()"></div></div>
            </div>
        </template>

        <p x-show="error" x-text="error" class="mt-4 text-sm text-red-600"></p>
        <div class="mt-6 flex justify-end gap-2">
            <template x-if="phase === 'scanned'"><a href="<?= e(url('customers/import.php', ['step' => 'map'])) ?>" class="btn-secondary">Back to mapping</a></template>
            <template x-if="phase === 'scanned'"><button class="btn-primary" @click="runImport()" :disabled="!scan || scan.valid === 0">Import <span x-text="scan ? scan.valid.toLocaleString() : ''"></span> rows</button></template>
            <template x-if="phase === 'done'"><a href="<?= e(url('customers/import.php', ['reset' => 1])) ?>" class="btn-secondary">Import another file</a></template>
            <template x-if="phase === 'done'"><a href="<?= e(url('customers/index.php', ['sort' => 'created', 'dir' => 'desc'])) ?>" class="btn-primary">View customers</a></template>
        </div>
    </div>
    <script>
    function importRunner(finished, finalResult) {
        return {
            phase: finished ? 'done' : 'scan', progress: finished ? 100 : 0, scan: null, result: finalResult, error: '',
            init() { if (!finished) this.loop('scan', <?= (int) $state['data_offset'] ?>); },
            async loop(action, offset) {
                try {
                    const fd = new FormData();
                    fd.append('action', action); fd.append('offset', offset);
                    const data = await api(location.pathname, { method: 'POST', body: fd });
                    this.progress = data.progress;
                    if (action === 'scan') this.scan = data.stats; else this.result = data.stats;
                    if (!data.done) return this.loop(action, data.offset);
                    this.progress = 100;
                    this.phase = action === 'scan' ? 'scanned' : 'done';
                } catch (e) { this.error = e.message; }
            },
            runImport() { this.phase = 'import'; this.progress = 0; this.loop('process', <?= (int) $state['data_offset'] ?>); },
        };
    }
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
