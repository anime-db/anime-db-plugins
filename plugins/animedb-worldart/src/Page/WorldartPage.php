<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\AnimedbWorldart\Page;

use Dom\HTMLDocument;
use Dom\XPath;

/**
 * A page of the site parsed from the raw bytes of the response.
 *
 * The bytes are NOT re-encoded: the page is in `windows-1251` and `Dom\HTMLDocument` takes the
 * encoding from its own `meta`. A page converted to UTF-8 beforehand would keep that `meta` and be
 * decoded a second time. The `<noindex>` block is not cut out: the links to other databases lie in it.
 */
final class WorldartPage
{
    private const XHTML = 'http://www.w3.org/1999/xhtml';

    private XPath $xpath;

    private function __construct(private readonly HTMLDocument $document)
    {
        $this->xpath = new XPath($document);
        $this->xpath->registerNamespace('x', self::XHTML);
    }

    public static function fromBytes(string $bytes): self
    {
        return new self(HTMLDocument::createFromString($bytes, \LIBXML_NOERROR));
    }

    public function xpath(): XPath
    {
        return $this->xpath;
    }

    /**
     * The title of the entry (`<font size=5>`), or null when the page has none: for a card this
     * is the only sign of "not found".
     */
    public function title(): ?string
    {
        $nodes = $this->xpath->query('//x:font[@size="5"]');
        if ($nodes->length === 0) {
            return null;
        }

        $title = self::clean((string) $nodes->item(0)?->textContent);

        return $title === '' ? null : $title;
    }

    /**
     * Pairs "label → value" of the card: a `td.review` cell holding `<b>Label</b>` and the second
     * following sibling cell (the first one is a spacer). Cells without a value are skipped. The
     * title check and every field mapper read the card through this one method.
     *
     * @return list<array{label: string, node: \Dom\Element}>
     */
    public function pairNodes(): array
    {
        $cells = $this->xpath->query(
            '//x:td[contains(concat(" ", normalize-space(@class), " "), " review ")][x:b]',
        );
        $pairs = [];
        foreach ($cells as $cell) {
            $label = self::clean((string) $this->xpath->query('x:b', $cell)->item(0)?->textContent);
            $value = $this->xpath->query('following-sibling::x:td[2]', $cell)->item(0);
            if ($label === '' || !$value instanceof \Dom\Element) {
                continue;
            }
            $pairs[] = ['label' => $label, 'node' => $value];
        }

        return $pairs;
    }

    /**
     * Pairs "label → value" of the card as plain text.
     *
     * @return list<array{label: string, value: string}>
     */
    public function pairs(): array
    {
        $pairs = [];
        foreach ($this->pairNodes() as $pair) {
            $pairs[] = ['label' => $pair['label'], 'value' => self::clean((string) $pair['node']->textContent)];
        }

        return $pairs;
    }

    /**
     * The value cell of the first pair with one of the labels (an unknown label is just not found).
     */
    public function valueNode(string ...$labels): ?\Dom\Element
    {
        $pairs = $this->pairNodes();
        foreach ($labels as $label) {
            foreach ($pairs as $pair) {
                if ($pair['label'] === $label) {
                    return $pair['node'];
                }
            }
        }

        return null;
    }

    /**
     * The summary paragraphs: `p.review` of the table that follows the "Краткое содержание"
     * heading, without the right-aligned copyright line.
     *
     * @return list<\Dom\Element>
     */
    public function descriptionParagraphs(): array
    {
        $nodes = $this->xpath->query(
            '//x:font[normalize-space(.)="Краткое содержание"]/ancestor::x:table[1]'
            .'/following-sibling::x:table[.//x:p[contains(@class, "review")]][1]'
            .'//x:p[contains(@class, "review")][not(@align="right")]',
        );
        $paragraphs = [];
        foreach ($nodes as $node) {
            if ($node instanceof \Dom\Element) {
                $paragraphs[] = $node;
            }
        }

        return $paragraphs;
    }

    /**
     * The links of the tags block (`div.newtag`).
     *
     * @return list<\Dom\Element>
     */
    public function tagLinks(): array
    {
        return $this->elements('//x:div[contains(concat(" ", normalize-space(@class), " "), " newtag ")]//x:a[@href]');
    }

    /**
     * The links to other databases: they sit inside `<noindex>`, which is not cut out.
     *
     * @return list<\Dom\Element>
     */
    public function externalLinks(): array
    {
        return $this->elements('//x:noindex//x:a[@href]');
    }

    /**
     * The text of a node with every `<br>` as a line break; the whitespace of the markup is
     * collapsed, the lines are trimmed and empty ones dropped.
     */
    public static function textWithBreaks(\Dom\Node $node): string
    {
        $lines = [];
        foreach (explode("\n", self::flatten($node)) as $line) {
            $line = self::clean($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private static function flatten(\Dom\Node $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \Dom\Text) {
                $text .= (string) preg_replace('/[\r\n]+/', ' ', $child->data);
            } elseif ($child instanceof \Dom\Element) {
                $text .= strtolower($child->localName) === 'br' ? "\n" : self::flatten($child);
            }
        }

        return $text;
    }

    /**
     * @return list<\Dom\Element>
     */
    private function elements(string $query): array
    {
        $elements = [];
        foreach ($this->xpath->query($query) as $node) {
            if ($node instanceof \Dom\Element) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * The `src` of the cover image of the card, as written on the page.
     */
    public function coverSrc(): ?string
    {
        $nodes = $this->xpath->query(
            '//x:img[contains(@src, "/animation/img/")][not(contains(@src, "/animation/img/converted"))]',
        );
        $node = $nodes->item(0);

        return $node instanceof \Dom\Element ? $node->getAttribute('src') : null;
    }

    /**
     * A card has the title of the entry and at least one pair "label → value"; anything else (a
     * proxy page, a stub, a portal) is not a card.
     */
    public function isCard(): bool
    {
        return $this->title() !== null && $this->pairs() !== [];
    }

    /**
     * The target of `<meta http-equiv="refresh" content="0; url=...">`, as written on the page.
     */
    public function refreshTarget(): ?string
    {
        $nodes = $this->xpath->query('//x:meta[@http-equiv]');
        foreach ($nodes as $node) {
            if (!$node instanceof \Dom\Element || strcasecmp((string) $node->getAttribute('http-equiv'), 'refresh') !== 0) {
                continue;
            }
            if (preg_match('/url\s*=\s*[\'"]?([^\'"\s]+)/i', (string) $node->getAttribute('content'), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
