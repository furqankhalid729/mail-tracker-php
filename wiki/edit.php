<?php
/**
 * Create (no id, optional ?parent_id=) or edit (?id=) a wiki page with the Editor.js block editor.
 * The browser posts the editor's JSON in `content`; the server re-validates it and stores a plain-text copy for search.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('wiki.manage');
$ws = ws_id();
$pages = wiki_pages_index();
$page = input_int('id') ? q_one('SELECT * FROM wiki_pages WHERE id = ? AND workspace_id = ?', [input_int('id'), $ws]) : null;
if (input_int('id') && !$page) {
    abort(404, 'Wiki page not found.');
}
// A page cannot be moved under itself or one of its own sub-pages
$blockedParents = $page ? array_flip(wiki_descendant_ids((int) $page['id'], $pages)) : [];

if (is_post()) {
    verify_csrf();
    $errors = validate($_POST, ['title' => 'required|max:200'], []);
    $icon = trim((string) input('icon'));
    if (mb_strlen($icon) > 4 || preg_match('/[<>&"\'\s]/', $icon)) {
        $errors['icon'] = 'Use a single emoji as the icon.';
    }
    $parentId = input_int('parent_id') ?: null;
    if ($parentId && (!isset($pages[$parentId]) || isset($blockedParents[$parentId]))) {
        $errors['parent_id'] = 'Choose another parent page.';
    }
    $content = (string) input('content', '');
    if (strlen($content) > 2 * 1024 * 1024) {
        $errors['content'] = 'This page is too long (over 2 MB). Split it into sub-pages.';
    }
    $decoded = json_decode($content, true);
    if ($content !== '' && !is_array($decoded['blocks'] ?? null)) {
        $errors['content'] = 'The page content could not be read. Please try again.';
    }
    if ($errors) {
        keep_old_input();
        flash_errors($errors);
        redirect(url('wiki/edit.php', array_filter(['id' => $page['id'] ?? null, 'parent_id' => $page ? null : $parentId])));
    }
    // Keep only what the renderer understands, with every text field sanitized
    $json = json_encode(['time' => $decoded['time'] ?? null, 'blocks' => wiki_clean_blocks($decoded['blocks'] ?? []), 'version' => $decoded['version'] ?? null], JSON_UNESCAPED_UNICODE);
    $data = [
        'title' => trim((string) input('title')),
        'icon' => $icon ?: null,
        'parent_id' => $parentId,
        'content' => $json,
        'content_text' => wiki_plain_text($json),
        'sort_order' => max(-9999, min(9999, input_int('sort_order'))),
        'updated_by' => user_id(),
        'updated_at' => now(),
    ];
    if ($page) {
        db_update('wiki_pages', $data, 'id = ? AND workspace_id = ?', [$page['id'], $ws]);
        // Images taken out of the page in this edit
        wiki_delete_unused_images(array_diff(wiki_image_names($page['content']), wiki_image_names($json)));
        $id = (int) $page['id'];
        flash('success', 'Page saved.');
    } else {
        $id = db_insert('wiki_pages', $data + ['workspace_id' => $ws, 'created_by' => user_id(), 'created_at' => now()]);
        flash('success', 'Page created.');
    }
    clear_old_input();
    redirect(url('wiki/view.php', ['id' => $id]));
}

$errors = get_errors();
$parentId = (int) old('parent_id', $page['parent_id'] ?? input_int('parent_id'));
$sortOrder = (int) old('sort_order', $page['sort_order'] ?? ((int) q_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM wiki_pages WHERE workspace_id = ? AND parent_id <=> ?', [$ws, $parentId ?: null])));
$content = (string) old('content', $page['content'] ?? '');
$initial = json_decode($content, true);
if (!is_array($initial['blocks'] ?? null)) {
    $initial = ['blocks' => []];
}

// Parent options: indented tree, without this page and its own sub-pages
$parentOptions = [];
$walk = function (int $pid, int $depth) use (&$walk, &$parentOptions, $pages, $blockedParents): void {
    foreach (wiki_children_map($pages)[$pid] ?? [] as $p) {
        if (isset($blockedParents[(int) $p['id']])) {
            continue;
        }
        $parentOptions[] = [(int) $p['id'], str_repeat('— ', $depth) . wiki_page_label($p)];
        $walk((int) $p['id'], $depth + 1);
    }
};
$walk(0, 0);

$extra_scripts = '';
foreach (['editorjs', 'header', 'list', 'table', 'quote', 'code', 'delimiter', 'inline-code', 'marker', 'warning', 'image', 'underline'] as $lib) {
    $extra_scripts .= '<script src="' . e(asset("vendor/editorjs/$lib.js")) . '"></script>';
}

$page_title = $page ? 'Edit ' . $page['title'] : 'New wiki page';
$active_nav = 'wiki';
require __DIR__ . '/../includes/header.php';
?>
<form method="post" action="<?= e(url('wiki/edit.php', $page ? ['id' => $page['id']] : [])) ?>" id="wiki-form" x-data="wikiEditor()" @submit.prevent="submit()">
    <?= csrf_field() ?>
    <input type="hidden" name="content" x-ref="content" value="">

    <div class="page-header">
        <div>
            <a href="<?= e($page ? url('wiki/view.php', ['id' => $page['id']]) : url('wiki/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= $page ? e($page['title']) : 'Wiki' ?></a>
            <h1 class="page-title"><?= $page ? 'Edit page' : 'New page' ?></h1>
        </div>
        <div class="flex items-center gap-2">
            <span class="hidden text-xs text-slate-400 sm:inline">Ctrl + S to save</span>
            <a href="<?= e($page ? url('wiki/view.php', ['id' => $page['id']]) : url('wiki/index.php')) ?>" class="btn-secondary">Cancel</a>
            <span x-show="error" x-cloak x-text="error" class="text-sm text-red-600"></span>
            <button class="btn-primary" :disabled="saving"><?= icon('check', 'h-4 w-4') ?> <span x-text="saving ? 'Saving…' : 'Save page'">Save page</span></button>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-4">
        <div class="card xl:col-span-3">
            <div class="px-6 pt-6 sm:px-14">
                <div class="flex items-center gap-2" x-data="{ picking: false }">
                    <div class="relative">
                        <button type="button" class="flex h-12 w-12 items-center justify-center rounded-lg text-3xl hover:bg-slate-100" @click="picking = !picking" title="Choose an icon">
                            <span x-text="icon || '＋'" :class="icon ? '' : 'text-base text-slate-400'"></span>
                        </button>
                        <div x-show="picking" x-cloak @click.outside="picking = false" class="dropdown left-0 !w-72 p-2">
                            <div class="grid grid-cols-8 gap-1">
                                <template x-for="em in emojis" :key="em">
                                    <button type="button" class="rounded p-1 text-xl hover:bg-slate-100" x-text="em" @click="icon = em; picking = false"></button>
                                </template>
                            </div>
                            <div class="mt-2 flex items-center gap-2 border-t border-slate-100 pt-2">
                                <input class="input !py-1 text-sm" x-model="icon" maxlength="4" placeholder="Or paste any emoji">
                                <button type="button" class="btn-ghost !py-1 text-xs" @click="icon = ''; picking = false">Remove</button>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="icon" :value="icon">
                </div>
                <?= field_error($errors, 'icon') ?>
                <input name="title" value="<?= e(old('title', $page['title'] ?? '')) ?>" required maxlength="200" placeholder="Untitled" aria-label="Title"
                       class="mt-2 w-full border-0 p-0 text-3xl font-bold tracking-tight text-slate-900 placeholder:text-slate-300 focus:ring-0">
                <?= field_error($errors, 'title') ?>
                <?= field_error($errors, 'content') ?>
            </div>
            <div id="editor" class="wiki-editor px-6 pb-16 pt-4 sm:px-14"></div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Page settings</h2></div>
                <div class="card-body space-y-4">
                    <div>
                        <label class="label" for="parent_id">Inside</label>
                        <select class="input" id="parent_id" name="parent_id">
                            <option value="">Top level</option>
                            <?php foreach ($parentOptions as [$pid, $label]): ?><option value="<?= $pid ?>" <?= $parentId === $pid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                        <?= field_error($errors, 'parent_id') ?>
                    </div>
                    <div>
                        <label class="label" for="sort_order">Order</label>
                        <input class="input" type="number" id="sort_order" name="sort_order" value="<?= $sortOrder ?>" min="-9999" max="9999">
                        <p class="help">Lower numbers are listed first among pages at the same level.</p>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">How to write</h2></div>
                <ul class="card-body list-disc space-y-1.5 pl-9 text-xs text-slate-600">
                    <li>Click <b>＋</b> at the left of an empty line, or press <b>Tab</b>, to add a heading, list, checklist, table, callout, quote, code, divider or image.</li>
                    <li>Select text to make it <b>bold</b>, <i>italic</i>, <u>underlined</u>, highlighted, inline code or a link.</li>
                    <li>Use <b>⋮⋮</b> beside a block to move, convert or delete it.</li>
                    <li>In lists press <b>Tab</b> to indent and <b>Shift + Tab</b> to outdent.</li>
                </ul>
            </div>
        </div>
    </div>
</form>
<script>
// The Editor.js instance lives outside Alpine's reactive state: Alpine proxies everything it holds, which breaks Editor.js.
let wikiEditorInstance = null;
window.wikiEditor = () => ({
    saving: false, dirty: false, error: '',
    icon: <?= json_encode((string) old('icon', $page['icon'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    emojis: ['📄','📘','📒','📌','📎','📝','✅','⭐','💡','🚀','🎯','📈','📊','💰','🧾','📞','✉️','🤝','👋','👥','🧑‍💻','🛠️','⚙️','🔒','❓','⚠️','📅','🏁','🏆','🎓','🗂️','🌍'],
    init() {
        wikiEditorInstance = new EditorJS({
            holder: 'editor',
            data: <?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
            onChange: () => { this.dirty = true; },
            placeholder: 'Start writing, or press Tab for headings, lists, tables…',
            inlineToolbar: ['bold', 'italic', 'underline', 'link', 'marker', 'inlineCode'],
            tools: {
                header: { class: Header, inlineToolbar: true, config: { levels: [1, 2, 3, 4], defaultLevel: 2 }, shortcut: 'CMD+SHIFT+H' },
                list: { class: EditorjsList, inlineToolbar: true, config: { defaultStyle: 'unordered' }, shortcut: 'CMD+SHIFT+L' },
                table: { class: Table, inlineToolbar: true, config: { rows: 3, cols: 3, withHeadings: true } },
                warning: { class: Warning, inlineToolbar: true, config: { titlePlaceholder: 'Callout title', messagePlaceholder: 'Message' }, toolbox: { title: 'Callout' } },
                quote: { class: Quote, inlineToolbar: true },
                code: CodeTool,
                delimiter: Delimiter,
                image: { class: ImageTool, config: {
                    endpoints: { byFile: <?= json_encode(url('wiki/upload.php')) ?> },
                    field: 'image', types: 'image/png, image/jpeg, image/gif, image/webp',
                    additionalRequestHeaders: { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content, 'X-Requested-With': 'XMLHttpRequest' },
                } },
                marker: { class: Marker, shortcut: 'CMD+SHIFT+M' },
                inlineCode: { class: InlineCode, shortcut: 'CMD+SHIFT+C' },
                underline: Underline,
            },
        });
        this.$el.addEventListener('input', () => { this.dirty = true; });
        window.addEventListener('beforeunload', (e) => { if (this.dirty && !this.saving) { e.preventDefault(); e.returnValue = ''; } });
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); this.submit(); }
        });
    },
    async submit() {
        if (this.saving) return;
        const form = document.getElementById('wiki-form');
        if (!form.reportValidity()) return;
        this.saving = true;
        try {
            this.error = '';
            const data = await wikiEditorInstance.save();
            this.$refs.content.value = JSON.stringify(data);
            form.submit();
        } catch (err) {
            this.saving = false;
            this.error = 'Could not save the page: ' + err.message;
        }
    },
});
</script>
<?php require __DIR__ . '/../includes/footer.php';
