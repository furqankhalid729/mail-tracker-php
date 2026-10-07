<?php
require_once __DIR__ . '/includes/init.php';
require_guest();

$firstUser = !q_val('SELECT id FROM users LIMIT 1');
if (!ALLOW_REGISTRATION && !$firstUser) {
    abort(403, 'Registration is disabled. Ask an administrator to add you to a workspace.');
}

$errors = [];
if (is_post()) {
    verify_csrf();
    if (!rate_limit('register:' . client_ip(), 10, 3600)) {
        $errors['email'] = 'Too many registrations from your network. Try again later.';
    } else {
        $errors = validate($_POST, [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190',
            'workspace' => 'max:120',
            'password' => 'required|min:8|max:200',
            'password_confirmation' => 'required|same:password',
        ], ['password_confirmation' => 'Password']);
        $email = strtolower(trim((string) input('email')));
        if (!$errors && q_val('SELECT id FROM users WHERE email = ?', [$email])) {
            $errors['email'] = 'An account with this email already exists.';
        }
        if (!$errors) {
            $userId = create_user_with_workspace(trim((string) input('name')), $email, (string) $_POST['password'], trim((string) input('workspace')) ?: null);
            login_user(q_one('SELECT * FROM users WHERE id = ?', [$userId]));
            flash('success', 'Welcome! Start by adding a mail account and importing customers.');
            redirect('dashboard.php');
        }
    }
    keep_old_input();
}

$page_title = 'Create account';
require __DIR__ . '/includes/guest-header.php';
?>
<h1 class="text-2xl font-semibold tracking-tight">Create your account</h1>
<p class="mt-1 text-sm text-slate-500">A workspace is created for you automatically.</p>

<form method="post" class="mt-8 space-y-4">
    <?= csrf_field() ?>
    <div>
        <label class="label" for="name">Your name</label>
        <input class="input" id="name" name="name" value="<?= e(old('name')) ?>" required autofocus>
        <?= field_error($errors, 'name') ?>
    </div>
    <div>
        <label class="label" for="email">Work email</label>
        <input class="input" id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email">
        <?= field_error($errors, 'email') ?>
    </div>
    <div>
        <label class="label" for="workspace">Workspace name <span class="font-normal text-slate-400">(optional)</span></label>
        <input class="input" id="workspace" name="workspace" value="<?= e(old('workspace')) ?>" placeholder="Acme Sales">
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="label" for="password">Password</label>
            <input class="input" id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div>
            <label class="label" for="password_confirmation">Confirm</label>
            <input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>
    </div>
    <?= field_error($errors, 'password') . field_error($errors, 'password_confirmation') ?>
    <button class="btn-primary w-full py-2.5">Create account</button>
</form>
<p class="mt-6 text-center text-sm text-slate-500">Already registered? <a class="font-medium text-indigo-600 hover:text-indigo-500" href="<?= e(url('login.php')) ?>">Sign in</a></p>
<?php require __DIR__ . '/includes/guest-footer.php';
