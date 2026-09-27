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

namespace AnimeDb\Plugins\AnimedbMediaDetails\BackgroundTask;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskHandlerInterface;
use AnimeDb\PluginContracts\Media\AudioTrack;
use AnimeDb\PluginContracts\Media\MediaFile;
use AnimeDb\PluginContracts\Media\MediaInfo;
use AnimeDb\PluginContracts\Media\MediaLibraryInterface;
use AnimeDb\PluginContracts\Media\MediaProbeFailedException;
use AnimeDb\PluginContracts\Media\MediaProbeInterface;
use AnimeDb\PluginContracts\Media\MediaProbeUnavailableException;
use AnimeDb\PluginContracts\Media\OtherTrack;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Media\SubtitleTrack;
use AnimeDb\PluginContracts\Media\VideoTrack;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface;

/**
 * The plugin's single {@see BackgroundTaskHandlerInterface}, covering the one task kind it
 * ever submits ({@see AnimeDb\Plugins\AnimedbMediaDetails\EventSubscriber\AnimeFilesChangedSubscriber}).
 *
 * Idempotent by design: every run re-derives what still needs probing from the current file
 * list and the previously stored payload, and probes only the files that actually diverge —
 * a run that finds nothing to do starts and ends no external process at all.
 *
 * A {@see MediaFile} handle is only ever the one this handler obtained from its own
 * {@see MediaLibraryInterface::listFiles()} call, never rebuilt from stored payload data: a
 * handle from another process (e.g. the widget that first requested this task) is rejected by
 * the prober before any disk access.
 */
final class MediaDetailsTaskHandler implements BackgroundTaskHandlerInterface
{
    private const TASK_NAME = 'probe-files';

    public function __construct(
        private readonly MediaLibraryInterface $library,
        private readonly MediaProbeInterface $prober,
        private readonly PluginDataStoreInterface $store,
    ) {
    }

    public function handle(BackgroundTask $task): void
    {
        if ($task->name !== self::TASK_NAME) {
            return;
        }

        if ($task->anime === null) {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $this->store->read($task->anime);

        try {
            $files = $this->library->listFiles($task->anime);
        } catch (StorageUnavailableException) {
            return;
        }

        if ($files === []) {
            return;
        }

        /** @var array<string, mixed> $existingFiles */
        $existingFiles = \is_array($payload['files'] ?? null) ? $payload['files'] : [];

        try {
            $currentProbeIdentity = $this->prober->probeIdentity();
        } catch (MediaProbeUnavailableException) {
            $this->markProberUnavailable($task->anime, $payload);

            return;
        }

        [$toProbe, $hasDivergence] = $this->findDivergence($files, $existingFiles, $currentProbeIdentity);

        if (!$hasDivergence) {
            return;
        }

        $probed = [];
        if ($toProbe !== []) {
            try {
                $probed = $this->prober->probeAll($toProbe);
            } catch (MediaProbeUnavailableException) {
                $this->markProberUnavailable($task->anime, $payload);

                return;
            } catch (MediaProbeFailedException) {
                // None of the divergent files could be parsed this round; fall back to
                // whatever was stored for them, same as an empty probeAll() result.
                $probed = [];
            }
        }

        $newFiles = [];
        foreach ($files as $file) {
            if (isset($probed[$file->relativePath])) {
                $newFiles[$file->relativePath] = $this->buildFileEntry($file, $probed[$file->relativePath]);
            } elseif (isset($existingFiles[$file->relativePath]) && \is_array($existingFiles[$file->relativePath])) {
                $newFiles[$file->relativePath] = $existingFiles[$file->relativePath];
            }
        }

        $newPayload = [
            'generated_at' => $this->now(),
            'files_total' => \count($files),
            'files' => $newFiles,
        ];

        $this->tryWrite($task->anime, $newPayload);
    }

    /**
     * @param MediaFile[]          $files
     * @param array<string, mixed> $existingFiles
     *
     * @return array{0: MediaFile[], 1: bool} the files that need (re)probing, and whether
     *                                        anything at all diverged from the stored payload
     */
    private function findDivergence(array $files, array $existingFiles, string $currentProbeIdentity): array
    {
        $toProbe = [];
        $currentPaths = [];

        foreach ($files as $file) {
            $currentPaths[] = $file->relativePath;

            $existing = $existingFiles[$file->relativePath] ?? null;
            if (
                \is_array($existing)
                && ($existing['handle_size'] ?? null) === $file->sizeBytes
                && ($existing['handle_mtime'] ?? null) === $file->modifiedAt->getTimestamp()
                && ($existing['probe_identity'] ?? null) === $currentProbeIdentity
            ) {
                continue;
            }

            $toProbe[] = $file;
        }

        if ($toProbe !== []) {
            return [$toProbe, true];
        }

        $storedPaths = array_map(strval(...), array_keys($existingFiles));
        sort($storedPaths);
        sort($currentPaths);

        return [[], $storedPaths !== $currentPaths];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFileEntry(MediaFile $file, MediaInfo $info): array
    {
        return [
            'handle_size' => $file->sizeBytes,
            'handle_mtime' => $file->modifiedAt->getTimestamp(),
            'probe_identity' => $info->probeIdentity,
            'size' => $info->sizeBytes,
            'container' => $info->containerFormat,
            'duration' => $info->durationSeconds,
            'bit_rate' => $info->bitRate,
            'track_count' => $info->trackCount,
            'video' => array_map($this->videoTrackToArray(...), $info->video),
            'audio' => array_map($this->audioTrackToArray(...), $info->audio),
            'subtitles' => array_map($this->subtitleTrackToArray(...), $info->subtitles),
            'other' => array_map($this->otherTrackToArray(...), $info->otherTracks),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function videoTrackToArray(VideoTrack $track): array
    {
        return [
            'index' => $track->index,
            'codec' => $track->codec,
            'profile' => $track->profile,
            'width' => $track->width,
            'height' => $track->height,
            'pixel_format' => $track->pixelFormat,
            'frame_rate' => $track->frameRate,
            'bit_rate' => $track->bitRate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function audioTrackToArray(AudioTrack $track): array
    {
        return [
            'index' => $track->index,
            'codec' => $track->codec,
            'channels' => $track->channels,
            'channel_layout' => $track->channelLayout,
            'sample_rate' => $track->sampleRate,
            'bit_rate' => $track->bitRate,
            'language' => $track->language,
            'title' => $track->title,
            'is_default' => $track->isDefault,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subtitleTrackToArray(SubtitleTrack $track): array
    {
        return [
            'index' => $track->index,
            'codec' => $track->codec,
            'language' => $track->language,
            'title' => $track->title,
            'is_default' => $track->isDefault,
            'is_forced' => $track->isForced,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function otherTrackToArray(OtherTrack $track): array
    {
        return [
            'index' => $track->index,
            'type' => $track->type,
            'codec' => $track->codec,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function markProberUnavailable(AnimeId $anime, array $payload): void
    {
        $payload['prober_unavailable_at'] = $this->now();

        $this->tryWrite($anime, $payload);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function tryWrite(AnimeId $anime, array $data): void
    {
        try {
            $this->store->write($anime, $data);
        } catch (\RuntimeException) {
            // A core-side write conflict is an application-level class this plugin cannot
            // reference or catch by type; the next AnimeFilesChangedEvent or task run
            // retries the write, so a single lost write here is not fatal.
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DATE_ATOM);
    }
}
