<?php
declare(strict_types=1);

/**
 * Allow-list HTML sanitizer for email bodies (outgoing templates and inbound messages).
 * Removes scripts, event handlers, forms, iframes and dangerous URL schemes.
 */
function sanitize_html(?string $html): string
{
    $html = (string) $html;
    if (trim($html) === '') {
        return '';
    }

    static $allowedTags = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'center', 'code', 'col', 'colgroup', 'dd', 'del', 'div', 'dl', 'dt',
        'em', 'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'li', 'mark', 'ol', 'p', 'pre', 'q',
        's', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
        'body', 'html', 'head', 'main', 'section', 'article', 'header', 'footer', 'figure', 'figcaption',
    ];
    // Tags removed together with their contents
    static $dropWithContent = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'textarea', 'select', 'button', 'input', 'noscript', 'template', 'svg', 'math', 'title', 'meta', 'link', 'base', 'frame', 'frameset'];
    static $allowedAttrs = [
        'href', 'src', 'alt', 'title', 'width', 'height', 'style', 'align', 'valign', 'colspan', 'rowspan', 'border',
        'cellpadding', 'cellspacing', 'bgcolor', 'color', 'face', 'size', 'target', 'rel', 'class', 'dir', 'lang', 'start', 'type',
    ];

    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('__root');
    if (!$root) {
        return e(strip_tags($html));
    }

    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $dropWithContent, $allowedAttrs): void {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction || $child instanceof DOMCdataSection) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, $dropWithContent, true)) {
                $node->removeChild($child);
                continue;
            }
            $walk($child);
            if (!in_array($tag, $allowedTags, true)) {
                // Unwrap: keep the children, drop the element
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $value = trim($attr->value);
                if (!in_array($name, $allowedAttrs, true)) {
                    $child->removeAttribute($attr->name);
                    continue;
                }
                if (in_array($name, ['href', 'src'], true) && !is_safe_url($value, $name === 'src')) {
                    $child->removeAttribute($attr->name);
                    continue;
                }
                if ($name === 'style') {
                    $clean = sanitize_css($value);
                    $clean === '' ? $child->removeAttribute('style') : $child->setAttribute('style', $clean);
                }
            }
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
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

function is_safe_url(string $url, bool $isImage = false): bool
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $compact = strtolower(preg_replace('/[\x00-\x20]+/', '', $url));
    if ($compact === '' || $compact[0] === '#' || $compact[0] === '/') {
        return true; // anchors, relative and protocol-relative (http/https) URLs
    }
    if (preg_match('/^(https?|mailto|tel):/', $compact)) {
        return true;
    }
    if ($isImage && preg_match('/^(cid:|data:image\/(png|jpe?g|gif|webp);base64,)/', $compact)) {
        return true;
    }
    // Relative path without scheme
    return !preg_match('/^[a-z][a-z0-9+.-]*:/', $compact);
}

function sanitize_css(string $css): string
{
    $lower = strtolower(preg_replace('/\s+/', '', $css));
    if (preg_match('/expression\(|javascript:|vbscript:|behavior:|-moz-binding|@import|url\((?!["\']?https?:)/', $lower)) {
        // Strip every declaration that contains something risky, keep the rest
        $safe = [];
        foreach (explode(';', $css) as $decl) {
            $d = strtolower(preg_replace('/\s+/', '', $decl));
            if ($d === '' || preg_match('/expression\(|javascript:|vbscript:|behavior:|-moz-binding|@import|url\(/', $d)) {
                continue;
            }
            $safe[] = trim($decl);
        }
        return implode('; ', $safe);
    }
    return trim($css);
}

/** Plain text → simple HTML paragraphs. */
function text_to_html(string $text): string
{
    $paras = preg_split("/\R{2,}/", trim($text));
    return implode("\n", array_map(fn($p) => '<p>' . nl2br(e($p)) . '</p>', $paras));
}

/** HTML → readable plain text (for text/plain alternative parts). */
function html_to_text(string $html): string
{
    $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace_callback('#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ($m) {
        $text = trim(strip_tags($m[3]));
        $href = html_entity_decode($m[2]);
        return ($text === '' || $text === $href || str_starts_with($href, 'mailto:')) ? ($text ?: $href) : "$text ($href)";
    }, $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = preg_replace('#</(p|div|h[1-6]|li|tr|blockquote)>#i', "\n\n", $html);
    $html = preg_replace('#<li[^>]*>#i', '- ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/ ?\n ?/", "\n", $text);
    return trim(preg_replace("/\n{3,}/", "\n\n", $text));
}
