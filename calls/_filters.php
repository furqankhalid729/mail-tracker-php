<?php
defined('APP_ROOT') || exit;
/** Shared filter bar for calls/index.php and calls/log.php. Expects $f (calls_filters()), $closers (admins only), $target. */
$ranges = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month', 'last_month' => 'Last month'];
$keep = fn(array $over) => url($target, array_filter(array_merge(['closer' => $f['closer'] && allowed('calls.view_all') ? $f['closer'] : null, 'direction' => $f['direction'] ?: null, 'result' => $f['result'] ?: null, 'q' => $f['q'] ?: null], $over), fn($v) => $v !== null && $v !== ''));
?>
<div class="card mb-6 p-3" x-data="{ custom: <?= $f['range'] === 'custom' ? 'true' : 'false' ?> }">
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap items-center gap-1 rounded-lg bg-slate-100 p-1">
            <?php foreach ($ranges as $k => $l): ?>
                <a href="<?= e($keep(['range' => $k])) ?>" class="rounded-md px-2.5 py-1 text-xs font-medium <?= $f['range'] === $k ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>"><?= e($l) ?></a>
            <?php endforeach; ?>
            <button type="button" @click="custom = !custom" class="rounded-md px-2.5 py-1 text-xs font-medium <?= $f['range'] === 'custom' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>"><?= icon('calendar', 'inline h-3.5 w-3.5') ?> Custom</button>
        </div>
        <form method="get" action="<?= e(url($target)) ?>" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="range" value="<?= e($f['range']) ?>">
            <input type="hidden" name="from" value="<?= e($f['from']) ?>">
            <input type="hidden" name="to" value="<?= e($f['to']) ?>">
            <?php if ($f['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($f['q']) ?>"><?php endif; ?>
            <?php if (allowed('calls.view_all')): ?>
                <select name="closer" class="input !w-auto !py-1.5 text-sm" onchange="this.form.submit()" aria-label="Closer">
                    <option value="">All closers</option>
                    <?php foreach ($closers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $f['closer'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                    <option value="unassigned" <?= $f['closer'] === 'unassigned' ? 'selected' : '' ?>>Unassigned numbers</option>
                </select>
            <?php endif; ?>
            <select name="direction" class="input !w-auto !py-1.5 text-sm" onchange="this.form.submit()" aria-label="Direction">
                <option value="">In &amp; outbound</option>
                <option value="outbound" <?= $f['direction'] === 'outbound' ? 'selected' : '' ?>>Outbound</option>
                <option value="inbound" <?= $f['direction'] === 'inbound' ? 'selected' : '' ?>>Inbound</option>
            </select>
            <select name="result" class="input !w-auto !py-1.5 text-sm" onchange="this.form.submit()" aria-label="Result">
                <option value="">Any result</option>
                <option value="connected" <?= $f['result'] === 'connected' ? 'selected' : '' ?>>Connected</option>
                <option value="not_connected" <?= $f['result'] === 'not_connected' ? 'selected' : '' ?>>Not connected</option>
            </select>
        </form>
        <span class="ml-auto text-xs text-slate-500"><?= e(format_dt($f['from'], 'M j, Y')) ?><?= $f['from'] !== $f['to'] ? ' – ' . e(format_dt($f['to'], 'M j, Y')) : '' ?></span>
    </div>
    <form method="get" action="<?= e(url($target)) ?>" x-show="custom" x-cloak class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3">
        <input type="hidden" name="range" value="custom">
        <?php foreach (['closer' => allowed('calls.view_all') ? $f['closer'] : '', 'direction' => $f['direction'], 'result' => $f['result']] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
        <div><label class="label !mb-1 text-xs" for="f_from">From</label><input class="input !py-1.5" type="date" id="f_from" name="from" value="<?= e($f['from']) ?>" required></div>
        <div><label class="label !mb-1 text-xs" for="f_to">To</label><input class="input !py-1.5" type="date" id="f_to" name="to" value="<?= e($f['to']) ?>" required></div>
        <button class="btn-primary btn-sm !py-2">Apply</button>
    </form>
</div>
