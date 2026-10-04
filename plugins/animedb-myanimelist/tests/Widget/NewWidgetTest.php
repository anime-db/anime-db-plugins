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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\Widget;

use AnimeDb\PluginContracts\Widget\WidgetListItem;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Tests\Widget\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbMyanimelist\Widget\NewWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class NewWidgetTest extends TestCase
{
    public function testMetadataReturnsExpectedNameTitleKeyAndDescriptionKey(): void
    {
        $metadata = NewWidget::metadata();

        self::assertSame('new', $metadata->name);
        self::assertSame('widget.new.title', $metadata->titleKey);
        self::assertSame('widget.new.description', $metadata->descriptionKey);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function seasonBoundaryProvider(): iterable
    {
        yield 'winter start' => ['2026-01-01', '/anime/season/2026/winter'];
        yield 'winter end' => ['2026-03-31', '/anime/season/2026/winter'];
        yield 'spring start' => ['2026-04-01', '/anime/season/2026/spring'];
        yield 'spring end' => ['2026-06-30', '/anime/season/2026/spring'];
        yield 'summer start' => ['2026-07-01', '/anime/season/2026/summer'];
        yield 'summer end' => ['2026-09-30', '/anime/season/2026/summer'];
        yield 'fall start' => ['2026-10-01', '/anime/season/2026/fall'];
        yield 'fall end' => ['2026-12-31', '/anime/season/2026/fall'];
        yield 'year boundary' => ['2025-12-31', '/anime/season/2025/fall'];
    }

    #[DataProvider('seasonBoundaryProvider')]
    public function testRenderRequestsTheSeasonMatchingTheInjectedDate(string $date, string $expectedPath): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->with($expectedPath, ['limit' => 20, 'fields' => 'id,title,main_picture'])
            ->willReturn(['data' => []]);

        $widget = $this->buildWidget($client, $date);

        $widget->render();
    }

    public function testRenderMakesExactlyOneHttpRequest(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->willReturn([
                'data' => [
                    ['node' => ['id' => 1, 'title' => 'Seasonal Anime']],
                ],
            ]);

        $widget = $this->buildWidget($client, '2026-04-15');

        $html = $widget->render();

        self::assertStringContainsString('Seasonal Anime', $html);
    }

    public function testRenderSendsRequestWithoutAuthorizationBearer(): void
    {
        $capturedBearer = 'not-called';
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturnCallback(
            function (string $path, array $query = [], ?callable $onHeartbeat = null, ?string $bearer = null) use (&$capturedBearer): array {
                $capturedBearer = $bearer;

                return ['data' => []];
            },
        );

        $widget = $this->buildWidget($client, '2026-04-15');
        $widget->render();

        self::assertNull($capturedBearer);
    }

    public function testRenderMapsFixtureFieldsIntoWidgetListItemProperties(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn([
            'data' => [
                [
                    'node' => [
                        'id' => 20,
                        'title' => 'Naruto',
                        'main_picture' => [
                            'medium' => 'https://cdn.myanimelist.net/images/anime/1141/142503.jpg',
                            'large' => 'https://cdn.myanimelist.net/images/anime/1141/142503l.jpg',
                        ],
                    ],
                ],
            ],
        ]);

        $widget = $this->buildWidget($client, '2026-04-15');

        $html = $widget->render();

        self::assertStringContainsString('Naruto', $html);
        self::assertStringContainsString('https://cdn.myanimelist.net/images/anime/1141/142503l.jpg', $html);
        self::assertStringContainsString('https://myanimelist.net/anime/20', $html);
    }

    public function testBuildItemMapsAnimeFieldsIntoWidgetListItemProperties(): void
    {
        $anime = [
            'node' => [
                'id' => 20,
                'title' => 'Naruto',
                'main_picture' => [
                    'medium' => 'https://cdn.myanimelist.net/images/anime/1141/142503.jpg',
                    'large' => 'https://cdn.myanimelist.net/images/anime/1141/142503l.jpg',
                ],
            ],
        ];

        $item = (new ReflectionMethod(NewWidget::class, 'buildItem'))->invoke(null, $anime);

        self::assertInstanceOf(WidgetListItem::class, $item);
        self::assertSame('https://cdn.myanimelist.net/images/anime/1141/142503l.jpg', $item->thumbnail);
        self::assertSame('Naruto', $item->title);
        self::assertNull($item->subtitle);
        self::assertSame('https://myanimelist.net/anime/20', $item->url);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidNodeIdProvider(): iterable
    {
        yield 'id is an array' => [['id' => [1, 2]]];
        yield 'id is a string with a path separator' => [['id' => '20/../../login']];
        yield 'id is zero' => [['id' => 0]];
    }

    #[DataProvider('invalidNodeIdProvider')]
    public function testBuildItemReturnsNullWhenNodeIdIsNotAPositiveInteger(array $node): void
    {
        $anime = [
            'node' => array_merge(['title' => 'Some Title'], $node),
        ];

        $item = (new ReflectionMethod(NewWidget::class, 'buildItem'))->invoke(null, $anime);

        self::assertNull($item);
    }

    private function buildWidget(MalApiClient $client, string $date): NewWidget
    {
        $now = new \DateTimeImmutable($date);

        return new NewWidget($client, StubTwigFactory::create(), static fn (): \DateTimeImmutable => $now);
    }
}
