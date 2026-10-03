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

namespace AnimeDb\Plugins\AnimedbMyanimelist\OAuth;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * `GET /oauth/myanimelist/start`: sends the user's browser straight to MyAnimeList's
 * authorize screen. A future caller of this route (the settings page added in task #180)
 * must open it as a top-level navigation (a plain link/full-page redirect), not through an
 * in-page swap — the desktop shell only hands a top-level navigation to a vendor domain off
 * to the system browser.
 */
#[AsController]
final class MalOAuthStartController
{
    public function __construct(
        private readonly MalOAuthClient $oauth,
    ) {
    }

    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->oauth->buildAuthorizeUrl());
    }
}
