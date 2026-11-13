<?php
/**
 * Lightweight allow-list HTML sanitiser for rich-text fields coming
 * out of the WYSIWYG editor (TinyMCE, in meeting_edit.php).
 *
 * The approach:
 *   - Parse the untrusted HTML into a DOMDocument.
 *   - Walk every element in the tree.
 *   - Any tag not in SAFE_HTML_TAGS is unwrapped (its children stay
 *     but the element itself is removed).
 *   - Any attribute not in SAFE_HTML_ATTRS for that tag is removed.
 *   - href / src values are checked against a protocol allowlist
 *     (http, https, mailto, #, /, relative) — javascript:, data:,
 *     vbscript: and friends get dropped.
 *   - <script>, <style>, <iframe>, <object>, <embed>, <form>,
 *     <input>, <link>, <meta> and their content are REMOVED
 *     entirely (not unwrapped), because their children aren't
 *     meaningful text content.
 *
 * What's allowed is a bounded, forum-grade rich-text set: paragraph
 * formatting, basic inline style (bold / italic / underline / strike /
 * code), headings, lists, links, tables, blockquotes, horizontal
 * rules. That's what the WYSIWYG toolbar actually emits.
 */

const SAFE_HTML_TAGS = [
    'p', 'br', 'hr',
    'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'code',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li',
    'a',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
    'blockquote', 'pre',
    'span', 'div',
];

const SAFE_HTML_ATTRS = [
    'a'    => ['href', 'title', 'target', 'rel'],
    'th'   => ['colspan', 'rowspan', 'scope', 'style'],
    'td'   => ['colspan', 'rowspan', 'style'],
    'table'=> ['style'],
    'tr'   => ['style'],
    'p'    => ['style'],
    'div'  => ['style'],
    'span' => ['style'],
    'h1'   => ['style'], 'h2' => ['style'], 'h3' => ['style'],
    'h4'   => ['style'], 'h5' => ['style'], 'h6' => ['style'],
];

// Unwanted root elements — removed with their contents.
const SAFE_HTML_KILL_TAGS = [
    'script', 'style', 'iframe', 'object', 'embed', 'form',
    'input', 'button', 'select', 'textarea', 'link', 'meta',
    'noscript', 'svg', 'math', 'applet',
];

// Only these inline style properties survive — keeps alignment and
// basic colour but kills things like `position: absolute`, `url(…)`,
// and the whole collection of CSS escape vectors.
const SAFE_HTML_STYLE_PROPS = [
    'text-align', 'font-weight', 'font-style', 'text-decoration',
    'color', 'background-color',
    'margin', 'margin-left', 'margin-right', 'padding',
    'width', 'height',
    'border', 'border-collapse', 'vertical-align',
];

const SAFE_HTML_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

function safe_html(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    // libxml < 2.9 would try to pull external DTDs — guard via
    // LIBXML_NONET + LIBXML_NOENT off. We wrap input in a UTF-8 meta
    // so DOMDocument doesn't mangle multibyte characters, and in a
    // root <body> so the output walker always has somewhere to start.
    $wrapped = '<?xml encoding="utf-8" ?><html><body>' . $raw . '</body></html>';
    $prev = libxml_use_internal_errors(true);
    $dom  = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadHTML($wrapped, LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body === null) return '';

    html_sanitizer__walk($body);

    $out = '';
    foreach ($body->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return trim($out);
}

/** Recursively prune / unwrap / strip-attrs under $node. */
function html_sanitizer__walk(DOMNode $node): void
{
    // Snapshot children because the iteration will mutate the tree.
    $kids = [];
    foreach ($node->childNodes as $k) $kids[] = $k;

    foreach ($kids as $child) {
        if ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);

            // Kill tags — remove entirely with their content.
            if (in_array($tag, SAFE_HTML_KILL_TAGS, true)) {
                $child->parentNode->removeChild($child);
                continue;
            }

            // Unknown tags — unwrap (keep the children, drop the tag).
            if (!in_array($tag, SAFE_HTML_TAGS, true)) {
                html_sanitizer__walk($child);
                // Move each child out of this element, then remove it.
                $parent = $child->parentNode;
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            // Strip attributes: anything not on the allow-list for
            // THIS tag (plus the universal URL / style scrubs) is gone.
            html_sanitizer__scrub_attrs($child, $tag);

            // Recurse into children.
            html_sanitizer__walk($child);
            continue;
        }
        if ($child instanceof DOMComment) {
            $child->parentNode->removeChild($child);
            continue;
        }
        // DOMText + DOMCdataSection: left as-is.
    }
}

function html_sanitizer__scrub_attrs(DOMElement $el, string $tag): void
{
    $allowed = SAFE_HTML_ATTRS[$tag] ?? [];
    $toRemove = [];
    foreach ($el->attributes as $a) $toRemove[] = $a->name;
    foreach ($toRemove as $name) {
        $lname = strtolower($name);

        // Always kill event handlers (onclick, onerror, …) regardless
        // of tag, and anything starting with xmlns / xml:.
        if (str_starts_with($lname, 'on') || str_starts_with($lname, 'xmlns') || str_starts_with($lname, 'xml:')) {
            $el->removeAttribute($name);
            continue;
        }

        if (!in_array($lname, $allowed, true)) {
            $el->removeAttribute($name);
            continue;
        }

        // URL protocols for href/src — only http/https/mailto/tel,
        // relative paths, and in-page anchors survive.
        if ($lname === 'href' || $lname === 'src') {
            $val = trim((string) $el->getAttribute($name));
            if ($val === '' || str_starts_with($val, '#') || str_starts_with($val, '/')
                || str_starts_with($val, 'http://') || str_starts_with($val, 'https://')
                || str_starts_with($val, 'mailto:') || str_starts_with($val, 'tel:')) {
                // keep it — plus a rel="noopener" if target=_blank
                if ($tag === 'a' && strtolower((string) $el->getAttribute('target')) === '_blank'
                    && $el->getAttribute('rel') === '') {
                    $el->setAttribute('rel', 'noopener noreferrer');
                }
                continue;
            }
            $el->removeAttribute($name);
            continue;
        }

        // Style: keep only the allowlisted properties.
        if ($lname === 'style') {
            $clean = html_sanitizer__clean_style((string) $el->getAttribute($name));
            if ($clean === '') $el->removeAttribute($name);
            else               $el->setAttribute($name, $clean);
            continue;
        }
    }
}

function html_sanitizer__clean_style(string $raw): string
{
    $out = [];
    foreach (explode(';', $raw) as $piece) {
        $piece = trim($piece);
        if ($piece === '') continue;
        $colon = strpos($piece, ':');
        if ($colon === false) continue;
        $prop  = strtolower(trim(substr($piece, 0, $colon)));
        $value = trim(substr($piece, $colon + 1));
        if (!in_array($prop, SAFE_HTML_STYLE_PROPS, true)) continue;
        // Kill any url(...) / expression(...) payloads.
        if (preg_match('/url\s*\(|expression\s*\(|javascript\s*:|@import/i', $value)) continue;
        // Collapse funky characters.
        $value = preg_replace('/[^a-zA-Z0-9\s\-_%#.,\/()]/u', '', $value);
        if ($value === '') continue;
        $out[] = $prop . ': ' . $value;
    }
    return implode('; ', $out);
}

/**
 * Render a stored description for display. If `${field}_html` on
 * $row is 1, the stored value is already sanitised HTML and can go
 * out directly. Otherwise it's legacy plain text — emit it esc()ed
 * with newlines preserved via nl2br, matching the pre-WYSIWYG look.
 */
function render_rich_text(?string $value, bool $isHtml): string
{
    if ($value === null) return '';
    $v = (string) $value;
    if ($v === '') return '';
    if ($isHtml) return safe_html($v);           // double-check on render
    return nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
}
