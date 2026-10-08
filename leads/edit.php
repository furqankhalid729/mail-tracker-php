<?php
/** Add or edit a lead (leads.manage). Phones and emails are one per line or separated by ; */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('leads.manage');
$ws = ws_id();
$lead = input_int('id') ? find_lead_or_404(input_int('id')) : null;
$statuses = lead_statuses($ws);
$members = workspace_users();

if (is_post()) {
    verify_csrf();
    $data = lead_data_from_input($_POST);
    $phones = lead_parse_phones((string) input('phones'));
    $emails = lead_parse_emails((string) input('emails'));
    $data['name'] = lead_fallback_name($data, $emails, $phones);
    $data['emails'] = $emails ? mb_substr(implode('; ', $emails), 0, 1000) : null;
    $data['source'] = ($s = trim((string) input('source'))) !== '' ? mb_substr($s, 0, 100) : null;
    $statusId = input_int('status_id');
    $data['status_id'] = isset($statuses[$statusId]) ? $statusId : null;
    $assignee = input_int('assigned_to');
    $data['assigned_to'] = $assignee && is_workspace_user($assignee) ? $assignee : null;

    if ($data['name'] === '') {
        keep_old_input();
        flash_errors(['name' => 'Enter a name, or at least a phone, email or website.']);
        redirect(url('leads/edit.php', ['id' => $lead['id'] ?? null]));
    }
    $now = now();
    $id = transaction(function () use ($lead, $data, $phones, $ws, $now, $statuses) {
        if ($lead) {
            $id = (int) $lead['id'];
            db_update('leads', $data + ['updated_at' => $now], 'id = ? AND workspace_id = ?', [$id, $ws]);
            if ((int) $lead['status_id'] !== (int) $data['status_id'] && $data['status_id']) {
                log_lead_activity($id, 'status', null, $lead['status_id'] ? ($statuses[$lead['status_id']]['name'] ?? null) : null, $statuses[$data['status_id']]['name']);
            }
            if ((int) $lead['assigned_to'] !== (int) $data['assigned_to']) {
                log_lead_activity($id, 'assigned', null, null, $data['assigned_to'] ? (string) q_val('SELECT name FROM users WHERE id = ?', [$data['assigned_to']]) : 'Unassigned');
            }
            log_lead_activity($id, 'edited');
        } else {
            $id = db_insert('leads', $data + ['workspace_id' => $ws, 'created_by' => user_id(), 'created_at' => $now, 'updated_at' => $now]);
            log_lead_activity($id, 'created');
        }
        lead_set_phones($ws, $id, $phones);
        return $id;
    });
    leads_link_calls($ws, [$id]);
    clear_old_input();
    flash('success', $lead ? 'Lead saved.' : 'Lead added.');
    redirect(url('leads/view.php', ['id' => $id]));
}

$errors = get_errors();
$v = fn(string $k, mixed $default = '') => old($k, $lead[$k] ?? $default);
$phoneText = $lead ? implode("\n", array_column(phones_for_leads([(int) $lead['id']])[(int) $lead['id']] ?? [], 'phone_number')) : '';

$page_title = $lead ? 'Edit lead' : 'Add lead';
$active_nav = 'leads';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url($lead ? 'leads/view.php' : 'leads/index.php', ['id' => $lead['id'] ?? null])) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= $lead ? e($lead['name']) : 'Leads' ?></a>
        <h1 class="page-title"><?= $lead ? 'Edit lead' : 'Add lead' ?></h1>
    </div>
</div>

<form method="post" class="card max-w-3xl">
    <?= csrf_field() ?>
    <div class="card-body grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="label" for="name">Business name</label>
            <input class="input" id="name" name="name" value="<?= e($v('name')) ?>" maxlength="255">
            <?= field_error($errors, 'name') ?>
        </div>
        <div>
            <label class="label" for="phones">Phones</label>
            <textarea class="input font-mono" id="phones" name="phones" rows="3" placeholder="(931) 647-0586"><?= e(old('phones', $phoneText)) ?></textarea>
            <p class="help">One per line. Zoom calls to any of them count as attempts.</p>
        </div>
        <div>
            <label class="label" for="emails">Emails</label>
            <textarea class="input" id="emails" name="emails" rows="3"><?= e(old('emails', str_replace('; ', "\n", (string) ($lead['emails'] ?? '')))) ?></textarea>
        </div>
        <div>
            <label class="label" for="website">Website</label>
            <input class="input" id="website" name="website" value="<?= e($v('website')) ?>" placeholder="example.com">
        </div>
        <div>
            <label class="label" for="maps_url">Google Maps URL</label>
            <input class="input" id="maps_url" name="maps_url" value="<?= e($v('maps_url')) ?>">
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="address">Address</label>
            <input class="input" id="address" name="address" value="<?= e($v('address')) ?>" maxlength="500">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="rating">Rating</label><input class="input" id="rating" name="rating" value="<?= e($v('rating')) ?>" inputmode="decimal" placeholder="4.7"></div>
            <div><label class="label" for="reviews_count">Reviews</label><input class="input" id="reviews_count" name="reviews_count" value="<?= e($v('reviews_count')) ?>" inputmode="numeric"></div>
        </div>
        <div>
            <label class="label" for="source">Source / list</label>
            <input class="input" id="source" name="source" value="<?= e($v('source')) ?>" maxlength="100">
        </div>
        <div>
            <label class="label" for="status_id">Status</label>
            <select class="input" id="status_id" name="status_id">
                <?php $cur = (int) $v('status_id', lead_default_status_id($ws)); foreach ($statuses as $sid => $s): ?><option value="<?= (int) $sid ?>" <?= $cur === (int) $sid ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="label" for="assigned_to">Assigned to</label>
            <select class="input" id="assigned_to" name="assigned_to">
                <option value="0">Unassigned</option>
                <?php $cur = (int) $v('assigned_to', 0); foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $cur === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?><?= $m['role'] === 'closer' ? '' : ' (' . e(role_label($m['role'])) . ')' ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="notes">Notes</label>
            <textarea class="input" id="notes" name="notes" rows="3"><?= e($v('notes')) ?></textarea>
        </div>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
        <a href="<?= e(url($lead ? 'leads/view.php' : 'leads/index.php', ['id' => $lead['id'] ?? null])) ?>" class="btn-ghost">Cancel</a>
        <button class="btn-primary"><?= $lead ? 'Save lead' : 'Add lead' ?></button>
    </div>
</form>
<?php require __DIR__ . '/../includes/footer.php';
