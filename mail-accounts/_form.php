<?php
defined('APP_ROOT') || exit;
/** SMTP account form. Expects $account (array), $errors. Secrets are never echoed back. */
$v = fn(string $k, $d = '') => old($k, $account[$k] ?? $d);
$isEdit = !empty($account['id']);
?>
<?= csrf_field() ?>
<div class="grid gap-6 lg:grid-cols-3" x-data="{
    host: <?= e(json_encode((string) $v('smtp_host'))) ?>, port: <?= e(json_encode((string) $v('smtp_port', '587'))) ?>, enc: <?= e(json_encode((string) $v('smtp_encryption', 'tls'))) ?>,
    imapHost: <?= e(json_encode((string) $v('imap_host'))) ?>, imapPort: <?= e(json_encode((string) $v('imap_port', '993'))) ?>, imapEnc: <?= e(json_encode((string) $v('imap_encryption', 'ssl'))) ?>,
    preset(p) {
        const presets = {
            gmail: ['smtp.gmail.com', '587', 'tls', 'imap.gmail.com', '993', 'ssl'],
            hostinger: ['smtp.hostinger.com', '465', 'ssl', 'imap.hostinger.com', '993', 'ssl'],
            outlook: ['smtp.office365.com', '587', 'tls', 'outlook.office365.com', '993', 'ssl'],
            zoho: ['smtp.zoho.com', '465', 'ssl', 'imap.zoho.com', '993', 'ssl'],
        };
        [this.host, this.port, this.enc, this.imapHost, this.imapPort, this.imapEnc] = presets[p];
    }
}">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Sender</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="name">Account label</label><input class="input" id="name" name="name" value="<?= e($v('name')) ?>" required maxlength="120" placeholder="Sales mailbox"><?= field_error($errors, 'name') ?></div>
                <div><label class="label" for="email">From email</label><input class="input" id="email" type="email" name="email" value="<?= e($v('email')) ?>" required maxlength="190"><?= field_error($errors, 'email') ?></div>
                <div><label class="label" for="from_name">From name</label><input class="input" id="from_name" name="from_name" value="<?= e($v('from_name')) ?>" maxlength="120" placeholder="Furqan from Acme"></div>
                <div><label class="label" for="reply_to">Reply-To <span class="font-normal text-slate-400">(optional)</span></label><input class="input" id="reply_to" type="email" name="reply_to" value="<?= e($v('reply_to')) ?>" maxlength="190"><?= field_error($errors, 'reply_to') ?></div>
                <div><label class="label" for="daily_limit">Daily sending limit</label><input class="input" id="daily_limit" type="number" min="1" max="10000" name="daily_limit" value="<?= e($v('daily_limit', '100')) ?>" required><p class="help">Gmail: ~500/day, Workspace: ~2000/day. Start low to protect reputation.</p><?= field_error($errors, 'daily_limit') ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">SMTP (outgoing)</h2>
                <div class="flex flex-wrap gap-1">
                    <span class="mr-1 self-center text-xs text-slate-400">Presets:</span>
                    <button type="button" class="btn-ghost btn-sm" @click="preset('hostinger')">Hostinger</button>
                    <button type="button" class="btn-ghost btn-sm" @click="preset('gmail')">Gmail</button>
                    <button type="button" class="btn-ghost btn-sm" @click="preset('outlook')">Outlook</button>
                    <button type="button" class="btn-ghost btn-sm" @click="preset('zoho')">Zoho</button>
                </div>
            </div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3"><label class="label" for="smtp_host">Host</label><input class="input" id="smtp_host" name="smtp_host" x-model="host" required maxlength="190" placeholder="smtp.example.com"><?= field_error($errors, 'smtp_host') ?></div>
                <div class="sm:col-span-1"><label class="label" for="smtp_port">Port</label><input class="input" id="smtp_port" type="number" name="smtp_port" x-model="port" required><?= field_error($errors, 'smtp_port') ?></div>
                <div class="sm:col-span-2"><label class="label" for="smtp_encryption">Encryption</label>
                    <select class="input" id="smtp_encryption" name="smtp_encryption" x-model="enc"><option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">None</option></select></div>
                <div class="sm:col-span-3"><label class="label" for="smtp_username">Username</label><input class="input" id="smtp_username" name="smtp_username" value="<?= e($v('smtp_username')) ?>" maxlength="190" placeholder="Usually the email address" autocomplete="off"></div>
                <div class="sm:col-span-3"><label class="label" for="smtp_password">Password<?= $isEdit ? ' <span class="font-normal text-slate-400">(leave blank to keep)</span>' : '' ?></label>
                    <input class="input" id="smtp_password" type="password" name="smtp_password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> placeholder="<?= $isEdit && !empty($account['encrypted_smtp_password']) ? '•••••••• (saved)' : '' ?>">
                    <p class="help">For Gmail with 2-step verification use an App Password. Stored encrypted.</p><?= field_error($errors, 'smtp_password') ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div><h2 class="card-title">IMAP (replies &amp; bounces)</h2><p class="text-xs text-slate-500">Optional. Cron checks this mailbox for replies and bounce notices.</p></div></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3"><label class="label" for="imap_host">Host</label><input class="input" id="imap_host" name="imap_host" x-model="imapHost" maxlength="190" placeholder="imap.example.com"></div>
                <div class="sm:col-span-1"><label class="label" for="imap_port">Port</label><input class="input" id="imap_port" type="number" name="imap_port" x-model="imapPort"></div>
                <div class="sm:col-span-2"><label class="label" for="imap_encryption">Encryption</label>
                    <select class="input" id="imap_encryption" name="imap_encryption" x-model="imapEnc"><option value="ssl">SSL/TLS (993)</option><option value="tls">STARTTLS (143)</option><option value="none">None</option></select></div>
                <div class="sm:col-span-3"><label class="label" for="imap_username">Username <span class="font-normal text-slate-400">(defaults to SMTP)</span></label><input class="input" id="imap_username" name="imap_username" value="<?= e($v('imap_username')) ?>" maxlength="190" autocomplete="off"></div>
                <div class="sm:col-span-3"><label class="label" for="imap_password">Password <span class="font-normal text-slate-400">(blank = <?= $isEdit ? 'keep / ' : '' ?>same as SMTP)</span></label><input class="input" id="imap_password" type="password" name="imap_password" autocomplete="new-password"></div>
            </div>
        </div>
    </div>
    <div class="space-y-4">
        <div class="card card-body text-sm text-slate-600">
            <h3 class="font-semibold text-slate-900">About delivery tracking</h3>
            <p class="mt-2">With SMTP, <b>sent</b> means the server accepted the message — not that it reached the inbox. Bounces arrive later and are picked up via IMAP or provider webhooks.</p>
            <p class="mt-2">Credentials are encrypted with your server's <code>ENCRYPTION_KEY</code> and never sent to the browser.</p>
        </div>
        <button class="btn-primary w-full py-2.5">Save account</button>
    </div>
</div>
