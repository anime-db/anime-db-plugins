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

use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbRequestException;
use AnimeDb\Plugins\AnimedbAnidb\Http\HotAnimeCache;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\AnidbTestCase;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Widget\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbAnidb\Widget\NewWidget;

final class NewWidgetTest extends AnidbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // cache entries get a real mtime, so the movable clock starts at the real time
        $this->now = time();
    }

    public function testMetadata(): void
    {
        $metadata = NewWidget::metadata();
        self::assertSame(['new', 'widget.new.title', 'widget.new.description'], [$metadata->name, $metadata->titleKey, $metadata->descriptionKey]);
    }

    public function testFreshCacheMakesNoRequest(): void
    {
        $this->hotAnime->put(self::fixture());

        $html = $this->widget()->render();

        self::assertStringContainsString('Bleach: Sennen Kessen Hen - Kashin Tan', $html);
        self::assertSame([], $this->requests);
    }

    public function testNoCacheMakesOneRequestAndSecondRenderNone(): void
    {
        $this->responses = [$this->response(200, self::fixture())];
        $widget = $this->widget();

        $first = $widget->render();
        $second = $widget->render();

        self::assertCount(1, $this->requests);
        self::assertStringContainsString('request=hotanime', $this->requests[0]['url']);
        self::assertStringNotContainsString('aid=', $this->requests[0]['url']);
        self::assertSame($first, $second);
        self::assertFileExists($this->cacheDir.'/anidb-hotanime.xml');
    }

    public function testGzipResponseIsUnpacked(): void
    {
        $this->responses = [$this->response(200, (string) gzencode(self::fixture()))];

        self::assertStringContainsString('Bleach: Sennen Kessen Hen - Kashin Tan', $this->widget()->render());
    }

    public function testStaleCacheMakesOneRequest(): void
    {
        $this->hotAnime->put(self::fixture());
        $this->now += HotAnimeCache::TTL + 1;
        $this->responses = [$this->response(200, self::fixture())];

        $this->widget()->render();

        self::assertCount(1, $this->requests);
    }

    public function testActiveBanMakesNoRequest(): void
    {
        $this->banGuard->markBanned();

        try {
            $this->widget()->render();
            self::fail('A banned client must not render.');
        } catch (AniDbRequestException) {
            self::assertSame([], $this->requests);
        }
    }

    public function testFailedRequestIsNotRepeatedOnTheNextRender(): void
    {
        $this->responses = [$this->response(200, '<error code="302">client version missing</error>')];
        $widget = $this->widget();

        foreach ([1, 2] as $_) {
            try {
                $widget->render();
                self::fail('An API error must fail the render.');
            } catch (AniDbRequestException) {
            }
        }
        self::assertCount(1, $this->requests, 'no second request while backing off');

        $this->now += HotAnimeCache::FAILURE_BACKOFF + 1;
        $this->responses = [$this->response(200, self::fixture())];
        self::assertStringContainsString('Bleach: Sennen Kessen Hen - Kashin Tan', $widget->render());
        self::assertCount(2, $this->requests);
    }

    public function testStaleCacheIsServedWhileBackingOff(): void
    {
        $this->hotAnime->put(self::fixture());
        $this->now += HotAnimeCache::TTL + 1;
        $this->responses = [$this->response(503)];
        $widget = $this->widget();

        try {
            $widget->render();
            self::fail('A failed request must fail the render.');
        } catch (AniDbRequestException) {
        }

        self::assertStringContainsString('Bleach: Sennen Kessen Hen - Kashin Tan', $widget->render());
        self::assertCount(1, $this->requests);
    }

    public function testBannedResponseTripsTheGuard(): void
    {
        $this->responses = [$this->response(200, '<error code="500">banned</error>')];

        try {
            $this->widget()->render();
            self::fail('A ban response must fail.');
        } catch (AniDbRequestException) {
        }
        self::assertCount(1, $this->requests);

        try {
            $this->widget()->render();
            self::fail('The guard must stay tripped.');
        } catch (AniDbRequestException) {
        }
        self::assertCount(1, $this->requests, 'no second request while banned');
        self::assertFileDoesNotExist($this->cacheDir.'/anidb-hotanime.xml');
    }

    public function testLiveFixtureFiltersRestrictedAndKeepsOrder(): void
    {
        $this->hotAnime->put(self::fixture());

        $items = $this->items();

        self::assertCount(8, $items);
        self::assertSame([
            'Bleach: Sennen Kessen Hen - Kashin Tan',
            'Kusuriya no Hitorigoto (2026)',
            'Nige Jouzu no Wakagimi (2026)',
            'Tensei Shitara Ken Deshita II',
            'Yasei no Last Boss ga Arawareta! (2026)',
            'Tempal: Item no Chikara',
            'Tensei Kizoku, Kantei Skill de Nariagaru Dai 3 Ki',
            'Boku no Hero Academia: I Am a Hero Too',
        ], array_map(static fn ($i): string => $i->title, $items));
        self::assertSame('https://anidb.net/anime/19079', $items[0]->url);
        self::assertSame('https://cdn-eu.anidb.net/images/main/327260.jpg', $items[0]->thumbnail);
        foreach ($items as $item) {
            self::assertStringNotContainsString('/20051', $item->url);
            self::assertStringNotContainsString('/20071', $item->url);
            self::assertStringNotContainsString('328317', (string) $item->thumbnail);
            self::assertStringNotContainsString('327132', (string) $item->thumbnail);
        }
        $html = $this->widget()->render();
        self::assertStringNotContainsString('Deco x Deco', $html);
        self::assertStringNotContainsString('Inaka ni wa', $html);
    }

    public function testMissingPictureGivesItemWithoutCover(): void
    {
        $this->hotAnime->put('<hotanime>'
            .'<anime id="1" restricted="false"><title type="main">With Cover</title><picture>a.jpg</picture></anime>'
            .'<anime id="2" restricted="false"><title type="main">No Cover</title></anime>'
            .'</hotanime>');

        $items = $this->items();

        self::assertCount(2, $items);
        self::assertSame('https://cdn-eu.anidb.net/images/main/a.jpg', $items[0]->thumbnail);
        self::assertNull($items[1]->thumbnail);
        self::assertSame('https://anidb.net/anime/2', $items[1]->url);
    }

    public function testRestrictedFilterIsClosedByDefault(): void
    {
        $this->hotAnime->put('<hotanime>'
            .'<anime id="1"><title type="main">No Attribute</title></anime>'
            .'<anime id="2" restricted="1"><title type="main">One</title></anime>'
            .'<anime id="3" restricted="yes"><title type="main">Yes</title></anime>'
            .'<anime id="4" restricted=""><title type="main">Empty</title></anime>'
            .'<anime id="5" restricted="true"><title type="main">True</title></anime>'
            .'<anime id="6" restricted="FALSE"><title type="main">Upper False</title></anime>'
            .'<anime id="7" restricted=" false "><title type="main">Padded False</title></anime>'
            .'</hotanime>');

        $items = $this->items();

        self::assertSame(['Upper False', 'Padded False'], array_map(static fn ($i): string => $i->title, $items));
    }

    public function testLimitsToTwentyItems(): void
    {
        $xml = '<hotanime>';
        for ($i = 1; $i <= 30; ++$i) {
            $xml .= \sprintf('<anime id="%d" restricted="false"><title type="main">Title %d</title></anime>', $i, $i);
        }
        $this->hotAnime->put($xml.'</hotanime>');

        $items = $this->items();

        self::assertCount(NewWidget::LIMIT, $items);
        self::assertSame('Title 1', $items[0]->title);
        self::assertSame('Title 20', $items[19]->title);
    }

    public function testInvalidIdOrCoverSkipsTheItem(): void
    {
        $this->hotAnime->put('<hotanime>'
            .'<anime id="0" restricted="false"><title type="main">Zero Id</title></anime>'
            .'<anime id="12/../x" restricted="false"><title type="main">Path Id</title></anime>'
            .'<anime id="1234567890" restricted="false"><title type="main">Long Id</title></anime>'
            .'<anime restricted="false"><title type="main">No Id</title></anime>'
            .'<anime id="5" restricted="false"><title type="main">Bad Cover</title><picture>../x.jpg</picture></anime>'
            .'<anime id="6" restricted="false"><title type="alias">No Main</title></anime>'
            .'<anime id="7" restricted="false"><title type="main">Good One</title></anime>'
            .'</hotanime>');

        $items = $this->items();

        self::assertCount(1, $items);
        self::assertSame('Good One', $items[0]->title);
    }

    public function testCatalogsContainNewKeysWithoutEmptyValues(): void
    {
        foreach (['en', 'ru'] as $locale) {
            $catalog = StubTwigFactory::catalog($locale);
            foreach (['widget.new.title', 'widget.new.description'] as $key) {
                self::assertArrayHasKey($key, $catalog);
                self::assertNotSame('', trim($catalog[$key]));
            }
        }
    }

    /**
     * @return list<\AnimeDb\PluginContracts\Widget\WidgetListItem>
     */
    private function items(): array
    {
        $method = new \ReflectionMethod(NewWidget::class, 'fetchItems');

        /** @var list<\AnimeDb\PluginContracts\Widget\WidgetListItem> */
        return $method->invoke($this->widget());
    }

    private function widget(): NewWidget
    {
        return new NewWidget($this->apiClient, StubTwigFactory::create());
    }

    private static function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixture/hotanime.xml');
    }
}
