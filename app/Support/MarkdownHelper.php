<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

class MarkdownHelper
{
    public static function htmlToMarkdown(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $text = self::children($document->getElementsByTagName('body')->item(0) ?? $document);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private static function children(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::convert($child);
        }

        return $text;
    }

    private static function convert(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/u', ' ', $node->textContent) ?? '';
        }
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'template'], true)) {
            return '';
        }
        if ($tag === 'table') {
            return self::table($node);
        }
        if ($tag === 'ul' || $tag === 'ol') {
            $items = [];
            $number = $node->hasAttribute('start') ? (int) $node->getAttribute('start') : 1;
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->tagName === 'li') {
                    $marker = $tag === 'ol' ? $number++.'. ' : '- ';
                    $items[] = $marker.str_replace("\n", "\n    ", trim(self::children($child)));
                }
            }

            return "\n".implode("\n", $items)."\n";
        }
        if ($tag === 'pre') {
            $fence = str_repeat('`', max(3, self::longestBacktickRun($node->textContent) + 1));

            return "\n\n{$fence}\n".trim($node->textContent, "\r\n")."\n{$fence}\n\n";
        }
        if ($tag === 'img') {
            return '!['.$node->getAttribute('alt').']('.$node->getAttribute('src').')';
        }

        $text = self::children($node);
        if (preg_match('/^h([1-6])$/', $tag, $matches)) {
            return "\n\n".str_repeat('#', (int) $matches[1]).' '.trim($text)."\n\n";
        }

        return match ($tag) {
            'a' => $node->hasAttribute('href') ? '['.trim($text).']('.$node->getAttribute('href').')' : $text,
            'b', 'strong' => '**'.trim($text).'**',
            'i', 'em' => '*'.trim($text).'*',
            'br' => "\n",
            'hr' => "\n\n---\n\n",
            'p', 'div', 'section', 'figure', 'figcaption' => "\n\n".trim($text)."\n\n",
            'blockquote' => "\n\n> ".str_replace("\n", "\n> ", trim($text))."\n\n",
            'code' => '`'.$node->textContent.'`',
            default => $text,
        };
    }

    private static function table(DOMElement $table): string
    {
        $rows = [];
        $hasHeader = false;
        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || ! in_array($cell->tagName, ['th', 'td'], true)) {
                    continue;
                }
                // Markdown cannot faithfully represent merged cells or nested tables.
                if ($cell->hasAttribute('colspan') || $cell->hasAttribute('rowspan') || $cell->getElementsByTagName('table')->length) {
                    return "\n\n".$table->ownerDocument->saveHTML($table)."\n\n";
                }
                $hasHeader = $hasHeader || ($rows === [] && $cell->tagName === 'th');
                $value = trim(self::children($cell));
                $cells[] = str_replace(['|', "\r", "\n"], ['\\|', '', '<br>'], $value);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return '';
        }

        $columns = max(array_map('count', $rows));
        if (! $hasHeader) {
            array_unshift($rows, array_fill(0, $columns, ''));
        }
        array_splice($rows, 1, 0, [array_fill(0, $columns, '---')]);
        $lines = array_map(fn ($row) => '| '.implode(' | ', array_pad($row, $columns, '')).' |', $rows);
        $caption = $table->getElementsByTagName('caption')->item(0)?->textContent;

        return "\n\n".($caption ? $caption."\n\n" : '').implode("\n", $lines)."\n\n";
    }

    private static function longestBacktickRun(string $text): int
    {
        preg_match_all('/`+/', $text, $matches);

        return $matches[0] ? max(array_map('strlen', $matches[0])) : 0;
    }
}
