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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartRequestException;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\WorldartTestCase;

final class WorldartFillerTest extends WorldartTestCase
{
    public function testImplementsFiller(): void
    {
        self::assertInstanceOf(SearchByPluginInterface::class, $this->filler);
        self::assertInstanceOf(FillerInterface::class, $this->filler);
    }

    public function testResolveExternalId(): void
    {
        self::assertSame('262', $this->filler->resolveExternalId([
            'http://example.com/',
            'http://www.world-art.ru/animation/animation.php?id=262',
        ]));
        self::assertNull($this->filler->resolveExternalId(['http://www.world-art.ru/cinema/cinema.php?id=262']));
    }

    public function testSearchRequestHasCp1251QueryAndAnimationSector(): void
    {
        $this->queueFixture('search_naruto.html');

        $this->filler->find('Наруто');

        self::assertSame(
            ['http://www.world-art.ru/search.php?public_search=%CD%E0%F0%F3%F2%EE&global_sector=animation'],
            $this->requests,
        );
    }

    public function testEmptyAndHieroglyphQueriesMakeNoRequest(): void
    {
        foreach (['', '   ', 'ナルト', '???'] as $query) {
            self::assertSame([], $this->filler->find($query), $query);
        }
        self::assertSame([], $this->requests);
    }

    public function testSingleResultIsTakenFromTheRedirectAndTheCardTitle(): void
    {
        $this->queueFixture('search_single_result_redirect.html');
        $this->queueFixture('card_6.html');
        $start = $this->now;

        $candidates = $this->filler->find('Serial Experiments Lain');

        self::assertCount(1, $candidates);
        self::assertSame('testvendor-probe', $candidates[0]->getPluginId());
        self::assertSame('Эксперименты Лэйн', $candidates[0]->getName());
        self::assertSame('6', $candidates[0]->getExternalId());
        self::assertSame(SearchByPluginCandidate::class, $candidates[0]::class);
        self::assertCount(2, $this->requests);
        self::assertSame('http://www.world-art.ru/animation/animation.php?id=6', $this->requests[1]);
        // the card went through the same limiter: the second request waited for its slot
        self::assertEqualsWithDelta(2.0, $this->now - $start, 0.001);
    }

    public function testRedirectToCardWithoutTitleGivesNothing(): void
    {
        $this->queueFixture('search_single_result_redirect.html');
        $this->responses[] = $this->response(200, '<html><body></body></html>');

        self::assertSame([], $this->filler->find('Serial Experiments Lain'));
        self::assertCount(2, $this->requests);
    }

    public function testNotConnectAnswerToCardAfterSingleResultGivesNothing(): void
    {
        $this->queueFixture('search_single_result_redirect.html');
        $this->queueFixture('search_not_connect_redirect.html');

        self::assertSame([], $this->filler->find('Serial Experiments Lain'));
        self::assertSame([], glob($this->cacheDir.'/worldart-card-*'));
    }

    public function testSingleResultCardGoesThroughTheCardCache(): void
    {
        $this->queueFixture('search_single_result_redirect.html');
        $this->queueFixture('card_6.html');
        $this->filler->find('Serial Experiments Lain');
        self::assertFileExists($this->cardCachePath(6));
        touch($this->cardCachePath(6), (int) $this->now);

        // the search is asked again, the card is not
        $this->queueFixture('search_single_result_redirect.html');
        $candidates = $this->filler->find('Serial Experiments Lain');

        self::assertCount(1, $candidates);
        self::assertSame('Эксперименты Лэйн', $candidates[0]->getName());
        self::assertCount(3, $this->requests);
        self::assertStringContainsString('/search.php', $this->requests[2]);
    }

    public function testRedirectTargetWithoutLeadingSlashIsResolvedAgainstTheBase(): void
    {
        $this->responses[] = $this->response(200, "<html><head><meta http-equiv='Refresh' content='0; url=animation/animation.php?id=6'></head></html>");
        $this->queueFixture('card_6.html');

        $candidates = $this->filler->find('Serial Experiments Lain');

        self::assertCount(1, $candidates);
        self::assertSame('6', $candidates[0]->getExternalId());
    }

    public function testRedirectToForeignHostWithSchemeRelativeTargetIsARequestException(): void
    {
        $this->responses[] = $this->response(200, "<html><head><meta http-equiv='Refresh' content='0; url=//other-host/animation/animation.php?id=6'></head></html>");

        $this->expectException(WorldartRequestException::class);
        $this->filler->find('x');
    }

    public function testRedirectToUnexpectedPageIsARequestException(): void
    {
        $this->responses[] = $this->response(200, "<html><head><meta http-equiv='Refresh' content='0; url=/cinema/cinema.php?id=1'></head></html>");

        $this->expectException(WorldartRequestException::class);
        $this->filler->find('x');
    }

    public function testNarutoOrdersExactMatchesFirstByYearAscending(): void
    {
        $this->queueFixture('search_naruto.html');

        $candidates = $this->filler->find('naruto');
        $ids = array_map(static fn (SearchByPluginCandidate $c): string => $c->getExternalId(), $candidates);

        self::assertCount(24, $candidates);
        // 262 (2002) and 11822 (2027) are exact matches of the Latin line "Naruto"
        self::assertSame(['262', '11822'], \array_slice($ids, 0, 2));
        // the rest keeps the order of the site (year descending)
        self::assertSame(
            ['9070', '8187', '2585', '386', '837', '926', '2517', '8152', '381', '5361', '7259', '7719', '6923', '6223'],
            \array_slice($ids, 2, 14),
        );
        self::assertSame(['5900', '3220', '5416', '279', '3789', '2610', '2611'], \array_slice($ids, 17));
        self::assertCount(24, array_unique($ids));
    }

    public function testRussianNarutoQueryPutsThreeExactMatchesFirstByYearAscending(): void
    {
        $this->queueFixture('search_naruto.html');

        $candidates = $this->filler->find('Наруто');
        $ids = array_map(static fn (SearchByPluginCandidate $c): string => $c->getExternalId(), $candidates);

        self::assertCount(24, $candidates);
        // 262 (2002), 6223 (2007) and 11822 (2027) match the Russian name exactly
        self::assertSame(['262', '6223', '11822'], \array_slice($ids, 0, 3));
        // the rest keeps the order of the site (year descending)
        self::assertSame(
            ['9070', '8187', '2585', '386', '837', '926', '2517', '8152', '381', '5361', '7259', '7719', '6923'],
            \array_slice($ids, 3, 13),
        );
        self::assertSame(['5900', '3220', '5416', '279', '3789', '2610', '2611'], \array_slice($ids, 17));
        self::assertCount(24, array_unique($ids));
    }

    public function testCandidateNamesHaveNoTailAndKeepSeasonMark(): void
    {
        $this->queueFixture('search_naruto.html');

        $names = [];
        foreach ($this->filler->find('naruto') as $candidate) {
            $names[$candidate->getExternalId()] = $candidate->getName();
            self::assertSame('testvendor-probe', $candidate->getPluginId());
            self::assertDoesNotMatchRegularExpression('/\(\d{4},/', $candidate->getName());
        }

        self::assertSame('Наруто [ТВ-1]', $names['262']);
        self::assertSame('Наруто [ТВ-2]', $names['6223']);
        self::assertSame('Наруто', $names['11822']);
    }

    public function testExactMatchIsCaseSeasonMarkAndYoInsensitive(): void
    {
        $html = $this->searchPage([
            ['1', 'Первый Ёж&nbsp;(2010, Япония, ТВ)', ['Other']],
            ['2', 'Первый еж [ТВ-1]&nbsp;(2001, Япония, ТВ)', ['Other']],
            ['3', 'Другое&nbsp;(1990, Япония, ТВ)', ['Another']],
        ]);
        $this->responses[] = $this->response(200, $html);

        $ids = array_map(
            static fn (SearchByPluginCandidate $c): string => $c->getExternalId(),
            $this->filler->find('ПЕРВЫЙ ЕЖ'),
        );

        self::assertSame(['2', '1', '3'], $ids);
    }

    public function testLatinLineOfTheRowCountsAsExactMatch(): void
    {
        $html = $this->searchPage([
            ['1', 'Продолжение&nbsp;(2010, Япония, ТВ)', ['Something Else']],
            ['2', 'Название&nbsp;(2012, Япония, ТВ)', ['Native Name', 'Full Metal']],
        ]);
        $this->responses[] = $this->response(200, $html);

        $ids = array_map(
            static fn (SearchByPluginCandidate $c): string => $c->getExternalId(),
            $this->filler->find('full metal'),
        );

        self::assertSame(['2', '1'], $ids);
    }

    public function testNoExactMatchKeepsTheOrderOfTheSite(): void
    {
        $this->queueFixture('search_naruto.html');

        $candidates = $this->filler->find('nar');

        self::assertSame('11822', $candidates[0]->getExternalId());
        self::assertSame('262', $candidates[23]->getExternalId());
    }

    public function testNotConnectAnswerToSearchIsARequestExceptionNotEmptyList(): void
    {
        $this->queueFixture('search_not_connect_redirect.html');

        $this->expectException(WorldartRequestException::class);
        $this->filler->find('naruto');
    }

    public function testSearchIsNotCached(): void
    {
        $this->queueFixture('search_naruto.html');
        $this->queueFixture('search_naruto.html');

        $this->filler->find('naruto');
        $this->filler->find('naruto');

        self::assertCount(2, $this->requests);
    }

    public function testHeartbeatIsCalledWhileWaitingForASlot(): void
    {
        $this->queueFixture('search_naruto.html');
        $this->queueFixture('search_naruto.html');
        $beats = 0;

        $this->filler->find('naruto', static function () use (&$beats): void {
            ++$beats;
        });
        self::assertSame(0, $beats);
        $this->filler->find('naruto', static function () use (&$beats): void {
            ++$beats;
        });

        self::assertSame(2, $beats);
    }

    /**
     * @param list<array{string, string, list<string>}> $rows id, link text, other names
     */
    private function searchPage(array $rows): string
    {
        $html = '<html><body><table width=100%>';
        foreach ($rows as [$id, $text, $others]) {
            $html .= \sprintf(
                '<tr><td ><a href = "animation/animation.php?id=%s" class=\'review\'>%s</a><br>%s<br><br></td></tr>',
                $id,
                $text,
                implode('<br>', $others),
            );
        }

        return $html.'</table></body></html>';
    }
}
