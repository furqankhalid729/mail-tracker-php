<?php
defined('APP_ROOT') || exit;
/** Expects $campaign, $errors, $accounts, $templates. */
$v = fn(string $k) => old($k, $campaign[$k] ?? '');
?>
<?= csrf_field() ?>
<div class="card max-w-2xl">
    <div class="card-body space-y-5">
        <div>
            <label class="label" for="name">Campaign name</label>
            <input class="input" id="name" name="name" value="<?= e($v('name')) ?>" required maxlength="150" placeholder="Shopify Outreach — Q4">
            <?= field_error($errors, 'name') ?>
        </div>
        <div>
            <label class="label" for="description">Description</label>
            <textarea class="input" id="description" name="description" rows="2" maxlength="2000"><?= e($v('description')) ?></textarea>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="mail_account_id">Send from</label>
                <select class="input" id="mail_account_id" name="mail_account_id">
                    <option value="">Choose later</option>
                    <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $v('mail_account_id') === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (<?= e($a['email']) ?>)</option><?php endforeach; ?>
                </select>
                <?php if (!$accounts): ?><p class="help"><a class="text-indigo-600" href="<?= e(url('mail-accounts/create.php')) ?>">Add a mail account</a> first.</p><?php endif; ?>
                <?= field_error($errors, 'mail_account_id') ?>
            </div>
            <div>
                <label class="label" for="template_id">Email template</label>
                <select class="input" id="template_id" name="template_id">
                    <option value="">Choose later</option>
                    <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $v('template_id') === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                </select>
                <?php if (!$templates): ?><p class="help"><a class="text-indigo-600" href="<?= e(url('templates/create.php')) ?>">Create a template</a> first.</p><?php endif; ?>
                <?= field_error($errors, 'template_id') ?>
            </div>
        </div>
        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">Each contact gets their own personalised copy of the template with an unsubscribe link. Emails are queued when you start the campaign and sent by cron within your sending limits.</p>
        <div class="flex justify-end gap-2">
            <a href="<?= e(url(!empty($campaign['id']) ? 'campaigns/view.php?id=' . (int) $campaign['id'] : 'campaigns/index.php')) ?>" class="btn-secondary">Cancel</a>
            <button class="btn-primary"><?= !empty($campaign['id']) ? 'Save changes' : 'Create &amp; add contacts' ?></button>
        </div>
    </div>
</div>
