<?php

namespace App\Support;

class MarkdownHelper
{
    /**
     * Convert HTML content to clean Markdown for LLMs / AI summary crawlers.
     */
    public static function htmlToMarkdown(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $html);

        // Convert headers
        $text = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n# $1\n", $text);
        $text = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n## $1\n", $text);
        $text = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n### $1\n", $text);
        $text = preg_replace('/<h4[^>]*>(.*?)<\/h4>/is', "\n#### $1\n", $text);
        $text = preg_replace('/<h5[^>]*>(.*?)<\/h5>/is', "\n##### $1\n", $text);
        $text = preg_replace('/<h6[^>]*>(.*?)<\/h6>/is', "\n###### $1\n", $text);

        // Convert links <a href="url">text</a> -> [text](url)
        $text = preg_replace_callback('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ($matches) {
            $url = $matches[1];
            $linkText = trim(strip_tags($matches[2]));
            return $linkText ? "[{$linkText}]({$url})" : $url;
        }, $text);

        // Convert images <img src="url" alt="alt"> -> ![alt](url)
        $text = preg_replace_callback('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/is', function ($matches) {
            $src = $matches[1];
            preg_match('/alt=["\']([^"\']*)["\']/', $matches[0], $altMatch);
            $alt = $altMatch[1] ?? 'image';
            return "\n![{$alt}]({$src})\n";
        }, $text);

        // Convert bold & italic
        $text = preg_replace('/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/is', '**$2**', $text);
        $text = preg_replace('/<(em|i)[^>]*>(.*?)<\/(em|i)>/is', '*$2*', $text);

        // Convert list items
        $text = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $text);
        $text = preg_replace('/<\/(ul|ol)>/i', "\n", $text);

        // Convert paragraphs and line breaks
        $text = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "\n$1\n", $text);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n> $1\n", $text);

        // Strip remaining HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up multiple empty lines
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text));

        return $text;
    }
}
