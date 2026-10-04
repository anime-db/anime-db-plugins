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
use AnimeDb\Plugins\AnimedbMyanimelist\Widget\RelatedWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RelatedWidgetTest extends TestCase
{
    public function testMetadataReturnsExpectedNameTitleKeyAndDescriptionKey(): void
    {
        $metadata = RelatedWidget::metadata();

        self::assertSame('related', $metadata->name);
        self::assertSame('widget.related.title', $metadata->titleKey);
        self::assertSame('widget.related.description', $metadata->descriptionKey);
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
            ->with('/anime/3455', ['fields' => 'related_anime'])
            ->willReturn(self::cardFixture('card_3455.json'));

        $widget = $this->buildWidget($client, '3455');

        $widget->render(new AnimeId(1));
    }

    public function testRenderKeepsTheResponseOrderOfRelatedAnime(): void
    {
        // Deliberately the opposite of every natural sort: the higher id, the
        // alphabetically-later title and the alphabetically-later relation both come first, so a
        // sort by id, title or relation_type_formatted would reorder this and fail the assertion.
        $card = [
            'related_anime' => [
                [
                    'node' => ['id' => 9181, 'title' => 'Z Sequel Title'],
                    'relation_type_formatted' => 'Z Sequel',
                ],
                [
                    'node' => ['id' => 5667, 'title' => 'A Side Story Title'],
                    'relation_type_formatted' => 'A Side story',
                ],
            ],
        ];
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn($card);

        $widget = $this->buildWidget($client, '3455');

        $html = $widget->render(new AnimeId(1));

        $firstPosition = strpos($html, 'Z Sequel Title');
        $secondPosition = strpos($html, 'A Side Story Title');

        self::assertNotFalse($firstPosition);
        self::assertNotFalse($secondPosition);
        self::assertLessThan($secondPosition, $firstPosition);
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

    public function testRenderMapsFixtureFieldsIntoWidgetListItemProperties(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::cardFixture('card_3455.json'));

        $widget = $this->buildWidget($client, '3455');

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('To LOVE-Ru OVA', $html);
        self::assertStringContainsString('Side story', $html);
        self::assertStringContainsString('https://cdn.myanimelist.net/images/anime/2/17923l.webp', $html);
        self::assertStringContainsString('https://myanimelist.net/anime/5667', $html);
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

    public function testBuildItemMapsRelationFieldsIntoWidgetListItemProperties(): void
    {
        $relation = [
            'node' => [
                'id' => 5667,
                'title' => 'To LOVE-Ru OVA',
                'main_picture' => [
                    'medium' => 'https://cdn.myanimelist.net/images/anime/2/17923.webp',
                    'large' => 'https://cdn.myanimelist.net/images/anime/2/17923l.webp',
                ],
            ],
            'relation_type' => 'side_story',
            'relation_type_formatted' => 'Side story',
        ];

        $item = (new ReflectionMethod(RelatedWidget::class, 'buildItem'))->invoke(null, $relation);

        self::assertInstanceOf(WidgetListItem::class, $item);
        self::assertSame('https://cdn.myanimelist.net/images/anime/2/17923l.webp', $item->thumbnail);
        self::assertSame('To LOVE-Ru OVA', $item->title);
        self::assertSame('Side story', $item->subtitle);
        self::assertSame('https://myanimelist.net/anime/5667', $item->url);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidNodeIdProvider(): iterable
    {
        yield 'id is an array' => [['id' => [1, 2]]];
        yield 'id is a string with a path separator' => [['id' => '5667/../../login']];
        yield 'id is zero' => [['id' => 0]];
    }

    #[DataProvider('invalidNodeIdProvider')]
    public function testBuildItemReturnsNullWhenNodeIdIsNotAPositiveInteger(array $node): void
    {
        $relation = [
            'node' => array_merge(['title' => 'Some Title'], $node),
        ];

        $item = (new ReflectionMethod(RelatedWidget::class, 'buildItem'))->invoke(null, $relation);

        self::assertNull($item);
    }

    private function buildWidget(MalApiClient $client, ?string $externalId): RelatedWidget
    {
        $catalogReader = $this->createMock(CatalogReaderInterface::class);
        $catalogReader->method('read')->willReturn(
            new AnimeView('Title', [], null, [], [], null, [], $externalId),
        );

        return new RelatedWidget($client, $catalogReader, StubTwigFactory::create());
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
