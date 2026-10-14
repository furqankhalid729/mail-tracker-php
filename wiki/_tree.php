<?php
defined('APP_ROOT') || exit;
/**
 * Left-hand page tree with search. Expects: $pages (wiki_pages_index()), $currentId (int, 0 on the home page), $q (string).
 * Branches containing the current page start open; the rest can be expanded.
 */
$childrenMap = wiki_children_map($pages);
$openIds = array_flip(array_map(fn($p) => (int) $p['id'], $currentId ? wiki_ancestors($currentId, $pages) : []));
$renderBranch = function (int $parentId, int $depth) use (&$renderBranch, $childrenMap, $openIds, $currentId): void {
    foreach ($childrenMap[$parentId] ?? [] as $p):
        $id = (int) $p['id'];
        $hasKids = !empty($childrenMap[$id]); ?>
        <li x-data="{ open: <?= isset($openIds[$id]) || $id === $currentId ? 'true' : 'false' ?> }">
            <div class="group flex items-center rounded-md <?= $id === $currentId ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-100' ?>" style="padding-left: <?= $depth * 12 ?>px">
                <?php if ($hasKids): ?>
                    <button type="button" class="flex h-6 w-5 shrink-0 items-center justify-center text-slate-400 hover:text-slate-600" @click="open = !open" :aria-label="open ? 'Collapse' : 'Expand'">
                        <span class="inline-block transition-transform" :class="open ? '' : '-rotate-90'"><?= icon('chevron-down', 'h-3.5 w-3.5') ?></span>
                    </button>
                <?php else: ?><span class="w-5 shrink-0"></span><?php endif; ?>
                <a href="<?= e(url('wiki/view.php', ['id' => $id])) ?>" class="flex min-w-0 flex-1 items-center gap-1.5 py-1.5 pr-2 text-sm <?= $id === $currentId ? 'font-medium' : '' ?>">
                    <span class="w-4 shrink-0 text-center"><?= $p['icon'] ? e($p['icon']) : '<span class="text-slate-400">' . icon('template', 'h-4 w-4') . '</span>' ?></span>
                    <span class="truncate"><?= e($p['title']) ?></span>
                </a>
            </div>
            <?php if ($hasKids): ?><ul x-show="open" x-cloak><?php $renderBranch($id, $depth + 1); ?></ul><?php endif; ?>
        </li>
    <?php endforeach;
};
?>
<aside class="lg:w-64 lg:shrink-0">
    <div class="card p-3 lg:sticky lg:top-20">
        <form method="get" action="<?= e(url('wiki/index.php')) ?>" class="relative mb-3">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
            <input class="input !pl-9" type="search" name="q" value="<?= e($q) ?>" placeholder="Search the wiki…" aria-label="Search the wiki">
        </form>
        <a href="<?= e(url('wiki/index.php')) ?>" class="mb-1 flex items-center gap-2 rounded-md px-2 py-1.5 text-sm <?= !$currentId && $q === '' ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-700 hover:bg-slate-100' ?>"><?= icon('home', 'h-4 w-4 text-slate-400') ?> Wiki home</a>
        <div class="max-h-[60vh] overflow-y-auto lg:max-h-[calc(100vh-14rem)]">
            <?php if ($pages): ?>
                <ul class="space-y-0.5"><?php $renderBranch(0, 0); ?></ul>
            <?php else: ?>
                <p class="px-2 py-3 text-xs text-slate-400">No pages yet.</p>
            <?php endif; ?>
        </div>
        <?php if (allowed('wiki.manage')): ?>
            <a href="<?= e(url('wiki/edit.php')) ?>" class="mt-2 flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-700"><?= icon('plus', 'h-4 w-4') ?> New page</a>
        <?php endif; ?>
    </div>
</aside>
