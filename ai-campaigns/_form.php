<?php
defined('APP_ROOT') || exit;
/** Expects $campaign, $errors, $accounts. */
$v = fn(string $k, mixed $default = '') => old($k, $campaign[$k] ?? $default);
$vars = implode(' ', array_map(fn($k) => '{{' . $k . '}}', TEMPLATE_VARIABLES));
?>
<?= csrf_field() ?>
<div class="card max-w-3xl">
    <div class="card-body space-y-5">
        <div>
            <label class="label" for="name">Campaign name</label>
            <input class="input" id="name" name="name" value="<?= e($v('name')) ?>" required maxlength="150" placeholder="Local plumbers — competitor angle">
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
                <label class="label" for="model">AI model (OpenRouter)</label>
                <input class="input font-mono text-sm" id="model" name="model" value="<?= e($v('model', OPENROUTER_DEFAULT_MODEL)) ?>" required maxlength="120" placeholder="<?= e(OPENROUTER_DEFAULT_MODEL) ?>">
                <p class="help">Any model id from openrouter.ai/models that supports tool calling.</p>
                <?= field_error($errors, 'model') ?>
            </div>
        </div>
        <div>
            <label class="label" for="research_prompt">Research prompt</label>
            <textarea class="input" id="research_prompt" name="research_prompt" rows="4" maxlength="5000" required placeholder="Look at {{website}}. Work out what {{company}} does and where. Find their top 3 local competitors and what those competitors do better online."><?= e($v('research_prompt')) ?></textarea>
            <p class="help">What the AI should find out about each customer. It reads their website, and searches the web when that is on.</p>
            <?= field_error($errors, 'research_prompt') ?>
        </div>
        <div>
            <label class="label" for="subject">Subject</label>
            <input class="input" id="subject" name="subject" value="<?= e($v('subject')) ?>" required maxlength="255" placeholder="A quick idea for {{company}}">
            <label class="mt-2 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="checkbox" name="keep_subject" value="1" <?= (int) $v('keep_subject', 0) ? 'checked' : '' ?>> Use this subject exactly (otherwise the AI may personalise it)</label>
            <?= field_error($errors, 'subject') ?>
        </div>
        <div>
            <label class="label" for="email_template">Email template</label>
            <textarea class="input font-mono text-sm" id="email_template" name="email_template" rows="10" maxlength="20000" required placeholder="Hi {{firstName}},&#10;&#10;[one line about something specific on their website]&#10;&#10;[what a competitor does that they don't, and how we can help]&#10;&#10;Worth a 15-minute call this week?&#10;&#10;Best,&#10;Sam"><?= e($v('email_template')) ?></textarea>
            <p class="help">The AI writes each email in this format: same structure, length, tone and sign-off, with the specifics filled in from its research.</p>
            <?= field_error($errors, 'email_template') ?>
        </div>
        <label class="flex items-start gap-2 text-sm text-slate-600">
            <input type="checkbox" class="checkbox mt-0.5" name="web_search" value="1" <?= (int) $v('web_search', 1) ? 'checked' : '' ?>>
            <span>Search the web for competitors <span class="text-slate-400">(about $0.001–0.005 extra per customer; off = the AI only reads the customer's own website)</span></span>
        </label>
        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">Variables: <code><?= e($vars) ?></code>. Emails are sent automatically with an unsubscribe link, by cron, within your sending limits. Use <b>Test on one customer</b> to check the result before starting.</p>
        <div class="flex justify-end gap-2">
            <a href="<?= e(url(!empty($campaign['id']) ? 'ai-campaigns/view.php?id=' . (int) $campaign['id'] : 'ai-campaigns/index.php')) ?>" class="btn-secondary">Cancel</a>
            <button class="btn-primary"><?= !empty($campaign['id']) ? 'Save changes' : 'Create &amp; add contacts' ?></button>
        </div>
    </div>
</div>
