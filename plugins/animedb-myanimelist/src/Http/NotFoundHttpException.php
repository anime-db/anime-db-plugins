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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Http;

/**
 * Thrown by {@see MalApiClient} when a request gets HTTP 404 back from MyAnimeList: the
 * requested resource (e.g. an anime id) does not exist.
 *
 * Kept as its own type (not a subclass of {@see MalRequestException}, see that class's doc)
 * so a caller such as {@see \AnimeDb\Plugins\AnimedbMyanimelist\MalFiller::findById()} can
 * catch "not found" apart from a hard failure and map it to its own `null` result.
 */
final class NotFoundHttpException extends \RuntimeException
{
}
