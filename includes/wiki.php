<?php
declare(strict_types=1);

/**
 * Wiki: Notion-style pages built from Editor.js blocks (headings, paragraphs, bullet / numbered / check lists,
 * tables, callouts, quotes, code, dividers, images). Admins write (wiki.manage); every member reads and searches.
 *
 * Pages are stored as Editor.js JSON (content) plus a plain-text copy (content_text) for search.
 * Rendering never trusts the JSON: block text is reduced to a small inline allow-list, everything else is escaped.
 */

const WIKI_IMAGE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

/** Every page of the workspace (no content), keyed by id. */
function wiki_pages_index(): array
{
    $rows = q_all('SELECT id, parent_id, title, icon, sort_order, updated_at FROM wiki_pages WHERE workspace_id = ? ORDER BY sort_order, title', [ws_id()]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

/** parent id (0 = top level) => list of child pages, in display order. */
function wiki_children_map(array $pages): array
{
    $map = [];
    foreach ($pages as $id => $p) {
        $parent = (int) $p['parent_id'];
        $map[isset($pages[$parent]) ? $parent : 0][] = $p;
    }
    return $map;
}

/** Ancestors of $id, root first (not including the page itself). */
function wiki_ancestors(int $id, array $pages): array
{
    $chain = [];
    $seen = [$id => true];
    $parent = (int) ($pages[$id]['parent_id'] ?? 0);
    while ($parent && isset($pages[$parent]) && !isset($seen[$parent])) {
        $seen[$parent] = true;
        array_unshift($chain, $pages[$parent]);
        $parent = (int) $pages[$parent]['parent_id'];
    }
    return $chain;
}

/** $id and every page below it (used to stop a page being moved under its own child). */
function wiki_descendant_ids(int $id, array $pages): array
{
    $map = wiki_children_map($pages);
    $ids = [$id];
    for ($i = 0; $i < count($ids); $i++) {
        foreach ($map[$ids[$i]] ?? [] as $child) {
            $ids[] = (int) $child['id'];
        }
    }
    return $ids;
}

function wiki_page_label(array $p): string
{
    return trim(($p['icon'] ? $p['icon'] . ' ' : '') . $p['title']);
}

/**
 * Inline HTML from Editor.js (bold, italic, underline, links, highlight, inline code) reduced to a strict allow-list.
 */
function wiki_inline(?string $html): string
{
    $html = (string) $html;
    if ($html === '' || !str_contains($html, '<') && !str_contains($html, '&')) {
        return e($html);
    }
    static $allowed = ['b', 'strong', 'i', 'em', 'u', 's', 'a', 'mark', 'code', 'br'];
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__root');
    if (!$root) {
        return e(strip_tags($html));
    }
    $walk = function (DOMNode $node) use (&$walk, $allowed): void {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child);
                continue;
            }
            $walk($child);
            $tag = strtolower($child->tagName);
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            // Editor.js recognises highlight and inline code by these classes when the page is edited again
            $class = ['mark' => 'cdx-marker', 'code' => 'inline-code'][$tag] ?? null;
            $keepClass = $class && $child->getAttribute('class') === $class;
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->name);
            }
            if ($keepClass) {
                $child->setAttribute('class', $class);
            }
            if ($tag === 'a') {
                if ($href !== '' && is_safe_url($href) && !str_starts_with(strtolower($href), '//')) {
                    $child->setAttribute('href', $href);
                    if (preg_match('#^https?://#i', $href)) {
                        $child->setAttribute('target', '_blank');
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                }
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    return $out;
}

/**
 * Clean Editor.js blocks before saving: every text field goes through wiki_inline(), so nothing unsafe is stored
 * (Editor.js puts saved HTML straight back into the page when it is edited again). Code blocks stay plain text.
 */
function wiki_clean_blocks(array $blocks): array
{
    $allowedTypes = ['paragraph', 'header', 'list', 'checklist', 'table', 'quote', 'warning', 'code', 'delimiter', 'image'];
    $clean = function (mixed $v, string $key = '') use (&$clean): mixed {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = $clean($x, (string) $k);
            }
            return $out;
        }
        if (!is_string($v) || in_array($key, ['code', 'url', 'style', 'alignment', 'id'], true)) {
            return is_string($v) || is_bool($v) || is_int($v) || is_float($v) || $v === null ? $v : null;
        }
        return wiki_inline($v);
    };
    $out = [];
    foreach ($blocks as $b) {
        if (!is_array($b) || !in_array($b['type'] ?? '', $allowedTypes, true)) {
            continue;
        }
        $data = $clean(is_array($b['data'] ?? null) ? $b['data'] : []);
        if (($b['type'] === 'image') && !wiki_image_url((string) ($data['file']['url'] ?? ''))) {
            continue;
        }
        $out[] = array_filter(['id' => is_string($b['id'] ?? null) ? mb_substr($b['id'], 0, 40) : null, 'type' => $b['type'], 'data' => $data], fn($x) => $x !== null);
    }
    return $out;
}

function wiki_blocks(?string $json): array
{
    $data = json_decode((string) $json, true);
    return is_array($data['blocks'] ?? null) ? $data['blocks'] : [];
}

/** Stored names of the uploaded images a page's content uses. */
function wiki_image_names(?string $json): array
{
    preg_match_all('#wiki/file\.php\?f=([a-f0-9]{32}\.(?:png|jpe?g|gif|webp))#', str_replace('\/', '/', (string) $json), $m);
    return array_values(array_unique($m[1]));
}

/** Delete uploaded images that no page in the workspace uses any more (call after the page is saved or deleted). */
function wiki_delete_unused_images(array $names): void
{
    foreach ($names as $name) {
        if (!preg_match('/^[a-f0-9]{32}\.(png|jpe?g|gif|webp)$/', $name)
            || q_val('SELECT 1 FROM wiki_pages WHERE workspace_id = ? AND content LIKE ? LIMIT 1', [ws_id(), '%' . $name . '%'])) {
            continue;
        }
        $path = UPLOAD_PATH . '/wiki/' . ws_id() . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/** URL of an uploaded wiki image, rebuilt from its stored name so links survive an APP_URL change. */
function wiki_image_url(string $url): ?string
{
    if (preg_match('#wiki/file\.php\?f=([a-f0-9]{32}\.(?:png|jpe?g|gif|webp))#', $url, $m)) {
        return url('wiki/file.php', ['f' => $m[1]]);
    }
    return preg_match('#^https://#i', $url) && is_safe_url($url) ? $url : null;
}

/**
 * Render Editor.js blocks to HTML. Returns [html, headings[]] where each heading is [id, level, text]
 * for the page's table of contents.
 */
function wiki_render(?string $json): array
{
    $html = '';
    $headings = [];
    $usedIds = [];
    $renderList = function (array $items, string $style) use (&$renderList): string {
        $tag = $style === 'ordered' ? 'ol' : 'ul';
        $out = '<' . $tag . ($style === 'checklist' ? ' class="wiki-checklist"' : '') . '>';
        foreach ($items as $item) {
            $text = is_array($item) ? (string) ($item['content'] ?? $item['text'] ?? '') : (string) $item;
            $checked = is_array($item) && !empty($item['meta']['checked']);
            $out .= '<li' . ($style === 'checklist' ? ' class="' . ($checked ? 'is-checked' : '') . '"' : '') . '>';
            if ($style === 'checklist') {
                $out .= '<span class="wiki-check" aria-hidden="true">' . ($checked ? '✓' : '') . '</span>';
            }
            $out .= '<span>' . wiki_inline($text) . '</span>';
            if (is_array($item) && !empty($item['items']) && is_array($item['items'])) {
                $out .= $renderList($item['items'], $style);
            }
            $out .= '</li>';
        }
        return $out . '</' . $tag . '>';
    };

    foreach (wiki_blocks($json) as $block) {
        $d = is_array($block['data'] ?? null) ? $block['data'] : [];
        switch ($block['type'] ?? '') {
            case 'paragraph':
                $html .= '<p>' . wiki_inline($d['text'] ?? '') . '</p>';
                break;
            case 'header':
                $level = max(1, min(4, (int) ($d['level'] ?? 2)));
                $plain = trim(html_entity_decode(strip_tags((string) ($d['text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($plain)), '-') ?: 'section';
                $id = $slug;
                for ($n = 2; isset($usedIds[$id]); $n++) {
                    $id = "$slug-$n";
                }
                $usedIds[$id] = true;
                $headings[] = [$id, $level, $plain];
                $html .= "<h$level id=\"" . e($id) . '">' . wiki_inline($d['text'] ?? '') . "</h$level>";
                break;
            case 'list':
                $style = in_array($d['style'] ?? '', ['ordered', 'unordered', 'checklist'], true) ? $d['style'] : 'unordered';
                $html .= $renderList(is_array($d['items'] ?? null) ? $d['items'] : [], $style);
                break;
            case 'checklist': // older Editor.js checklist tool
                $html .= $renderList(array_map(fn($i) => ['content' => $i['text'] ?? '', 'meta' => ['checked' => !empty($i['checked'])]], (array) ($d['items'] ?? [])), 'checklist');
                break;
            case 'table':
                $rows = array_values(array_filter(is_array($d['content'] ?? null) ? $d['content'] : [], 'is_array'));
                $cells = fn(array $row, string $tag) => implode('', array_map(fn($c) => "<$tag>" . wiki_inline(is_string($c) ? $c : '') . "</$tag>", $row));
                $head = !empty($d['withHeadings']) && $rows ? array_shift($rows) : null;
                $html .= '<div class="wiki-table"><table>'
                    . ($head !== null ? '<thead><tr>' . $cells($head, 'th') . '</tr></thead>' : '')
                    . '<tbody>' . implode('', array_map(fn($r) => '<tr>' . $cells($r, 'td') . '</tr>', $rows)) . '</tbody></table></div>';
                break;
            case 'quote':
                $html .= '<blockquote><p>' . wiki_inline($d['text'] ?? '') . '</p>' . (!empty($d['caption']) ? '<cite>' . wiki_inline($d['caption']) . '</cite>' : '') . '</blockquote>';
                break;
            case 'warning':
                $html .= '<div class="wiki-callout"><span class="wiki-callout-icon" aria-hidden="true">💡</span><div>'
                    . (!empty($d['title']) ? '<div class="wiki-callout-title">' . wiki_inline($d['title']) . '</div>' : '')
                    . (!empty($d['message']) ? '<div>' . wiki_inline($d['message']) . '</div>' : '') . '</div></div>';
                break;
            case 'code':
                $html .= '<pre><code>' . e((string) ($d['code'] ?? '')) . '</code></pre>';
                break;
            case 'delimiter':
                $html .= '<hr>';
                break;
            case 'image':
                $src = wiki_image_url((string) ($d['file']['url'] ?? ''));
                if ($src) {
                    $cls = trim((!empty($d['withBorder']) ? 'with-border ' : '') . (!empty($d['withBackground']) ? 'with-background ' : '') . (!empty($d['stretched']) ? 'stretched' : ''));
                    $caption = trim(strip_tags((string) ($d['caption'] ?? '')));
                    $html .= '<figure class="wiki-image ' . e($cls) . '"><img src="' . e($src) . '" alt="' . e($caption) . '" loading="lazy">'
                        . ($caption !== '' ? '<figcaption>' . wiki_inline($d['caption']) . '</figcaption>' : '') . '</figure>';
                }
                break;
        }
    }
    return [$html, $headings];
}

/** Plain text of every block, for search. */
function wiki_plain_text(?string $json): string
{
    $parts = [];
    $collect = function (mixed $v) use (&$collect, &$parts): void {
        if (is_string($v)) {
            $t = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</li>'], ' ', $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '' && !preg_match('#^(https?://|wiki/file\.php|ordered|unordered|checklist|[0-9.]+)$#', $t)) {
                $parts[] = $t;
            }
        } elseif (is_array($v)) {
            foreach ($v as $k => $x) {
                if (!in_array($k, ['file', 'style', 'meta', 'level', 'withHeadings', 'stretched', 'alignment', 'id'], true)) {
                    $collect($x);
                }
            }
        }
    };
    foreach (wiki_blocks($json) as $b) {
        $collect($b['data'] ?? []);
    }
    return preg_replace('/\s+/u', ' ', implode("\n", $parts));
}

/** A short excerpt of $text around the first match of $q, with matches highlighted (HTML). */
function wiki_snippet(string $text, string $q, int $length = 200): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $pos = $q !== '' ? mb_stripos($text, $q) : false;
    $start = $pos === false ? 0 : max(0, $pos - 60);
    $excerpt = mb_substr($text, $start, $length);
    $html = ($start > 0 ? '… ' : '') . e($excerpt) . ($start + $length < mb_strlen($text) ? ' …' : '');
    if ($q !== '') {
        $html = preg_replace('/' . preg_quote(e($q), '/') . '/iu', '<mark>$0</mark>', $html);
    }
    return $html;
}
