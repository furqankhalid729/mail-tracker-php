<?php
$nav = [
    ['dashboard', 'Dashboard', 'dashboard.php', 'home'],
    ['customers', 'Customers', 'customers/index.php', 'users'],
    ['campaigns', 'Campaigns', 'campaigns/index.php', 'megaphone'],
    ['inbox', 'Inbox', 'inbox/index.php', 'inbox'],
    ['tasks', 'Tasks', 'tasks/index.php', 'tasks'],
    ['calls', 'Calls', 'calls/index.php', 'phone'],
    ['activity', 'Activity', 'activity/index.php', 'activity'],
    ['templates', 'Templates', 'templates/index.php', 'template'],
    ['mail-accounts', 'Mail Accounts', 'mail-accounts/index.php', 'at'],
    ['tags', 'Tags', 'tags/index.php', 'tag'],
    ['settings', 'Settings', 'settings/index.php', 'cog'],
];
if (!can_see_calls()) {
    $nav = array_values(array_filter($nav, fn($item) => $item[0] !== 'calls'));
}
if (is_closer()) {
    $nav = array_values(array_filter($nav, fn($item) => in_array($item[0], ['calls', 'inbox', 'customers', 'settings'], true)));
    usort($nav, fn($a, $b) => array_search($a[0], ['calls', 'inbox', 'customers', 'settings']) <=> array_search($b[0], ['calls', 'inbox', 'customers', 'settings']));
}
$unread = (int) q_val('SELECT COUNT(*) FROM email_threads WHERE workspace_id = ? AND is_unread = 1', [ws_id()]);
$myOpenTaskCount = my_open_task_count();
$myWorkspaces = q_all('SELECT w.id, w.name FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id WHERE m.user_id = ? ORDER BY w.name', [user_id()]);

$renderNav = function () use ($nav, $active_nav, $unread, $myOpenTaskCount, $myWorkspaces, $workspace) { ?>
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
        <?php foreach ($nav as [$key, $label, $href, $ic]): ?>
            <a href="<?= e(url($href)) ?>" class="nav-link <?= $active_nav === $key ? 'active' : '' ?>">
                <?= icon($ic, 'h-5 w-5 text-slate-400') ?>
                <span class="flex-1"><?= e($label) ?></span>
                <?php if ($key === 'inbox' && $unread > 0): ?>
                    <span class="rounded-full bg-indigo-600 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-white"><?= $unread > 99 ? '99+' : $unread ?></span>
                <?php elseif ($key === 'tasks' && $myOpenTaskCount > 0): ?>
                    <span class="rounded-full bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-indigo-700"><?= $myOpenTaskCount > 99 ? '99+' : $myOpenTaskCount ?></span>
                <?php endif; ?>
            </a>
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
