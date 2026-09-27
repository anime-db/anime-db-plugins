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

namespace AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture;

use AnimeDb\PluginContracts\Media\MediaFile;
use AnimeDb\PluginContracts\Media\MediaInfo;
use AnimeDb\PluginContracts\Media\MediaProbeFailedException;
use AnimeDb\PluginContracts\Media\MediaProbeInterface;
use AnimeDb\PluginContracts\Media\MediaProbeUnavailableException;

/**
 * In-memory {@see MediaProbeInterface} that recognises a {@see MediaFile} handle by object
 * identity, exactly like the real contract requires: a handle registered via
 * {@see self::registerResult()} is honoured, a `new MediaFile(...)` built from cached data
 * with the same {@see MediaFile::$relativePath} is rejected with
 * {@see MediaProbeFailedException}, the same way a real implementation would reject a handle
 * it never issued.
 */
final class FakeMediaProbe implements MediaProbeInterface
{
    /** @var \SplObjectStorage<MediaFile, MediaInfo> */
    private \SplObjectStorage $known;

    private ?string $identity = 'fake-prober-identity';

    private bool $unavailable = false;

    public int $probeAllCallCount = 0;

    public int $probeIdentityCallCount = 0;

    public function __construct()
    {
        $this->known = new \SplObjectStorage();
    }

    public function registerResult(MediaFile $file, MediaInfo $info): void
    {
        $this->known[$file] = $info;
    }

    public function setIdentity(string $identity): void
    {
        $this->identity = $identity;
    }

    public function setUnavailable(): void
    {
        $this->unavailable = true;
    }

    public function probe(MediaFile $file): MediaInfo
    {
        $result = $this->probeAll([$file]);

        return $result[$file->relativePath] ?? throw new MediaProbeFailedException('Could not parse the file.');
    }

    public function probeAll(array $files): array
    {
        ++$this->probeAllCallCount;

        if ($this->unavailable) {
            throw new MediaProbeUnavailableException('No prober is available.');
        }

        foreach ($files as $file) {
            if (!$this->known->offsetExists($file)) {
                throw new MediaProbeFailedException(\sprintf('Handle for "%s" was not issued by this prober.', $file->relativePath));
            }
        }

        $result = [];
        foreach ($files as $file) {
            $result[$file->relativePath] = $this->known[$file];
        }

        if ($result === []) {
            throw new MediaProbeFailedException('None of the given files could be parsed.');
        }

        return $result;
    }

    public function probeIdentity(): string
    {
        ++$this->probeIdentityCallCount;

        if ($this->identity === null || $this->unavailable) {
            throw new MediaProbeUnavailableException('No prober is available.');
        }

        return $this->identity;
    }
}
