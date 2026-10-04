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

use AnimeDb\PluginContracts\Catalog\AnimeView;
use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\WidgetListItem;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\NotFoundHttpException;
use AnimeDb\Plugins\AnimedbMyanimelist\Tests\Widget\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbMyanimelist\Widget\SimilarWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SimilarWidgetTest extends TestCase
{
    public function testMetadataReturnsExpectedNameTitleKeyAndDescriptionKey(): void
    {
        $metadata = SimilarWidget::metadata();

        self::assertSame('similar', $metadata->name);
        self::assertSame('widget.similar.title', $metadata->titleKey);
        self::assertSame('widget.similar.description', $metadata->descriptionKey);
    }

    public function testRenderReturnsEmptyListWithoutHttpRequestWhenRecordHasNoExternalId(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::never())->method('get');

        $widget = $this->buildWidget($client, null);

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('plugin-widget__list', $html);
        self::assertStringNotContainsString('<li>', $html);
    }

    public function testRenderMakesExactlyOneHttpRequest(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->with('/anime/3455', ['fields' => 'recommendations'])
            ->willReturn(self::cardFixture('card_3455.json'));

        $widget = $this->buildWidget($client, '3455');

        $widget->render(new AnimeId(1));
    }

    public function testRenderMapsRecommendationsIntoWidgetItems(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::cardFixture('card_3455.json'));

        $widget = $this->buildWidget($client, '3455');

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('Rosario to Vampire', $html);
        self::assertStringContainsString('https://cdn.myanimelist.net/images/anime/12/75242l.jpg', $html);
        self::assertStringContainsString('https://myanimelist.net/anime/2993', $html);
    }

    public function testRenderReturnsEmptyListWhenCardHasNoRecommendations(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::cardFixture('card_59068.json'));

        $widget = $this->buildWidget($client, '59068');

        $html = $widget->render(new AnimeId(1));

        self::assertStringNotContainsString('<li>', $html);
    }

    public function testRenderReturnsEmptyListWhenApiRespondsWithNotFound(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willThrowException(new NotFoundHttpException('not found'));

        $widget = $this->buildWidget($client, '3455');

        $html = $widget->render(new AnimeId(1));

        self::assertStringNotContainsString('<li>', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidExternalIdProvider(): iterable
    {
        yield 'non-numeric' => ['abc'];
        yield 'zero' => ['0'];
        yield 'leading zero' => ['012'];
        yield 'path traversal' => ['1/../x'];
        yield 'query injection' => ['1?a=b'];
        yield 'empty string' => [''];
    }

    #[DataProvider('invalidExternalIdProvider')]
    public function testRenderReturnsEmptyListWithoutHttpRequestForInvalidExternalId(string $externalId): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::never())->method('get');

        $widget = $this->buildWidget($client, $externalId);

        $html = $widget->render(new AnimeId(1));

        self::assertStringNotContainsString('<li>', $html);
    }

    public function testRenderDoesNotWrapTheHostListHelperInAPluginSpecificElement(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::cardFixture('card_3455.json'));

        $widget = $this->buildWidget($client, '3455');

        $html = $widget->render(new AnimeId(1));

        self::assertStringNotContainsString('animedb-myanimelist-carousel', $html);
    }

    public function testRenderSendsRequestWithoutAuthorizationBearer(): void
    {
        $capturedBearer = 'not-called';
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturnCallback(
            function (string $path, array $query = [], ?callable $onHeartbeat = null, ?string $bearer = null) use (&$capturedBearer): array {
                $capturedBearer = $bearer;

                return self::cardFixture('card_3455.json');
            },
        );

        $widget = $this->buildWidget($client, '3455');
        $widget->render(new AnimeId(1));

        self::assertNull($capturedBearer);
    }

    public function testBuildItemMapsRecommendationFieldsIntoWidgetListItemProperties(): void
    {
        $recommendation = [
            'node' => [
                'id' => 2993,
                'title' => 'Rosario to Vampire',
                'main_picture' => [
                    'medium' => 'https://cdn.myanimelist.net/images/anime/12/75242.jpg',
                    'large' => 'https://cdn.myanimelist.net/images/anime/12/75242l.jpg',
                ],
            ],
            'num_recommendations' => 18,
        ];

        $item = (new ReflectionMethod(SimilarWidget::class, 'buildItem'))->invoke(null, $recommendation);

        self::assertInstanceOf(WidgetListItem::class, $item);
        self::assertSame('https://cdn.myanimelist.net/images/anime/12/75242l.jpg', $item->thumbnail);
        self::assertSame('Rosario to Vampire', $item->title);
        self::assertNull($item->subtitle);
        self::assertSame('https://myanimelist.net/anime/2993', $item->url);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidNodeIdProvider(): iterable
    {
        yield 'id is an array' => [['id' => [1, 2]]];
        yield 'id is a string with a path separator' => [['id' => '2993/../../login']];
        yield 'id is zero' => [['id' => 0]];
    }

    #[DataProvider('invalidNodeIdProvider')]
    public function testBuildItemReturnsNullWhenNodeIdIsNotAPositiveInteger(array $node): void
    {
        $recommendation = [
            'node' => array_merge(['title' => 'Some Title'], $node),
        ];

        $item = (new ReflectionMethod(SimilarWidget::class, 'buildItem'))->invoke(null, $recommendation);

        self::assertNull($item);
    }

    private function buildWidget(MalApiClient $client, ?string $externalId): SimilarWidget
    {
        $catalogReader = $this->createMock(CatalogReaderInterface::class);
        $catalogReader->method('read')->willReturn(
            new AnimeView('Title', [], null, [], [], null, [], $externalId),
        );

        return new SimilarWidget($client, $catalogReader, StubTwigFactory::create());
    }

    /**
     * @return array<string, mixed>
     */
    private static function cardFixture(string $file): array
    {
        $json = file_get_contents(\dirname(__DIR__).'/Fixture/'.$file);
        \assert($json !== false);

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $fixture;
    }
}
