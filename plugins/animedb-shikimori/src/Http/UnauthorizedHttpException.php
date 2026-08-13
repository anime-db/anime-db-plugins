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

namespace AnimeDb\Plugins\AnimedbShikimori\Http;

/**
 * Thrown by {@see GraphQlClient} and {@see ShikimoriRestClient} when an authed request gets
 * HTTP 401 back from Shikimori.
 *
 * Deliberately transport-agnostic (a standalone type, not a subclass of either
 * {@see GraphQlRequestException} or {@see RestRequestException}): both clients throw the same
 * type so {@see \AnimeDb\Plugins\AnimedbShikimori\Sync\ShikimoriAuthRetrier} can catch a single
 * exception class regardless of which transport (REST push / GraphQL pull) the failing request
 * used.
 */
final class UnauthorizedHttpException extends \RuntimeException
{
}
