<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\AnimedbShikimori\OAuth;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * `GET /oauth/shikimori/start`: sends the user's browser straight to Shikimori's authorize
 * screen. The settings page links here with a plain top-level `<a href>`, not an HTMX swap —
 * the desktop shell only hands a top-level navigation to a vendor domain off to the system
 * browser (see {@see \AnimeDb\Plugins\AnimedbShikimori\Settings\ShikimoriSettingsPage}).
 */
#[AsController]
final class ShikimoriOAuthStartController
{
    public function __construct(
        private readonly ShikimoriOAuthClient $oauth,
    ) {
    }

    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->oauth->buildAuthorizeUrl());
    }
}
