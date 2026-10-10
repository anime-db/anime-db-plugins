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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Widget;

use AnimeDb\PluginContracts\Catalog\AnimeView;
use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\AnidbTestCase;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Widget\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbAnidb\Widget\RelatedWidget;
use AnimeDb\Plugins\AnimedbAnidb\Widget\RelationType;
use AnimeDb\Plugins\AnimedbAnidb\Widget\SimilarWidget;
use PHPUnit\Framework\Attributes\DataProvider;

final class WidgetsTest extends AnidbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // cache entries get a real mtime, so the movable clock starts at the real time
        $this->now = time();
    }

    public function testMetadata(): void
    {
        $related = RelatedWidget::metadata();
        self::assertSame(['related', 'widget.related.title', 'widget.related.description'], [$related->name, $related->titleKey, $related->descriptionKey]);
        $similar = SimilarWidget::metadata();
        self::assertSame(['similar', 'widget.similar.title', 'widget.similar.description'], [$similar->name, $similar->titleKey, $similar->descriptionKey]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function widgets(): iterable
    {
        yield 'related' => [RelatedWidget::class];
        yield 'similar' => [SimilarWidget::class];
    }

    #[DataProvider('widgets')]
    public function testCachedCardMakesNoRequest(string $class): void
    {
        $this->cards->put(4000, self::card());

        $html = $this->widget($class, '4000')->render(new AnimeId(1));

        self::assertStringContainsString('<li>', $html);
        self::assertSame([], $this->requests);
    }

    #[DataProvider('widgets')]
    public function testUncachedCardMakesOneRequestAndSecondRenderNone(string $class): void
    {
        $this->responses = [$this->response(200, self::card())];
        $widget = $this->widget($class, '4000');

        $first = $widget->render(new AnimeId(1));
        $second = $widget->render(new AnimeId(1));

        self::assertCount(1, $this->requests);
        self::assertStringContainsString('aid=4000', $this->requests[0]['url']);
        self::assertSame($first, $second);
    }

    #[DataProvider('widgets')]
    public function testRecordWithoutExternalIdRendersEmptyListWithoutRequest(string $class): void
    {
        $html = $this->widget($class, null)->render(new AnimeId(1));

        self::assertStringContainsString('plugin-widget__list', $html);
        self::assertStringNotContainsString('<li>', $html);
        self::assertSame([], $this->requests);
    }

    #[DataProvider('widgets')]
    public function testInvalidExternalIdMakesNoRequest(string $class): void
    {
        foreach (['abc', '0', '012', '1/../x', '', '1234567890'] as $externalId) {
            $html = $this->widget($class, $externalId)->render(new AnimeId(1));
            self::assertStringNotContainsString('<li>', $html);
        }
        self::assertSame([], $this->requests);
    }

    #[DataProvider('widgets')]
    public function testNotFoundCardGivesEmptyList(string $class): void
    {
        $this->responses = [$this->response(200, '<error>Anime not found</error>')];

        $html = $this->widget($class, '4000')->render(new AnimeId(1));

        self::assertStringNotContainsString('<li>', $html);
    }

    public function testRelatedKeepsApiOrderAndShowsTranslatedRelationType(): void
    {
        $this->cards->put(4000, self::card());

        $html = $this->widget(RelatedWidget::class, '4000')->render(new AnimeId(1));

        $positions = array_map(
            static fn (string $title): int|false => strpos($html, $title),
            ['Zeta Sequel', 'Alpha Side Story', 'Mid Prequel', 'Odd One'],
        );
        self::assertSame($positions, array_values(array_filter($positions, 'is_int')));
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'items must keep the response order');

        self::assertStringContainsString('<p class="subtitle">Sequel</p>', $html);
        self::assertStringContainsString('<p class="subtitle">Side story</p>', $html);
        self::assertStringContainsString('<p class="subtitle">Prequel</p>', $html);
        self::assertStringContainsString('<p class="subtitle">Other</p>', $html);
        self::assertStringContainsString('href="https://anidb.net/anime/9100"', $html);
        self::assertStringNotContainsString('Broken Id', $html);
        self::assertStringNotContainsString('Zulu Similar', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testSimilarListsSimilarAnimeOnlyInApiOrder(): void
    {
        $this->cards->put(4000, self::card());

        $html = $this->widget(SimilarWidget::class, '4000')->render(new AnimeId(1));

        $first = strpos($html, 'Zulu Similar');
        $second = strpos($html, 'Alpha Similar');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertLessThan($second, $first);
        self::assertStringContainsString('href="https://anidb.net/anime/8800"', $html);
        self::assertStringNotContainsString('subtitle', $html);
        self::assertStringNotContainsString('Zero Id', $html);
        self::assertStringNotContainsString('Zeta Sequel', $html);
        self::assertStringNotContainsString('User Recommended Title', $html);
    }

    public function testRelationTypeFallsBackToOther(): void
    {
        self::assertSame('widget.relation.other', RelationType::translationKey('Brand New Relation'));
        self::assertSame('widget.relation.side_story', RelationType::translationKey('Side Story'));
    }

    public function testCatalogsCoverAllWidgetAndRelationKeysWithoutEmptyValues(): void
    {
        $required = [
            'widget.related.title',
            'widget.related.description',
            'widget.similar.title',
            'widget.similar.description',
            ...RelationType::translationKeys(),
        ];
        $en = StubTwigFactory::catalog('en');
        $ru = StubTwigFactory::catalog('ru');

        foreach ([$en, $ru] as $catalog) {
            foreach ($required as $key) {
                self::assertArrayHasKey($key, $catalog);
                self::assertNotSame('', trim($catalog[$key]));
            }
            self::assertSame(array_keys($en), array_keys($catalog));
        }
    }

    /**
     * @param class-string<RelatedWidget|SimilarWidget> $class
     */
    private function widget(string $class, ?string $externalId): RelatedWidget|SimilarWidget
    {
        $reader = $this->createMock(CatalogReaderInterface::class);
        $reader->method('read')->willReturn(new AnimeView('Title', [], null, [], [], null, [], $externalId));

        return new $class($this->apiClient, $reader, StubTwigFactory::create());
    }

    private static function card(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixture/card_related.xml');
    }
}
