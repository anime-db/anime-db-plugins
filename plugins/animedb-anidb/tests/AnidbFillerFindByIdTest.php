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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests;

use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbRequestException;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\AnidbTestCase;

final class AnidbFillerFindByIdTest extends AnidbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // cache entries get a real mtime, so the movable clock starts at the real time
        $this->now = time();
    }

    public function testFreshCachedCardMakesNoRequest(): void
    {
        $this->cards->put(7000, self::fixture('card_7000.xml'));

        $data = $this->filler->findById('7000');

        self::assertNotNull($data);
        self::assertSame('Bu She Zhi She', $data->title);
        self::assertSame([], $this->requests);
    }

    public function testCardIsFetchedOnceAndThenServedFromCache(): void
    {
        $this->responses = [$this->response(200, self::fixture('card_7000.xml'))];

        $this->filler->findById('7000');
        $this->filler->findById('7000');

        self::assertCount(1, $this->requests);
    }

    public function testNonExistentAidGivesNull(): void
    {
        $this->responses = [$this->response(200, self::fixture('not_found.xml'))];

        self::assertNull($this->filler->findById('5000'));
    }

    public function testInvalidIdGivesNullWithoutRequest(): void
    {
        self::assertNull($this->filler->findById('abc'));
        self::assertNull($this->filler->findById('0'));
        self::assertNull($this->filler->findById('7000/../1'));
        self::assertSame([], $this->requests);
    }

    public function testOpenBanWindowThrowsWithoutRequest(): void
    {
        $this->settings->data['api_banned_until'] = $this->now + 100;

        try {
            $this->filler->findById('7000');
            self::fail('Expected AniDbRequestException.');
        } catch (AniDbRequestException) {
        }

        self::assertSame([], $this->requests);
    }

    public function testOtherClientFailuresArePropagated(): void
    {
        $this->responses = [$this->response(500)];

        $this->expectException(AniDbRequestException::class);
        $this->filler->findById('7000');
    }

    public function testCardWithoutMainTitleGivesNull(): void
    {
        $this->responses = [$this->response(200, '<anime id="1"><titles><title xml:lang="en" type="official">X</title></titles></anime>')];

        self::assertNull($this->filler->findById('1'));
    }

    public function testIncompleteEndDateIsEndOfPeriod(): void
    {
        $data = $this->fill(14500);

        self::assertSame('2018-05-14', $data->datePremiere?->format('Y-m-d'));
        self::assertSame('2018-12-31', $data->dateEnd?->format('Y-m-d'));
    }

    public function testEndBeforePremiereDropsOnlyEnd(): void
    {
        $data = $this->fillFromDates('2018-12-31', '2018-05');

        self::assertSame('2018-12-31', $data->datePremiere?->format('Y-m-d'));
        self::assertNull($data->dateEnd);
    }

    public function testEndEqualToPremiereIsKept(): void
    {
        $data = $this->fillFromDates('2018-05-14', '2018-05-14');

        self::assertSame('2018-05-14', $data->datePremiere?->format('Y-m-d'));
        self::assertSame('2018-05-14', $data->dateEnd?->format('Y-m-d'));
    }

    public function testPartialEndInPremiereMonthIsRoundedToEndOfMonthAndKept(): void
    {
        $data = $this->fillFromDates('2018-05-14', '2018-05');

        self::assertSame('2018-05-14', $data->datePremiere?->format('Y-m-d'));
        self::assertSame('2018-05-31', $data->dateEnd?->format('Y-m-d'));
    }

    public function testTagsAreMappedToGenresThemesAndDemographic(): void
    {
        $tag = static fn (string $name): string => '<tag id="1" weight="300" localspoiler="false" globalspoiler="false"><name>'.$name.'</name></tag>';
        $this->responses = [$this->response(200, '<anime id="9"><titles><title xml:lang="x-jat" type="main">T</title></titles><tags>'
            .$tag('romance').$tag('mecha').$tag('Seinen').'</tags></anime>')];

        $data = $this->filler->findById('9');

        self::assertNotNull($data);
        self::assertSame([GenreCode::Romance], $data->genres);
        self::assertSame([ThemeCode::Mecha], $data->themes);
        self::assertSame(Demographic::Seinen, $data->demographic);
    }

    public function testCardWithoutTagsGivesNullGenresThemesAndDemographic(): void
    {
        $this->responses = [$this->response(200, '<anime id="9"><titles><title xml:lang="x-jat" type="main">T</title></titles></anime>')];

        $data = $this->filler->findById('9');

        self::assertNotNull($data);
        self::assertNull($data->genres);
        self::assertNull($data->themes);
        self::assertNull($data->demographic);
    }

    public function testTitlesAreMappedWithoutDuplicatesAndKanareading(): void
    {
        $this->responses = [$this->response(200, '<anime id="9"><titles>'
            .'<title xml:lang="x-jat" type="main">Main</title>'
            .'<title xml:lang="x-jat" type="synonym">Main</title>'
            .'<title xml:lang="en" type="synonym">Main</title>'
            .'<title xml:lang="en" type="official">Other</title>'
            .'<title xml:lang="en" type="synonym">Other</title>'
            .'<title xml:lang="x-jat" type="synonym">Romaji</title>'
            .'<title xml:lang="ja" type="kanareading">かな</title>'
            .'</titles></anime>')];

        $data = $this->filler->findById('9');

        self::assertNotNull($data);
        self::assertSame('Main', $data->title);
        $names = array_map(static fn ($n): array => [$n->name, $n->locale, $n->role], $data->alternativeNames ?? []);
        self::assertSame([
            ['Other', 'en', NameRole::Official],
            ['Romaji', null, NameRole::Synonym],
        ], $names);
    }

    public function testNameEqualToTitleIsSkippedInEveryLocale(): void
    {
        $data = $this->fill(2500);

        $names = array_map(static fn ($n): string => $n->name.'|'.($n->locale ?? '-'), $data->alternativeNames ?? []);
        self::assertSame('Momiji', $data->title);
        self::assertNotContains('Momiji|en', $names);
        self::assertNotContains('Momiji|pt-BR', $names);
        self::assertContains('My Sex Tutor|en', $names);
    }

    public function testDescriptionHasOnlyEnglishKeyAndIsCleaned(): void
    {
        $data = $this->fill(2500);

        self::assertNotNull($data->descriptions);
        self::assertSame(['en'], array_keys($data->descriptions));
        self::assertStringContainsString('Lune.', $data->descriptions['en']);
        self::assertStringNotContainsString('anidb.net', $data->descriptions['en']);
        self::assertStringNotContainsString('Source', $data->descriptions['en']);
    }

    public function testNoteLinesAreRemovedAndNestedTagDescriptionsAreIgnored(): void
    {
        $this->responses = [$this->response(200, '<anime id="9"><titles><title type="main">T</title></titles>'
            ."<description>Text.\nNote: editorial</description>"
            .'<tags><tag><name>x</name><description>Tag text</description></tag></tags></anime>')];

        $data = $this->filler->findById('9');

        self::assertSame(['en' => 'Text.'], $data?->descriptions);
    }

    public function testEmptyDescriptionGivesNull(): void
    {
        self::assertNull($this->fill(14500)->descriptions);
    }

    public function testEpisodesCountAndDurationAndType(): void
    {
        $data = $this->fill(14500);

        self::assertSame(4, $data->episodesCount);
        self::assertSame(5, $data->durationMinutes);
        self::assertSame(AnimeType::Ona, $data->type);
    }

    public function testCoverAndStudios(): void
    {
        self::assertSame('https://cdn-eu.anidb.net/images/main/35800.jpg', $this->fill(7000)->cover);
        self::assertSame(['Shura'], $this->fill(2500)->studios);
        self::assertNull($this->fill(7000)->studios);
    }

    public function testRestrictedCardIsFilledLikeAnyOther(): void
    {
        $data = $this->fill(2500);

        self::assertSame('Momiji', $data->title);
        self::assertSame(AnimeType::Ova, $data->type);
        self::assertSame('2003-08-25', $data->dateEnd?->format('Y-m-d'));
        self::assertSame(4, $data->episodesCount);
        self::assertNotNull($data->descriptions);
        self::assertNotNull($data->cover);
    }

    public function testImagesAndCountriesAreNotFilled(): void
    {
        $data = $this->fill(2500);

        self::assertNull($data->images);
        self::assertNull($data->countries);
    }

    public function testFillableFields(): void
    {
        self::assertSame([
            'title',
            'alternativeNames',
            'descriptions',
            'genres',
            'themes',
            'demographic',
            'type',
            'datePremiere',
            'dateEnd',
            'durationMinutes',
            'episodesCount',
            'studios',
            'cover',
        ], $this->filler->getFillableFields());
    }

    private function fillFromDates(string $start, string $end): \AnimeDb\PluginContracts\Filler\PluginAnimeData
    {
        $this->responses = [$this->response(200, '<anime id="9"><startdate>'.$start.'</startdate><enddate>'.$end.'</enddate>'
            .'<titles><title xml:lang="x-jat" type="main">T</title></titles></anime>')];
        $data = $this->filler->findById('9');
        self::assertNotNull($data);

        return $data;
    }

    private function fill(int $aid): \AnimeDb\PluginContracts\Filler\PluginAnimeData
    {
        $this->cards->put($aid, self::fixture(\sprintf('card_%d.xml', $aid)));
        $data = $this->filler->findById((string) $aid);
        self::assertNotNull($data);

        return $data;
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixture/'.$name);
    }
}
