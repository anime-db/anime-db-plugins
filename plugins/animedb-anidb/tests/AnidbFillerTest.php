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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\AnidbTestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class AnidbFillerTest extends AnidbTestCase
{
    /**
     * @return list<string>
     */
    private function ids(string $query): array
    {
        return array_map(static fn ($c): string => $c->getExternalId(), $this->filler->find($query));
    }

    public function testImplementsFillerContractOnly(): void
    {
        self::assertInstanceOf(FillerInterface::class, $this->filler);
        self::assertInstanceOf(SearchByPluginInterface::class, $this->filler);
        self::assertNotInstanceOf(SyncInterface::class, $this->filler);
    }

    public function testResolveExternalId(): void
    {
        self::assertSame('42', $this->filler->resolveExternalId(['https://anidb.net/anime/42']));
        self::assertNull($this->filler->resolveExternalId(['https://example.com/anime/42']));
    }

    public function testEmptyCacheDownloadsBuildsIndexAndAnswers(): void
    {
        $this->responses = [$this->dumpResponse($this->fixtureDump())];

        $candidates = $this->filler->find('cowboy bebop');

        self::assertCount(1, $this->requests);
        self::assertFileExists($this->files->indexPath());
        self::assertCount(1, $candidates);
        self::assertSame('testvendor-probe', $candidates[0]->getPluginId());
        self::assertSame('Cowboy Bebop', $candidates[0]->getName());
        self::assertSame('5', $candidates[0]->getExternalId());
    }

    public function testFreshAttemptWithEmptyCacheDoesNotRequestAndFindsNothing(): void
    {
        $this->settings->data['dump_attempt'] = ['at' => $this->now, 'kind' => 'response'];

        self::assertSame([], $this->filler->find('naruto'));
        self::assertSame([], $this->requests);
    }

    public function testStaleDumpIsSearchedWhenNewOneCannotBeFetched(): void
    {
        $this->seedDump($this->fixtureDump());
        $this->now += 86400;
        $this->responses = [$this->createMock(ClientExceptionInterface::class)];

        self::assertSame(['5'], $this->ids('cowboy bebop'));
        self::assertCount(1, $this->requests);
    }

    public function testIndexIsNotRebuiltUntilVersionChanges(): void
    {
        $this->seedDump($this->fixtureDump());
        $this->ids('naruto');
        $marker = new \PDO('sqlite:'.$this->files->indexPath());
        $marker->exec("INSERT INTO meta (k, v) VALUES ('marker', '1')");
        $marker = null;

        $this->ids('naruto');
        $this->now += 86400;
        $this->responses = [$this->response(304)];
        $this->ids('naruto');
        self::assertSame('1', $this->markerValue());

        $this->now += 86400;
        $this->responses = [$this->dumpResponse($this->fixtureDump(), ['ETag' => '"v2"'])];
        $this->ids('naruto');
        self::assertNull($this->markerValue());
    }

    public function testIndexVersionFallsBackToLastModified(): void
    {
        file_put_contents($this->files->dumpPath(), $this->fixtureDump());
        $this->files->writeMeta(null, 'lm-1');
        $this->settings->data['dump_attempt'] = ['at' => $this->now, 'kind' => 'response'];
        $this->ids('naruto');
        $marker = new \PDO('sqlite:'.$this->files->indexPath());
        $marker->exec("INSERT INTO meta (k, v) VALUES ('marker', '1')");
        $marker = null;

        $this->ids('naruto');
        self::assertSame('1', $this->markerValue());

        $this->now += 86400;
        $this->responses = [$this->dumpResponse($this->fixtureDump(), ['Last-Modified' => 'lm-2'])];
        $this->ids('naruto');
        self::assertNull($this->markerValue());
    }

    public function testExactMatchExcludesPrefixMatches(): void
    {
        $this->seedDump($this->fixtureDump());

        self::assertSame(['2'], $this->ids('NARUTO!'));
    }

    public function testPrefixMatchesWhenNoExactOne(): void
    {
        $this->seedDump($this->fixtureDump());

        self::assertSame(['4'], $this->ids('boruto naruto'));
        self::assertSame([], $this->ids('narut'));
    }

    public function testSubstringMatchesAreNotReturned(): void
    {
        $this->seedDump($this->fixtureDump());

        self::assertSame(['6'], $this->ids('bebop'));
        self::assertSame([], $this->ids('rival'));
        self::assertSame([], $this->ids('shippuuden'));
    }

    public function testRankingByTypeThenAid(): void
    {
        $this->seedDump($this->fixtureDump());

        self::assertSame(['13', '14', '11', '12', '10'], $this->ids('zeta'));
    }

    public function testKanareadingIsNotIndexed(): void
    {
        $this->seedDump($this->fixtureDump());

        self::assertSame([], $this->ids('Kaubooi Bibappu'));
    }

    public function testOneCandidatePerAidNamedByMainTitle(): void
    {
        $this->seedDump($this->fixtureDump());

        $candidates = $this->filler->find('Naruto');

        self::assertCount(1, $candidates);
        self::assertSame('Naruto', $candidates[0]->getName());

        $byOfficial = $this->filler->find('Tale of the Star World');
        self::assertSame('Seikai no Monogatari', $byOfficial[0]->getName());
        self::assertSame('1', $byOfficial[0]->getExternalId());
    }

    public function testResultIsLimited(): void
    {
        $dump = '';
        for ($aid = 100; $aid < 130; ++$aid) {
            $dump .= "$aid|1|x-jat|Many $aid\n$aid|4|en|Many official $aid\n";
        }
        $this->seedDump($dump);

        $ids = $this->ids('many');

        self::assertCount(10, $ids);
        self::assertSame('100', $ids[0]);
        self::assertCount(\count(array_unique($ids)), $ids);
    }

    public function testEmptyAndPunctuationOnlyQueriesFindNothing(): void
    {
        $this->seedDump($this->fixtureDump());

        self::assertSame([], $this->ids(''));
        self::assertSame([], $this->ids('!!!'));
    }

    private function markerValue(): ?string
    {
        $pdo = new \PDO('sqlite:'.$this->files->indexPath());
        $value = $pdo->query("SELECT v FROM meta WHERE k = 'marker'")->fetchColumn();

        return $value === false ? null : (string) $value;
    }
}
