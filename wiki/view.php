<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$canManage = allowed('wiki.manage');
$page = q_one(
    'SELECT p.*, cu.name creator_name, uu.name updater_name FROM wiki_pages p
     LEFT JOIN users cu ON cu.id = p.created_by LEFT JOIN users uu ON uu.id = p.updated_by
     WHERE p.id = ? AND p.workspace_id = ?',
    [input_int('id'), $ws]
);
if (!$page) {
    abort(404, 'Wiki page not found.');
}
$pages = wiki_pages_index();
$currentId = (int) $page['id'];
$q = '';
$highlight = mb_substr(trim((string) input('q')), 0, 100); // arriving from search: highlight the term
[$html, $headings] = wiki_render($page['content']);
$children = wiki_children_map($pages)[$currentId] ?? [];
$ancestors = wiki_ancestors($currentId, $pages);

$page_title = $page['title'];
$active_nav = 'wiki';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex flex-col gap-6 lg:flex-row lg:items-start">
    <?php require __DIR__ . '/_tree.php'; ?>

    <article class="min-w-0 flex-1">
        <div class="card">
            <div class="border-b border-slate-100 px-6 pb-5 pt-5 sm:px-10">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <nav class="flex min-w-0 flex-wrap items-center gap-1 text-xs text-slate-500" aria-label="Breadcrumb">
                        <a href="<?= e(url('wiki/index.php')) ?>" class="hover:text-slate-700">Wiki</a>
                        <?php foreach ($ancestors as $a): ?>
                            <span class="text-slate-300">/</span><a href="<?= e(url('wiki/view.php', ['id' => $a['id']])) ?>" class="max-w-[12rem] truncate hover:text-slate-700"><?= e(wiki_page_label($a)) ?></a>
                        <?php endforeach; ?>
                    </nav>
                    <?php if ($canManage): ?>
                        <div class="flex gap-2">
                            <a href="<?= e(url('wiki/edit.php', ['parent_id' => $currentId])) ?>" class="btn-ghost !py-1.5 text-xs"><?= icon('plus', 'h-4 w-4') ?> Sub-page</a>
                            <a href="<?= e(url('wiki/edit.php', ['id' => $currentId])) ?>" class="btn-secondary !py-1.5"><?= icon('pencil', 'h-4 w-4') ?> Edit</a>
                            <form method="post" action="<?= e(url('wiki/delete.php')) ?>" data-confirm="Delete “<?= e($page['title']) ?>”?<?= $children ? ' Its sub-pages will move up one level.' : '' ?>" data-confirm-button="Delete">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $currentId ?>">
                                <button class="btn-secondary !py-1.5 text-red-600" aria-label="Delete page" title="Delete page"><?= icon('trash', 'h-4 w-4') ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($page['icon']): ?><div class="mt-4 text-5xl leading-none"><?= e($page['icon']) ?></div><?php endif; ?>
                <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-900"><?= e($page['title']) ?></h1>
                <p class="mt-2 text-xs text-slate-400">
                    Updated <?= e(time_ago($page['updated_at'])) ?><?= $page['updater_name'] ? ' by ' . e($page['updater_name']) : '' ?>
                    · created by <?= e($page['creator_name'] ?: 'a former member') ?> on <?= e(format_dt($page['created_at'], 'M j, Y')) ?>
                </p>
            </div>

            <div class="px-6 py-6 sm:px-10">
                <?php if ($html !== ''): ?>
                    <div class="wiki-content" id="wiki-content"><?= $html ?></div>
                <?php elseif (!$children): ?>
                    <p class="text-sm text-slate-400">This page is empty.<?= $canManage ? ' <a class="text-indigo-600 hover:underline" href="' . e(url('wiki/edit.php', ['id' => $currentId])) . '">Start writing</a>.' : '' ?></p>
                <?php endif; ?>

                <?php if ($children): ?>
                    <div class="<?= $html !== '' ? 'mt-8 border-t border-slate-100 pt-6' : '' ?>">
                        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Pages in this section</h2>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <?php foreach ($children as $c): ?>
                                <a href="<?= e(url('wiki/view.php', ['id' => $c['id']])) ?>" class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm hover:border-indigo-300 hover:bg-slate-50">
                                    <span class="text-lg leading-none"><?= $c['icon'] ? e($c['icon']) : '📄' ?></span>
                                    <span class="truncate font-medium text-slate-800"><?= e($c['title']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </article>

    <?php if (count($headings) >= 2): ?>
        <nav class="hidden w-52 shrink-0 xl:block" aria-label="On this page">
            <div class="sticky top-20">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">On this page</h2>
                <ul class="space-y-1 border-l border-slate-200 text-sm">
                    <?php foreach ($headings as [$hid, $level, $text]): ?>
                        <li><a href="#<?= e($hid) ?>" class="-ml-px block truncate border-l border-transparent py-0.5 text-slate-500 hover:border-slate-400 hover:text-slate-800" style="padding-left: <?= 12 + ($level - min(array_column($headings, 1))) * 12 ?>px"><?= e($text) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </nav>
    <?php endif; ?>
</div>
<?php if ($highlight !== ''): ?>
<script>
// Highlight the searched term in the page and scroll to its first match
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('wiki-content');
    const term = <?= json_encode(mb_strtolower($highlight), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (!root || !term) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const hits = [];
    while (walker.nextNode()) {
        if (walker.currentNode.nodeValue.toLowerCase().includes(term)) hits.push(walker.currentNode);
    }
    let first = null;
    hits.forEach((node) => {
        const parts = node.nodeValue.split(new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'i'));
        const frag = document.createDocumentFragment();
        parts.forEach((part, i) => {
            if (i % 2) { const m = document.createElement('mark'); m.className = 'wiki-hit'; m.textContent = part; frag.appendChild(m); first = first || m; }
            else if (part) frag.appendChild(document.createTextNode(part));
        });
        node.parentNode.replaceChild(frag, node);
    });
    if (first) first.scrollIntoView({ block: 'center' });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
