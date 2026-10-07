<?php
require_once __DIR__ . '/includes/init.php';

$token = (string) input('token', '');
$reset = preg_match('/^[a-f0-9]{64}$/', $token)
    ? q_one('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), now()])
    : null;

$errors = [];
if ($reset && is_post()) {
    verify_csrf();
    $errors = validate($_POST, [
        'password' => 'required|min:8|max:200',
        'password_confirmation' => 'required|same:password',
    ], ['password_confirmation' => 'Password']);
    if (!$errors) {
        transaction(function () use ($reset) {
            q('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash((string) $_POST['password'], PASSWORD_DEFAULT), now(), $reset['user_id']]);
            q('UPDATE password_resets SET used_at = ? WHERE id = ?', [now(), $reset['id']]);
        });
        logout_user();
        start_secure_session();
        flash('success', 'Password updated. You can now sign in.');
        redirect('login.php');
    }
}

$page_title = 'Choose a new password';
require __DIR__ . '/includes/guest-header.php';
?>
<h1 class="text-2xl font-semibold tracking-tight">Choose a new password</h1>
<?php if (!$reset): ?>
    <div class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">This reset link is invalid or has expired.</div>
    <p class="mt-6 text-sm"><a class="font-medium text-indigo-600" href="<?= e(url('forgot-password.php')) ?>">Request a new link</a></p>
<?php else: ?>
    <form method="post" class="mt-8 space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div>
            <label class="label" for="password">New password</label>
            <input class="input" id="password" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
            <?= field_error($errors, 'password') ?>
        </div>
        <div>
            <label class="label" for="password_confirmation">Confirm new password</label>
            <input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            <?= field_error($errors, 'password_confirmation') ?>
        </div>
        <button class="btn-primary w-full py-2.5">Update password</button>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/includes/guest-footer.php';
