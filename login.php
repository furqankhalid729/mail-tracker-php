<?php
require_once __DIR__ . '/includes/init.php';
require_guest();

$errors = [];
if (is_post()) {
    verify_csrf();
    $email = strtolower(trim((string) input('email')));
    $password = (string) ($_POST['password'] ?? '');
    $bucketUser = 'login:' . $email . '|' . client_ip();
    $bucketIp = 'login-ip:' . client_ip();

    if (!rate_limit($bucketUser, 5, 900) || !rate_limit($bucketIp, 30, 900)) {
        $errors['email'] = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
    } else {
        $user = q_one('SELECT * FROM users WHERE email = ?', [$email]);
        // Always do one bcrypt operation so response time doesn't reveal whether the email exists
        $hash = $user['password_hash'] ?? password_hash(random_token(8), PASSWORD_DEFAULT);
        if ($user && password_verify($password, $hash)) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            rate_limit_clear($bucketUser);
            login_user($user);
            $intended = $_SESSION['_intended'] ?? null;
            unset($_SESSION['_intended']);
            redirect($intended && str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : 'dashboard.php');
        }
        $errors['email'] = 'These credentials do not match our records.';
    }
    keep_old_input();
}

$page_title = 'Sign in';
require __DIR__ . '/includes/guest-header.php';
?>
<h1 class="text-2xl font-semibold tracking-tight">Welcome back</h1>
<p class="mt-1 text-sm text-slate-500">Sign in to your workspace.</p>

<form method="post" class="mt-8 space-y-5">
    <?= csrf_field() ?>
    <div>
        <label class="label" for="email">Email</label>
        <input class="input" id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="email">
        <?= field_error($errors, 'email') ?>
    </div>
    <div>
        <div class="flex items-center justify-between">
            <label class="label" for="password">Password</label>
            <a href="<?= e(url('forgot-password.php')) ?>" class="mb-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-500">Forgot password?</a>
        </div>
        <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
    </div>
    <button class="btn-primary w-full py-2.5">Sign in</button>
</form>
<?php if (ALLOW_REGISTRATION || !q_val('SELECT id FROM users LIMIT 1')): ?>
    <p class="mt-6 text-center text-sm text-slate-500">No account? <a class="font-medium text-indigo-600 hover:text-indigo-500" href="<?= e(url('register.php')) ?>">Create one</a></p>
<?php endif; ?>
<?php require __DIR__ . '/includes/guest-footer.php';
