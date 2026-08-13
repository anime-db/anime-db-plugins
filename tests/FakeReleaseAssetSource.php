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

namespace AnimeDb\Plugins\Tools\Tests;

use AnimeDb\Plugins\Tools\ReleaseAssetSource;

/**
 * In-memory {@see ReleaseAssetSource} test double, so {@see \AnimeDb\Plugins\Tools\MirrorBackfillPublisher}
 * can be exercised without a real `gh` CLI / GitHub network call.
 */
final class FakeReleaseAssetSource implements ReleaseAssetSource
{
    /**
     * @param list<array{id: string, version: string}> $releases
     * @param list<string>                             $omitAssetFor list of "<id>/<version>" to
     *                                                               download with a missing asset
     */
    public function __construct(
        private readonly array $releases,
        private readonly array $omitAssetFor = [],
    ) {
    }

    public function listReleases(): array
    {
        return $this->releases;
    }

    public function downloadAssets(string $pluginId, string $version, string $destDir): array
    {
        file_put_contents($destDir.'/plugin.zip', 'zip-bytes');
        file_put_contents($destDir.'/manifest.json', json_encode(['id' => $pluginId], \JSON_THROW_ON_ERROR));

        if (\in_array($pluginId.'/'.$version, $this->omitAssetFor, true)) {
            unlink($destDir.'/manifest.json');

            return ['plugin.zip'];
        }

        return ['plugin.zip', 'manifest.json'];
    }
}
