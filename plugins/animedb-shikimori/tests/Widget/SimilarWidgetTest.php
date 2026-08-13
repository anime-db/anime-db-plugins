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
use AnimeDb\Plugins\AnimedbShikimori\Http\ShikimoriRestClient;
use AnimeDb\Plugins\AnimedbShikimori\Tests\Widget\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbShikimori\Widget\SimilarWidget;
use PHPUnit\Framework\TestCase;

final class SimilarWidgetTest extends TestCase
{
    public function testMetadataReturnsExpectedNameTitleAndDescription(): void
    {
        $metadata = SimilarWidget::metadata();

        self::assertSame('similar', $metadata->name);
        self::assertNotSame('', $metadata->title);
        self::assertNotSame('', $metadata->description);
    }

    public function testRenderReturnsEmptyListWhenRecordHasNoShikimoriExternalId(): void
    {
        $restClient = $this->createMock(ShikimoriRestClient::class);
        $restClient->expects(self::never())->method('getSimilarAnimes');

        $widget = $this->buildWidget($restClient, null);

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('plugin-widget__list', $html);
        self::assertStringNotContainsString('<li>', $html);
    }

    public function testRenderMapsRestResponseIntoWidgetItems(): void
    {
        $restClient = $this->createMock(ShikimoriRestClient::class);
        $restClient->method('getSimilarAnimes')->with('1')->willReturn([
            [
                'id' => 205,
                'name' => 'Samurai Champloo',
                'russian' => 'Самурай Чамплу',
                'image' => ['original' => '/system/animes/original/205.jpg'],
            ],
            [
                'id' => 6,
                'name' => null,
                'russian' => 'Триган',
                'image' => ['original' => null],
            ],
        ]);

        $widget = $this->buildWidget($restClient, '1');

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('Samurai Champloo', $html);
        self::assertStringContainsString('https://shikimori.io/system/animes/original/205.jpg', $html);
        self::assertStringContainsString('https://shikimori.io/animes/205', $html);
        self::assertStringContainsString('Триган', $html);
        self::assertStringContainsString('https://shikimori.io/animes/6', $html);
    }

    public function testRenderSkipsEntriesWithoutAnId(): void
    {
        $restClient = $this->createMock(ShikimoriRestClient::class);
        $restClient->method('getSimilarAnimes')->willReturn([
            ['name' => 'No id', 'image' => []],
        ]);

        $widget = $this->buildWidget($restClient, '1');

        $html = $widget->render(new AnimeId(1));

        self::assertStringNotContainsString('No id', $html);
    }

    private function buildWidget(ShikimoriRestClient $restClient, ?string $externalId): SimilarWidget
    {
        $catalogReader = $this->createMock(CatalogReaderInterface::class);
        $catalogReader->method('read')->willReturn(
            new AnimeView('Title', [], null, [], [], null, [], $externalId),
        );

        return new SimilarWidget($restClient, $catalogReader, StubTwigFactory::create());
    }
}
