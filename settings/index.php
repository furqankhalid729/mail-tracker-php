<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$workspace = current_workspace();
$tab = in_array(input('tab'), ['workspace', 'sending', 'tracking', 'members', 'profile', 'cron'], true) ? input('tab') : 'workspace';
$errors = get_errors();

if (is_post()) {
    verify_csrf();
    $action = (string) input('action');
    $back = url('settings/index.php', ['tab' => $tab]);

    if (in_array($action, ['workspace', 'sending', 'tracking'], true)) {
        require_permission('settings.manage');
    }
    if (in_array($action, ['member_add', 'member_role', 'member_remove'], true)) {
        require_permission('members.manage');
    }
    switch ($action) {
        case 'workspace':
            $name = trim((string) input('name'));
            if ($name === '' || mb_strlen($name) > 120) {
                flash('error', 'Workspace name is required.');
                break;
            }
            q('UPDATE workspaces SET name = ?, updated_at = ? WHERE id = ?', [$name, now(), $ws]);
            flash('success', 'Workspace updated.');
            break;

        case 'sending':
            $errs = validate($_POST, [
                'emails_per_run' => 'required|int|between:1,100',
                'max_attempts' => 'required|int|between:1,10',
                'delay_ms' => 'required|int|between:0,10000',
                'daily_limit' => 'required|int|between:1,100000',
            ]);
            if ($errs) {
                flash_errors($errs);
                break;
            }
            db_update('workspaces', [
                'emails_per_run' => input_int('emails_per_run'),
                'max_attempts' => input_int('max_attempts'),
                'delay_ms' => input_int('delay_ms'),
                'daily_limit' => input_int('daily_limit'),
                'updated_at' => now(),
            ], 'id = ?', [$ws]);
            release_limit_deferred_jobs($ws);
            flash('success', 'Sending limits saved.');
            break;

        case 'tracking':
            db_update('workspaces', [
                'track_opens' => input('track_opens') === '1' ? 1 : 0,
                'track_clicks' => input('track_clicks') === '1' ? 1 : 0,
                'store_ip' => input('store_ip') === '1' ? 1 : 0,
                'ip_retention_days' => max(1, min(3650, input_int('ip_retention_days', 90))),
                'company_address' => mb_substr(trim((string) input('company_address')), 0, 255) ?: null,
                'updated_at' => now(),
            ], 'id = ?', [$ws]);
            if (input('purge_ip') === '1') {
                q('UPDATE email_events SET ip_address = NULL, user_agent = NULL WHERE workspace_id = ?', [$ws]);
            }
            flash('success', 'Tracking & privacy settings saved.');
            break;

        case 'member_add':
            $email = strtolower(trim((string) input('email')));
            $role = (string) input('role');
            if (!in_array($role, assignable_roles(), true)) {
                flash('error', 'You cannot give that role.');
                break;
            }
            if (!is_valid_email($email)) {
                keep_old_input();
                flash_errors(['member_email' => 'Enter a valid email address.']);
                break;
            }
            $user = q_one('SELECT id, name FROM users WHERE email = ?', [$email]);
            if (!$user) {
                // No account yet: create a login for them (they can change the password under Profile)
                $errs = validate($_POST, ['name' => 'required|max:120', 'password' => 'required|min:8|max:200'], ['password' => 'Temporary password']);
                if ($errs) {
                    keep_old_input();
                    flash_errors($errs + ['member_email' => 'No account uses this email yet, so enter a name and a temporary password to create one.']);
                    break;
                }
                $name = trim((string) input('name'));
                transaction(function () use ($email, $name, $role, $ws) {
                    $id = db_insert('users', [
                        'name' => $name,
                        'email' => $email,
                        'password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
                        'current_workspace_id' => $ws,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    db_insert('workspace_members', ['workspace_id' => $ws, 'user_id' => $id, 'role' => $role, 'created_at' => now()]);
                });
                flash('success', 'Account created for ' . $name . ' as ' . role_label($role) . '. Share the email and temporary password with them.');
                break;
            }
            if (q_val('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $user['id']])) {
                flash('info', $user['name'] . ' is already a member. Change their role in the list.');
                break;
            }
            db_insert('workspace_members', ['workspace_id' => $ws, 'user_id' => $user['id'], 'role' => $role, 'created_at' => now()]);
            flash('success', $user['name'] . ' can now access this workspace as ' . role_label($role) . '.');
            break;

        case 'member_role':
            $memberId = input_int('user_id');
            $role = (string) input('role');
            $target = q_one('SELECT role FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $memberId]);
            if (!$target || !in_array($role, assignable_roles(), true) || !can_manage_member($target['role'])) {
                flash('error', 'You cannot give that role.');
                break;
            }
            if ($memberId === user_id()) {
                flash('error', 'You cannot change your own role.');
                break;
            }
            q('UPDATE workspace_members SET role = ? WHERE workspace_id = ? AND user_id = ?', [$role, $ws, $memberId]);
            flash('success', 'Role changed to ' . role_label($role) . '.');
            break;

        case 'member_remove':
            $memberId = input_int('user_id');
            $target = q_one('SELECT role FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $memberId]);
            if (!$target || $memberId === user_id() || !can_manage_member($target['role'])) {
                flash('error', 'You cannot remove this member.');
                break;
            }
            q('DELETE FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $memberId]);
            flash('success', 'Member removed.');
            break;

        case 'profile':
            $errs = validate($_POST, ['name' => 'required|max:120', 'email' => 'required|email']);
            $email = strtolower(trim((string) input('email')));
            if (!$errs && q_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, user_id()])) {
                $errs['email'] = 'That email is already used by another account.';
            }
            if ($errs) {
                flash_errors($errs);
                break;
            }
            q('UPDATE users SET name = ?, email = ?, updated_at = ? WHERE id = ?', [trim((string) input('name')), $email, now(), user_id()]);
            flash('success', 'Profile updated.');
            break;

        case 'password':
            $user = q_one('SELECT password_hash FROM users WHERE id = ?', [user_id()]);
            $errs = validate($_POST, ['password' => 'required|min:8|max:200', 'password_confirmation' => 'required|same:password'], ['password_confirmation' => 'Password']);
            if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
                $errs['current_password'] = 'Current password is incorrect.';
            }
            if ($errs) {
                flash_errors($errs);
                break;
            }
            q('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash((string) $_POST['password'], PASSWORD_DEFAULT), now(), user_id()]);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
            break;
    }
    redirect($back);
}

$members = q_all("SELECT u.id, u.name, u.email, m.role, m.created_at FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? ORDER BY FIELD(m.role, 'owner', 'admin', 'manager', 'marketer', 'member'), u.name", [$ws]);
$user = current_user();
$lastQueueRun = q_val("SELECT MAX(processed_at) FROM email_jobs WHERE status IN ('completed','failed')");
$lastInboxCheck = q_val('SELECT MAX(inbox_checked_at) FROM mail_accounts WHERE workspace_id = ?', [$ws]);
$isAdmin = allowed('settings.manage');
$canManageMembers = allowed('members.manage');
$roleOptions = assignable_roles();

$page_title = 'Settings';
$active_nav = 'settings';
require __DIR__ . '/../includes/header.php';
$tabs = ['workspace' => 'Workspace', 'sending' => 'Sending limits', 'tracking' => 'Tracking & privacy', 'members' => 'Members', 'profile' => 'Profile', 'cron' => 'Cron & webhooks'];
$disabled = $isAdmin ? '' : 'disabled';
?>
<div class="page-header"><div><h1 class="page-title">Settings</h1><p class="page-subtitle"><?= e($workspace['name']) ?> · your role: <?= e(role_label(user_role())) ?></p></div></div>

<div class="grid gap-6 lg:grid-cols-4">
    <nav class="flex gap-1 overflow-x-auto lg:flex-col">
        <?php foreach ($tabs as $k => $l): ?>
            <a href="<?= e(url('settings/index.php', ['tab' => $k])) ?>" class="nav-link whitespace-nowrap <?= $tab === $k ? 'active' : '' ?>"><?= e($l) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="lg:col-span-3 space-y-6">
    <?php if ($tab === 'workspace'): ?>
        <form method="post" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="workspace">
            <div class="card-header"><h2 class="card-title">Workspace</h2></div>
            <div class="card-body space-y-4">
                <div><label class="label">Name</label><input class="input max-w-md" name="name" value="<?= e($workspace['name']) ?>" required maxlength="120" <?= $disabled ?>></div>
                <?php if ($isAdmin): ?><button class="btn-primary">Save</button><?php endif; ?>
            </div>
        </form>
        <form method="post" action="<?= e(url('settings/workspace.php')) ?>" class="card" id="new-workspace">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="card-header"><div><h2 class="card-title">Create another workspace</h2><p class="text-xs text-slate-500">Workspaces are fully isolated: customers, campaigns, mail accounts and data never cross over.</p></div></div>
            <div class="card-body flex gap-2"><input class="input max-w-md" name="name" placeholder="Agency client B" required maxlength="120"><button class="btn-secondary">Create</button></div>
        </form>

    <?php elseif ($tab === 'sending'): ?>
        <form method="post" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="sending">
            <div class="card-header"><div><h2 class="card-title">Sending limits</h2><p class="text-xs text-slate-500">Protect your sender reputation and stay inside host limits. Each mail account also has its own daily limit.</p></div></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div><label class="label">Emails per cron run</label><input class="input" type="number" name="emails_per_run" min="1" max="100" value="<?= (int) $workspace['emails_per_run'] ?>" <?= $disabled ?>><p class="help">Cron runs every minute. 10–25 is safe for shared hosting.</p><?= field_error($errors, 'emails_per_run') ?></div>
                <div><label class="label">Daily sending limit (workspace)</label><input class="input" type="number" name="daily_limit" min="1" value="<?= (int) $workspace['daily_limit'] ?>" <?= $disabled ?>><p class="help">Emails beyond this wait until tomorrow.</p><?= field_error($errors, 'daily_limit') ?></div>
                <div><label class="label">Delay between emails (ms)</label><input class="input" type="number" name="delay_ms" min="0" max="10000" value="<?= (int) $workspace['delay_ms'] ?>" <?= $disabled ?>><?= field_error($errors, 'delay_ms') ?></div>
                <div><label class="label">Maximum retry attempts</label><input class="input" type="number" name="max_attempts" min="1" max="10" value="<?= (int) $workspace['max_attempts'] ?>" <?= $disabled ?>><p class="help">Retries wait 5 min, 30 min, then 2 h. Permanent errors are not retried.</p><?= field_error($errors, 'max_attempts') ?></div>
            </div>
            <?php if ($isAdmin): ?><div class="border-t border-slate-100 px-5 py-3"><button class="btn-primary">Save limits</button></div><?php endif; ?>
        </form>

    <?php elseif ($tab === 'tracking'): ?>
        <form method="post" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="tracking">
            <div class="card-header"><h2 class="card-title">Tracking &amp; privacy</h2></div>
            <div class="card-body space-y-4">
                <label class="flex gap-3"><input type="checkbox" class="checkbox mt-0.5" name="track_opens" value="1" <?= $workspace['track_opens'] ? 'checked' : '' ?> <?= $disabled ?>>
                    <span><span class="block text-sm font-medium">Open tracking</span><span class="text-xs text-slate-500">Adds an invisible 1×1 image. <b>Approximate:</b> Apple Mail Privacy Protection and some corporate scanners load images automatically (false opens), while clients that block images never register an open.</span></span></label>
                <label class="flex gap-3"><input type="checkbox" class="checkbox mt-0.5" name="track_clicks" value="1" <?= $workspace['track_clicks'] ? 'checked' : '' ?> <?= $disabled ?>>
                    <span><span class="block text-sm font-medium">Click tracking</span><span class="text-xs text-slate-500">Links are rewritten through a signed redirect on this domain (only http/https destinations; no open redirect). Security scanners may pre-click links.</span></span></label>
                <label class="flex gap-3"><input type="checkbox" class="checkbox mt-0.5" name="store_ip" value="1" <?= $workspace['store_ip'] ? 'checked' : '' ?> <?= $disabled ?>>
                    <span><span class="block text-sm font-medium">Store IP address &amp; user agent with opens/clicks</span><span class="text-xs text-slate-500">Turn off to store only the event and timestamp.</span></span></label>
                <div class="max-w-xs"><label class="label">Delete IP/user-agent after (days)</label><input class="input" type="number" name="ip_retention_days" min="1" max="3650" value="<?= (int) $workspace['ip_retention_days'] ?>" <?= $disabled ?>></div>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="checkbox" name="purge_ip" value="1" <?= $disabled ?>> Erase all stored IP addresses and user agents now</label>
                <div><label class="label">Postal address for campaign footers</label><input class="input" name="company_address" value="<?= e($workspace['company_address']) ?>" maxlength="255" placeholder="Acme Inc, 1 Main St, Springfield, USA" <?= $disabled ?>><p class="help">Included with the unsubscribe link (required by CAN-SPAM and similar laws).</p></div>
                <div class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><b>Delivery:</b> with SMTP, “sent” means the server accepted the message. “Delivered” and bounces need provider webhooks or bounce messages read via IMAP. Tracking data is used only for the analytics shown in this app.</div>
            </div>
            <?php if ($isAdmin): ?><div class="border-t border-slate-100 px-5 py-3"><button class="btn-primary">Save</button></div><?php endif; ?>
        </form>

    <?php elseif ($tab === 'members'): ?>
        <div class="card overflow-hidden">
            <div class="card-header"><h2 class="card-title">Members</h2></div>
            <table class="table">
                <thead><tr><th>Name</th><th>Role</th><th>Joined</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td><div class="font-medium text-slate-900"><?= e($m['name']) ?><?= (int) $m['id'] === user_id() ? ' <span class="text-xs text-slate-400">(you)</span>' : '' ?></div><div class="text-xs text-slate-500"><?= e($m['email']) ?></div></td>
                        <td>
                            <?php if ((int) $m['id'] !== user_id() && can_manage_member($m['role'])): ?>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="member_role"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
                                    <select name="role" class="input !w-auto !py-1 text-xs" onchange="this.form.submit()">
                                        <?php foreach ($roleOptions as $r): ?><option value="<?= $r ?>" <?= $m['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
                                    </select></form>
                            <?php else: ?><?= role_badge($m['role']) ?><?php endif; ?>
                        </td>
                        <td class="text-xs text-slate-500"><?= e(format_dt($m['created_at'], 'M j, Y')) ?></td>
                        <td class="text-right">
                            <?php if ((int) $m['id'] !== user_id() && can_manage_member($m['role'])): ?>
                                <form method="post" data-confirm="Remove <?= e($m['name']) ?> from this workspace?" data-confirm-button="Remove"><?= csrf_field() ?><input type="hidden" name="action" value="member_remove"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>"><button class="btn-ghost btn-sm text-red-600">Remove</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canManageMembers): ?>
        <form method="post" class="card" x-data="{ role: <?= e(json_encode((string) old('role', 'marketer'))) ?> }">
            <?= csrf_field() ?><input type="hidden" name="action" value="member_add">
            <div class="card-header"><div><h2 class="card-title">Add team member</h2><p class="text-xs text-slate-500">If they already have an account, only the email is needed. Otherwise enter a name and a temporary password to create their login.</p></div></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="m_email">Email</label><input type="email" id="m_email" name="email" class="input" value="<?= e(old('email')) ?>" placeholder="teammate@company.com" required><?= field_error($errors, 'member_email') ?></div>
                <div>
                    <label class="label" for="m_role">Role</label>
                    <select id="m_role" name="role" class="input" x-model="role">
                        <?php foreach ($roleOptions as $r): ?><option value="<?= $r ?>"><?= e(role_label($r)) ?></option><?php endforeach; ?>
                    </select>
                    <?php foreach ($roleOptions as $r): ?><p class="help" x-show="role === '<?= $r ?>'" x-cloak><?= e(ROLE_DESCRIPTIONS[$r]) ?></p><?php endforeach; ?>
                </div>
                <div><label class="label" for="m_name">Name <span class="font-normal text-slate-400">(new accounts)</span></label><input id="m_name" name="name" class="input" value="<?= e(old('name')) ?>" maxlength="120" placeholder="Jane Doe"><?= field_error($errors, 'name') ?></div>
                <div><label class="label" for="m_password">Temporary password <span class="font-normal text-slate-400">(new accounts)</span></label><input type="password" id="m_password" name="password" class="input" minlength="8" autocomplete="new-password"><?= field_error($errors, 'password') ?></div>
                <div class="flex justify-end sm:col-span-2"><button class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Add member</button></div>
            </div>
        </form>
        <?php endif; ?>
        <div class="card overflow-hidden">
            <div class="card-header"><div><h2 class="card-title">What each role can do</h2><p class="text-xs text-slate-500">A workspace can have several Super Admins.</p></div></div>
            <ul class="divide-y divide-slate-100">
                <?php foreach (ROLE_LABELS as $r => $l): ?>
                    <li class="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-center sm:gap-4"><span class="shrink-0 sm:w-36"><?= role_badge($r) ?></span><span class="text-sm text-slate-600"><?= e(ROLE_DESCRIPTIONS[$r]) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php elseif ($tab === 'profile'): ?>
        <form method="post" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="profile">
            <div class="card-header"><h2 class="card-title">Profile</h2></div>
            <div class="card-body grid max-w-xl gap-4">
                <div><label class="label">Name</label><input class="input" name="name" value="<?= e($user['name']) ?>" required maxlength="120"><?= field_error($errors, 'name') ?></div>
                <div><label class="label">Email</label><input class="input" type="email" name="email" value="<?= e($user['email']) ?>" required><?= field_error($errors, 'email') ?></div>
                <div><button class="btn-primary">Save profile</button></div>
            </div>
        </form>
        <form method="post" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="password">
            <div class="card-header"><h2 class="card-title">Change password</h2></div>
            <div class="card-body grid max-w-xl gap-4">
                <div><label class="label">Current password</label><input class="input" type="password" name="current_password" required autocomplete="current-password"><?= field_error($errors, 'current_password') ?></div>
                <div><label class="label">New password</label><input class="input" type="password" name="password" required minlength="8" autocomplete="new-password"><?= field_error($errors, 'password') ?></div>
                <div><label class="label">Confirm new password</label><input class="input" type="password" name="password_confirmation" required autocomplete="new-password"><?= field_error($errors, 'password_confirmation') ?></div>
                <div><button class="btn-primary">Update password</button></div>
            </div>
        </form>

    <?php else: $php = PHP_BINARY && !preg_match('/fpm|cgi|httpd|apache/i', PHP_BINARY) ? PHP_BINARY : '/usr/bin/php'; ?>
        <div class="card">
            <div class="card-header"><div><h2 class="card-title">Cron jobs</h2><p class="text-xs text-slate-500">All background work runs through short cron executions — no daemons or workers.</p></div></div>
            <div class="card-body space-y-4 text-sm">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Last email queue activity</div><div class="font-medium"><?= e($lastQueueRun ? time_ago($lastQueueRun) : 'never') ?></div></div>
                    <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Last inbox check</div><div class="font-medium"><?= e($lastInboxCheck ? time_ago($lastInboxCheck) : 'never') ?></div></div>
                </div>
                <p>In hPanel → <b>Advanced → Cron Jobs</b>, add (use the PHP path your host shows, often <code>/usr/bin/php</code> or <code>/opt/alt/php82/usr/bin/php</code>):</p>
                <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><?php foreach ([['* * * * *', 'process-email-queue.php'], ['*/5 * * * *', 'check-inbox.php'], ['*/5 * * * *', 'process-webhooks.php'], ['*/15 * * * *', 'retry-failed.php'], ['30 3 * * *', 'cleanup.php']] as [$when, $f]) {
                    echo e("$when $php " . APP_ROOT . "/cron/$f") . "\n";
                } ?></pre>
                <p class="font-medium">HTTP fallback (if CLI cron isn't available)</p>
                <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100">curl -s -H "Authorization: Bearer YOUR_CRON_SECRET" <?= e(url('cron/process-email-queue.php')) ?></pre>
                <p class="text-xs text-slate-500">The secret is the <code>CRON_SECRET</code> value in your <code>.env</code> file. It is intentionally not shown here.</p>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div><h2 class="card-title">Delivery webhooks</h2><p class="text-xs text-slate-500">Optional: lets your provider report “delivered” and bounces.</p></div></div>
            <div class="card-body space-y-2 text-sm">
                <?php foreach (['sendgrid' => 'SendGrid (Event Webhook)', 'mailgun' => 'Mailgun', 'ses' => 'Amazon SES (via SNS HTTPS subscription)', 'generic' => 'Generic JSON {"event":"delivered|bounced|failed","message_id":"<…>"}'] as $prov => $label): ?>
                    <div><div class="text-xs font-medium text-slate-600"><?= e($label) ?></div><code class="block overflow-x-auto rounded bg-slate-100 px-2 py-1 text-xs"><?= e(url('webhooks/email.php', ['provider' => $prov])) ?>&amp;token=YOUR_WEBHOOK_SECRET</code></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
