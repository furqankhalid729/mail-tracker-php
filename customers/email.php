<?php
/** Email composer for one customer: send now or save a draft. Optional campaign link. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$customer = find_or_404('customers', input_int('customer_id'));
$cid = (int) $customer['id'];

$draft = null;
if (input_int('draft_id')) {
    $draft = q_one("SELECT * FROM email_messages WHERE id = ? AND workspace_id = ? AND customer_id = ? AND status = 'draft'", [input_int('draft_id'), $ws, $cid]);
    if (!$draft) {
        abort(404, 'Draft not found.');
    }
}

$accounts = q_all("SELECT id, name, email, provider, status FROM mail_accounts WHERE workspace_id = ? AND status <> 'disconnected' ORDER BY name", [$ws]);
$templates = q_all('SELECT id, name, subject, html_body, text_body FROM email_templates WHERE workspace_id = ? ORDER BY name', [$ws]);
$campaigns = q_all("SELECT id, name FROM campaigns WHERE workspace_id = ? AND status NOT IN ('archived') ORDER BY created_at DESC", [$ws]);
$errors = [];

if (is_post()) {
    verify_csrf();
    $mode = input('mode') === 'draft' ? 'draft' : 'send';
    $accountId = input_int('mail_account_id');
    $account = $accountId ? q_one('SELECT * FROM mail_accounts WHERE id = ? AND workspace_id = ?', [$accountId, $ws]) : null;
    $campaignId = input_int('campaign_id') ?: null;
    $campaign = $campaignId ? q_one('SELECT * FROM campaigns WHERE id = ? AND workspace_id = ?', [$campaignId, $ws]) : null;
    $subject = trim((string) input('subject'));
    $html = (string) ($_POST['html_body'] ?? '');
    $plainOnly = input('plain_only') === '1';
    [$cc, $badCc] = parse_email_list((string) input('cc'));
    [$bcc, $badBcc] = parse_email_list((string) input('bcc'));

    if (!$account && $mode === 'send') {
        $errors['mail_account_id'] = 'Choose a sending account.';
    }
    if ($campaignId && !$campaign) {
        $errors['campaign_id'] = 'Invalid campaign.';
    }
    if ($subject === '' && $mode === 'send') {
        $errors['subject'] = 'Subject is required.';
    }
    if (trim(strip_tags($html)) === '' && $mode === 'send') {
        $errors['body'] = 'Write a message first.';
    }
    if ($badCc || $badBcc) {
        $errors['cc'] = 'Invalid address: ' . implode(', ', [...$badCc, ...$badBcc]);
    }
    if ($campaign && $customer['unsubscribed_at']) {
        $errors['campaign_id'] = 'This customer unsubscribed; campaign emails cannot be sent to them.';
    }
    [$newAttachments, $attErrors] = store_uploaded_attachments('attachments', $ws);
    if ($attErrors) {
        $errors['attachments'] = implode(' ', $attErrors);
    }

    if (!$errors) {
        $id = transaction(function () use ($mode, $ws, $customer, $cid, $account, $campaign, $subject, $html, $plainOnly, $cc, $bcc, $draft, $newAttachments) {
            if ($campaign) {
                ensure_campaign_contact($ws, (int) $campaign['id'], $cid);
            }
            // Drafts keep {{variables}}; they are rendered at send time
            $finalSubject = $mode === 'send' ? render_vars($subject, $customer) : $subject;
            $finalHtml = $mode === 'send' ? render_vars($html, $customer, true) : $html;
            $threadId = $draft['thread_id'] ?? null;
            if ($mode === 'send' && !$threadId) {
                $threadId = create_thread($ws, $cid, $campaign ? (int) $campaign['id'] : null, $account ? (int) $account['id'] : null, $finalSubject);
            }
            $id = save_outbound_message([
                'workspace_id' => $ws,
                'customer' => $customer,
                'campaign_id' => $campaign ? (int) $campaign['id'] : null,
                'account' => $account,
                'thread_id' => $threadId,
                'user_id' => user_id(),
                'subject' => $finalSubject,
                'html' => $plainOnly ? '' : $finalHtml,
                'text' => $plainOnly ? html_to_text($finalHtml) : '',
                'cc' => $cc ? implode(', ', $cc) : null,
                'bcc' => $bcc ? implode(', ', $bcc) : null,
                'status' => $mode === 'send' ? 'queued' : 'draft',
                'message_id' => $draft['id'] ?? null,
            ]);
            attach_files_to_message($newAttachments, $id);
            foreach (input_ids('remove_attachments') as $aid) {
                $att = q_one('SELECT * FROM attachments WHERE id = ? AND email_message_id = ? AND workspace_id = ?', [$aid, $id, $ws]);
                if ($att) {
                    @unlink(UPLOAD_PATH . '/' . $att['storage_path']);
                    q('DELETE FROM attachments WHERE id = ?', [$aid]);
                }
            }
            return $id;
        });

        if ($mode === 'draft') {
            flash('success', 'Draft saved.');
            redirect(url('customers/email.php', ['customer_id' => $cid, 'draft_id' => $id]));
        }

        $message = q_one('SELECT * FROM email_messages WHERE id = ?', [$id]);
        record_event($message, 'queued', ['manual' => true], 'queued:' . $id);
        $result = send_message($id);
        if ($result['ok']) {
            flash('success', 'Email sent to ' . $customer['email'] . '.');
        } else {
            fail_message($id, (string) $result['error']);
            flash('error', 'Sending failed: ' . $result['error']);
        }
        redirect(url('customers/view.php', ['id' => $cid, 'tab' => 'emails']));
    }
    keep_old_input();
    $_SESSION['_old']['html_body'] = $html;
}

$existingAttachments = $draft ? q_all('SELECT * FROM attachments WHERE email_message_id = ?', [$draft['id']]) : [];
$val = fn(string $k, $default = '') => old($k, $draft[$k] ?? $default);
$customerVars = template_vars($customer);

$page_title = 'Email ' . customer_name($customer);
$active_nav = 'customers';
$extra_head = '<link rel="stylesheet" href="' . e(asset('vendor/quill.snow.css')) . '">';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('customers/view.php', ['id' => $cid])) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= e(customer_name($customer)) ?></a>
        <h1 class="page-title"><?= $draft ? 'Edit draft' : 'New email' ?></h1>
    </div>
</div>

<?php if (!$accounts): ?>
    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">No mail account connected yet. <a class="font-medium underline" href="<?= e(url('mail-accounts/create.php')) ?>">Add one</a> to send email.</div>
<?php endif; ?>
<?php if ($customer['unsubscribed_at']): ?>
    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">This customer unsubscribed on <?= e(format_dt($customer['unsubscribed_at'])) ?>. Only send a personal, non-marketing message.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" x-data="composer()" x-init="init()" @submit="sync()">
    <?= csrf_field() ?>
    <input type="hidden" name="html_body" x-ref="html">
    <div class="grid gap-6 xl:grid-cols-5">
        <div class="space-y-4 xl:col-span-3">
            <div class="card divide-y divide-slate-100">
                <div class="flex items-center gap-3 px-4 py-2.5">
                    <label class="w-16 shrink-0 text-xs font-medium text-slate-500" for="mail_account_id">From</label>
                    <select class="input !ring-0 !shadow-none !px-0" id="mail_account_id" name="mail_account_id">
                        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $val('mail_account_id') === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> &lt;<?= e($a['email']) ?>&gt;<?= $a['status'] === 'error' ? ' (error)' : '' ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?= field_error($errors, 'mail_account_id') ?>
                <div class="flex items-center gap-3 px-4 py-2.5">
                    <span class="w-16 shrink-0 text-xs font-medium text-slate-500">To</span>
                    <span class="text-sm"><?= e(customer_name($customer)) ?> &lt;<?= e($customer['email']) ?>&gt;</span>
                    <button type="button" class="ml-auto text-xs font-medium text-indigo-600" @click="showCc = !showCc">Cc / Bcc</button>
                </div>
                <div x-show="showCc" x-cloak class="flex items-center gap-3 px-4 py-2.5">
                    <label class="w-16 shrink-0 text-xs font-medium text-slate-500" for="cc">Cc</label>
                    <input class="input !ring-0 !shadow-none !px-0" id="cc" name="cc" value="<?= e($val('cc')) ?>" placeholder="name@example.com, …">
                </div>
                <div x-show="showCc" x-cloak class="flex items-center gap-3 px-4 py-2.5">
                    <label class="w-16 shrink-0 text-xs font-medium text-slate-500" for="bcc">Bcc</label>
                    <input class="input !ring-0 !shadow-none !px-0" id="bcc" name="bcc" value="<?= e($val('bcc')) ?>">
                </div>
                <?= field_error($errors, 'cc') ?>
                <div class="flex items-center gap-3 px-4 py-2.5">
                    <label class="w-16 shrink-0 text-xs font-medium text-slate-500" for="subject">Subject</label>
                    <input class="input !ring-0 !shadow-none !px-0 font-medium" id="subject" name="subject" x-model="subject" maxlength="255" placeholder="Quick question about {{company}}">
                </div>
                <?= field_error($errors, 'subject') ?>
            </div>

            <div>
                <div class="mb-2 flex flex-wrap items-center gap-1.5">
                    <span class="text-xs text-slate-500">Insert:</span>
                    <?php foreach (TEMPLATE_VARIABLES as $var): ?>
                        <button type="button" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 hover:bg-indigo-100 hover:text-indigo-700" @click="insertVar('<?= e($var) ?>')">{{<?= e($var) ?>}}</button>
                    <?php endforeach; ?>
                </div>
                <div x-ref="editor"></div>
                <?= field_error($errors, 'body') ?>
            </div>

            <div class="card p-4">
                <label class="label">Attachments</label>
                <?php foreach ($existingAttachments as $att): ?>
                    <label class="mb-1 flex items-center gap-2 text-sm text-slate-600">
                        <?= icon('paperclip', 'h-4 w-4 text-slate-400') ?> <?= e($att['filename']) ?> <span class="text-xs text-slate-400"><?= e(human_size((int) $att['size'])) ?></span>
                        <span class="ml-auto inline-flex items-center gap-1 text-xs text-red-600"><input type="checkbox" class="checkbox" name="remove_attachments[]" value="<?= (int) $att['id'] ?>"> remove</span>
                    </label>
                <?php endforeach; ?>
                <input type="file" name="attachments[]" multiple class="block w-full text-sm text-slate-500 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                       accept=".<?= e(implode(',.', array_keys(ALLOWED_ATTACHMENT_TYPES))) ?>">
                <p class="help">Max <?= UPLOAD_LIMIT_MB ?> MB each. Executables and scripts are rejected.</p>
                <?= field_error($errors, 'attachments') ?>
            </div>
        </div>

        <div class="space-y-4 xl:col-span-2">
            <div class="card p-4 space-y-4">
                <div>
                    <label class="label" for="template">Template</label>
                    <select id="template" class="input" @change="applyTemplate($event.target.value)">
                        <option value="">— None —</option>
                        <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="campaign_id">Campaign <span class="font-normal text-slate-400">(optional)</span></label>
                    <select id="campaign_id" name="campaign_id" class="input">
                        <option value="">No campaign</option>
                        <?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $val('campaign_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select>
                    <p class="help">The customer is added to the campaign if needed. Campaign emails include an unsubscribe link.</p>
                    <?= field_error($errors, 'campaign_id') ?>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="checkbox" name="plain_only" value="1" <?= old('plain_only') ? 'checked' : '' ?>> Send as plain text only (no open/click tracking)</label>
                <div class="flex gap-2 pt-1">
                    <button name="mode" value="send" class="btn-primary flex-1" <?= $accounts ? '' : 'disabled' ?>><?= icon('send', 'h-4 w-4') ?> Send now</button>
                    <button name="mode" value="draft" class="btn-secondary">Save draft</button>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title">Preview</h2><span class="text-xs text-slate-400">with <?= e(customer_name($customer)) ?>'s data</span></div>
                <div class="border-b border-slate-100 px-5 py-3 text-sm"><span class="text-slate-500">Subject:</span> <span class="font-medium" x-text="renderVars(subject, vars) || '(no subject)'"></span></div>
                <div class="prose-email max-h-[28rem] overflow-y-auto px-5 py-4" x-html="previewHtml"></div>
            </div>
        </div>
    </div>
</form>

<script src="<?= e(asset('vendor/quill.js')) ?>"></script>
<script>
function composer() {
    const templates = <?= json_encode(array_column($templates, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    return {
        quill: null, showCc: <?= $val('cc') || $val('bcc') ? 'true' : 'false' ?>,
        subject: <?= json_encode((string) $val('subject'), JSON_HEX_TAG) ?>,
        vars: <?= json_encode($customerVars, JSON_HEX_TAG) ?>,
        previewHtml: '',
        init() {
            this.quill = new Quill(this.$refs.editor, {
                theme: 'snow', placeholder: 'Hi {{firstName}},',
                modules: { toolbar: [[{ header: [false, 2, 3] }], ['bold', 'italic', 'underline', 'strike'], [{ color: [] }], [{ list: 'ordered' }, { list: 'bullet' }], ['blockquote', 'link'], ['clean']] },
            });
            const initial = <?= json_encode((string) old('html_body', $draft['html_body'] ?? ''), JSON_HEX_TAG) ?>;
            if (initial) this.quill.clipboard.dangerouslyPasteHTML(initial);
            this.quill.on('text-change', () => this.updatePreview());
            this.updatePreview();
        },
        html() { return this.quill.getText().trim() === '' ? '' : this.quill.root.innerHTML; },
        updatePreview() {
            // Preview is client-side only; the server re-sanitizes before sending
            const tmp = document.createElement('div');
            tmp.innerHTML = renderVars(this.html(), this.vars, true);
            tmp.querySelectorAll('script,iframe,object,embed').forEach(n => n.remove());
            this.previewHtml = tmp.innerHTML || '<p class="text-slate-400">Nothing to preview yet.</p>';
        },
        insertVar(v) {
            const range = this.quill.getSelection(true);
            this.quill.insertText(range ? range.index : this.quill.getLength(), '{{' + v + '}}', 'user');
        },
        async applyTemplate(id) {
            const t = templates[id];
            if (!t) return;
            if (this.quill.getText().trim() !== '' && !(await Alpine.store('confirm').ask({ title: 'Replace message?', message: 'The template will replace your current subject and body.', button: 'Use template', danger: false }))) return;
            this.subject = t.subject;
            this.quill.setContents([]);
            this.quill.clipboard.dangerouslyPasteHTML(t.html_body || (t.text_body || '').replace(/\n/g, '<br>'));
            this.updatePreview();
        },
        sync() { this.$refs.html.value = this.html(); },
    };
}
</script>
<?php require __DIR__ . '/../includes/footer.php';
