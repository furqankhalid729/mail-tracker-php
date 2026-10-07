<?php
require_once __DIR__ . '/includes/init.php';
require_guest();

$sent = false;
$errors = [];
if (is_post()) {
    verify_csrf();
    $email = strtolower(trim((string) input('email')));
    if (!is_valid_email($email)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (!rate_limit('reset:' . client_ip(), 5, 3600) || !rate_limit('reset-email:' . $email, 3, 3600)) {
        $errors['email'] = 'Too many reset requests. Please try again later.';
    } else {
        $user = q_one('SELECT id, name, email FROM users WHERE email = ?', [$email]);
        if ($user) {
            $token = random_token(32);
            q('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
            db_insert('password_resets', [
                'user_id' => $user['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'created_at' => now(),
            ]);
            $link = url('reset-password.php', ['token' => $token]);
            send_system_email($user['email'], 'Reset your ' . APP_NAME . ' password',
                '<p>Hi ' . e($user['name']) . ',</p><p>Someone requested a password reset for your account. The link below is valid for 60 minutes:</p>'
                . '<p><a href="' . e($link) . '">Reset my password</a></p><p>If you did not request this, ignore this email.</p>');
        }
        // Same response whether or not the account exists
        $sent = true;
    }
    keep_old_input();
}

$page_title = 'Forgot password';
require __DIR__ . '/includes/guest-header.php';
?>
<h1 class="text-2xl font-semibold tracking-tight">Reset your password</h1>
<?php if ($sent): ?>
    <div class="mt-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 ring-1 ring-emerald-200">If an account exists for that email, a reset link is on its way. It expires in 60 minutes.</div>
<?php else: ?>
    <p class="mt-1 text-sm text-slate-500">We'll email you a link to choose a new password.</p>
    <form method="post" class="mt-8 space-y-5">
        <?= csrf_field() ?>
        <div>
            <label class="label" for="email">Email</label>
            <input class="input" id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autofocus>
            <?= field_error($errors, 'email') ?>
        </div>
        <button class="btn-primary w-full py-2.5">Send reset link</button>
    </form>
<?php endif; ?>
<p class="mt-6 text-center text-sm text-slate-500"><a class="font-medium text-indigo-600 hover:text-indigo-500" href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
<?php require __DIR__ . '/includes/guest-footer.php';
