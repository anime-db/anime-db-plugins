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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Widget;

use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetListItem;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use Twig\Environment;

/**
 * Catalog widget listing the current season's anime, sourced from `GET
 * /anime/season/{year}/{season}` — anonymous, no Bearer needed.
 *
 * Adapted from {@see \AnimeDb\Plugins\AnimedbShikimori\Widget\NewWidget}, but deliberately NOT a
 * copy of its OAuth-aware branch: MyAnimeList has no server-side equivalent of Shikimori's
 * `mylist` filter argument, so there is no cheap way to exclude titles already on the user's own
 * list — doing it client-side would mean fetching the user's whole list with a Bearer token on
 * every catalog render, which at this plugin's ~1 request/second rate limit would cost seconds
 * per page. This widget is therefore always anonymous and always shows the full season, same for
 * every user, with no `MalOAuthClient` dependency at all.
 *
 * `{year}`/`{season}` are derived from the current date, not stored or configurable.
 * {@see self::SEASON_BY_MONTH} is the official MyAnimeList API v2 season-to-month mapping
 * (https://myanimelist.net/apiconfig/references/api/v2): `winter` = January-March, `spring` =
 * April-June, `summer` = July-September, `fall` = October-December.
 */
final class NewWidget implements CatalogWidgetInterface
{
    private const TEMPLATE = '@AnimedbMyanimelist/widget/new.html.twig';
    private const DEFAULT_ENDPOINT = 'https://myanimelist.net';
    private const LIMIT = 20;
    private const FIELDS = 'id,title,main_picture';

    private const SEASON_BY_MONTH = [
        1 => 'winter', 2 => 'winter', 3 => 'winter',
        4 => 'spring', 5 => 'spring', 6 => 'spring',
        7 => 'summer', 8 => 'summer', 9 => 'summer',
        10 => 'fall', 11 => 'fall', 12 => 'fall',
    ];

    /** @var callable(): \DateTimeImmutable */
    private $now;

    /**
     * $now is constructor-injectable (not a host-provided type, so this stays safely
     * autowirable) purely so tests can drive the current date deterministically, the same
     * pattern {@see \AnimeDb\Plugins\AnimedbMyanimelist\Http\RateLimiter} uses for `$clock`.
     */
    public function __construct(
        private readonly MalApiClient $client,
        private readonly Environment $twig,
        ?callable $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata(
            'new',
            'widget.new.title',
            'widget.new.description',
        );
    }

    public function render(): string
    {
        [$year, $season] = $this->currentYearAndSeason();

        $data = $this->client->get(\sprintf('/anime/season/%d/%s', $year, $season), [
            'limit' => self::LIMIT,
            'fields' => self::FIELDS,
        ]);

        $animes = \is_array($data['data'] ?? null) ? $data['data'] : [];

        $items = [];
        foreach ($animes as $anime) {
            $item = self::buildItem($anime);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $this->twig->render(self::TEMPLATE, ['items' => $items]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function currentYearAndSeason(): array
    {
        $now = ($this->now)();

        return [(int) $now->format('Y'), self::SEASON_BY_MONTH[(int) $now->format('n')]];
    }

    /**
     * @param mixed $anime a single `data[]` element
     */
    private static function buildItem(mixed $anime): ?WidgetListItem
    {
        $node = \is_array($anime) ? ($anime['node'] ?? null) : null;
        if (!\is_array($node) || !\is_int($node['id'] ?? null) || $node['id'] < 1) {
            return null;
        }

        $title = $node['title'] ?? null;
        if (!\is_string($title) || $title === '') {
            return null;
        }

        $thumbnail = $node['main_picture']['large'] ?? null;

        return new WidgetListItem(
            \is_string($thumbnail) && $thumbnail !== '' ? $thumbnail : null,
            $title,
            null,
            self::DEFAULT_ENDPOINT.'/anime/'.$node['id'],
        );
    }
}
