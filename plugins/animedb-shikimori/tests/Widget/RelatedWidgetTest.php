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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Widget;

use AnimeDb\PluginContracts\Catalog\AnimeView;
use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use AnimeDb\Plugins\AnimedbShikimori\Tests\Widget\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbShikimori\Widget\RelatedWidget;
use PHPUnit\Framework\TestCase;

final class RelatedWidgetTest extends TestCase
{
    public function testMetadataReturnsExpectedNameTitleAndDescription(): void
    {
        $metadata = RelatedWidget::metadata();

        self::assertSame('related', $metadata->name);
        self::assertNotSame('', $metadata->title);
        self::assertNotSame('', $metadata->description);
    }

    public function testResolveExternalIdRecognizesShikimoriUrls(): void
    {
        $widget = $this->buildWidget($this->createMock(GraphQlClient::class), null);

        self::assertSame('20', $widget->resolveExternalId(['https://shikimori.io/animes/20-naruto']));
        self::assertNull($widget->resolveExternalId(['https://myanimelist.net/anime/20']));
    }

    public function testRenderReturnsEmptyListWhenRecordHasNoShikimoriExternalId(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->expects(self::never())->method('query');

        $widget = $this->buildWidget($client, null);

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('plugin-widget__list', $html);
        self::assertStringNotContainsString('<li>', $html);
    }

    public function testRenderDropsMangaRelationsAndKeepsOnlyAnime(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->with(self::anything(), ['ids' => '1'])->willReturn([
            'animes' => [[
                'related' => [
                    ['anime' => null, 'manga' => ['id' => '174', 'name' => 'Cowboy Bebop manga']],
                    ['anime' => ['id' => '5', 'name' => 'Cowboy Bebop: Tengoku no Tobira', 'russian' => null, 'airedOn' => ['date' => '2001-09-01'], 'poster' => ['originalUrl' => 'https://shikimori.io/poster/5.jpg']], 'manga' => null],
                ],
            ]],
        ]);

        $widget = $this->buildWidget($client, '1');

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('Cowboy Bebop: Tengoku no Tobira', $html);
        self::assertStringNotContainsString('Cowboy Bebop manga', $html);
        self::assertStringContainsString('https://shikimori.io/poster/5.jpg', $html);
        self::assertStringContainsString('https://shikimori.io/animes/5', $html);
    }

    public function testRenderSortsRelationsByAiredOnDateWithUnknownDatesLast(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn([
            'animes' => [[
                'related' => [
                    ['anime' => ['id' => '1', 'name' => 'Newer', 'airedOn' => ['date' => '2010-01-01']]],
                    ['anime' => ['id' => '2', 'name' => 'No date', 'airedOn' => null]],
                    ['anime' => ['id' => '3', 'name' => 'Oldest', 'airedOn' => ['date' => '1998-04-01']]],
                ],
            ]],
        ]);

        $widget = $this->buildWidget($client, '1');

        $html = $widget->render(new AnimeId(1));

        $oldestPosition = strpos($html, 'Oldest');
        $newerPosition = strpos($html, 'Newer');
        $noDatePosition = strpos($html, 'No date');

        self::assertNotFalse($oldestPosition);
        self::assertNotFalse($newerPosition);
        self::assertNotFalse($noDatePosition);
        self::assertLessThan($newerPosition, $oldestPosition);
        self::assertLessThan($noDatePosition, $newerPosition);
    }

    private function buildWidget(GraphQlClient $client, ?string $externalId): RelatedWidget
    {
        $catalogReader = $this->createMock(CatalogReaderInterface::class);
        $catalogReader->method('read')->willReturn(
            new AnimeView('Title', [], null, [], [], null, [], $externalId),
        );

        return new RelatedWidget($client, $catalogReader, StubTwigFactory::create());
    }
}
