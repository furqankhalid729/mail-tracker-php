<?php
/**
 * Admins assign phone numbers / extensions to closers.
 * Calls whose caller or callee matches a closer's number count towards that closer.
 *   POST action=add     user_id + numbers (one per line, optional ", label")
 *   POST action=upload  CSV: email, phone_number[, label]
 *   POST action=remove  id
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('calls.manage');
$ws = ws_id();
$self = 'calls/numbers.php';

/** Assign numbers to a user. Returns [added, skipped messages]. */
$assign = function (int $userId, array $lines) use ($ws): array {
    $added = 0;
    $skipped = [];
    foreach ($lines as [$number, $label]) {
        $number = trim((string) $number);
        $key = number_key($number);
        if ($number === '' || !$key || strlen($key) < 3) {
            if ($number !== '') {
                $skipped[] = "$number (not a number)";
            }
            continue;
        }
        $owner = q_one('SELECT n.user_id, u.name FROM closer_numbers n JOIN users u ON u.id = n.user_id WHERE n.workspace_id = ? AND n.number_key = ?', [$ws, $key]);
        if ($owner) {
            if ((int) $owner['user_id'] !== $userId) {
                $skipped[] = "$number (already assigned to {$owner['name']})";
            }
            continue;
        }
        db_insert('closer_numbers', [
            'workspace_id' => $ws,
            'user_id' => $userId,
            'phone_number' => mb_substr($number, 0, 40),
            'number_key' => $key,
            'label' => ($label = trim((string) $label)) !== '' ? mb_substr($label, 0, 100) : null,
            'created_at' => now(),
        ]);
        $added++;
    }
    return [$added, $skipped];
};

$report = function (int $added, array $skipped): void {
    flash($added ? 'success' : 'info', number_format($added) . ' number' . ($added === 1 ? '' : 's') . ' assigned.');
    if ($skipped) {
        flash('error', 'Skipped: ' . implode('; ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? ' and ' . (count($skipped) - 10) . ' more' : '') . '.');
    }
};

if (is_post()) {
    verify_csrf();
    $action = (string) input('action');

    if ($action === 'add') {
        $userId = input_int('user_id');
        if (!is_workspace_user($userId)) {
            flash('error', 'Choose a team member.');
            redirect($self);
        }
        $lines = [];
        foreach (preg_split('/\R/', (string) input('numbers')) as $line) {
            $lines[] = array_pad(array_map('trim', explode(',', $line, 2)), 2, '');
        }
        [$added, $skipped] = $assign($userId, $lines);
        zoom_reattribute($ws);
        $report($added, $skipped);
    } elseif ($action === 'upload') {
        $file = $_FILES['csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) {
            flash('error', 'Upload a CSV file (max 2 MB).');
            redirect($self);
        }
        $fh = fopen($file['tmp_name'], 'r');
        $users = [];
        foreach (q_all('SELECT u.id, u.email FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ?', [$ws]) as $u) {
            $users[strtolower($u['email'])] = (int) $u['id'];
        }
        $byUser = [];
        $skipped = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $email = strtolower(trim((string) ($row[0] ?? '')));
            if ($email === '' || $email === 'email' || str_starts_with($email, '#')) {
                continue; // blank line or header
            }
            if (!isset($users[$email])) {
                $skipped[] = "$email (not a member of this workspace)";
                continue;
            }
            $byUser[$users[$email]][] = [$row[1] ?? '', $row[2] ?? ''];
        }
        fclose($fh);
        $added = 0;
        foreach ($byUser as $uid => $lines) {
            [$n, $s] = $assign($uid, $lines);
            $added += $n;
            $skipped = [...$skipped, ...$s];
        }
        zoom_reattribute($ws);
        $report($added, $skipped);
    } elseif ($action === 'remove') {
        q('DELETE FROM closer_numbers WHERE id = ? AND workspace_id = ?', [input_int('id'), $ws]);
        zoom_reattribute($ws);
        flash('success', 'Number removed. Its calls are now unassigned.');
    }
    redirect($self);
}

$members = workspace_users();
usort($members, fn($a, $b) => [$a['role'] !== 'closer', $a['name']] <=> [$b['role'] !== 'closer', $b['name']]);
$since = date('Y-m-d H:i:s', strtotime('-30 days'));
$numbers = q_all(
    'SELECT n.*, u.name user_name, u.email user_email, m.role,
        (SELECT COUNT(*) FROM zoom_calls c WHERE c.workspace_id = n.workspace_id AND c.closer_number_key = n.number_key AND c.start_time >= ?) calls_30d
     FROM closer_numbers n JOIN users u ON u.id = n.user_id
     LEFT JOIN workspace_members m ON m.user_id = n.user_id AND m.workspace_id = n.workspace_id
     WHERE n.workspace_id = ? ORDER BY u.name, n.phone_number',
    [$since, $ws]
);
$grouped = [];
foreach ($numbers as $n) {
    $grouped[$n['user_id']][] = $n;
}
$closersWithout = array_filter($members, fn($m) => $m['role'] === 'closer' && !isset($grouped[$m['id']]));
$errors = get_errors();

$page_title = 'Closer numbers';
$active_nav = 'calls';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('calls/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Calls</a>
        <h1 class="page-title">Closer numbers</h1>
        <p class="page-subtitle">Each closer sees only calls made from or to the numbers assigned here. Changes apply to past calls too.</p>
    </div>
    <a href="<?= e(url('calls/zoom.php')) ?>" class="btn-secondary"><?= icon('cog', 'h-4 w-4') ?> Zoom connection</a>
</div>

<?php if ($closersWithout): ?>
    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
        No numbers yet for: <b><?= e(implode(', ', array_column($closersWithout, 'name'))) ?></b>. Their dashboards will be empty until you assign some.
    </div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <?php foreach ($grouped as $uid => $rows): $u = $rows[0]; ?>
            <div class="card overflow-hidden">
                <div class="card-header">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold <?= e(avatar_color($u['user_name'])) ?>" title="<?= e($u['user_name']) ?>"><?= e(user_initials($u['user_name'])) ?></span>
                        <div><div class="text-sm font-semibold text-slate-900"><?= e($u['user_name']) ?></div><div class="text-xs text-slate-500"><?= e($u['user_email']) ?> · <?= e(role_label($u['role'])) ?></div></div>
                    </div>
                    <a class="text-xs font-medium text-indigo-600" href="<?= e(url('calls/index.php', ['closer' => $uid])) ?>">View stats</a>
                </div>
                <table class="table">
                    <thead><tr><th>Number</th><th>Label</th><th class="text-right">Calls (30d)</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $n): ?>
                        <tr>
                            <td class="font-medium tabular-nums text-slate-900"><?= e($n['phone_number']) ?></td>
                            <td class="text-slate-500"><?= e($n['label'] ?: '—') ?></td>
                            <td class="text-right tabular-nums"><?= number_format((int) $n['calls_30d']) ?></td>
                            <td class="text-right">
                                <form method="post" data-confirm="Remove <?= e($n['phone_number']) ?> from <?= e($u['user_name']) ?>? Its calls become unassigned." data-confirm-button="Remove">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                    <button class="btn-icon hover:!text-red-600" aria-label="Remove number" title="Remove number"><?= icon('trash', 'h-4 w-4') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
        <?php if (!$grouped): ?>
            <div class="card empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><?= icon('users', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold">No numbers assigned yet</h3>
                <p class="mt-1 text-sm text-slate-500">Add each closer's Zoom Phone number or extension on the right.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="space-y-6">
        <form method="post" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="add">
            <div class="card-header"><h2 class="card-title">Assign numbers</h2></div>
            <div class="card-body space-y-4">
                <div>
                    <label class="label" for="user_id">Closer</label>
                    <select class="input" id="user_id" name="user_id" required>
                        <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?><?= $m['role'] === 'closer' ? '' : ' (' . e(role_label($m['role'])) . ')' ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="numbers">Numbers</label>
                    <textarea class="input font-mono" id="numbers" name="numbers" rows="5" required placeholder="+1 408 533 3518, Main line&#10;+1 408 533 3519&#10;1107"></textarea>
                    <p class="help">One per line, optionally followed by “, label”. Full numbers in any format, or a Zoom extension.</p>
                </div>
                <button class="btn-primary w-full"><?= icon('plus', 'h-4 w-4') ?> Assign</button>
            </div>
        </form>

        <form method="post" enctype="multipart/form-data" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="upload">
            <div class="card-header"><div><h2 class="card-title">Upload CSV</h2><p class="text-xs text-slate-500">For many closers at once.</p></div></div>
            <div class="card-body space-y-3">
                <pre class="rounded bg-slate-100 px-3 py-2 text-xs text-slate-600">email,phone_number,label
jane@company.com,+14085333518,Main
jane@company.com,+14085333519,
sam@company.com,1107,Ext</pre>
                <input type="file" name="csv" accept=".csv,text/csv" required class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
                <p class="help">The email must belong to a member of this workspace. Add closers first in Settings → Members.</p>
                <button class="btn-secondary w-full"><?= icon('upload', 'h-4 w-4') ?> Upload</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
