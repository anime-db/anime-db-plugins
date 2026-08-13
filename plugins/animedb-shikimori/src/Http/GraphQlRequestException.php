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
 * The Shikimori GraphQL source is unreachable or misbehaving: transport failure, a non-2xx
 * HTTP status, a non-empty GraphQL `errors[]`, or the 429 retry/backoff budget was exhausted.
 *
 * Deliberately distinct from a "not found" result ({@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller::findById()}
 * returns `null` for that instead), so the host can tell "the source has no such record"
 * apart from "the source is down" and react to each differently.
 */
final class GraphQlRequestException extends \RuntimeException
{
}
