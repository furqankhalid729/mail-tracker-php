<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$canManage = allowed('wiki.manage');
$pages = wiki_pages_index();
$currentId = 0;
$q = mb_substr(trim((string) input('q')), 0, 100);

if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $results = q_all(
        'SELECT p.id, p.parent_id, p.title, p.icon, p.content_text, p.updated_at, u.name updater_name
         FROM wiki_pages p LEFT JOIN users u ON u.id = p.updated_by
         WHERE p.workspace_id = ? AND (p.title LIKE ? OR p.content_text LIKE ?)
         ORDER BY (p.title LIKE ?) DESC, p.updated_at DESC LIMIT 50',
        [$ws, $like, $like, $like]
    );
} else {
    $topLevel = wiki_children_map($pages)[0] ?? [];
    $recent = q_all(
        'SELECT p.id, p.parent_id, p.title, p.icon, p.updated_at, u.name updater_name
         FROM wiki_pages p LEFT JOIN users u ON u.id = p.updated_by
         WHERE p.workspace_id = ? ORDER BY p.updated_at DESC LIMIT 8',
        [$ws]
    );
}

$page_title = $q !== '' ? "Search: $q" : 'Wiki';
$active_nav = 'wiki';
require __DIR__ . '/../includes/header.php';

$crumbs = function (array $p) use ($pages): string {
    $chain = wiki_ancestors((int) $p['id'], $pages);
    return $chain ? e(implode(' / ', array_map(fn($a) => $a['title'], $chain))) : '';
};
?>
<div class="page-header">
    <div><h1 class="page-title">Wiki</h1><p class="page-subtitle">Guides, processes and know-how for the whole team.</p></div>
    <?php if ($canManage): ?><a href="<?= e(url('wiki/edit.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> New page</a><?php endif; ?>
</div>

<div class="flex flex-col gap-6 lg:flex-row lg:items-start">
    <?php require __DIR__ . '/_tree.php'; ?>

    <div class="min-w-0 flex-1 space-y-6">
        <?php if ($q !== ''): ?>
            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for “<?= e($q) ?>”</h2><a href="<?= e(url('wiki/index.php')) ?>" class="btn-ghost">Clear</a></div>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($results as $r): $path = $crumbs($r); ?>
                        <li>
                            <a href="<?= e(url('wiki/view.php', ['id' => $r['id'], 'q' => $q])) ?>" class="block px-5 py-4 hover:bg-slate-50">
                                <?php if ($path): ?><div class="mb-0.5 truncate text-xs text-slate-400"><?= $path ?></div><?php endif; ?>
                                <div class="font-medium text-slate-900"><?= $r['icon'] ? e($r['icon']) . ' ' : '' ?><?= wiki_snippet($r['title'], $q, 200) ?></div>
                                <?php $snip = wiki_snippet((string) $r['content_text'], $q); if ($snip !== ''): ?><p class="mt-1 line-clamp-2 text-sm text-slate-600 [&_mark]:rounded [&_mark]:bg-amber-100 [&_mark]:px-0.5"><?= $snip ?></p><?php endif; ?>
                                <div class="mt-1 text-xs text-slate-400">Updated <?= e(time_ago($r['updated_at'])) ?><?= $r['updater_name'] ? ' by ' . e($r['updater_name']) : '' ?></div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$results): ?><li class="px-5 py-10 text-center text-sm text-slate-400">Nothing in the wiki matches “<?= e($q) ?>”.</li><?php endif; ?>
                </ul>
            </div>
        <?php elseif (!$pages): ?>
            <div class="card">
                <div class="empty-state">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><?= icon('book', 'h-6 w-6') ?></span>
                    <h3 class="mt-3 text-sm font-semibold">The wiki is empty</h3>
                    <p class="mt-1 text-sm text-slate-500"><?= $canManage ? 'Write the first page: onboarding, sales scripts, policies, FAQs…' : 'An admin hasn’t written any pages yet.' ?></p>
                    <?php if ($canManage): ?><a href="<?= e(url('wiki/edit.php')) ?>" class="btn-primary mt-4"><?= icon('plus', 'h-4 w-4') ?> New page</a><?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div>
                <h2 class="mb-3 text-sm font-semibold text-slate-900">Sections</h2>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($topLevel as $p): $kids = count(wiki_children_map($pages)[(int) $p['id']] ?? []); ?>
                        <a href="<?= e(url('wiki/view.php', ['id' => $p['id']])) ?>" class="card block p-4 transition hover:border-indigo-300 hover:shadow">
                            <div class="mb-2 text-2xl leading-none"><?= $p['icon'] ? e($p['icon']) : '📄' ?></div>
                            <div class="truncate font-medium text-slate-900"><?= e($p['title']) ?></div>
                            <div class="mt-0.5 text-xs text-slate-500"><?= $kids ? $kids . ' sub-page' . ($kids === 1 ? '' : 's') . ' · ' : '' ?>updated <?= e(time_ago($p['updated_at'])) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title">Recently updated</h2></div>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($recent as $r): $path = $crumbs($r); ?>
                        <li>
                            <a href="<?= e(url('wiki/view.php', ['id' => $r['id']])) ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                                <span class="w-5 shrink-0 text-center"><?= $r['icon'] ? e($r['icon']) : '📄' ?></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-900"><?= e($r['title']) ?></span>
                                    <?php if ($path): ?><span class="block truncate text-xs text-slate-400"><?= $path ?></span><?php endif; ?>
                                </span>
                                <span class="shrink-0 text-xs text-slate-400"><?= e(time_ago($r['updated_at'])) ?><?= $r['updater_name'] ? ' · ' . e($r['updater_name']) : '' ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
