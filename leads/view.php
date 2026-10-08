<?php
/** One lead: contact details, status + notes, Zoom call history on its numbers, and the timeline. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$lead = find_lead_or_404(input_int('id'));
$id = (int) $lead['id'];

$statuses = lead_statuses($ws);
$phones = phones_for_leads([$id])[$id] ?? [];
$extras = json_decode((string) $lead['extra_fields'], true) ?: [];
$calls = q_all(
    'SELECT c.*, u.name closer_name FROM zoom_calls c LEFT JOIN users u ON u.id = c.closer_user_id
     WHERE c.workspace_id = ? AND c.lead_id = ? ORDER BY c.start_time DESC, c.id DESC LIMIT 100',
    [$ws, $id]
);
$callsByKey = [];
foreach ($calls as $c) {
    $callsByKey[$c['external_key']] = ($callsByKey[$c['external_key']] ?? 0) + 1;
}
$activity = q_all(
    'SELECT a.*, u.name user_name FROM lead_activity a LEFT JOIN users u ON u.id = a.user_id
     WHERE a.lead_id = ? AND a.workspace_id = ? ORDER BY a.created_at DESC, a.id DESC LIMIT 200',
    [$id, $ws]
);
$manage = allowed('leads.manage');
$members = $manage ? workspace_users() : [];

$page_title = $lead['name'];
$active_nav = 'leads';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div class="min-w-0">
        <a href="<?= e(url('leads/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Leads</a>
        <h1 class="page-title truncate"><?= e($lead['name']) ?></h1>
        <p class="page-subtitle">
            <?= lead_status_chip($lead['status_id'] ? (int) $lead['status_id'] : null) ?>
            <?php if ($lead['rating'] !== null): ?><span class="ml-2">★ <?= e($lead['rating']) ?><?= $lead['reviews_count'] !== null ? ' (' . number_format((int) $lead['reviews_count']) . ' reviews)' : '' ?></span><?php endif; ?>
            <?php if ($lead['source']): ?><span class="ml-2 text-slate-400">· <?= e($lead['source']) ?></span><?php endif; ?>
        </p>
    </div>
    <?php if ($manage): ?>
        <div class="flex gap-2">
            <a href="<?= e(url('leads/edit.php', ['id' => $id])) ?>" class="btn-secondary"><?= icon('pencil', 'h-4 w-4') ?> Edit</a>
            <form method="post" action="<?= e(url('leads/actions.php')) ?>" data-confirm="Delete <?= e($lead['name']) ?> and its timeline? Its Zoom calls stay in the call log." data-confirm-button="Delete">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn-secondary hover:!text-red-600"><?= icon('trash', 'h-4 w-4') ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="grid grid-cols-3 gap-3">
            <div class="card p-4"><div class="text-xs text-slate-500">Total attempts</div><div class="mt-1 text-2xl font-semibold tabular-nums"><?= number_format((int) $lead['total_attempts']) ?></div></div>
            <div class="card p-4"><div class="text-xs text-slate-500">Connected calls</div><div class="mt-1 text-2xl font-semibold tabular-nums text-emerald-600"><?= number_format((int) $lead['connected_calls']) ?></div></div>
            <div class="card p-4"><div class="text-xs text-slate-500">Last call</div><div class="mt-1"><?= lead_last_call_badge($lead) ?></div></div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Contact</h2></div>
            <dl class="card-body grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium text-slate-500">Phones</dt>
                    <dd class="mt-1 space-y-1">
                        <?php foreach ($phones as $ph): ?>
                            <div class="flex items-center gap-2">
                                <a href="<?= e(lead_tel_href($ph['phone_number'])) ?>" class="inline-flex items-center gap-1.5 font-medium tabular-nums text-slate-900 hover:text-indigo-600"><?= icon('phone', 'h-3.5 w-3.5 text-slate-400') ?><?= e($ph['phone_number']) ?></a>
                                <?php if (!empty($callsByKey[$ph['number_key']])): ?><span class="text-xs text-slate-400"><?= $callsByKey[$ph['number_key']] ?> call<?= $callsByKey[$ph['number_key']] > 1 ? 's' : '' ?></span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$phones): ?><span class="text-slate-400">—</span><?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Emails</dt>
                    <dd class="mt-1 space-y-1">
                        <?php foreach (array_filter(explode('; ', (string) $lead['emails'])) as $em): ?>
                            <a href="mailto:<?= e($em) ?>" class="block truncate text-slate-900 hover:text-indigo-600"><?= e($em) ?></a>
                        <?php endforeach; ?>
                        <?php if (!$lead['emails']): ?><span class="text-slate-400">—</span><?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Website</dt>
                    <dd class="mt-1 truncate"><?= $lead['website'] ? '<a href="' . e($lead['website']) . '" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">' . e(preg_replace('#^https?://(www\.)?#', '', rtrim($lead['website'], '/'))) . '</a>' : '<span class="text-slate-400">—</span>' ?></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Address</dt>
                    <dd class="mt-1"><?= e($lead['address'] ?: '—') ?><?php if ($lead['maps_url']): ?> <a href="<?= e($lead['maps_url']) ?>" target="_blank" rel="noopener noreferrer" class="ml-1 text-xs text-indigo-600 hover:underline">Google Maps ↗</a><?php endif; ?></dd>
                </div>
                <?php if ($lead['notes']): ?>
                    <div class="sm:col-span-2"><dt class="text-xs font-medium text-slate-500">Notes</dt><dd class="mt-1 whitespace-pre-line text-slate-700"><?= e($lead['notes']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ($extras): ?>
                <div class="border-t border-slate-100 px-5 py-4" x-data="{ open: false }">
                    <button type="button" class="text-xs font-medium text-slate-500 hover:text-slate-700" @click="open = !open">Other imported columns (<?= count($extras) ?>) <span x-text="open ? '▴' : '▾'"></span></button>
                    <dl x-show="open" x-cloak class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <?php foreach ($extras as $k => $v): ?>
                            <div class="min-w-0"><dt class="text-xs text-slate-500"><?= e($k) ?></dt><dd class="break-words text-slate-700"><?= e(is_scalar($v) ? (string) $v : json_encode($v)) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endif; ?>
        </div>

        <div class="card overflow-hidden">
            <div class="card-header"><h2 class="card-title">Zoom calls</h2><span class="text-xs text-slate-500">Matched by phone number</span></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>When</th><th>Direction</th><th>Number</th><th>Closer</th><th>Result</th><th class="text-right">Duration</th></tr></thead>
                    <tbody>
                    <?php foreach ($calls as $c): ?>
                        <tr>
                            <td class="whitespace-nowrap text-xs"><?= e(format_dt($c['start_time'])) ?></td>
                            <td class="text-xs capitalize"><?= e($c['direction'] ?: '—') ?></td>
                            <td class="whitespace-nowrap text-xs tabular-nums"><?= e($c['external_number'] ?: '—') ?></td>
                            <td class="whitespace-nowrap text-xs"><?= $c['closer_name'] ? e($c['closer_name']) : '<span class="text-slate-400">—</span>' ?></td>
                            <td><?= call_result_badge($c['call_result'], (bool) $c['is_connected']) ?></td>
                            <td class="text-right text-xs tabular-nums"><?= e(format_duration((int) $c['duration'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$calls): ?><tr><td colspan="6" class="py-8 text-center text-sm text-slate-400">No Zoom calls to these numbers yet. Calls appear after the next Zoom sync.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <form method="post" action="<?= e(url('leads/actions.php')) ?>" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="card-header"><h2 class="card-title">Update</h2></div>
            <div class="card-body space-y-3">
                <div>
                    <label class="label" for="status_id">Status</label>
                    <select class="input" id="status_id" name="status_id" required>
                        <?php if (!$lead['status_id']): ?><option value="">Choose…</option><?php endif; ?>
                        <?php foreach ($statuses as $sid => $s): ?><option value="<?= (int) $sid ?>" <?= (int) $lead['status_id'] === (int) $sid ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="note">Note <span class="font-normal text-slate-400">(optional)</span></label>
                    <textarea class="input" id="note" name="note" rows="3" maxlength="10000" placeholder="Spoke to the owner, call back Tuesday…"></textarea>
                </div>
                <button class="btn-primary w-full">Save</button>
            </div>
        </form>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Assigned to</h2></div>
            <div class="card-body">
                <?php if ($manage): ?>
                    <form method="post" action="<?= e(url('leads/actions.php')) ?>" class="flex gap-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= $id ?>">
                        <select name="user_id" class="input">
                            <option value="0">Unassigned</option>
                            <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $lead['assigned_to'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?><?= $m['role'] === 'closer' ? '' : ' (' . e(role_label($m['role'])) . ')' ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn-secondary">Save</button>
                    </form>
                <?php else: ?>
                    <p class="text-sm"><?= $lead['assignee_name'] ? e($lead['assignee_name']) : '<span class="text-slate-400">Unassigned</span>' ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Timeline</h2></div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($activity as $a): ?>
                    <li class="px-5 py-3 text-sm">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-slate-700">
                                <b class="font-medium text-slate-900"><?= e($a['user_name'] ?: 'System') ?></b>
                                <?php if ($a['type'] === 'status'): ?>
                                    set status <?= $a['from_label'] ? '<span class="text-slate-400">' . e($a['from_label']) . ' →</span> ' : '' ?><b class="font-medium"><?= e($a['to_label']) ?></b>
                                <?php elseif ($a['type'] === 'assigned'): ?>
                                    <?= $a['to_label'] === 'Unassigned' ? 'unassigned the lead' : 'assigned to <b class="font-medium">' . e($a['to_label']) . '</b>' ?>
                                <?php elseif ($a['type'] === 'note'): ?>
                                    added a note
                                <?php elseif ($a['type'] === 'imported'): ?>
                                    imported the lead<?= $a['to_label'] ? ' from ' . e($a['to_label']) : '' ?>
                                <?php elseif ($a['type'] === 'created'): ?>
                                    added the lead
                                <?php else: ?>
                                    edited the lead
                                <?php endif; ?>
                            </span>
                            <span class="shrink-0 text-xs text-slate-400" title="<?= e(format_dt($a['created_at'])) ?>"><?= e(time_ago($a['created_at'])) ?></span>
                        </div>
                        <?php if ($a['body']): ?><p class="mt-1 whitespace-pre-line rounded bg-slate-50 px-3 py-2 text-slate-700"><?= e($a['body']) ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$activity): ?><li class="px-5 py-6 text-center text-sm text-slate-400">Nothing yet.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
