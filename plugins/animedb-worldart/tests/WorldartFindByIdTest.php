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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests;

use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartRequestException;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\WorldartTestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class WorldartFindByIdTest extends WorldartTestCase
{
    public function testFillsCard11466(): void
    {
        $this->queueFixture('card_11466.html');

        $data = $this->filler->findById('11466');

        self::assertNotNull($data);
        self::assertSame('Фрирен [ТВ-1]', $data->title);
        self::assertIsArray($data->descriptions);
        self::assertArrayHasKey('ru', $data->descriptions);
        self::assertNotSame('', $data->descriptions['ru']);
        self::assertIsArray($data->externalUrls);
        self::assertSame('http://www.world-art.ru/animation/animation.php?id=11466', $data->externalUrls[0]);
        $hosts = array_map(static fn (string $url): string => (string) parse_url($url, \PHP_URL_HOST), $data->externalUrls);
        self::assertContains('anidb.net', array_map(static fn (string $h): string => preg_replace('/^www\./', '', $h) ?? $h, $hosts));
        self::assertContains('myanimelist.net', array_map(static fn (string $h): string => preg_replace('/^www\./', '', $h) ?? $h, $hosts));
        self::assertNull($data->studios);
        self::assertNull($data->images);
    }

    public function testFreshCacheEntryMakesNoRequest(): void
    {
        $this->queueFixture('card_11466.html');
        $this->filler->findById('11466');
        self::assertCount(1, $this->requests);

        $this->now += 3600;
        touch($this->cardCachePath(11466), (int) ($this->now - 3600));
        $data = $this->filler->findById('11466');

        self::assertNotNull($data);
        self::assertCount(1, $this->requests);
    }

    public function testNotFoundAnswersGiveNull(): void
    {
        $this->queueFixture('search_not_connect_redirect.html');
        $this->queueFixture('card_animation_index_redirect.html');

        self::assertNull($this->filler->findById('5'));
        self::assertNull($this->filler->findById('6'));
        self::assertSame([], glob($this->cacheDir.'/worldart-card-*'));
    }

    public function testInvalidIdsGiveNullWithoutRequest(): void
    {
        foreach (['0', '-1', 'abc', '', '01', '1.5', ' 1', '1 '] as $id) {
            self::assertNull($this->filler->findById($id), $id);
        }
        self::assertSame([], $this->requests);
    }

    public function testPageWithoutTitleGivesNull(): void
    {
        $this->responses[] = $this->response(200, '<html><body><table><tr><td class="review"><b>Тип</b></td><td></td><td class="review">ТВ</td></tr></table></body></html>');

        self::assertNull($this->filler->findById('7'));
    }

    public function testTransportFailureIsAnException(): void
    {
        $this->responses[] = new class('boom') extends \RuntimeException implements ClientExceptionInterface {
        };

        $this->expectException(WorldartRequestException::class);
        $this->filler->findById('11466');
    }

    public function testEndEarlierThanPremiereIsDropped(): void
    {
        $card = $this->cardWith(['Выпуск' => 'c 10.05.2020 по 10.05.2019']);
        $this->responses[] = $this->response(200, $card);

        $data = $this->filler->findById('42');

        self::assertNotNull($data);
        self::assertNotNull($data->datePremiere);
        self::assertSame('2020-05-10', $data->datePremiere->format('Y-m-d'));
        self::assertNull($data->dateEnd);
    }

    public function testValidDateRangeIsKept(): void
    {
        $this->responses[] = $this->response(200, $this->cardWith(['Выпуск' => 'c 10.05.2019 по 10.05.2020']));

        $data = $this->filler->findById('42');

        self::assertNotNull($data?->datePremiere);
        self::assertNotNull($data->dateEnd);
    }

    public function testAlternativeNamesAreCleanAndDeduplicated(): void
    {
        $this->responses[] = $this->response(200, $this->cardWith([
            'Название (англ.)' => 'Same',
            'Название (ромадзи)' => 'Same',
            'Название (кандзи)' => '  ',
            'Названия (прочие)' => 'Заголовок<br>Other<br>Other<br>',
        ]));

        $data = $this->filler->findById('42');

        self::assertNotNull($data);
        self::assertSame('Заголовок', $data->title);
        $pairs = array_map(static fn ($n): array => [$n->name, $n->locale, $n->role], $data->alternativeNames ?? []);
        self::assertSame([
            ['Same', 'en', NameRole::Official],
            ['Same', null, NameRole::Official],
            ['Other', null, NameRole::Synonym],
        ], $pairs);
    }

    public function testCountriesOnAllCards(): void
    {
        foreach (CardFactory::cards() as $name) {
            $this->requests = [];
            $this->responses = [$this->response(200, $this->fixture($name))];
            preg_match('/(\d+)/', $name, $m);
            $data = $this->filler->findById($m[1]);

            self::assertNotNull($data, $name);
            foreach ($data->countries ?? [] as $code) {
                self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $code, $name);
            }
            self::assertNull($data->studios, $name);
            self::assertNull($data->images, $name);
        }
    }

    public function testFillableFieldsMatchWhatIsFilled(): void
    {
        $fields = $this->filler->getFillableFields();

        self::assertSame([
            'title', 'alternativeNames', 'descriptions', 'genres', 'themes', 'demographic', 'type',
            'datePremiere', 'dateEnd', 'durationMinutes', 'episodesCount', 'countries', 'cover', 'externalUrls',
        ], $fields);
        self::assertNotContains('studios', $fields);
        self::assertNotContains('images', $fields);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function cardWith(array $pairs): string
    {
        $html = '<html><head><meta charset="utf-8"></head><body><table><tr><td><font size=5>Заголовок</font></td></tr></table>';
        $pairs = ['Тип' => 'ТВ'] + $pairs;
        foreach ($pairs as $label => $value) {
            $html .= "<table><tr><td align=left width=145 class='review' Valign=top><b>".$label.'</b></td><td width=15></td>'
                ."<td class='review' Valign=top>".$value.'</td></tr></table>';
        }

        return $html.'</body></html>';
    }
}
