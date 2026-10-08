<?php
/**
 * Lead import wizard: Upload → Map columns + assign → Import (batched over AJAX) → Summary.
 * Built for scraped lists (Google Maps exports, website/email/phone sheets). Unmapped columns are kept as extra fields.
 * Duplicates: a row whose phone already belongs to a lead (or, without phones, the same name + website) is skipped.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('leads.manage');
$ws = ws_id();
$self = 'leads/import.php';

const LEAD_IMPORT_BATCH = 300;
$state = $_SESSION['lead_import'] ?? null;
if ($state && (int) $state['ws'] !== $ws) {
    $state = null;
}
$path = fn(array $s) => UPLOAD_PATH . '/' . $s['file'];

/* ---------------------------------------------------------------------------
 * AJAX batch endpoint
 * ------------------------------------------------------------------------- */
if (is_post() && input('action') === 'process') {
    verify_csrf();
    if (!$state || empty($state['mapping'])) {
        json_response(['ok' => false, 'error' => 'Import session expired. Start again.'], 400);
    }
    if (!is_file($path($state))) {
        json_response(['ok' => false, 'error' => 'This file was already imported. Start a new import.'], 400);
    }
    set_time_limit(60);
    $offset = max((int) input('offset', $state['data_offset']), (int) $state['data_offset']);
    if ($offset === (int) $state['data_offset']) {
        unset($_SESSION['lead_import']['result']);
    }
    [$rows, $next, $eof] = csv_read_batch($path($state), $state['delimiter'], $offset, LEAD_IMPORT_BATCH);

    $s = $_SESSION['lead_import']['result'] ?? ['rows' => 0, 'imported' => 0, 'duplicates' => 0, 'empty' => 0];
    $newIds = [];
    $now = now();
    transaction(function () use ($rows, $state, $ws, $now, &$s, &$newIds) {
        foreach ($rows as $row) {
            $s['rows']++;
            $in = [];
            $phoneVals = [];
            $emailVals = [];
            $extra = [];
            foreach ($state['mapping'] as $i => $target) {
                $val = trim((string) ($row[$i] ?? ''));
                if ($target === '' || $val === '') {
                    continue;
                }
                if ($target === 'phones') {
                    $phoneVals[] = $val;
                } elseif ($target === 'emails') {
                    $emailVals[] = $val;
                } elseif (str_starts_with($target, 'extra:')) {
                    $extra[substr($target, 6)] = mb_substr($val, 0, 2000);
                } else {
                    $in[$target] = isset($in[$target]) ? $in[$target] . ' ' . $val : $val;
                }
            }
            $data = lead_data_from_input($in);
            $phones = lead_parse_phones(...$phoneVals);
            $emails = lead_parse_emails(...$emailVals);
            $data['name'] = lead_fallback_name($data, $emails, $phones);
            if ($data['name'] === '') {
                $s['empty']++;
                continue;
            }
            if ($state['duplicate'] === 'skip' && lead_find_duplicate($ws, $data['name'], $data['website'], $phones)) {
                $s['duplicates']++;
                continue;
            }
            $id = db_insert('leads', $data + [
                'workspace_id' => $ws,
                'assigned_to' => $state['assigned_to'],
                'status_id' => $state['status_id'],
                'emails' => $emails ? mb_substr(implode('; ', $emails), 0, 1000) : null,
                'source' => $state['source'] ?: null,
                'extra_fields' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
                'created_by' => user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            lead_set_phones($ws, $id, $phones);
            log_lead_activity($id, 'imported', null, null, $state['name']);
            $newIds[] = $id;
            $s['imported']++;
        }
    });
    // Zoom calls already synced for these numbers count straight away
    leads_link_calls($ws, $newIds);

    $_SESSION['lead_import']['result'] = $s;
    $done = $eof || !$rows;
    if ($done) {
        @unlink($path($state));
        $_SESSION['lead_import']['finished'] = true;
    }
    json_response(['ok' => true, 'offset' => $next, 'done' => $done, 'stats' => $s, 'progress' => min(100, round($next / max(1, $state['size']) * 100))]);
}

/* ---------------------------------------------------------------------------
 * Wizard steps (form posts)
 * ------------------------------------------------------------------------- */
if (is_post() && input('action') === 'upload') {
    verify_csrf();
    if ($state && is_file($path($state))) {
        @unlink($path($state));
    }
    $upload = csv_store_upload($_FILES['csv'] ?? null, $ws);
    if (isset($upload['error'])) {
        flash('error', $upload['error']);
        redirect($self);
    }
    $_SESSION['lead_import'] = ['ws' => $ws] + $upload;
    redirect(url($self, ['step' => 'map']));
}

if (is_post() && input('action') === 'map' && $state) {
    verify_csrf();
    $mapping = [];
    foreach ($state['headers'] as $i => $h) {
        $target = (string) ($_POST['map'][$i] ?? '');
        if ($target === 'extra') {
            $target = 'extra:' . lead_extra_key((string) $h, $i);
        } elseif (!isset(LEAD_FIELDS[$target])) {
            $target = '';
        }
        $mapping[$i] = $target;
    }
    if (!array_intersect(['name', 'phones'], $mapping)) {
        flash('error', 'Map at least one column to Business name or Phone(s).');
        redirect(url($self, ['step' => 'map']));
    }
    $statuses = lead_statuses($ws);
    $assignee = input_int('assigned_to');
    $_SESSION['lead_import'] = array_merge($_SESSION['lead_import'], [
        'mapping' => $mapping,
        'duplicate' => input('duplicate') === 'import' ? 'import' : 'skip',
        'assigned_to' => $assignee && is_workspace_user($assignee) ? $assignee : null,
        'status_id' => isset($statuses[input_int('status_id')]) ? input_int('status_id') : lead_default_status_id($ws),
        'source' => mb_substr(trim((string) input('source')), 0, 100),
    ]);
    unset($_SESSION['lead_import']['result'], $_SESSION['lead_import']['finished']);
    redirect(url($self, ['step' => 'run']));
}

if (input('reset') === '1') {
    if ($state && is_file($path($state))) {
        @unlink($path($state));
    }
    unset($_SESSION['lead_import']);
    redirect($self);
}

$step = $state ? (string) input('step', 'map') : 'upload';
if ($step === 'run' && empty($state['mapping'])) {
    $step = 'map';
}
if (!empty($state['finished'])) {
    $step = 'run';
}

$page_title = 'Import leads';
$active_nav = 'leads';
require __DIR__ . '/../includes/header.php';
$steps = ['upload' => 'Upload', 'map' => 'Map & assign', 'run' => 'Import'];
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('leads/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Leads</a>
        <h1 class="page-title">Import leads</h1>
    </div>
    <?php if ($state): ?><a href="<?= e(url($self, ['reset' => 1])) ?>" class="btn-ghost">Start over</a><?php endif; ?>
</div>

<ol class="mb-6 flex items-center gap-2 text-sm">
    <?php $reached = true; foreach ($steps as $k => $label): $isCurrent = $k === $step; ?>
        <li class="flex items-center gap-2">
            <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold <?= $isCurrent ? 'bg-indigo-600 text-white' : ($reached ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200 text-slate-500') ?>"><?= array_search($k, array_keys($steps)) + 1 ?></span>
            <span class="<?= $isCurrent ? 'font-semibold text-slate-900' : 'text-slate-500' ?>"><?= e($label) ?></span>
            <?php if ($k !== 'run'): ?><span class="mx-2 h-px w-8 bg-slate-300"></span><?php endif; ?>
        </li>
    <?php if ($isCurrent) $reached = false; endforeach; ?>
</ol>

<?php if ($step === 'upload'): ?>
    <form method="post" enctype="multipart/form-data" class="card max-w-2xl p-6" x-data="{ name: '' }">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-300 px-6 py-12 text-center hover:border-indigo-400 hover:bg-indigo-50/30">
            <span class="text-slate-400"><?= icon('upload', 'h-10 w-10') ?></span>
            <span class="mt-3 text-sm font-medium text-slate-700" x-text="name || 'Choose a CSV file'"></span>
            <span class="mt-1 text-xs text-slate-500">Google Sheets: File → Download → Comma-separated values · first row must be headers · max <?= UPLOAD_LIMIT_MB ?> MB</span>
            <input type="file" name="csv" accept=".csv,text/csv,.txt" class="sr-only" required @change="name = $event.target.files[0]?.name">
        </label>
        <div class="mt-5 flex items-center justify-between">
            <p class="text-xs text-slate-500">Works with Google Maps exports (name, phone, website, fullAddress, rating…) or any Website / Email / Phones sheet.</p>
            <button class="btn-primary shrink-0">Upload &amp; continue</button>
        </div>
    </form>

<?php elseif ($step === 'map'):
    [$sample] = csv_read_batch($path($state), $state['delimiter'], (int) $state['data_offset'], 3);
    $guess = function (string $h): string {
        $n = strtolower(preg_replace('/[^a-z]/i', '', $h));
        $map = [
            'name' => 'name', 'businessname' => 'name', 'company' => 'name', 'companyname' => 'name', 'title' => 'name', 'business' => 'name',
            'phone' => 'phones', 'phones' => 'phones', 'phonenumber' => 'phones', 'phonenumbers' => 'phones', 'telephone' => 'phones', 'mobile' => 'phones', 'tel' => 'phones',
            'email' => 'emails', 'emails' => 'emails', 'emailaddress' => 'emails', 'mail' => 'emails',
            'website' => 'website', 'url' => 'website', 'site' => 'website', 'domain' => 'website', 'web' => 'website',
            'address' => 'address', 'fulladdress' => 'address', 'streetaddress' => 'address', 'location' => 'address',
            'rating' => 'rating', 'stars' => 'rating', 'totalscore' => 'rating',
            'reviews' => 'reviews_count', 'reviewscount' => 'reviews_count', 'reviewcount' => 'reviews_count',
            'googlemapsurl' => 'maps_url', 'mapsurl' => 'maps_url', 'mapurl' => 'maps_url', 'googlemaps' => 'maps_url', 'placeurl' => 'maps_url',
            'notes' => 'notes', 'note' => 'notes',
        ];
        return $map[$n] ?? 'extra';
    };
    $mapping = $state['mapping'] ?? null;
    $members = workspace_users();
    usort($members, fn($a, $b) => [$a['role'] !== 'closer', $a['name']] <=> [$b['role'] !== 'closer', $b['name']]);
    $statuses = lead_statuses($ws);
?>
    <form method="post" class="space-y-6">
        <?= csrf_field() ?><input type="hidden" name="action" value="map">
        <div class="card overflow-hidden">
            <div class="card-header"><div><h2 class="card-title">Map columns</h2><p class="text-xs text-slate-500"><?= e($state['name']) ?> · several columns can go to Phone(s) or Email(s) · other columns are kept and filterable</p></div></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>CSV column</th><th>Sample values</th><th class="w-64">Import as</th></tr></thead>
                    <tbody>
                    <?php foreach ($state['headers'] as $i => $h):
                        $sel = $mapping ? ($mapping[$i] === '' ? '' : (str_starts_with($mapping[$i], 'extra:') ? 'extra' : $mapping[$i])) : $guess((string) $h); ?>
                        <tr>
                            <td class="font-medium text-slate-900"><?= e($h ?: '(column ' . ($i + 1) . ')') ?></td>
                            <td class="max-w-xs truncate text-xs text-slate-500"><?= e(implode(' · ', array_filter(array_map(fn($r) => mb_strimwidth($r[$i] ?? '', 0, 60, '…'), $sample)))) ?></td>
                            <td>
                                <select name="map[<?= $i ?>]" class="input !py-1.5">
                                    <option value="">— Don't import —</option>
                                    <?php foreach (LEAD_FIELDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $sel === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                                    <option value="extra" <?= $sel === 'extra' ? 'selected' : '' ?>>Keep as “<?= e(mb_strimwidth((string) $h, 0, 24, '…')) ?>”</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="card p-5 space-y-4">
                <h3 class="card-title">Assign &amp; label</h3>
                <div>
                    <label class="label" for="assigned_to">Assign all imported leads to</label>
                    <select class="input" id="assigned_to" name="assigned_to">
                        <option value="0">Nobody yet (assign later from the list)</option>
                        <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) ($state['assigned_to'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?><?= $m['role'] === 'closer' ? '' : ' (' . e(role_label($m['role'])) . ')' ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="status_id">Starting status</label>
                    <select class="input" id="status_id" name="status_id">
                        <?php foreach ($statuses as $sid => $s): ?><option value="<?= (int) $sid ?>" <?= (int) ($state['status_id'] ?? 0) === (int) $sid ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="source">List name</label>
                    <input class="input" id="source" name="source" value="<?= e($state['source'] ?? preg_replace('/\.(csv|txt)$/i', '', $state['name'])) ?>" maxlength="100">
                    <p class="help">Shown as “Source / list” and usable as a filter.</p>
                </div>
            </div>
            <div class="card p-5">
                <h3 class="card-title">Duplicates</h3>
                <div class="mt-3 space-y-2">
                    <?php foreach (['skip' => ['Skip duplicates', 'Skip a row if any of its phone numbers already belongs to a lead (or, without phones, the same name and website).'], 'import' => ['Import everything', 'Add every row, even if the number is already in your leads.']] as $k => [$l, $d]): ?>
                        <label class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50/40">
                            <input type="radio" name="duplicate" value="<?= $k ?>" class="mt-0.5 text-indigo-600" <?= ($state['duplicate'] ?? 'skip') === $k ? 'checked' : '' ?>>
                            <span><span class="block text-sm font-medium"><?= e($l) ?></span><span class="text-xs text-slate-500"><?= e($d) ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="flex justify-end"><button class="btn-primary">Continue</button></div>
    </form>

<?php else:
    $assigneeName = $state['assigned_to'] ? (string) q_val('SELECT name FROM users WHERE id = ?', [$state['assigned_to']]) : 'nobody';
?>
    <div class="card max-w-3xl p-6" x-data="leadImport(<?= !empty($state['finished']) ? 'true' : 'false' ?>, <?= e(json_encode($state['result'] ?? null)) ?>)">
        <div class="flex items-center justify-between">
            <h2 class="card-title" x-text="phase === 'ready' ? 'Ready to import' : (phase === 'import' ? 'Importing…' : 'Import complete')"></h2>
            <span class="text-xs text-slate-500"><?= e($state['name']) ?> · assigned to <b><?= e($assigneeName) ?></b> · duplicates: <b><?= e($state['duplicate']) ?></b></span>
        </div>
        <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-600 transition-all" :style="`width: ${progress}%`"></div></div>

        <template x-if="result">
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Rows read</div><div class="text-xl font-semibold" x-text="result.rows.toLocaleString()"></div></div>
                <div class="rounded-lg bg-emerald-50 p-3"><div class="text-xs text-emerald-700">Imported</div><div class="text-xl font-semibold text-emerald-700" x-text="result.imported.toLocaleString()"></div></div>
                <div class="rounded-lg bg-amber-50 p-3"><div class="text-xs text-amber-700">Duplicates skipped</div><div class="text-xl font-semibold text-amber-700" x-text="result.duplicates.toLocaleString()"></div></div>
                <div class="rounded-lg bg-red-50 p-3"><div class="text-xs text-red-700">Empty rows</div><div class="text-xl font-semibold text-red-700" x-text="result.empty.toLocaleString()"></div></div>
            </div>
        </template>

        <p x-show="error" x-text="error" class="mt-4 text-sm text-red-600"></p>
        <div class="mt-6 flex justify-end gap-2">
            <template x-if="phase === 'ready'"><a href="<?= e(url($self, ['step' => 'map'])) ?>" class="btn-secondary">Back to mapping</a></template>
            <template x-if="phase === 'ready'"><button class="btn-primary" @click="run()">Start import</button></template>
            <template x-if="phase === 'done'"><a href="<?= e(url($self, ['reset' => 1])) ?>" class="btn-secondary">Import another file</a></template>
            <template x-if="phase === 'done'"><a href="<?= e(url('leads/index.php', ['source' => $state['source'] ?: null])) ?>" class="btn-primary">View leads</a></template>
        </div>
    </div>
    <script>
    function leadImport(finished, finalResult) {
        return {
            phase: finished ? 'done' : 'ready', progress: finished ? 100 : 0, result: finalResult, error: '',
            async loop(offset) {
                try {
                    const fd = new FormData();
                    fd.append('action', 'process'); fd.append('offset', offset);
                    const data = await api(location.pathname, { method: 'POST', body: fd });
                    this.progress = data.progress;
                    this.result = data.stats;
                    if (!data.done) return this.loop(data.offset);
                    this.progress = 100;
                    this.phase = 'done';
                } catch (e) { this.error = e.message; }
            },
            run() { this.phase = 'import'; this.error = ''; this.loop(<?= (int) $state['data_offset'] ?>); },
        };
    }
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
