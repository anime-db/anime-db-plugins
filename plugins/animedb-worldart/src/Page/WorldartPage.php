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
     * Pairs "label → value" of the card: a `td.review` cell holding `<b>Label</b>` and the text of
     * the second following sibling cell (the first one is a spacer). Cells without a value are skipped.
     *
     * @return list<array{label: string, value: string}>
     */
    public function pairs(): array
    {
        $cells = $this->xpath->query(
            '//x:td[contains(concat(" ", normalize-space(@class), " "), " review ")][x:b]',
        );
        $pairs = [];
        foreach ($cells as $cell) {
            $label = self::clean((string) $this->xpath->query('x:b', $cell)->item(0)?->textContent);
            $value = $this->xpath->query('following-sibling::x:td[2]', $cell)->item(0);
            if ($label === '' || $value === null) {
                continue;
            }
            $pairs[] = ['label' => $label, 'value' => self::clean((string) $value->textContent)];
        }

        return $pairs;
    }

    /**
     * The `src` of the cover image of the card, as written on the page.
     */
    public function coverSrc(): ?string
    {
        $nodes = $this->xpath->query('//x:img[contains(@src, "/animation/img/")]');
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
