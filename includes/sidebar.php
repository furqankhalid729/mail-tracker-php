<?php
$nav = [
    ['dashboard', 'Dashboard', 'dashboard.php', 'home'],
    ['inbox', 'Inbox', 'inbox/index.php', 'inbox'],
    ['tasks', 'Tasks', 'tasks/index.php', 'tasks'],
    ['leads', 'Leads', 'leads/index.php', 'phone-out'],
    ['leads-pending', 'Pending tasks', 'leads/pending.php', 'calendar'],
    ['calls', 'Calls', 'calls/index.php', 'phone'],
    ['customers', 'Customers', 'customers/index.php', 'users'],
    ['campaigns', 'Campaigns', 'campaigns/index.php', 'megaphone'],
    ['ai-campaigns', 'AI Campaigns', 'ai-campaigns/index.php', 'sparkles'],
    ['templates', 'Templates', 'templates/index.php', 'template'],
    ['tags', 'Tags', 'tags/index.php', 'tag'],
    ['employees', 'Employees', 'employees/index.php', 'briefcase'],
    ['leaves', 'Leave', 'leaves/index.php', 'sun'],
    ['payroll', 'Payroll', 'payroll/index.php', 'banknotes'],
    ['expenses', 'Expenses', 'expenses/index.php', 'receipt'],
    ['reports', 'Reports', 'reports/index.php', 'chart'],
    ['activity', 'Activity', 'activity/index.php', 'activity'],
    ['wiki', 'Wiki', 'wiki/index.php', 'book'],
    ['mail-accounts', 'Mail Accounts', 'mail-accounts/index.php', 'at'],
    ['settings', 'Settings', 'settings/index.php', 'cog'],
];
// Collapsible sidebar groups: group key => [label, icon, member nav keys]. Unlisted keys stay top-level.
$navGroups = [
    'sales' => ['Sales', 'phone-out', ['leads', 'leads-pending', 'calls', 'customers']],
    'marketing' => ['Marketing', 'megaphone', ['campaigns', 'ai-campaigns', 'templates', 'tags']],
    'people' => ['People', 'briefcase', ['employees', 'leaves']],
    'finance' => ['Finance', 'banknotes', ['payroll', 'expenses']],
    'insights' => ['Insights', 'chart', ['reports', 'activity']],
    'admin' => ['Settings', 'cog', ['mail-accounts', 'settings']],
];
if (!can_see_calls()) {
    $nav = array_values(array_filter($nav, fn($item) => $item[0] !== 'calls'));
}
foreach (['employees' => 'employees.manage', 'payroll' => 'payroll.manage', 'expenses' => 'expenses.manage'] as $navKey => $perm) {
    if (!allowed($perm)) {
        $nav = array_values(array_filter($nav, fn($item) => $item[0] !== $navKey));
    }
}
if (!allowed('reports.view')) {
    $nav = array_values(array_filter($nav, fn($item) => $item[0] !== 'reports'));
}
if (!can_see_leads()) {
    $nav = array_values(array_filter($nav, fn($item) => !in_array($item[0], ['leads', 'leads-pending'], true)));
}
if (is_closer()) {
    $closerNav = ['leads', 'leads-pending', 'calls', 'inbox', 'customers', 'leaves', 'wiki', 'settings'];
    $nav = array_values(array_filter($nav, fn($item) => in_array($item[0], $closerNav, true)));
    usort($nav, fn($a, $b) => array_search($a[0], $closerNav) <=> array_search($b[0], $closerNav));
}
$unread = (int) q_val('SELECT COUNT(*) FROM email_threads WHERE workspace_id = ? AND is_unread = 1', [ws_id()]);
$myOpenTaskCount = my_open_task_count();
$pendingLeaveCount = leave_pending_count();
$subscriptionsDueCount = in_array('expenses', array_column($nav, 0), true) ? count(subscriptions_due_soon(7)) : 0;
$pendingApprovalCount = in_array('leads', array_column($nav, 0), true) ? lead_pending_approval_count() : 0;
$pendingDueCount = in_array('leads-pending', array_column($nav, 0), true) ? lead_pending_due_count() : 0;
$myWorkspaces = q_all('SELECT w.id, w.name FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id WHERE m.user_id = ? ORDER BY w.name', [user_id()]);

$navBadges = [
    'inbox' => [$unread, 'bg-indigo-600 text-white', ''],
    'leads-pending' => [$pendingDueCount, 'bg-red-100 text-red-700', 'Due today or overdue (assigned to you)'],
    'leads' => [$pendingApprovalCount, 'bg-amber-100 text-amber-700', 'Leads awaiting your approval'],
    'expenses' => [$subscriptionsDueCount, 'bg-amber-100 text-amber-700', 'Subscriptions due within 7 days'],
    'leaves' => [$pendingLeaveCount, 'bg-amber-100 text-amber-700', 'Leave requests waiting for approval'],
    'tasks' => [$myOpenTaskCount, 'bg-indigo-100 text-indigo-700', ''],
];
// Build sections in the order their first item appears in $nav (keeps the closer ordering intact).
// Built inside a closure so its loop variables can't clobber the including page's variables.
$navSections = (function () use ($nav, $navGroups) {
    $sections = [];
    $byKey = array_column($nav, null, 0);
    foreach ($nav as $item) {
        $groupKey = null;
        foreach ($navGroups as $gk => [, , $members]) {
            if (in_array($item[0], $members, true)) { $groupKey = $gk; break; }
        }
        if ($groupKey === null) {
            $sections[] = ['item', $item];
        } elseif (!isset($sections['g:' . $groupKey])) {
            $items = array_values(array_filter(array_map(fn($k) => $byKey[$k] ?? null, $navGroups[$groupKey][2])));
            $sections['g:' . $groupKey] = count($items) === 1 ? ['item', $items[0]] : ['group', $groupKey, $items];
        }
    }
    return $sections;
})();

$renderBadge = function (int $count, string $cls, string $title) {
    if ($count <= 0) return;
    echo '<span class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold leading-none ' . $cls . '"' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . ($count > 99 ? '99+' : $count) . '</span>';
};
$renderLink = function (array $item, bool $child = false) use ($active_nav, $pendingApprovalCount, $navBadges, $renderBadge) {
    [$key, $label, $href, $ic] = $item; ?>
    <a href="<?= e($key === 'leads' && $pendingApprovalCount ? url($href, ['approval' => 'pending']) : url($href)) ?>" class="nav-link <?= $active_nav === $key ? 'active' : '' ?> <?= $child ? 'py-1.5 pl-9 text-[13px]' : '' ?>">
        <?= icon($ic, $child ? 'h-4 w-4 text-slate-400' : 'h-5 w-5 text-slate-400') ?>
        <span class="flex-1"><?= e($label) ?></span>
        <?php if (isset($navBadges[$key])) $renderBadge(...$navBadges[$key]); ?>
    </a>
<?php };

$renderNav = function () use ($navSections, $navGroups, $navBadges, $active_nav, $renderLink, $renderBadge, $myWorkspaces, $workspace) { ?>
    <div class="flex h-14 shrink-0 items-center gap-2.5 border-b border-slate-200 px-5">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-sm"><?= icon('mail', 'h-4.5 w-4.5 h-[18px] w-[18px]') ?></span>
        <span class="text-[15px] font-semibold tracking-tight text-slate-900"><?= e(APP_NAME) ?></span>
    </div>
    <div class="px-3 pt-4" x-data="{ open: false }" @click.outside="open = false">
        <button type="button" @click="open = !open" class="flex w-full items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-left text-sm hover:bg-slate-50">
            <span class="flex h-6 w-6 items-center justify-center rounded bg-slate-800 text-[11px] font-semibold text-white"><?= e(initials($workspace['name'])) ?></span>
            <span class="flex-1 truncate font-medium text-slate-800"><?= e($workspace['name']) ?></span>
            <?= icon('chevron-down', 'h-4 w-4 text-slate-400') ?>
        </button>
        <div x-cloak x-show="open" x-transition.opacity class="dropdown left-3 right-3 !min-w-0">
            <?php foreach ($myWorkspaces as $w): ?>
                <form method="post" action="<?= e(url('settings/workspace.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="switch"><input type="hidden" name="workspace_id" value="<?= (int) $w['id'] ?>">
                    <button class="dropdown-item <?= (int) $w['id'] === (int) $workspace['id'] ? 'font-semibold text-indigo-700' : '' ?>"><?= e($w['name']) ?></button>
                </form>
            <?php endforeach; ?>
            <a href="<?= e(url('settings/index.php', ['tab' => 'workspace'])) ?>#new-workspace" class="dropdown-item border-t border-slate-100 text-slate-500"><?= icon('plus', 'h-4 w-4') ?> New workspace</a>
        </div>
    </div>
    <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
        <?php foreach ($navSections as $section): ?>
            <?php if ($section[0] === 'item'): $renderLink($section[1]); continue; endif; ?>
            <?php
            [, $gk, $items] = $section;
            [$gLabel, $gIcon] = $navGroups[$gk];
            $gKeys = array_column($items, 0);
            $gActive = in_array($active_nav, $gKeys, true);
            $gCount = array_sum(array_map(fn($k) => $navBadges[$k][0] ?? 0, $gKeys));
            $store = 'crm.nav.' . $gk;
            ?>
            <div x-data="{ open: <?= $gActive ? 'true' : "(() => { try { return localStorage.getItem('$store') === '1' } catch (e) { return false } })()" ?> }"
                 x-effect="try { localStorage.setItem('<?= $store ?>', open ? '1' : '0') } catch (e) {}">
                <button type="button" @click="open = !open" :aria-expanded="open" class="nav-link w-full text-left <?= $gActive ? 'text-slate-900' : '' ?>">
                    <?= icon($gIcon, 'h-5 w-5 text-slate-400') ?>
                    <span class="flex-1"><?= e($gLabel) ?></span>
                    <?php if ($gCount > 0): ?><span x-show="!open"><?php $renderBadge($gCount, 'bg-amber-100 text-amber-700', ''); ?></span><?php endif; ?>
                    <span class="transition-transform" :class="open ? 'rotate-180' : ''"><?= icon('chevron-down', 'h-4 w-4 text-slate-400') ?></span>
                </button>
                <div x-show="open" <?= $gActive ? '' : 'x-cloak' ?> class="mt-0.5 space-y-0.5">
                    <?php foreach ($items as $item) $renderLink($item, true); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="border-t border-slate-200 p-4 text-xs text-slate-400">
        Opens are approximate. <a class="underline hover:text-slate-600" href="<?= e(url('settings/index.php', ['tab' => 'tracking'])) ?>">Why?</a>
    </div>
<?php };
?>
<!-- Mobile sidebar -->
<div x-cloak x-show="sidebarOpen" class="relative z-40 lg:hidden">
    <div x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 bg-slate-900/50" @click="sidebarOpen = false"></div>
    <div x-show="sidebarOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
         class="fixed inset-y-0 left-0 flex w-72 flex-col bg-white shadow-xl">
        <button type="button" class="btn-icon absolute right-3 top-3" @click="sidebarOpen = false" aria-label="Close menu"><?= icon('x') ?></button>
        <?php $renderNav(); ?>
    </div>
</div>
<!-- Desktop sidebar -->
<aside class="hidden lg:fixed lg:inset-y-0 lg:z-30 lg:flex lg:w-64 lg:flex-col lg:border-r lg:border-slate-200 lg:bg-white">
    <?php $renderNav(); ?>
</aside>
