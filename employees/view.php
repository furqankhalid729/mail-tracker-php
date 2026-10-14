<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('employees.manage');
$ws = ws_id();
$emp = find_employee_or_404(input_int('id'));
$eid = (int) $emp['id'];
$isCurrent = in_array($emp['status'], EMPLOYEE_CURRENT_STATUSES, true);

$documents = q_all(
    "SELECT d.*, u.name uploader_name FROM employee_documents d LEFT JOIN users u ON u.id = d.uploaded_by
     WHERE d.employee_id = ? AND d.workspace_id = ?
     ORDER BY FIELD(d.doc_type, 'contract', 'offer_letter', 'id_document', 'resume', 'certificate', 'payslip', 'other'), d.created_at DESC",
    [$eid, $ws]
);
$errors = get_errors();

$page_title = $emp['full_name'];
$active_nav = 'employees';
require __DIR__ . '/../includes/header.php';

$row = function (string $label, ?string $value, bool $raw = false) {
    $shown = $value === null || $value === '' ? '<span class="text-slate-400">—</span>' : ($raw ? $value : e($value));
    echo '<div class="flex items-start justify-between gap-4 px-5 py-3"><dt class="shrink-0 text-slate-500">' . e($label) . '</dt><dd class="min-w-0 break-words text-right text-slate-800">' . $shown . '</dd></div>';
};
$date = fn(?string $d) => $d ? format_dt($d, 'M j, Y') : null;
?>
<div class="page-header">
    <div class="flex min-w-0 items-center gap-4">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-base font-semibold <?= e(avatar_color($emp['full_name'])) ?>"><?= e(user_initials($emp['full_name'])) ?></span>
        <div class="min-w-0">
            <a href="<?= e(url('employees/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Employees</a>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="page-title"><?= e($emp['full_name']) ?></h1>
                <?= employee_status_badge($emp['status']) ?>
                <?php if ($emp['employee_code']): ?><span class="text-sm text-slate-400">#<?= e($emp['employee_code']) ?></span><?php endif; ?>
            </div>
            <p class="page-subtitle"><?= e(implode(' · ', array_filter([$emp['job_title'], $emp['department'], EMPLOYEE_TYPES[$emp['employment_type']] ?? null]))) ?></p>
        </div>
    </div>
    <div class="flex gap-2">
        <a href="<?= e(url('employees/edit.php', ['id' => $eid])) ?>" class="btn-secondary"><?= icon('pencil', 'h-4 w-4') ?> Edit</a>
        <form method="post" action="<?= e(url('employees/delete.php')) ?>" data-confirm="Delete <?= e($emp['full_name']) ?> and all their documents? To keep the record, set the status to “Left” instead." data-confirm-button="Delete">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $eid ?>">
            <button class="btn-secondary text-red-600" aria-label="Delete employee" title="Delete employee"><?= icon('trash', 'h-4 w-4') ?></button>
        </form>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Contracts &amp; documents</h2><span class="text-xs text-slate-400"><?= count($documents) ?> item<?= count($documents) === 1 ? '' : 's' ?></span></div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($documents as $d): ?>
                    <li class="flex items-start gap-3 px-5 py-3">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg <?= $d['doc_type'] === 'contract' ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-100 text-slate-500' ?>"><?= icon($d['storage_path'] ? 'paperclip' : 'link', 'h-4 w-4') ?></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-slate-900"><?= e($d['title']) ?></span>
                                <span class="badge badge-slate"><?= e(EMPLOYEE_DOC_TYPES[$d['doc_type']] ?? $d['doc_type']) ?></span>
                                <?= employee_doc_expiry_label($d['expires_at']) ?>
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                <?php if ($d['storage_path']): ?>
                                    <a class="inline-flex items-center gap-1 font-medium text-indigo-600 hover:text-indigo-700" href="<?= e(url('employees/file.php', ['id' => $d['id']])) ?>" target="_blank" rel="noopener"><?= icon('eye', 'h-3.5 w-3.5') ?> Open</a>
                                    <a class="inline-flex items-center gap-1 font-medium text-indigo-600 hover:text-indigo-700" href="<?= e(url('employees/file.php', ['id' => $d['id'], 'download' => 1])) ?>"><?= icon('download', 'h-3.5 w-3.5') ?> <?= e($d['filename']) ?> (<?= e(human_size((int) $d['size'])) ?>)</a>
                                <?php endif; ?>
                                <?php if ($d['link_url']): ?>
                                    <a class="inline-flex max-w-xs items-center gap-1 truncate font-medium text-indigo-600 hover:text-indigo-700" href="<?= e($d['link_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('link', 'h-3.5 w-3.5') ?> <?= e(parse_url($d['link_url'], PHP_URL_HOST) ?: $d['link_url']) ?></a>
                                <?php endif; ?>
                                <span>Added by <?= e($d['uploader_name'] ?: 'a former member') ?> · <?= e(time_ago($d['created_at'])) ?></span>
                            </div>
                            <?php if ($d['notes']): ?><p class="mt-1 text-sm text-slate-600"><?= e($d['notes']) ?></p><?php endif; ?>
                        </div>
                        <form method="post" action="<?= e(url('employees/documents.php')) ?>" data-confirm="Delete “<?= e($d['title']) ?>”?" data-confirm-button="Delete">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <button class="btn-icon text-slate-400 hover:text-red-600" aria-label="Delete document" title="Delete document"><?= icon('trash', 'h-4 w-4') ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
                <?php if (!$documents): ?><li class="px-5 py-8 text-center text-sm text-slate-400">No contract or documents yet.</li><?php endif; ?>
            </ul>

            <form method="post" action="<?= e(url('employees/documents.php')) ?>" enctype="multipart/form-data" class="border-t border-slate-200 bg-slate-50/60 p-5" x-data="{ open: <?= $documents && !$errors ? 'false' : 'true' ?> }">
                <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="employee_id" value="<?= $eid ?>">
                <button type="button" class="btn-secondary" x-show="!open" @click="open = true"><?= icon('upload', 'h-4 w-4') ?> Add document</button>
                <div x-show="open" x-cloak class="space-y-4">
                    <h3 class="text-sm font-semibold text-slate-900">Add a document</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label" for="doc_type">Type</label>
                            <select class="input" id="doc_type" name="doc_type">
                                <?php foreach (EMPLOYEE_DOC_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= old('doc_type', $documents ? 'other' : 'contract') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="label" for="doc_title">Title</label>
                            <input class="input" id="doc_title" name="title" value="<?= e(old('title')) ?>" maxlength="200" placeholder="Defaults to the type, e.g. “Contract”">
                            <?= field_error($errors, 'title') ?>
                        </div>
                        <div>
                            <label class="label" for="doc_file">File</label>
                            <input class="input" type="file" id="doc_file" name="file" accept="<?= e('.' . implode(',.', array_keys(ALLOWED_ATTACHMENT_TYPES))) ?>">
                            <p class="help">Up to <?= UPLOAD_LIMIT_MB ?> MB.</p>
                            <?= field_error($errors, 'file') ?>
                        </div>
                        <div>
                            <label class="label" for="doc_link">…or link</label>
                            <input class="input" type="url" id="doc_link" name="link_url" value="<?= e(old('link_url')) ?>" maxlength="1000" placeholder="https://drive.google.com/…">
                            <?= field_error($errors, 'link_url') ?>
                        </div>
                        <div>
                            <label class="label" for="doc_expires">Expiry / renewal date</label>
                            <input class="input" type="date" id="doc_expires" name="expires_at" value="<?= e(old('expires_at')) ?>">
                            <?= field_error($errors, 'expires_at') ?>
                        </div>
                        <div>
                            <label class="label" for="doc_notes">Notes</label>
                            <input class="input" id="doc_notes" name="notes" value="<?= e(old('notes')) ?>" maxlength="500" placeholder="Signed copy, 1-year term…">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <?php if ($documents): ?><button type="button" class="btn-ghost" @click="open = false">Cancel</button><?php endif; ?>
                        <button class="btn-primary"><?= icon('upload', 'h-4 w-4') ?> Save document</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Personal details</h2></div>
            <dl class="divide-y divide-slate-100 text-sm">
                <?php
                $row('Email', $emp['email'] ? '<a class="text-indigo-600 hover:underline" href="mailto:' . e($emp['email']) . '">' . e($emp['email']) . '</a>' : null, true);
                $row('Phone', $emp['phone'] ? '<a class="text-indigo-600 hover:underline" href="tel:' . e($emp['phone']) . '">' . e($emp['phone']) . '</a>' : null, true);
                $row('Date of birth', $date($emp['date_of_birth']));
                $row('National ID', $emp['national_id']);
                $row('Address', $emp['address'] ? nl2br(e($emp['address'])) : null, true);
                $row('Emergency contact', trim(($emp['emergency_contact_name'] ?? '') . ($emp['emergency_contact_phone'] ? ' · ' . $emp['emergency_contact_phone'] : ''), ' ·'));
                ?>
            </dl>
        </div>

        <?php if ($emp['notes']): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Notes</h2></div>
            <div class="card-body whitespace-pre-line text-sm leading-relaxed text-slate-700"><?= e($emp['notes']) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Employment</h2></div>
            <dl class="divide-y divide-slate-100 text-sm">
                <?php
                $row('Status', employee_status_badge($emp['status']), true);
                $row('Type', EMPLOYEE_TYPES[$emp['employment_type']] ?? null);
                $row('Joined', $date($emp['join_date']));
                if ($emp['leave_date']) {
                    $row($emp['status'] === 'notice_period' ? 'Last working day' : 'Left on', $date($emp['leave_date']));
                }
                $row($isCurrent ? 'With us for' : 'Worked for', $emp['join_date'] ? employee_tenure($emp['join_date'], $isCurrent ? null : $emp['leave_date']) : null);
                if ($emp['leave_reason']) {
                    $row('Reason for leaving', $emp['leave_reason']);
                }
                $row('CRM account', $emp['user_id'] ? e($emp['user_name']) . '<span class="block text-xs text-slate-500">' . e($emp['user_email']) . '</span>' : null, true);
                ?>
            </dl>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Pay</h2></div>
            <dl class="divide-y divide-slate-100 text-sm">
                <?php
                $row('Monthly salary', $emp['salary'] !== null ? money($emp['salary'], $emp['salary_currency'] ?: APP_CURRENCY) : null);
                $row('Bank details', $emp['bank_details']);
                ?>
            </dl>
        </div>
        <p class="px-1 text-xs text-slate-400">Added by <?= e($emp['creator_name'] ?: 'a former member') ?> · <?= e(format_dt($emp['created_at'], 'M j, Y')) ?> · updated <?= e(time_ago($emp['updated_at'])) ?></p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
