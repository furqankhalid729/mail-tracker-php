<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$campaign = find_or_404('campaigns', input_int('id'));
$cid = (int) $campaign['id'];
$tab = in_array(input('tab'), ['board', 'table', 'analytics'], true) ? input('tab') : 'board';

$stats = campaign_stats($cid);
$account = $campaign['mail_account_id'] ? q_one('SELECT id, name, email, status FROM mail_accounts WHERE id = ?', [$campaign['mail_account_id']]) : null;
$template = $campaign['template_id'] ? q_one('SELECT id, name, subject FROM email_templates WHERE id = ?', [$campaign['template_id']]) : null;
$failedJobs = (int) q_val("SELECT COUNT(*) FROM email_jobs WHERE campaign_id = ? AND status = 'failed'", [$cid]);
$filters = contact_filters_from_request();
$allTags = workspace_tags($ws);
$senders = q_all('SELECT id, email FROM mail_accounts WHERE workspace_id = ? ORDER BY email', [$ws]);
$nextJob = q_val("SELECT MIN(scheduled_at) FROM email_jobs WHERE campaign_id = ? AND status = 'pending'", [$cid]);

$page_title = $campaign['name'];
$active_nav = 'campaigns';
$wide = $tab === 'board';
require __DIR__ . '/../includes/header.php';
$viewUrl = fn(array $extra = []) => url('campaigns/view.php', array_merge(['id' => $cid, 'tab' => $tab], $filters, $extra));
?>
<a href="<?= e(url('campaigns/index.php')) ?>" class="mb-3 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Campaigns</a>

<div class="page-header !items-start">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-title"><?= e($campaign['name']) ?></h1>
            <?= status_badge($campaign['status']) ?>
        </div>
        <p class="page-subtitle">
            <?= $account ? 'From ' . e($account['email']) : '<span class="text-amber-600">No sender</span>' ?> ·
            <?= $template ? 'Template ' . (allowed('templates.manage') ? '<a class="text-indigo-600" href="' . e(url('templates/edit.php', ['id' => $template['id']])) . '">' . e($template['name']) . '</a>' : e($template['name'])) : '<span class="text-amber-600">No template</span>' ?>
            <?php if ($campaign['started_at']): ?> · started <?= e(format_dt($campaign['started_at'], 'M j, Y')) ?><?php endif; ?>
        </p>
        <?php if ($campaign['description']): ?><p class="mt-1 max-w-2xl text-sm text-slate-500"><?= e($campaign['description']) ?></p><?php endif; ?>
    </div>
    <?php if (allowed('campaigns.manage')): ?>
    <div class="flex flex-wrap gap-2">
        <a href="<?= e(url('tasks/create.php', ['campaign_id' => $cid])) ?>" class="btn-secondary"><?= icon('tasks', 'h-4 w-4') ?> Add task</a>
        <?php if ($campaign['status'] !== 'archived'): ?>
            <a href="<?= e(url('campaigns/contacts.php', ['id' => $cid])) ?>" class="btn-secondary"><?= icon('plus', 'h-4 w-4') ?> Add contacts</a>
        <?php endif; ?>
        <?php if (in_array($campaign['status'], ['draft', 'paused', 'completed'], true)): ?>
            <form method="post" action="<?= e(url('campaigns/start.php')) ?>" data-confirm="<?= $campaign['status'] === 'paused' ? 'Resume sending?' : 'Queue personalised emails for ' . number_format($stats['unqueued']) . ' contacts? Cron sends them in batches within your daily limits.' ?>" data-confirm-button="<?= $campaign['status'] === 'paused' ? 'Resume' : 'Start campaign' ?>" data-confirm-danger="0">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>">
                <button class="btn-primary"><?= icon('play', 'h-4 w-4') ?> <?= $campaign['status'] === 'paused' ? 'Resume' : ($campaign['status'] === 'completed' ? 'Send to new contacts' : 'Start campaign') ?></button>
            </form>
        <?php elseif ($campaign['status'] === 'active'): ?>
            <form method="post" action="<?= e(url('campaigns/pause.php')) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>">
                <button class="btn-secondary"><?= icon('pause', 'h-4 w-4') ?> Pause</button>
            </form>
        <?php endif; ?>
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" class="btn-secondary" @click="open = !open">More <?= icon('chevron-down', 'h-4 w-4') ?></button>
            <div x-show="open" x-cloak class="dropdown right-0">
                <a href="<?= e(url('campaigns/edit.php', ['id' => $cid])) ?>" class="dropdown-item"><?= icon('pencil', 'h-4 w-4') ?> Edit settings</a>
                <?php if ($failedJobs): ?>
                    <form method="post" action="<?= e(url('campaigns/status.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button name="action" value="retry_failed" class="dropdown-item"><?= icon('bolt', 'h-4 w-4') ?> Retry <?= $failedJobs ?> failed</button></form>
                <?php endif; ?>
                <?php if (in_array($campaign['status'], ['active', 'paused'], true)): ?>
                    <form method="post" action="<?= e(url('campaigns/status.php')) ?>" data-confirm="Mark as completed? Emails still in the queue will be cancelled." data-confirm-button="Complete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button name="action" value="complete" class="dropdown-item"><?= icon('check', 'h-4 w-4') ?> Mark completed</button></form>
                <?php endif; ?>
                <?php if ($campaign['status'] !== 'archived'): ?>
                    <form method="post" action="<?= e(url('campaigns/status.php')) ?>" data-confirm="Archive this campaign? Queued emails are cancelled; history is kept." data-confirm-button="Archive"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button name="action" value="archive" class="dropdown-item">Archive</button></form>
                <?php else: ?>
                    <form method="post" action="<?= e(url('campaigns/status.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button name="action" value="unarchive" class="dropdown-item">Unarchive</button></form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('campaigns/delete.php')) ?>" data-confirm="Delete “<?= e($campaign['name']) ?>”? Contacts stay in your CRM; sent emails remain in customer timelines." data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button class="dropdown-item text-red-600"><?= icon('trash', 'h-4 w-4') ?> Delete campaign</button></form>
            </div>
        </div>
    </div>
    <?php else: ?>
    <a href="<?= e(url('tasks/create.php', ['campaign_id' => $cid])) ?>" class="btn-secondary"><?= icon('tasks', 'h-4 w-4') ?> Add task</a>
    <?php endif; ?>
</div>

<?php if ($campaign['status'] === 'active' && $account && $account['status'] !== 'active'): ?>
    <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">The sending account <?= e($account['email']) ?> has a problem (<?= e($account['status']) ?>). <a class="font-medium underline" href="<?= e(url('mail-accounts/index.php')) ?>">Check it</a> — emails are waiting.</div>
<?php endif; ?>
<?php if ($campaign['status'] === 'active' && ($stats['queued'] || $stats['unqueued'])): ?>
    <div class="mb-4 flex items-center gap-2 rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800 ring-1 ring-indigo-200">
        <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-indigo-500"></span></span>
        Sending: <?= number_format($stats['queued']) ?> queued<?= $stats['unqueued'] ? ', ' . number_format($stats['unqueued']) . ' waiting to be queued' : '' ?>. Cron sends a batch every minute<?= $nextJob && strtotime($nextJob) > time() + 60 ? ' — next batch at ' . e(format_dt($nextJob, 'M j, g:i A')) . ' (daily limit or retry delay)' : '' ?>.
    </div>
<?php endif; ?>

<div class="grid grid-cols-3 gap-3 sm:grid-cols-5 xl:grid-cols-9">
    <?php foreach ([
        ['Contacts', $stats['contacts'], null, ''],
        ['Queued', $stats['queued'], null, ''],
        ['Sent', $stats['sent'], null, ''],
        ['Delivered', $stats['delivered'], $stats['rates']['delivery'], 'Needs webhook / provider support'],
        ['Opened ~', $stats['opened'], $stats['rates']['open'], 'Approximate — image proxies can pre-load'],
        ['Clicked', $stats['clicked'], $stats['rates']['click'], ''],
        ['Replied', $stats['replied'], $stats['rates']['reply'], ''],
        ['Bounced', $stats['bounced'], $stats['rates']['bounce'], ''],
        ['Unsubscribed', $stats['unsubscribed'], null, ''],
    ] as [$label, $n, $rate, $tip]): ?>
        <div class="stat-card !p-3 <?= $tip ? 'tooltip' : '' ?>" <?= $tip ? 'data-tip="' . e($tip) . '"' : '' ?>>
            <div class="text-[11px] font-medium uppercase tracking-wide text-slate-500"><?= e($label) ?></div>
            <div class="mt-0.5 text-xl font-semibold tabular-nums"><?= number_format($n) ?></div>
            <?php if ($rate !== null): ?><div class="text-xs text-slate-400"><?= number_format($rate, 1) ?>%</div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="mb-4 mt-6 flex items-center gap-6 border-b border-slate-200">
    <?php foreach (['board' => ['Board', 'board'], 'table' => ['Table', 'table'], 'analytics' => ['Analytics', 'chart']] as $k => [$label, $ic]): ?>
        <a href="<?= e(url('campaigns/view.php', array_merge(['id' => $cid, 'tab' => $k], $k !== 'analytics' ? $filters : []))) ?>" class="tab <?= $tab === $k ? 'active' : '' ?>"><?= icon($ic, 'h-4 w-4') ?> <?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab !== 'analytics'): ?>
<form method="get" class="card mb-4 p-3" x-data="{ more: <?= count(array_diff_key($filters, ['q' => 1])) ? 'true' : 'false' ?> }">
    <input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?php if ($tab === 'board'): ?><input type="hidden" name="group" value="<?= e(input('group', 'manual')) ?>"><?php endif; ?>
    <div class="flex flex-col gap-2 md:flex-row">
        <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search customer or company…" class="input md:max-w-xs">
        <select name="system_status" class="input md:w-44">
            <option value="">Any email status</option>
            <option value="none" <?= ($filters['system_status'] ?? '') === 'none' ? 'selected' : '' ?>>Not queued</option>
            <?php foreach (SYSTEM_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= ($filters['system_status'] ?? '') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
        </select>
        <select name="manual_status" class="input md:w-44">
            <option value="">Any CRM stage</option>
            <?php foreach (CRM_STATUSES as $s): ?><option <?= ($filters['manual_status'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="btn-secondary" @click="more = !more"><?= icon('filter', 'h-4 w-4') ?> More</button>
        <button class="btn-primary">Filter</button>
        <?php if ($filters): ?><a class="btn-ghost" href="<?= e(url('campaigns/view.php', ['id' => $cid, 'tab' => $tab])) ?>">Reset</a><?php endif; ?>
    </div>
    <div x-show="more" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div><label class="label">Tags (any)</label><select name="tags[]" multiple class="input h-24"><?php foreach ($allTags as $t): ?><option value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $filters['tags'] ?? [], true) ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="space-y-3">
            <div><label class="label">Company contains</label><input name="company" value="<?= e($filters['company'] ?? '') ?>" class="input"></div>
            <div><label class="label">Sender</label><select name="sender" class="input"><option value="">Any</option><?php foreach ($senders as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) ($filters['sender'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['email']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <?php foreach (['opened' => 'Opened', 'clicked' => 'Clicked', 'replied' => 'Replied', 'bounced' => 'Bounced'] as $k => $l): ?>
                <div><label class="label"><?= $l ?></label><select name="<?= $k ?>" class="input"><option value="">Any</option><option value="1" <?= ($filters[$k] ?? '') === '1' ? 'selected' : '' ?>>Yes</option><option value="0" <?= ($filters[$k] ?? '') === '0' ? 'selected' : '' ?>>No</option></select></div>
            <?php endforeach; ?>
        </div>
        <div class="space-y-3">
            <div><label class="label">Activity from</label><input type="date" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>" class="input"></div>
            <div><label class="label">Activity to</label><input type="date" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>" class="input"></div>
        </div>
    </div>
</form>
<?php endif; ?>

<?php if ($tab === 'board'):
    $group = input('group') === 'system' ? 'system' : 'manual'; ?>
    <div class="mb-3 flex items-center justify-between">
        <div class="flex gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200">
            <a href="<?= e($viewUrl(['group' => 'manual'])) ?>" class="rounded-md px-3 py-1 text-xs font-medium <?= $group === 'manual' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">CRM pipeline</a>
            <a href="<?= e($viewUrl(['group' => 'system'])) ?>" class="rounded-md px-3 py-1 text-xs font-medium <?= $group === 'system' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">Email status</a>
        </div>
        <p class="text-xs text-slate-500"><?= $group === 'manual' ? 'Drag cards to change the CRM stage. Email status is never changed by dragging.' : 'Read-only: email statuses come from tracking events.' ?></p>
    </div>
    <div x-data="kanbanBoard(<?= $cid ?>, '<?= $group ?>', <?= e(json_encode($filters)) ?>)" x-init="load()" class="-mx-4 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
        <div class="flex gap-4" style="min-height: 60vh">
            <template x-for="col in columns" :key="col.key">
                <div class="kanban-col">
                    <div class="flex items-center justify-between px-3 py-2.5">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-600" x-text="col.label"></span>
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-slate-500 ring-1 ring-slate-200" x-text="col.count"></span>
                    </div>
                    <div class="flex-1 space-y-2 overflow-y-auto px-2 pb-2" style="max-height: 70vh" :data-status="col.key" x-init="initSortable($el)">
                        <template x-for="card in col.cards" :key="card.id">
                            <div class="kanban-card" :data-id="card.id" :class="group === 'system' && '!cursor-default'">
                                <div class="flex items-start justify-between gap-2">
                                    <a :href="card.url" class="truncate text-sm font-semibold text-slate-900 hover:text-indigo-600" x-text="card.name"></a>
                                    <span class="badge shrink-0" :class="badgeClass(group === 'manual' ? card.system_status : card.manual_status)" x-text="(group === 'manual' ? card.system_status : card.manual_status) || 'not queued'"></span>
                                </div>
                                <div class="truncate text-xs text-slate-600" x-text="card.company" x-show="card.company"></div>
                                <div class="truncate text-xs text-slate-400" x-text="card.email"></div>
                                <div class="mt-2 flex flex-wrap gap-1" x-show="card.tags.length">
                                    <template x-for="t in card.tags" :key="t.name"><span class="tag-chip" :style="`--tag: ${t.color}`" x-text="t.name"></span></template>
                                </div>
                                <div class="mt-2 border-t border-slate-100 pt-2 text-[11px] text-slate-500" x-show="card.last_subject || card.activity">
                                    <div class="truncate" x-show="card.last_subject">✉ <span x-text="card.last_subject"></span></div>
                                    <div class="text-slate-400" x-text="card.activity"></div>
                                </div>
                            </div>
                        </template>
                        <p x-show="col.count > col.cards.length" class="py-1 text-center text-[11px] text-slate-400">+<span x-text="col.count - col.cards.length"></span> more — use filters or Table view</p>
                    </div>
                </div>
            </template>
            <div x-show="loading" class="py-10 text-sm text-slate-400">Loading board…</div>
        </div>
    </div>

<?php elseif ($tab === 'table'):
    [$where, $params] = contact_filter_sql($cid, $filters);
    $total = (int) q_val('SELECT COUNT(*) FROM ' . CONTACT_FROM_SQL . " WHERE $where", $params);
    $p = paginate($total, per_page(50));
    $order = sort_sql(['name' => 'c.full_name', 'company' => 'c.company', 'system' => 'cc.system_status', 'manual' => 'cc.manual_status', 'activity' => 'cc.last_activity_at', 'added' => 'cc.assigned_at'], 'activity', 'desc');
    $rows = q_all(
        'SELECT cc.*, c.first_name, c.last_name, c.full_name, c.email, c.company, c.status customer_status, m.subject last_subject, m.sent_at, m.open_count, m.click_count, m.thread_id
         FROM ' . CONTACT_FROM_SQL . " WHERE $where ORDER BY $order, cc.id DESC LIMIT ? OFFSET ?",
        [...$params, $p['per_page'], $p['offset']]
    );
    $tagMap = tags_for_customers(array_column($rows, 'customer_id'));
?>
    <form method="post" action="<?= e(url('campaigns/contacts.php', ['id' => $cid])) ?>" class="card overflow-hidden" x-data="bulkSelect(<?= $total ?>)" data-confirm="Remove the selected contacts from this campaign? Their queued emails will be cancelled." data-confirm-button="Remove">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove">
        <div x-show="selected.length" x-cloak class="flex items-center gap-3 border-b border-indigo-100 bg-indigo-50/60 px-4 py-2 text-sm">
            <span class="font-medium text-indigo-900"><span x-text="selected.length"></span> selected</span>
            <?php if (allowed('campaigns.manage')): ?><button class="btn-danger btn-sm">Remove from campaign</button><?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr>
                    <th class="w-10"><input type="checkbox" class="checkbox" :checked="pageAllSelected" @change="togglePage($event)"></th>
                    <th><?= sort_link('name', 'Customer') ?></th><th><?= sort_link('company', 'Company') ?></th><th>Tags</th>
                    <th><?= sort_link('system', 'Email status') ?></th><th><?= sort_link('manual', 'CRM stage') ?></th><th>Last email</th><th><?= sort_link('activity', 'Last activity') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><input type="checkbox" class="checkbox" name="contact_ids[]" data-row-id value="<?= (int) $r['id'] ?>" x-model="selected"></td>
                        <td><a class="font-medium text-slate-900 hover:text-indigo-600" href="<?= e(url('customers/view.php', ['id' => $r['customer_id']])) ?>"><?= e(customer_name($r)) ?></a><div class="text-xs text-slate-500"><?= e($r['email']) ?><?= $r['customer_status'] !== 'active' ? ' · <span class="text-amber-600">' . e($r['customer_status']) . '</span>' : '' ?></div></td>
                        <td class="max-w-[10rem] truncate"><?= e($r['company'] ?: '—') ?></td>
                        <td><div class="flex max-w-[12rem] flex-wrap gap-1"><?php foreach (array_slice($tagMap[$r['customer_id']] ?? [], 0, 3) as $t) echo tag_chip($t); ?></div></td>
                        <td><?= status_badge($r['system_status']) ?></td>
                        <td>
                            <select class="input !w-auto !py-1 text-xs" @change="api(appUrl('api/kanban.php'), { method: 'POST', body: { contact_id: <?= (int) $r['id'] ?>, manual_status: $event.target.value } }).then(() => toast('Stage updated')).catch(e => toast(e.message, 'error'))">
                                <?php foreach (CRM_STATUSES as $s): ?><option <?= $r['manual_status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                            </select>
                        </td>
                        <td class="max-w-[14rem]">
                            <?php if ($r['last_subject']): ?>
                                <a href="<?= e(url('inbox/thread.php', ['id' => $r['thread_id']])) ?>" class="block truncate text-xs text-slate-700 hover:text-indigo-600"><?= e($r['last_subject']) ?></a>
                                <span class="text-[11px] text-slate-400"><?= $r['open_count'] ? (int) $r['open_count'] . ' opens ~' : '' ?><?= $r['click_count'] ? ' · ' . (int) $r['click_count'] . ' clicks' : '' ?></span>
                            <?php else: ?><span class="text-xs text-slate-400">—</span><?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap text-xs text-slate-500"><?= e(time_ago($r['last_activity_at'] ?? $r['assigned_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8" class="py-12 text-center text-slate-400"><?= $filters ? 'No contacts match these filters.' : 'No contacts yet.' ?> <?php if (allowed('campaigns.manage')): ?><a class="text-indigo-600" href="<?= e(url('campaigns/contacts.php', ['id' => $cid])) ?>">Add contacts</a><?php endif; ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_links($p) ?>
    </form>

<?php else:
    $series = campaign_daily_series($cid, 30);
    $byStage = array_column(q_all('SELECT manual_status s, COUNT(*) n FROM campaign_contacts WHERE campaign_id = ? GROUP BY manual_status', [$cid]), 'n', 's');
    $links = q_all(
        "SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.url')) url, COUNT(*) clicks, COUNT(DISTINCT email_message_id) uniq
         FROM email_events WHERE campaign_id = ? AND type = 'clicked' GROUP BY url ORDER BY clicks DESC LIMIT 10",
        [$cid]
    );
?>
    <div class="grid gap-4 sm:grid-cols-5">
        <?php foreach (['Delivery rate' => 'delivery', 'Open rate ~' => 'open', 'Click rate' => 'click', 'Reply rate' => 'reply', 'Bounce rate' => 'bounce'] as $l => $k): ?>
            <div class="stat-card"><div class="stat-label"><?= e($l) ?></div><div class="stat-value"><?= number_format($stats['rates'][$k], 1) ?>%</div></div>
        <?php endforeach; ?>
    </div>
    <p class="mt-2 text-xs text-slate-500">Rates are per sent email. Opens are approximate (Apple Mail Privacy Protection and image proxies pre-load images). “Delivered” needs provider webhooks — SMTP “sent” only means the server accepted the message.</p>
    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2"><div class="card-header"><h2 class="card-title">Activity over time</h2><span class="text-xs text-slate-400">Last 30 days</span></div><div class="card-body"><div class="h-72"><canvas id="seriesChart"></canvas></div></div></div>
        <div class="card"><div class="card-header"><h2 class="card-title">Funnel</h2></div><div class="card-body"><div class="h-72"><canvas id="funnelChart"></canvas></div></div></div>
        <div class="card"><div class="card-header"><h2 class="card-title">CRM pipeline</h2></div><div class="card-body"><div class="h-64"><canvas id="stageChart"></canvas></div></div></div>
        <div class="card overflow-hidden lg:col-span-2">
            <div class="card-header"><h2 class="card-title">Top clicked links</h2></div>
            <table class="table"><thead><tr><th>URL</th><th class="text-right">Clicks</th><th class="text-right">Unique</th></tr></thead><tbody>
                <?php foreach ($links as $l): ?><tr><td class="max-w-md truncate text-xs"><?= e($l['url']) ?></td><td class="text-right"><?= (int) $l['clicks'] ?></td><td class="text-right"><?= (int) $l['uniq'] ?></td></tr><?php endforeach; ?>
                <?php if (!$links): ?><tr><td colspan="3" class="py-8 text-center text-slate-400">No clicks yet.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
<?php
    $extra_scripts = '<script src="' . e(asset('vendor/chart.umd.min.js')) . '"></script><script>
    (function () {
        const s = ' . json_encode($series) . ';
        const labels = Object.keys(s).map(d => new Date(d + "T00:00:00").toLocaleDateString(undefined, { month: "short", day: "numeric" }));
        const ds = (label, key, color) => ({ label, data: Object.values(s).map(v => v[key]), borderColor: color, backgroundColor: color, tension: .35, pointRadius: 0, borderWidth: 2 });
        const grid = { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "#f1f5f9" } } };
        new Chart(document.getElementById("seriesChart"), { type: "line", data: { labels, datasets: [ds("Sent", "sent", "#6366f1"), ds("Opens ~", "opened", "#8b5cf6"), ds("Clicks", "clicked", "#0ea5e9"), ds("Replies", "replied", "#10b981"), ds("Bounces", "bounced", "#ef4444")] },
            options: { maintainAspectRatio: false, interaction: { mode: "index", intersect: false }, plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 6 } } }, scales: grid } });
        new Chart(document.getElementById("funnelChart"), { type: "bar", data: { labels: ["Sent", "Delivered", "Opened ~", "Clicked", "Replied"], datasets: [{ data: ' . json_encode([$stats['sent'], $stats['delivered'], $stats['opened'], $stats['clicked'], $stats['replied']]) . ', backgroundColor: ["#6366f1", "#0ea5e9", "#8b5cf6", "#4f46e5", "#10b981"], borderRadius: 6 }] },
            options: { indexAxis: "y", maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } } } });
        const stages = ' . json_encode(array_map(fn($st) => (int) ($byStage[$st] ?? 0), CRM_STATUSES)) . ';
        new Chart(document.getElementById("stageChart"), { type: "doughnut", data: { labels: ' . json_encode(CRM_STATUSES) . ', datasets: [{ data: stages, backgroundColor: ["#94a3b8", "#3b82f6", "#8b5cf6", "#6366f1", "#f59e0b", "#10b981", "#ef4444"] }] },
            options: { maintainAspectRatio: false, cutout: "65%", plugins: { legend: { position: "right", labels: { usePointStyle: true, boxWidth: 6 } } } } });
    })();
    </script>';
endif; ?>

<?php if ($tab === 'board'): ?>
<script src="<?= e(asset('vendor/sortable.min.js')) ?>"></script>
<script>
function kanbanBoard(campaignId, group, filters) {
    const badge = { queued: 'badge-slate', sent: 'badge-blue', delivered: 'badge-sky', opened: 'badge-violet', clicked: 'badge-indigo', replied: 'badge-green', bounced: 'badge-red', failed: 'badge-red',
        New: 'badge-slate', Contacted: 'badge-blue', Interested: 'badge-violet', Qualified: 'badge-indigo', 'Follow Up': 'badge-amber', Won: 'badge-green', Lost: 'badge-red' };
    return {
        columns: [], loading: true, group,
        badgeClass(s) { return badge[s] || 'badge-slate'; },
        async load() {
            const params = new URLSearchParams({ campaign_id: campaignId, group });
            Object.entries(filters).forEach(([k, v]) => Array.isArray(v) ? v.forEach(x => params.append(k + '[]', x)) : params.set(k, v));
            try {
                const d = await api(appUrl('api/kanban.php') + '?' + params);
                this.columns = d.columns;
            } catch (e) { toast(e.message, 'error'); }
            this.loading = false;
        },
        initSortable(el) {
            if (this.group !== 'manual') return;
            Sortable.create(el, {
                group: 'kanban', animation: 150, ghostClass: 'sortable-ghost', delay: 100, delayOnTouchOnly: true,
                onEnd: async (evt) => {
                    const id = parseInt(evt.item.dataset.id, 10), to = evt.to.dataset.status, from = evt.from.dataset.status;
                    if (to === from) return;
                    // Move the card in Alpine state so the DOM and data stay in sync
                    evt.item.remove();
                    evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex] || null);
                    const src = this.columns.find(c => c.key === from), dst = this.columns.find(c => c.key === to);
                    const idx = src.cards.findIndex(c => c.id === id);
                    const [card] = src.cards.splice(idx, 1);
                    dst.cards.splice(evt.newIndex, 0, card);
                    src.count--; dst.count++;
                    try {
                        await api(appUrl('api/kanban.php'), { method: 'POST', body: { contact_id: id, manual_status: to } });
                    } catch (e) {
                        toast(e.message, 'error');
                        this.load();
                    }
                },
            });
        },
    };
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
