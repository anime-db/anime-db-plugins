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

namespace AnimeDb\Plugins\Tools;

/**
 * Shared helpers around the `<id>`/`<version>`/`<file>` URL-template macros used by both
 * `asset_mirrors` entries in `plugins-registry.json` and a `MIRROR_CREDS` entry's `public_url`.
 * Centralised so the "is this template well-formed" check (used to fail-open a bad `public_url`
 * out of `asset_mirrors`, see {@see AssetMirrorsResolver}) and the "build a concrete asset URL"
 * substitution (used to HEAD-verify a mirror after upload, see
 * {@see MirrorAssetReachabilityVerifier}) always agree on what a valid template looks like.
 */
final class MirrorAssetUrl
{
    private const REQUIRED_MACROS = ['<id>', '<version>', '<file>'];

    /**
     * A mirror is untrusted transport (the client pins `sha256` from the signed registry), but a
     * plain `http://` template would still let an active network attacker silently swap which
     * mirror a client is redirected to, so `public_url` templates are required to be `https://`.
     */
    public static function isHttpsTemplate(string $template): bool
    {
        return str_starts_with($template, 'https://');
    }

    public static function hasRequiredMacros(string $template): bool
    {
        foreach (self::REQUIRED_MACROS as $macro) {
            if (!str_contains($template, $macro)) {
                return false;
            }
        }

        return true;
    }

    public static function isValidTemplate(string $template): bool
    {
        return self::isHttpsTemplate($template) && self::hasRequiredMacros($template);
    }

    public static function build(string $template, string $pluginId, string $version, string $file): string
    {
        return strtr($template, [
            '<id>' => $pluginId,
            '<version>' => $version,
            '<file>' => $file,
        ]);
    }
}
