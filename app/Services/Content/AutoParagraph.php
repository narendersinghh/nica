<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Converts classic-editor text (blank-line separated paragraphs, raw newlines)
 * into HTML paragraphs, like WordPress' wpautop(). Used once at import so
 * stored content is plain, renderable HTML.
 */
final class AutoParagraph
{
    private const BLOCK = 'table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary';

    public function convert(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }
        // Keep <pre> blocks untouched.
        $pre = [];
        $text = preg_replace_callback('#<pre\b.*?</pre>#is', static function ($m) use (&$pre) {
            $pre[] = $m[0];
            return "\n\n<!--PRE" . (count($pre) - 1) . "-->\n\n";
        }, $text);

        $block = self::BLOCK;
        // Put block tags on their own "paragraph".
        $text = (string) preg_replace("#(<(?:{$block})\b[^>]*>)#i", "\n\n$1", $text);
        $text = (string) preg_replace("#(</(?:{$block})>)#i", "$1\n\n", $text);
        $text = (string) preg_replace("/\n\s*\n+/", "\n\n", $text);

        $out = '';
        foreach (preg_split("/\n\n/", $text) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (preg_match("#^<!--PRE\d+-->$#", $chunk) || preg_match("#^</?(?:{$block})\b#i", $chunk)) {
                $out .= $chunk . "\n";
                continue;
            }
            $out .= '<p>' . str_replace("\n", "<br>\n", $chunk) . "</p>\n";
        }

        return trim((string) preg_replace_callback('#<!--PRE(\d+)-->#', static fn ($m) => $pre[(int) $m[1]], $out));
    }
}
