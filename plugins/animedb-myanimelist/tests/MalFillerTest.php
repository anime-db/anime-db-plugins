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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\MalFiller;
use PHPUnit\Framework\TestCase;

final class MalFillerTest extends TestCase
{
    private const STUB_MANIFEST_ID = 'animedb-myanimelist-stub';

    public function testFindReturnsEmptyArrayWithoutHttpCallWhenQueryIsEmpty(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::never())->method('get');

        self::assertSame([], $this->buildFiller($client)->find(''));
    }

    public function testFindReturnsEmptyArrayWithoutHttpCallWhenQueryIsShorterThanMinimum(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::never())->method('get');

        // MIN_QUERY_LENGTH is 3; 2 characters must still short-circuit.
        self::assertSame([], $this->buildFiller($client)->find('ok'));
    }

    public function testFindSendsQueryAtExactlyTheMinimumLength(): void
    {
        $sentQuery = null;
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, array $query) use (&$sentQuery): array {
                $sentQuery = $query;

                return ['data' => []];
            });

        $this->buildFiller($client)->find('kon');

        self::assertSame('kon', $sentQuery['q'] ?? null);
    }

    public function testFindTruncatesQueryLongerThanMaximum(): void
    {
        $tooLong = str_repeat('a', MalFiller::MAX_QUERY_LENGTH + 10);
        $truncated = str_repeat('a', MalFiller::MAX_QUERY_LENGTH);

        $sentQuery = null;
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, array $query) use (&$sentQuery): array {
                $sentQuery = $query;

                return ['data' => []];
            });

        $this->buildFiller($client)->find($tooLong);

        self::assertSame($truncated, $sentQuery['q'] ?? null);
    }

    /**
     * Confirms truncation counts characters, not bytes: each `ナ` is 3 bytes in UTF-8, so a
     * byte-based `substr()` would cut mid-character well before 64 of them and could corrupt
     * the query. Limits were confirmed live against the MyAnimeList API with exactly this kind
     * of multibyte query (64 accepted, 65 rejected).
     */
    public function testFindTruncatesMultibyteQueryByCharacterNotByte(): void
    {
        $tooLong = str_repeat('ナ', MalFiller::MAX_QUERY_LENGTH + 5);
        $truncated = str_repeat('ナ', MalFiller::MAX_QUERY_LENGTH);

        $sentQuery = null;
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, array $query) use (&$sentQuery): array {
                $sentQuery = $query;

                return ['data' => []];
            });

        $this->buildFiller($client)->find($tooLong);

        self::assertSame($truncated, $sentQuery['q'] ?? null);
    }

    public function testFindMapsCandidatesFromARealSearchResponse(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::narutoSearchFixture());

        $candidates = $this->buildFiller($client)->find('naruto');

        self::assertEquals(
            new SearchByPluginCandidate(self::STUB_MANIFEST_ID, 'Naruto', '20'),
            $candidates[0],
        );
        self::assertCount(10, $candidates);
    }

    public function testFindReturnsEmptyArrayWhenNothingMatches(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(['data' => []]);

        self::assertSame([], $this->buildFiller($client)->find('does not exist'));
    }

    public function testFindSkipsItemsWithAMissingNodeOrAnInvalidTitleOrId(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(['data' => [
            // no `node` at all.
            ['foo' => 'bar'],
            // `node` present but no `title`.
            ['node' => ['id' => 1]],
            // `title` is an empty string.
            ['node' => ['id' => 2, 'title' => '']],
            // `id` missing entirely.
            ['node' => ['title' => 'No Id']],
            // `id` is not an int: an array, a bool, a numeric string, zero, and a negative int.
            ['node' => ['id' => ['nested'], 'title' => 'Array Id']],
            ['node' => ['id' => true, 'title' => 'Bool Id']],
            ['node' => ['id' => '3', 'title' => 'String Id']],
            ['node' => ['id' => 0, 'title' => 'Zero Id']],
            ['node' => ['id' => -1, 'title' => 'Negative Id']],
            // the only valid item.
            ['node' => ['id' => 4, 'title' => 'Valid']],
        ]]);

        $candidates = $this->buildFiller($client)->find('query');

        self::assertEquals(
            [new SearchByPluginCandidate(self::STUB_MANIFEST_ID, 'Valid', '4')],
            $candidates,
        );
    }

    public function testResolveExternalIdMatchesMyAnimeListDomainsAndPaths(): void
    {
        $filler = $this->buildFiller($this->createMock(MalApiClient::class));

        self::assertSame('20', $filler->resolveExternalId(['https://myanimelist.net/anime/20/Naruto']));
        self::assertSame('20', $filler->resolveExternalId(['https://myanimelist.net/anime.php?id=20']));
        self::assertNull($filler->resolveExternalId(['https://shikimori.io/animes/20-naruto']));
    }

    private function buildFiller(MalApiClient $client): MalFiller
    {
        return new MalFiller($client, $this->stubOwnManifest());
    }

    private function stubOwnManifest(): OwnManifestInterface
    {
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn(self::STUB_MANIFEST_ID);

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private static function narutoSearchFixture(): array
    {
        $json = file_get_contents(__DIR__.'/Fixture/search_naruto.json');
        \assert($json !== false);

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $fixture;
    }
}
