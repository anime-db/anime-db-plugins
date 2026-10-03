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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\Http;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\UserAgent;
use PHPUnit\Framework\TestCase;

final class UserAgentTest extends TestCase
{
    public function testForManifestMatchesTheRequiredUserAgentFormat(): void
    {
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn('animedb-myanimelist');
        $manifest->method('version')->willReturn('0.1.0');

        self::assertSame(
            'AnimeDB animedb-myanimelist/0.1.0 (+https://anime-db.org/)',
            UserAgent::forManifest($manifest),
        );
    }
}
