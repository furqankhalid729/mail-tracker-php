<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$customer = find_or_404('customers', input_int('id'));
$cid = (int) $customer['id'];
$name = customer_name($customer);
$tab = in_array(input('tab'), ['activity', 'emails', 'notes', 'campaigns'], true) ? input('tab') : 'activity';

$tags = tags_for_customers([$cid])[$cid] ?? [];
$allTags = workspace_tags($ws);
$memberships = q_all(
    'SELECT cc.*, cp.name, cp.status AS campaign_status FROM campaign_contacts cc JOIN campaigns cp ON cp.id = cc.campaign_id
     WHERE cc.customer_id = ? ORDER BY cc.assigned_at DESC',
    [$cid]
);
$availableCampaigns = q_all(
    "SELECT id, name FROM campaigns WHERE workspace_id = ? AND status <> 'archived' AND id NOT IN (SELECT campaign_id FROM campaign_contacts WHERE customer_id = ?) ORDER BY created_at DESC",
    [$ws, $cid]
);
$custom = json_decode((string) $customer['custom_fields'], true) ?: [];
$stats = q_one(
    "SELECT SUM(direction = 'outbound' AND sent_at IS NOT NULL) sent, SUM(first_opened_at IS NOT NULL) opened,
            SUM(first_clicked_at IS NOT NULL) clicked, SUM(direction = 'inbound') replies
     FROM email_messages WHERE workspace_id = ? AND customer_id = ?",
    [$ws, $cid]
);

// Unified timeline: email events + CRM activity + notes
$timeline = q_all(
    "(SELECT 'event' kind, ev.type, ev.created_at, ev.metadata, cp.name campaign, m.subject, NULL body, NULL author, m.thread_id
        FROM email_events ev LEFT JOIN campaigns cp ON cp.id = ev.campaign_id LEFT JOIN email_messages m ON m.id = ev.email_message_id
        WHERE ev.customer_id = ? AND ev.workspace_id = ?)
     UNION ALL
     (SELECT 'activity', a.type, a.created_at, NULL, cp.name, NULL, a.description, u.name, NULL
        FROM activity_log a LEFT JOIN campaigns cp ON cp.id = a.campaign_id LEFT JOIN users u ON u.id = a.user_id
        WHERE a.customer_id = ? AND a.workspace_id = ?)
     UNION ALL
     (SELECT 'note', 'note_added', n.created_at, NULL, NULL, NULL, n.body, u.name, NULL
        FROM notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.customer_id = ? AND n.workspace_id = ?)
     ORDER BY created_at DESC LIMIT 150",
    [$cid, $ws, $cid, $ws, $cid, $ws]
);

$emails = q_all(
    'SELECT m.*, cp.name campaign_name, (SELECT COUNT(*) FROM attachments a WHERE a.email_message_id = m.id) att
     FROM email_messages m LEFT JOIN campaigns cp ON cp.id = m.campaign_id
     WHERE m.workspace_id = ? AND m.customer_id = ? ORDER BY m.created_at DESC LIMIT 100',
    [$ws, $cid]
);
$notes = q_all('SELECT n.*, u.name author FROM notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.customer_id = ? AND n.workspace_id = ? ORDER BY n.created_at DESC', [$cid, $ws]);

$page_title = $name;
$active_nav = 'customers';
require __DIR__ . '/../includes/header.php';
$actionUrl = url('customers/actions.php');
?>
<a href="<?= e(url('customers/index.php')) ?>" class="mb-3 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Customers</a>

<div class="card mb-6 p-5">
    <div class="flex flex-col gap-5 md:flex-row md:items-start">
        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full text-lg font-semibold <?= avatar_color($customer['email']) ?>"><?= e(initials($name)) ?></span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold"><?= e($name) ?></h1>
                <?= status_badge($customer['status']) ?>
            </div>
            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
                <?php if ($customer['job_title'] || $customer['company']): ?><span><?= e(trim($customer['job_title'] . ($customer['job_title'] && $customer['company'] ? ' at ' : '') . $customer['company'])) ?></span><?php endif; ?>
                <a href="mailto:<?= e($customer['email']) ?>" class="hover:text-indigo-600"><?= e($customer['email']) ?></a>
                <?php if ($customer['phone']): ?><span><?= e($customer['phone']) ?></span><?php endif; ?>
                <?php if ($customer['website']): ?><a href="<?= e($customer['website']) ?>" target="_blank" rel="noopener noreferrer" class="hover:text-indigo-600"><?= e(preg_replace('#^https?://#', '', $customer['website'])) ?></a><?php endif; ?>
                <?php if ($customer['country']): ?><span><?= e($customer['country']) ?></span><?php endif; ?>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-1.5" x-data="{ open: false }">
                <?php foreach ($tags as $t) echo tag_chip($t); ?>
                <div class="relative" @click.outside="open = false">
                    <button type="button" class="rounded-full border border-dashed border-slate-300 px-2 py-0.5 text-xs text-slate-500 hover:border-slate-400" @click="open = !open">+ Tag</button>
                    <form x-show="open" x-cloak method="post" action="<?= e($actionUrl) ?>" class="dropdown left-0 max-h-72 overflow-y-auto p-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="tags"><input type="hidden" name="customer_id" value="<?= $cid ?>">
                        <?php foreach ($allTags as $t): ?>
                            <label class="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                                <input type="checkbox" class="checkbox" name="tags[]" value="<?= (int) $t['id'] ?>" <?= in_array($t['id'], array_column($tags, 'id')) ? 'checked' : '' ?>>
                                <span class="h-2 w-2 rounded-full" style="background: <?= e($t['color']) ?>"></span><?= e($t['name']) ?>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$allTags): ?><p class="px-2 py-1 text-xs text-slate-400">No tags. <a class="text-indigo-600" href="<?= e(url('tags/index.php')) ?>">Create one</a></p><?php endif; ?>
                        <button class="btn-primary btn-sm mt-2 w-full">Save tags</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 md:justify-end">
            <a href="<?= e(url('customers/email.php', ['customer_id' => $cid])) ?>" class="btn-primary"><?= icon('send', 'h-4 w-4') ?> Send email</a>
            <a href="<?= e(url('customers/edit.php', ['id' => $cid])) ?>" class="btn-secondary"><?= icon('pencil', 'h-4 w-4') ?> Edit</a>
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" class="btn-secondary" @click="open = !open">More <?= icon('chevron-down', 'h-4 w-4') ?></button>
                <div x-show="open" x-cloak class="dropdown right-0">
                    <form method="post" action="<?= e($actionUrl) ?>">
                        <?= csrf_field() ?><input type="hidden" name="customer_id" value="<?= $cid ?>">
                        <?php if ($customer['unsubscribed_at']): ?>
                            <button class="dropdown-item" name="action" value="resubscribe">Resubscribe</button>
                        <?php else: ?>
                            <button class="dropdown-item" name="action" value="unsubscribe">Mark unsubscribed</button>
                        <?php endif; ?>
                    </form>
                    <form method="post" action="<?= e(url('customers/delete.php')) ?>" data-confirm="Delete <?= e($name) ?> and all related emails, notes and activity?" data-confirm-button="Delete">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>">
                        <button class="dropdown-item text-red-600">Delete customer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php if ($customer['unsubscribed_at']): ?>
        <div class="mt-4 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-800 ring-1 ring-amber-200">Unsubscribed on <?= e(format_dt($customer['unsubscribed_at'])) ?>. Campaign emails are suppressed for this contact.</div>
    <?php endif; ?>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <div class="mb-4 flex gap-6 border-b border-slate-200">
            <?php foreach (['activity' => 'Activity', 'emails' => 'Emails (' . count($emails) . ')', 'notes' => 'Notes (' . count($notes) . ')', 'campaigns' => 'Campaigns (' . count($memberships) . ')'] as $k => $label): ?>
                <a href="<?= e(url('customers/view.php', ['id' => $cid, 'tab' => $k])) ?>" class="tab <?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($tab === 'activity'): ?>
            <div class="card p-5">
                <?php if (!$timeline): ?><p class="py-8 text-center text-sm text-slate-400">No activity yet.</p><?php endif; ?>
                <ol class="relative space-y-5">
                    <?php foreach ($timeline as $i => $t):
                        $meta = $t['metadata'] ? (json_decode($t['metadata'], true) ?: []) : []; ?>
                        <li class="relative flex gap-3">
                            <?php if ($i < count($timeline) - 1): ?><span class="absolute left-4 top-9 -bottom-5 w-px bg-slate-200"></span><?php endif; ?>
                            <?= event_icon($t['type']) ?>
                            <div class="min-w-0 flex-1 pt-1">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <p class="text-sm font-medium text-slate-800">
                                        <?php if ($t['kind'] === 'activity'): ?><?= e($t['body']) ?>
                                        <?php elseif ($t['kind'] === 'note'): ?>Note<?= $t['author'] ? ' by ' . e($t['author']) : '' ?>
                                        <?php else: ?><?= e(event_label($t['type'])) ?><?php endif; ?>
                                    </p>
                                    <time class="text-xs text-slate-400" title="<?= e(format_dt($t['created_at'])) ?>"><?= e(format_dt($t['created_at'], 'M j, g:i A')) ?></time>
                                </div>
                                <?php if ($t['kind'] === 'note'): ?>
                                    <p class="mt-1 whitespace-pre-line rounded-lg bg-yellow-50 px-3 py-2 text-sm text-slate-700"><?= e($t['body']) ?></p>
                                <?php elseif ($t['kind'] === 'event'): ?>
                                    <p class="text-xs text-slate-500">
                                        <?php if ($t['subject']): ?>“<?= e($t['subject']) ?>”<?php endif; ?>
                                        <?php if ($t['campaign']): ?> · <?= e($t['campaign']) ?><?php endif; ?>
                                        <?php if (!empty($meta['url'])): ?> · <span class="text-indigo-600"><?= e(mb_strimwidth($meta['url'], 0, 70, '…')) ?></span><?php endif; ?>
                                        <?php if (!empty($meta['reason'])): ?> · <?= e($meta['reason']) ?><?php endif; ?>
                                        <?php if (!empty($meta['error'])): ?> · <span class="text-red-600"><?= e($meta['error']) ?></span><?php endif; ?>
                                    </p>
                                    <?php if ($t['type'] === 'replied' && $t['thread_id']): ?><a class="text-xs font-medium text-indigo-600" href="<?= e(url('inbox/thread.php', ['id' => $t['thread_id']])) ?>">Open conversation →</a><?php endif; ?>
                                <?php elseif ($t['campaign']): ?>
                                    <p class="text-xs text-slate-500"><?= e($t['campaign']) ?><?= $t['author'] ? ' · by ' . e($t['author']) : '' ?></p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>

        <?php elseif ($tab === 'emails'): ?>
            <div class="card divide-y divide-slate-100">
                <?php foreach ($emails as $m): ?>
                    <div class="flex items-start gap-3 px-5 py-3.5">
                        <span class="mt-0.5 text-slate-400"><?= icon($m['direction'] === 'inbound' ? 'reply' : 'send', 'h-4 w-4') ?></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ($m['thread_id']): ?>
                                    <a href="<?= e(url('inbox/thread.php', ['id' => $m['thread_id']])) ?>" class="truncate text-sm font-medium text-slate-900 hover:text-indigo-600"><?= e($m['subject'] ?: '(no subject)') ?></a>
                                <?php else: ?>
                                    <span class="truncate text-sm font-medium text-slate-900"><?= e($m['subject'] ?: '(no subject)') ?></span>
                                <?php endif; ?>
                                <?= status_badge($m['status']) ?>
                                <?php if ($m['att']): ?><span class="text-slate-400"><?= icon('paperclip', 'h-3.5 w-3.5') ?></span><?php endif; ?>
                            </div>
                            <p class="mt-0.5 line-clamp-1 text-xs text-slate-500"><?= e(mb_strimwidth((string) $m['text_body'], 0, 160, '…')) ?></p>
                            <p class="mt-1 text-xs text-slate-400">
                                <?= $m['direction'] === 'inbound' ? 'From ' . e($m['from_email']) : 'From ' . e($m['from_email'] ?: '—') ?>
                                <?= $m['campaign_name'] ? ' · ' . e($m['campaign_name']) : '' ?>
                                <?php if ($m['open_count']): ?> · opened <?= (int) $m['open_count'] ?>× ~<?php endif; ?>
                                <?php if ($m['click_count']): ?> · <?= (int) $m['click_count'] ?> click<?= $m['click_count'] > 1 ? 's' : '' ?><?php endif; ?>
                                <?php if ($m['error_message'] && in_array($m['status'], ['failed', 'bounced', 'cancelled', 'queued'], true)): ?> · <span class="text-red-600"><?= e($m['error_message']) ?></span><?php endif; ?>
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-xs text-slate-400"><?= e(format_dt($m['sent_at'] ?: $m['created_at'], 'M j, g:i A')) ?></div>
                            <?php if ($m['status'] === 'draft'): ?><a href="<?= e(url('customers/email.php', ['customer_id' => $cid, 'draft_id' => $m['id']])) ?>" class="text-xs font-medium text-indigo-600">Edit draft</a><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$emails): ?><p class="py-10 text-center text-sm text-slate-400">No emails yet. <a class="text-indigo-600" href="<?= e(url('customers/email.php', ['customer_id' => $cid])) ?>">Send the first one</a></p><?php endif; ?>
            </div>

        <?php elseif ($tab === 'notes'): ?>
            <form method="post" action="<?= e($actionUrl) ?>" class="card mb-4 p-4">
                <?= csrf_field() ?><input type="hidden" name="action" value="note_add"><input type="hidden" name="customer_id" value="<?= $cid ?>">
                <textarea name="body" rows="3" class="input" placeholder="Write a note…" required maxlength="10000"></textarea>
                <div class="mt-2 flex justify-end"><button class="btn-primary btn-sm">Add note</button></div>
            </form>
            <div class="space-y-3">
                <?php foreach ($notes as $n): ?>
                    <div class="card p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-slate-600"><?= e($n['author'] ?? 'Unknown') ?> · <span class="font-normal text-slate-400"><?= e(format_dt($n['created_at'])) ?></span></span>
                            <form method="post" action="<?= e($actionUrl) ?>" data-confirm="Delete this note?" data-confirm-button="Delete">
                                <?= csrf_field() ?><input type="hidden" name="action" value="note_delete"><input type="hidden" name="customer_id" value="<?= $cid ?>"><input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                                <button class="btn-icon !h-6 !w-6 hover:!text-red-600"><?= icon('trash', 'h-3.5 w-3.5') ?></button>
                            </form>
                        </div>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-700"><?= e($n['body']) ?></p>
                    </div>
                <?php endforeach; ?>
                <?php if (!$notes): ?><p class="py-6 text-center text-sm text-slate-400">No notes yet.</p><?php endif; ?>
            </div>

        <?php else: ?>
            <div class="card overflow-hidden">
                <table class="table">
                    <thead><tr><th>Campaign</th><th>Email status</th><th>CRM stage</th><th>Added</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($memberships as $m): ?>
                        <tr>
                            <td><a class="font-medium text-slate-900 hover:text-indigo-600" href="<?= e(url('campaigns/view.php', ['id' => $m['campaign_id']])) ?>"><?= e($m['name']) ?></a> <span class="ml-1"><?= status_badge($m['campaign_status']) ?></span></td>
                            <td><?= status_badge($m['system_status']) ?></td>
                            <td>
                                <form method="post" action="<?= e($actionUrl) ?>">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="campaign_status"><input type="hidden" name="customer_id" value="<?= $cid ?>"><input type="hidden" name="campaign_id" value="<?= (int) $m['campaign_id'] ?>">
                                    <select name="manual_status" class="input !w-auto !py-1 text-xs" onchange="this.form.submit()">
                                        <?php foreach (CRM_STATUSES as $s): ?><option <?= $m['manual_status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td class="text-xs text-slate-500"><?= e(format_dt($m['assigned_at'], 'M j, Y')) ?></td>
                            <td class="text-right">
                                <form method="post" action="<?= e($actionUrl) ?>" data-confirm="Remove from “<?= e($m['name']) ?>”? Queued emails for this campaign will be cancelled." data-confirm-button="Remove">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="campaign_remove"><input type="hidden" name="customer_id" value="<?= $cid ?>"><input type="hidden" name="campaign_id" value="<?= (int) $m['campaign_id'] ?>">
                                    <button class="btn-ghost btn-sm text-red-600">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$memberships): ?><tr><td colspan="5" class="py-8 text-center text-slate-400">Not in any campaign.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <?php if ($availableCampaigns): ?>
                    <form method="post" action="<?= e($actionUrl) ?>" class="flex gap-2 border-t border-slate-200 p-4">
                        <?= csrf_field() ?><input type="hidden" name="action" value="campaign_add"><input type="hidden" name="customer_id" value="<?= $cid ?>">
                        <select name="campaign_id" class="input">
                            <?php foreach ($availableCampaigns as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn-secondary">Add to campaign</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">CRM stage</h2></div>
            <form method="post" action="<?= e($actionUrl) ?>" class="card-body">
                <?= csrf_field() ?><input type="hidden" name="action" value="crm_status"><input type="hidden" name="customer_id" value="<?= $cid ?>">
                <div class="flex flex-wrap gap-1.5">
                    <?php foreach (CRM_STATUSES as $s): ?>
                        <button name="crm_status" value="<?= e($s) ?>" class="rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset <?= $customer['crm_status'] === $s ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' ?>"><?= e($s) ?></button>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Engagement</h2></div>
            <dl class="grid grid-cols-2 gap-px bg-slate-100">
                <?php foreach (['Sent' => $stats['sent'], 'Opened ~' => $stats['opened'], 'Clicked' => $stats['clicked'], 'Replies' => $stats['replies']] as $l => $n): ?>
                    <div class="bg-white px-5 py-3"><dt class="text-xs text-slate-500"><?= e($l) ?></dt><dd class="text-lg font-semibold"><?= (int) $n ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Details</h2></div>
            <dl class="card-body space-y-3 text-sm">
                <?php foreach (['Source' => $customer['source'], 'Added' => format_dt($customer['created_at']), 'Last activity' => time_ago($customer['last_activity_at'])] as $l => $val): ?>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500"><?= e($l) ?></dt><dd class="text-right text-slate-800"><?= e($val ?: '—') ?></dd></div>
                <?php endforeach; ?>
                <?php foreach ($custom as $k => $val): ?>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500"><?= e($k) ?></dt><dd class="break-all text-right text-slate-800"><?= e(is_scalar($val) ? $val : json_encode($val)) ?></dd></div>
                <?php endforeach; ?>
                <?php if ($customer['notes']): ?><div class="border-t border-slate-100 pt-3 text-slate-600"><?= nl2br(e($customer['notes'])) ?></div><?php endif; ?>
            </dl>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
