<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$thread = find_or_404('email_threads', input_int('id'));
$tid = (int) $thread['id'];
$customer = q_one('SELECT * FROM customers WHERE id = ? AND workspace_id = ?', [$thread['customer_id'], $ws]);
if (!$customer) {
    abort(404, 'Customer not found.');
}

$messages = q_all(
    "SELECT m.*, u.name user_name FROM email_messages m LEFT JOIN users u ON u.id = m.user_id
     WHERE m.thread_id = ? AND m.workspace_id = ? AND m.status NOT IN ('draft','cancelled') ORDER BY COALESCE(m.sent_at, m.created_at), m.id",
    [$tid, $ws]
);
$attachments = [];
if ($messages) {
    foreach (q_all('SELECT * FROM attachments WHERE email_message_id IN (' . placeholders(array_column($messages, 'id')) . ')', array_column($messages, 'id')) as $a) {
        $attachments[$a['email_message_id']][] = $a;
    }
}
// Opening the conversation marks it read
if ($thread['is_unread']) {
    q('UPDATE email_threads SET is_unread = 0 WHERE id = ?', [$tid]);
    q("UPDATE email_messages SET is_read = 1 WHERE thread_id = ? AND direction = 'inbound'", [$tid]);
}

$accounts = q_all("SELECT id, name, email FROM mail_accounts WHERE workspace_id = ? AND status <> 'disconnected' ORDER BY name", [$ws]);
$defaultAccount = (int) ($thread['mail_account_id'] ?? 0);
$membership = $thread['campaign_id'] ? q_one('SELECT cc.*, cp.name FROM campaign_contacts cc JOIN campaigns cp ON cp.id = cc.campaign_id WHERE cc.campaign_id = ? AND cc.customer_id = ?', [$thread['campaign_id'], $customer['id']]) : null;
$campaigns = q_all("SELECT id, name FROM campaigns WHERE workspace_id = ? AND status <> 'archived' ORDER BY created_at DESC", [$ws]);
$notes = q_all('SELECT n.*, u.name author FROM notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.customer_id = ? ORDER BY n.created_at DESC LIMIT 5', [$customer['id']]);
$tags = tags_for_customers([(int) $customer['id']])[$customer['id']] ?? [];
$name = customer_name($customer);
$returnTo = 'inbox/thread.php?id=' . $tid;

$page_title = $thread['subject'] ?: 'Conversation';
$active_nav = 'inbox';
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= e(url('inbox/index.php')) ?>" class="mb-3 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Inbox</a>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <h1 class="mb-4 text-lg font-semibold"><?= e($thread['subject'] ?: '(no subject)') ?></h1>
        <div class="space-y-4">
            <?php foreach ($messages as $m):
                $mine = $m['direction'] === 'outbound';
                $html = (string) $m['html_body']; ?>
                <div class="card overflow-hidden <?= $mine ? '' : 'ring-1 ring-emerald-200' ?>">
                    <div class="flex items-start gap-3 border-b border-slate-100 px-5 py-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= $mine ? 'bg-indigo-600 text-white' : avatar_color($customer['email']) ?>"><?= e(initials($mine ? ($m['from_name'] ?: $m['user_name'] ?: $m['from_email']) : $name)) ?></span>
                        <div class="min-w-0 flex-1 text-sm">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                <span class="font-semibold text-slate-900"><?= $mine ? 'You' . ($m['user_name'] ? ' (' . e($m['user_name']) . ')' : '') : e($m['from_name'] ?: $name) ?></span>
                                <span class="text-xs text-slate-400"><?= e(format_dt($m['sent_at'] ?: $m['created_at'])) ?></span>
                            </div>
                            <div class="truncate text-xs text-slate-500"><?= e($m['from_email']) ?> → <?= e($m['to_email']) ?><?= $m['cc'] ? ' · cc ' . e($m['cc']) : '' ?></div>
                        </div>
                        <?php if ($mine): ?>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <?= status_badge($m['status']) ?>
                                <?php if ($m['open_count']): ?><span class="badge badge-violet tooltip" data-tip="Approximate">👁 <?= (int) $m['open_count'] ?></span><?php endif; ?>
                                <?php if ($m['click_count']): ?><span class="badge badge-indigo">↗ <?= (int) $m['click_count'] ?></span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="px-5 py-4">
                        <?php if (trim(strip_tags($html)) !== ''): ?>
                            <!-- Sandboxed (no scripts) and sanitized on save -->
                            <iframe sandbox="allow-same-origin allow-popups" class="w-full" style="min-height: 60px" title="Email body"
                                    srcdoc="<?= e('<base target="_blank"><style>body{font-family:Inter,Arial,sans-serif;font-size:14px;line-height:1.6;color:#1e293b;margin:0;overflow-wrap:anywhere}img{max-width:100%;height:auto}blockquote{border-left:2px solid #e2e8f0;margin:0;padding-left:12px;color:#64748b}a{color:#4f46e5}</style>' . $html) ?>"
                                    onload="this.style.height = (this.contentDocument.documentElement.scrollHeight + 4) + 'px'"></iframe>
                        <?php else: ?>
                            <div class="whitespace-pre-line text-sm leading-relaxed text-slate-800"><?= e($mine ? $m['text_body'] : strip_quoted_reply((string) $m['text_body'])) ?></div>
                        <?php endif; ?>
                        <?php foreach ($attachments[$m['id']] ?? [] as $a): ?>
                            <span class="mr-2 mt-3 inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600"><?= icon('paperclip', 'h-3.5 w-3.5') ?> <?= e($a['filename']) ?> · <?= e(human_size((int) $a['size'])) ?></span>
                        <?php endforeach; ?>
                        <?php if ($m['status'] === 'failed' || $m['status'] === 'bounced'): ?><p class="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700"><?= e($m['error_message'] ?: 'Delivery failed') ?></p><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$messages): ?><p class="card py-10 text-center text-sm text-slate-400">No sent messages in this conversation yet.</p><?php endif; ?>
        </div>

        <!-- Reply / forward -->
        <form method="post" action="<?= e(url('inbox/reply.php')) ?>" enctype="multipart/form-data" class="card mt-6" x-data="{ mode: 'reply' }">
            <?= csrf_field() ?><input type="hidden" name="thread_id" value="<?= $tid ?>">
            <div class="flex items-center gap-4 border-b border-slate-100 px-5 pt-3">
                <button type="button" class="tab" :class="mode === 'reply' && 'active'" @click="mode = 'reply'"><?= icon('reply', 'h-4 w-4') ?> Reply</button>
                <button type="button" class="tab" :class="mode === 'forward' && 'active'" @click="mode = 'forward'"><?= icon('forward', 'h-4 w-4') ?> Forward</button>
                <input type="hidden" name="mode" :value="mode">
            </div>
            <div class="space-y-3 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label">From</label>
                        <select name="mail_account_id" class="input">
                            <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $defaultAccount ? 'selected' : '' ?>><?= e($a['email']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div x-show="mode === 'reply'"><label class="label">To</label><input class="input bg-slate-50" value="<?= e($customer['email']) ?>" disabled></div>
                    <div x-show="mode === 'forward'" x-cloak><label class="label">Forward to</label><input class="input" type="email" name="forward_to" placeholder="colleague@company.com" :required="mode === 'forward'"></div>
                </div>
                <div><label class="label">Cc <span class="font-normal text-slate-400">(optional)</span></label><input class="input" name="cc" placeholder="name@example.com"></div>
                <textarea name="body" rows="6" class="input" required placeholder="Write your message… ({{firstName}} works here too)"></textarea>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <input type="file" name="attachments[]" multiple class="text-xs text-slate-500 file:mr-2 file:rounded-md file:border-0 file:bg-slate-100 file:px-2.5 file:py-1 file:text-xs file:font-medium">
                    <button class="btn-primary" <?= $accounts ? '' : 'disabled' ?>><?= icon('send', 'h-4 w-4') ?> <span x-text="mode === 'reply' ? 'Send reply' : 'Forward'"></span></button>
                </div>
                <p class="text-xs text-slate-500" x-show="mode === 'reply'">Sent in the same thread (In-Reply-To / References preserved).</p>
            </div>
        </form>
    </div>

    <!-- Sidebar -->
    <div class="space-y-4">
        <div class="card p-5">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-full text-sm font-semibold <?= avatar_color($customer['email']) ?>"><?= e(initials($name)) ?></span>
                <div class="min-w-0">
                    <a href="<?= e(url('customers/view.php', ['id' => $customer['id']])) ?>" class="block truncate font-semibold text-slate-900 hover:text-indigo-600"><?= e($name) ?></a>
                    <div class="truncate text-xs text-slate-500"><?= e($customer['company'] ?: $customer['email']) ?></div>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-1"><?php foreach ($tags as $t) echo tag_chip($t); ?></div>
            <form method="post" action="<?= e(url('customers/actions.php')) ?>" class="mt-4">
                <?= csrf_field() ?><input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>"><input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <?php if ($membership): ?>
                    <input type="hidden" name="action" value="campaign_status"><input type="hidden" name="campaign_id" value="<?= (int) $membership['campaign_id'] ?>">
                    <label class="label">CRM stage in “<?= e($membership['name']) ?>”</label>
                    <select name="manual_status" class="input" onchange="this.form.submit()"><?php foreach (CRM_STATUSES as $s): ?><option <?= $membership['manual_status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
                    <p class="mt-1 text-xs text-slate-500">Email status: <?= status_badge($membership['system_status']) ?></p>
                <?php else: ?>
                    <input type="hidden" name="action" value="crm_status">
                    <label class="label">CRM stage</label>
                    <select name="crm_status" class="input" onchange="this.form.submit()"><?php foreach (CRM_STATUSES as $s): ?><option <?= $customer['crm_status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
                <?php endif; ?>
            </form>
        </div>

        <form method="post" action="<?= e(url('customers/actions.php')) ?>" class="card p-5">
            <?= csrf_field() ?><input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>"><input type="hidden" name="action" value="campaign_add"><input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <label class="label">Assign to campaign</label>
            <div class="flex gap-2">
                <select name="campaign_id" class="input"><?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
                <button class="btn-secondary" <?= $campaigns ? '' : 'disabled' ?>>Add</button>
            </div>
        </form>

        <div class="card p-5">
            <form method="post" action="<?= e(url('customers/actions.php')) ?>">
                <?= csrf_field() ?><input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>"><input type="hidden" name="action" value="note_add"><input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <label class="label">Add note</label>
                <textarea name="body" rows="3" class="input" required maxlength="10000" placeholder="Called them, interested in Pro plan…"></textarea>
                <button class="btn-secondary btn-sm mt-2">Save note</button>
            </form>
            <?php foreach ($notes as $n): ?>
                <div class="mt-3 rounded-lg bg-yellow-50 px-3 py-2 text-sm">
                    <p class="whitespace-pre-line text-slate-700"><?= e($n['body']) ?></p>
                    <p class="mt-1 text-[11px] text-slate-400"><?= e($n['author'] ?? '') ?> · <?= e(time_ago($n['created_at'])) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
