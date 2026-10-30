<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Allow-list HTML sanitizer for rich text (blog posts, product descriptions,
 * legal pages). Anything not explicitly allowed is removed; disallowed
 * elements keep their text content unless they are dangerous containers.
 */
final class HtmlSanitizer
{
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'h5' => ['id'], 'h6' => ['id'],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'mark' => [], 'small' => [],
        'blockquote' => ['cite'], 'q' => [], 'cite' => [], 'code' => [], 'pre' => [],
        'ul' => [], 'ol' => ['start', 'type'], 'li' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'srcset', 'sizes'],
        'figure' => [], 'figcaption' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'span' => [], 'div' => ['data-block', 'data-ids'],
    ];

    /** Removed together with everything inside them. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'meta', 'link', 'base'];

    /** h1 inside body content is demoted: the page already has one. */
    private const RENAME = ['h1' => 'h2'];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body><div id="__root">' . $html . '</div></body>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if ($root === null) {
            return '';
        }
        $this->cleanChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private function cleanChildren(\DOMNode $parent): void
    {
        // Copy first: the live NodeList changes while we edit.
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction || $node instanceof \DOMCdataSection) {
                $parent->removeChild($node);
                continue;
            }
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                continue;
            }
            if (isset(self::RENAME[$tag])) {
                $node = $this->rename($node, self::RENAME[$tag]);
                $tag = self::RENAME[$tag];
            }
            if (!array_key_exists($tag, self::ALLOWED)) {
                // Unwrap: keep the (cleaned) children, drop the element itself.
                $this->cleanChildren($node);
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $this->cleanAttributes($node, self::ALLOWED[$tag]);
            $this->cleanChildren($node);
        }
    }

    private function cleanAttributes(\DOMElement $el, array $allowed): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);
            if (!in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->name);
                continue;
            }
            if (in_array($name, ['href', 'src', 'cite'], true) && !$this->safeUrl($value, $name === 'href')) {
                $el->removeAttribute($attr->name);
                continue;
            }
            if ($name === 'srcset' && preg_match('/(javascript|data|vbscript):/i', $value)) {
                $el->removeAttribute($attr->name);
            }
            if ($name === 'data-ids' && !preg_match('/^[0-9,]*$/', $value)) {
                $el->removeAttribute($attr->name);
            }
            if ($name === 'target' && $value !== '_blank') {
                $el->removeAttribute($attr->name);
            }
            if (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'start'], true) && !ctype_digit($value)) {
                $el->removeAttribute($attr->name);
            }
        }
        // Links opening a new tab must not get access to window.opener.
        if (strtolower($el->tagName) === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
        if (strtolower($el->tagName) === 'img' && !$el->hasAttribute('loading')) {
            $el->setAttribute('loading', 'lazy');
        }
    }

    private function safeUrl(string $url, bool $allowContactSchemes): bool
    {
        // Strip control chars/whitespace browsers ignore ("java\tscript:").
        $normalized = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($normalized === '' || $normalized[0] === '/' || $normalized[0] === '#' || $normalized[0] === '?') {
            return true; // relative or same-page link
        }
        if (!preg_match('/^([a-z][a-z0-9+.-]*):/', $normalized, $m)) {
            return true; // relative path
        }
        $allowed = $allowContactSchemes ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'];
        return in_array($m[1], $allowed, true);
    }

    private function rename(\DOMElement $el, string $tag): \DOMElement
    {
        $new = $el->ownerDocument->createElement($tag);
        foreach (iterator_to_array($el->attributes) as $attr) {
            $new->setAttribute($attr->name, $attr->value);
        }
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);
        return $new;
    }
}
