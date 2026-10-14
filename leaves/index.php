<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$me = user_id();
$canApprove = allowed('leaves.approve');
$today = date('Y-m-d');
$year = (int) date('Y');

$views = ['mine' => 'My leave'];
if ($canApprove) {
    $pendingCount = leave_pending_count();
    $views = ['pending' => 'Waiting for approval' . ($pendingCount ? " ($pendingCount)" : ''), 'all' => 'All requests'] + $views;
}
$view = array_key_exists((string) input('view'), $views) ? (string) input('view') : ($canApprove ? 'pending' : 'mine');

$select = 'SELECT l.*, u.name user_name, u.email user_email, r.name reviewer_name
    FROM leave_requests l JOIN users u ON u.id = l.user_id LEFT JOIN users r ON r.id = l.reviewed_by';

if ($view === 'mine') {
    $summary = q_all(
        "SELECT leave_type, SUM(days) days FROM leave_requests
         WHERE workspace_id = ? AND user_id = ? AND status = 'approved' AND YEAR(start_date) = ? GROUP BY leave_type",
        [$ws, $me, $year]
    );
    $taken = array_column($summary, 'days', 'leave_type');
    $myPending = (int) q_val("SELECT COUNT(*) FROM leave_requests WHERE workspace_id = ? AND user_id = ? AND status = 'pending'", [$ws, $me]);
    $total = (int) q_val('SELECT COUNT(*) FROM leave_requests WHERE workspace_id = ? AND user_id = ?', [$ws, $me]);
    $p = paginate($total, per_page(25));
    $leaves = q_all("$select WHERE l.workspace_id = ? AND l.user_id = ? ORDER BY l.start_date DESC, l.id DESC LIMIT ? OFFSET ?", [$ws, $me, $p['per_page'], $p['offset']]);
} elseif ($view === 'pending') {
    $leaves = q_all("$select WHERE l.workspace_id = ? AND l.status = 'pending' ORDER BY l.start_date, l.id", [$ws]);
    // Who else is already off on overlapping dates, so the approver can see clashes
    $offSoon = q_all(
        "$select WHERE l.workspace_id = ? AND l.status = 'approved' AND l.end_date >= ? AND l.start_date <= ? ORDER BY l.start_date",
        [$ws, $today, date('Y-m-d', strtotime('+14 days'))]
    );
} else {
    $where = 'l.workspace_id = ?';
    $params = [$ws];
    $status = array_key_exists((string) input('status'), LEAVE_STATUSES) ? (string) input('status') : '';
    if ($status) {
        $where .= ' AND l.status = ?';
        $params[] = $status;
    }
    $member = input_int('member');
    if ($member) {
        $where .= ' AND l.user_id = ?';
        $params[] = $member;
    }
    $type = array_key_exists((string) input('type'), LEAVE_TYPES) ? (string) input('type') : '';
    if ($type) {
        $where .= ' AND l.leave_type = ?';
        $params[] = $type;
    }
    $filterYear = input_int('year');
    if ($filterYear) {
        $where .= ' AND YEAR(l.start_date) = ?';
        $params[] = $filterYear;
    }
    $total = (int) q_val("SELECT COUNT(*) FROM leave_requests l WHERE $where", $params);
    $p = paginate($total, per_page(25));
    $leaves = q_all("$select WHERE $where ORDER BY l.start_date DESC, l.id DESC LIMIT ? OFFSET ?", [...$params, $p['per_page'], $p['offset']]);
    $approvedDays = (float) q_val("SELECT COALESCE(SUM(l.days), 0) FROM leave_requests l WHERE $where AND l.status = 'approved'", $params);
    $users = workspace_users();
    $years = array_map('intval', q_col('SELECT DISTINCT YEAR(start_date) y FROM leave_requests WHERE workspace_id = ? ORDER BY y DESC', [$ws]));
}
$errors = get_errors();

$page_title = 'Leave';
$active_nav = 'leaves';
require __DIR__ . '/../includes/header.php';

/** One request row; $showUser for approver views, $actions = 'cancel' | 'review' | ''. */
$renderLeave = function (array $l, bool $showUser, string $actions) use ($today) { ?>
    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start" x-data="{ reviewing: false }">
        <?php if ($showUser): ?>
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= e(avatar_color($l['user_name'])) ?>" title="<?= e($l['user_name']) ?>"><?= e(user_initials($l['user_name'])) ?></span>
        <?php endif; ?>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <?php if ($showUser): ?><span class="font-medium text-slate-900"><?= e($l['user_name']) ?></span><?php endif; ?>
                <span class="<?= $showUser ? 'text-slate-600' : 'font-medium text-slate-900' ?>"><?= e(format_leave_range($l['start_date'], $l['end_date'])) ?></span>
                <span class="text-sm text-slate-500">· <?= e($l['half_day'] ? 'Half day' : format_leave_days($l['days'])) ?></span>
                <span class="badge badge-slate"><?= e(LEAVE_TYPES[$l['leave_type']] ?? $l['leave_type']) ?></span>
                <?= leave_status_badge($l['status']) ?>
                <?php if ($l['status'] === 'approved' && $l['start_date'] <= $today && $l['end_date'] >= $today): ?><span class="badge badge-violet">Off today</span><?php endif; ?>
            </div>
            <?php if ($l['reason']): ?><p class="mt-1 whitespace-pre-line text-sm text-slate-600"><?= e($l['reason']) ?></p><?php endif; ?>
            <div class="mt-1 text-xs text-slate-400">
                Requested <?= e(time_ago($l['created_at'])) ?>
                <?php if ($l['reviewed_at']): ?> · <?= e(strtolower(LEAVE_STATUSES[$l['status']] ?? '')) ?> by <?= e($l['reviewer_name'] ?: 'a former member') ?> <?= e(time_ago($l['reviewed_at'])) ?><?php endif; ?>
            </div>
            <?php if ($l['review_note']): ?>
                <div class="mt-1.5 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700"><span class="text-xs font-medium text-slate-500">Note:</span> <?= e($l['review_note']) ?></div>
            <?php endif; ?>
            <?php if ($actions === 'review'): ?>
                <form method="post" action="<?= e(url('leaves/actions.php')) ?>" x-show="reviewing" x-cloak class="mt-3 space-y-2">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <input class="input" name="review_note" maxlength="500" placeholder="Note for <?= e($l['user_name']) ?> (optional)">
                    <div class="flex gap-2">
                        <button name="action" value="approve" class="btn-primary"><?= icon('check', 'h-4 w-4') ?> Approve</button>
                        <button name="action" value="reject" class="btn-secondary text-red-600">Reject</button>
                        <button type="button" class="btn-ghost" @click="reviewing = false">Cancel</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($actions === 'review'): ?>
            <div class="flex shrink-0 gap-2" x-show="!reviewing">
                <form method="post" action="<?= e(url('leaves/actions.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <button name="action" value="approve" class="btn-primary !py-1.5"><?= icon('check', 'h-4 w-4') ?> Approve</button>
                </form>
                <button type="button" class="btn-secondary !py-1.5" @click="reviewing = true">Reject / add note</button>
            </div>
        <?php elseif ($actions === 'cancel' && $l['status'] === 'pending'): ?>
            <form method="post" action="<?= e(url('leaves/actions.php')) ?>" data-confirm="Cancel this leave request?" data-confirm-button="Cancel request" class="shrink-0">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                <button class="btn-secondary !py-1.5 text-slate-600">Cancel request</button>
            </form>
        <?php endif; ?>
    </li>
<?php };
?>
<div class="page-header">
    <div><h1 class="page-title">Leave</h1><p class="page-subtitle"><?= $canApprove ? 'Review your team’s leave requests and request your own.' : 'Request time off and follow your requests.' ?></p></div>
    <?php if ($view !== 'mine'): ?><a href="<?= e(url('leaves/index.php', ['view' => 'mine'])) ?>#request" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Request leave</a><?php endif; ?>
</div>

<?php if (count($views) > 1): ?>
<div class="mb-6 flex gap-6 overflow-x-auto border-b border-slate-200">
    <?php foreach ($views as $k => $l): ?>
        <a href="<?= e(url('leaves/index.php', ['view' => $k])) ?>" class="tab whitespace-nowrap <?= $view === $k ? 'active' : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($view === 'mine'): ?>
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <div class="stat-card"><div class="stat-label">Taken in <?= $year ?></div><div class="stat-value"><?= e(rtrim(rtrim(number_format(array_sum($taken), 1), '0'), '.')) ?></div></div>
        <?php foreach (LEAVE_TYPES as $k => $label): ?>
            <div class="stat-card"><div class="stat-label"><?= e($label) ?></div><div class="stat-value text-slate-600"><?= e(rtrim(rtrim(number_format((float) ($taken[$k] ?? 0), 1), '0'), '.')) ?></div></div>
        <?php endforeach; ?>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-hidden lg:col-span-2">
            <div class="card-header"><h2 class="card-title">My requests</h2><?php if ($myPending): ?><span class="text-xs text-amber-600"><?= $myPending ?> pending</span><?php endif; ?></div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($leaves as $l) { $renderLeave($l, false, 'cancel'); } ?>
                <?php if (!$leaves): ?><li class="px-5 py-10 text-center text-sm text-slate-400">You haven’t requested any leave yet.</li><?php endif; ?>
            </ul>
            <?php if ($leaves): ?><?= pagination_links($p) ?><?php endif; ?>
        </div>

        <form id="request" method="post" action="<?= e(url('leaves/actions.php')) ?>" class="card self-start" x-data="{ start: <?= e(json_encode((string) old('start_date'))) ?>, end: <?= e(json_encode((string) old('end_date'))) ?>, half: <?= old('half_day') ? 'true' : 'false' ?> }">
            <?= csrf_field() ?><input type="hidden" name="action" value="request">
            <div class="card-header"><div><h2 class="card-title">Request leave</h2><p class="text-xs text-slate-500">A Super Admin approves or rejects it.</p></div></div>
            <div class="card-body space-y-4">
                <div>
                    <label class="label" for="leave_type">Type</label>
                    <select class="input" id="leave_type" name="leave_type">
                        <?php foreach (LEAVE_TYPES as $k => $label): ?><option value="<?= $k ?>" <?= old('leave_type', 'casual') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="start_date">From <span class="text-red-500">*</span></label>
                        <input class="input" type="date" id="start_date" name="start_date" x-model="start" @change="if (!end || end < start || half) end = start" required>
                        <?= field_error($errors, 'start_date') ?>
                    </div>
                    <div>
                        <label class="label" for="end_date">To</label>
                        <input class="input" type="date" id="end_date" name="end_date" x-model="end" :min="start" :readonly="half" :class="half && 'bg-slate-50'">
                        <?= field_error($errors, 'end_date') ?>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="half_day" value="1" class="rounded border-slate-300 text-indigo-600" x-model="half" @change="if (half) end = start"> Half day
                </label>
                <?= field_error($errors, 'half_day') ?>
                <p class="text-xs text-slate-500" x-show="start" x-cloak>
                    <span x-text="half ? '0.5 day' : (() => { const d = Math.round((new Date(end || start) - new Date(start)) / 864e5) + 1; return d > 0 ? d + (d === 1 ? ' day' : ' days') : '—'; })()"></span>
                </p>
                <div>
                    <label class="label" for="reason">Reason</label>
                    <textarea class="input" id="reason" name="reason" rows="3" maxlength="2000" placeholder="Family event, doctor’s appointment…"><?= e(old('reason')) ?></textarea>
                    <?= field_error($errors, 'reason') ?>
                </div>
                <div class="flex justify-end"><button class="btn-primary"><?= icon('send', 'h-4 w-4') ?> Submit request</button></div>
            </div>
        </form>
    </div>

<?php elseif ($view === 'pending'): ?>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-hidden lg:col-span-2">
            <div class="card-header"><h2 class="card-title">Waiting for approval</h2><span class="text-xs text-slate-400"><?= count($leaves) ?> request<?= count($leaves) === 1 ? '' : 's' ?></span></div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($leaves as $l) { $renderLeave($l, true, 'review'); } ?>
                <?php if (!$leaves): ?>
                    <li class="empty-state">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><?= icon('check', 'h-6 w-6') ?></span>
                        <h3 class="mt-3 text-sm font-semibold">All caught up</h3>
                        <p class="mt-1 text-sm text-slate-500">No leave requests are waiting for you.</p>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="card self-start">
            <div class="card-header"><div><h2 class="card-title">Off in the next 2 weeks</h2><p class="text-xs text-slate-500">Approved leave, to spot clashes.</p></div></div>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($offSoon as $o): ?>
                    <li class="flex items-center gap-3 px-5 py-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold <?= e(avatar_color($o['user_name'])) ?>"><?= e(user_initials($o['user_name'])) ?></span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-slate-800"><?= e($o['user_name']) ?></div>
                            <div class="text-xs text-slate-500"><?= e(format_leave_range($o['start_date'], $o['end_date'])) ?> · <?= e(LEAVE_TYPES[$o['leave_type']] ?? '') ?></div>
                        </div>
                        <?php if ($o['start_date'] <= $today): ?><span class="badge badge-violet">Off today</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$offSoon): ?><li class="px-5 py-8 text-center text-slate-400">Nobody is off.</li><?php endif; ?>
            </ul>
        </div>
    </div>

<?php else: ?>
    <div class="card overflow-hidden">
        <form method="get" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
            <input type="hidden" name="view" value="all">
            <select name="member" class="input !w-auto" onchange="this.form.submit()">
                <option value="">Everyone</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $member === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
            <select name="status" class="input !w-auto" onchange="this.form.submit()">
                <option value="">Any status</option>
                <?php foreach (LEAVE_STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <select name="type" class="input !w-auto" onchange="this.form.submit()">
                <option value="">Any type</option>
                <?php foreach (LEAVE_TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <?php if ($years): ?>
                <select name="year" class="input !w-auto" onchange="this.form.submit()">
                    <option value="">Any year</option>
                    <?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if ($status || $member || $type || $filterYear): ?><a href="<?= e(url('leaves/index.php', ['view' => 'all'])) ?>" class="btn-ghost">Clear</a><?php endif; ?>
            <span class="ml-auto text-xs text-slate-500"><?= $total ?> request<?= $total === 1 ? '' : 's' ?> · <?= e(format_leave_days($approvedDays)) ?> approved</span>
        </form>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($leaves as $l) { $renderLeave($l, true, $l['status'] === 'pending' ? 'review' : ''); } ?>
            <?php if (!$leaves): ?><li class="px-5 py-10 text-center text-sm text-slate-400">No leave requests match these filters.</li><?php endif; ?>
        </ul>
        <?php if ($leaves): ?><?= pagination_links($p) ?><?php endif; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
