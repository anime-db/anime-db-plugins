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

namespace AnimeDb\Plugins\AnimedbMediaDetails\Widget;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use AnimeDb\PluginContracts\Media\MediaProbeInterface;
use AnimeDb\PluginContracts\Media\MediaProbeUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;
use AnimeDb\PluginContracts\Widget\WidgetPendingUpdate;
use Twig\Environment;

/**
 * Entry widget rendering a single, record-level summary of the file characteristics this
 * plugin's {@see \AnimeDb\Plugins\AnimedbMediaDetails\BackgroundTask\MediaDetailsTaskHandler}
 * collects into the per-file payload — never a per-file list (see the class docblock of
 * `MediaDetailsTaskHandler` for the payload shape this widget only ever reads).
 *
 * This is also the only place that ever triggers the plugin's first probe of a record: unlike
 * {@see \AnimeDb\Plugins\AnimedbMediaDetails\EventSubscriber\AnimeFilesChangedSubscriber},
 * which only re-queues a probe for a record this plugin already has data for, `render()`
 * queues the very first one, the moment a payload is found empty.
 *
 * Deliberately does not depend on {@see \AnimeDb\PluginContracts\Media\MediaLibraryInterface}:
 * listing a record's files walks its storage folder, which can block a web worker for a long
 * time on an unresponsive network share. Everything this widget shows comes from the payload
 * already written by the background task; a stale or missing entry is a reason to queue a
 * refresh, never a reason to read the filesystem inline.
 *
 * States are checked in a fixed order because they are not mutually exclusive as plain
 * conditions — a record whose payload is both marked "prober unavailable" and happens to have
 * an empty `files` map must show the neutral "unavailable" message, not queue a task the prober
 * cannot run:
 *
 * 1. the payload carries a "prober unavailable" mark — re-checked with a cheap
 *    {@see \AnimeDb\PluginContracts\Media\MediaProbeInterface::probeIdentity()} call right
 *    here, so a prober that came back does not leave the record stuck forever;
 * 2. the payload is empty — the cold-start case, queues the first probe;
 * 3. `files` is empty but the payload is not —
 *    {@see \AnimeDb\Plugins\AnimedbMediaDetails\BackgroundTask\MediaDetailsTaskHandler} already
 *    ran at least once and found either nothing to parse (`files_total > 0`, "parse failed") or
 *    no files at all (`files_total === 0`, "no files"); shown as a neutral message, no task
 *    queued, since nothing changed since that attempt and probing again would just repeat it;
 * 4/5. `files` is non-empty — the summary is shown either way, a divergent
 *    {@see \AnimeDb\PluginContracts\Media\MediaProbeInterface::probeIdentity()} additionally
 *    queues a refresh.
 */
final class SummaryWidget implements EntryWidgetInterface
{
    private const TEMPLATE = '@AnimedbMediaDetails/widget/summary.html.twig';

    /**
     * Matches {@see \AnimeDb\Plugins\AnimedbMediaDetails\EventSubscriber\AnimeFilesChangedSubscriber}
     * and {@see \AnimeDb\Plugins\AnimedbMediaDetails\BackgroundTask\MediaDetailsTaskHandler::TASK_NAME},
     * which is private there — this plugin has a single task kind, named independently in each
     * of the (at most) two places that submit it.
     */
    private const TASK_NAME = 'probe-files';

    /**
     * How many distinct values of a single field (codec, resolution, ...) are listed before
     * the rest collapse into "and N more".
     */
    private const MAX_DISTINCT_VALUES = 3;

    public function __construct(
        private readonly PluginDataStoreInterface $store,
        private readonly BackgroundTaskQueueInterface $tasks,
        private readonly MediaProbeInterface $prober,
        private readonly Environment $twig,
    ) {
    }

    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata(
            'summary',
            'widget.summary.title',
            'widget.summary.description',
        );
    }

    public function render(AnimeId $anime): string
    {
        $payload = $this->store->read($anime);

        if (isset($payload['prober_unavailable_at'])) {
            // The mark only ever meant "unavailable as of the last background run" — it is
            // cheap to re-check right here, the same call already made a few lines down for
            // the ready path, so a prober that has since come back does not leave the record
            // stuck showing "unavailable" until its files happen to change.
            try {
                $this->prober->probeIdentity();
            } catch (MediaProbeUnavailableException) {
                return $this->twig->render(self::TEMPLATE, ['state' => 'unavailable']);
            }

            $this->tasks->submit(new BackgroundTask(self::TASK_NAME, $anime));

            return WidgetPendingUpdate::mark($this->twig->render(self::TEMPLATE, ['state' => 'pending']));
        }

        $filesTotal = \is_int($payload['files_total'] ?? null) ? $payload['files_total'] : 0;
        $files = $this->filterFileEntries($payload['files'] ?? null);

        if ($files === []) {
            if ($payload === []) {
                // Never probed at all: queue the very first attempt.
                $this->tasks->submit(new BackgroundTask(self::TASK_NAME, $anime));

                return WidgetPendingUpdate::mark($this->twig->render(self::TEMPLATE, ['state' => 'pending']));
            }

            // Already probed at least once (see MediaDetailsTaskHandler), so this is a
            // settled result, not a cold start — never re-queue on every render for it.
            return $this->twig->render(self::TEMPLATE, [
                'state' => $filesTotal > 0 ? 'parse_failed' : 'no_files',
            ]);
        }

        try {
            $currentIdentity = $this->prober->probeIdentity();
        } catch (MediaProbeUnavailableException) {
            return $this->twig->render(self::TEMPLATE, ['state' => 'unavailable']);
        }

        if ($this->identityDiverges($files, $currentIdentity)) {
            $this->tasks->submit(new BackgroundTask(self::TASK_NAME, $anime));
        }

        return $this->twig->render(self::TEMPLATE, [
            'state' => 'ready',
            ...$this->buildSummary($files, $filesTotal),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterFileEntries(mixed $files): array
    {
        if (!\is_array($files)) {
            return [];
        }

        $entries = [];
        foreach ($files as $file) {
            if (\is_array($file)) {
                $entries[] = $file;
            }
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    private function identityDiverges(array $files, string $currentIdentity): bool
    {
        foreach ($files as $file) {
            $identity = $file['probe_identity'] ?? null;
            if (!\is_string($identity) || $identity !== $currentIdentity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $files
     *
     * @return array<string, mixed>
     */
    private function buildSummary(array $files, int $filesTotal): array
    {
        $knownCount = \count($files);

        $sizeBytes = 0;
        $durationSum = 0.0;
        $durationKnownCount = 0;

        /** @var list<string> $videoCodecs */
        $videoCodecs = [];
        /** @var list<string> $videoResolutions */
        $videoResolutions = [];
        /** @var list<string> $videoFrameRates */
        $videoFrameRates = [];

        /** @var list<int> $audioTrackCounts */
        $audioTrackCounts = [];
        /** @var list<string> $audioLanguages */
        $audioLanguages = [];
        $filesWithAudio = 0;
        $filesWithKnownAudioLanguage = 0;

        /** @var list<string> $subtitleLanguages */
        $subtitleLanguages = [];
        $filesWithSubtitles = 0;
        $filesWithKnownSubtitleLanguage = 0;

        foreach ($files as $file) {
            $sizeBytes += \is_int($file['handle_size'] ?? null) ? $file['handle_size'] : 0;

            $duration = $file['duration'] ?? null;
            if (\is_float($duration) || \is_int($duration)) {
                $durationSum += (float) $duration;
                ++$durationKnownCount;
            }

            foreach ($this->filterFileEntries($file['video'] ?? null) as $track) {
                if (\is_string($track['codec'] ?? null)) {
                    $videoCodecs[] = $track['codec'];
                }

                $width = $track['width'] ?? null;
                $height = $track['height'] ?? null;
                if (\is_int($width) && \is_int($height)) {
                    $videoResolutions[] = $width.'x'.$height;
                }

                $frameRate = $track['frame_rate'] ?? null;
                if (\is_float($frameRate) || \is_int($frameRate)) {
                    $videoFrameRates[] = $this->formatFrameRate((float) $frameRate);
                }
            }

            $audio = $this->filterFileEntries($file['audio'] ?? null);
            $audioTrackCounts[] = \count($audio);
            if ($audio !== []) {
                ++$filesWithAudio;
            }
            $hasKnownAudioLanguage = false;
            foreach ($audio as $track) {
                if (\is_string($track['language'] ?? null)) {
                    $audioLanguages[] = $track['language'];
                    $hasKnownAudioLanguage = true;
                }
            }
            if ($hasKnownAudioLanguage) {
                ++$filesWithKnownAudioLanguage;
            }

            $subtitles = $this->filterFileEntries($file['subtitles'] ?? null);
            if ($subtitles !== []) {
                ++$filesWithSubtitles;
            }
            $hasKnownSubtitleLanguage = false;
            foreach ($subtitles as $track) {
                if (\is_string($track['language'] ?? null)) {
                    $subtitleLanguages[] = $track['language'];
                    $hasKnownSubtitleLanguage = true;
                }
            }
            if ($hasKnownSubtitleLanguage) {
                ++$filesWithKnownSubtitleLanguage;
            }
        }

        return [
            'files_total' => $filesTotal,
            'unparsed_count' => max(0, $filesTotal - $knownCount),
            ...$this->formatBytes($sizeBytes),
            'size_partial' => $knownCount < $filesTotal,
            'duration_formatted' => $durationKnownCount > 0 ? $this->formatDuration($durationSum) : null,
            'duration_partial' => $durationKnownCount > 0 && $durationKnownCount < $filesTotal,
            'video_codec' => $videoCodecs === [] ? null : $this->buildValueDisplay($videoCodecs),
            'video_resolution' => $videoResolutions === [] ? null : $this->buildValueDisplay($videoResolutions),
            'video_frame_rate' => $videoFrameRates === [] ? null : $this->buildValueDisplay($videoFrameRates),
            'audio_tracks' => $filesWithAudio === 0
                ? null
                : $this->buildValueDisplay(array_map(strval(...), $audioTrackCounts)),
            'audio_languages' => $filesWithAudio === 0 ? null : [
                ...$this->buildValueDisplay($audioLanguages),
                'unknown' => $audioLanguages === [],
                'partial' => $filesWithKnownAudioLanguage < $knownCount,
            ],
            'subtitle_languages' => $filesWithSubtitles === 0 ? null : [
                ...$this->buildValueDisplay($subtitleLanguages),
                'unknown' => $subtitleLanguages === [],
                'partial' => $filesWithKnownSubtitleLanguage < $knownCount,
            ],
        ];
    }

    /**
     * Honesty rule: a field is shown as a single value only when every file agrees on it;
     * otherwise up to {@see self::MAX_DISTINCT_VALUES} distinct values are listed, with the
     * rest collapsed into a count the template renders as "and N more".
     *
     * @param list<string> $values
     *
     * @return array{shown: list<string>, moreCount: int}
     */
    private function buildValueDisplay(array $values): array
    {
        $distinct = [];
        foreach ($values as $value) {
            if (!\in_array($value, $distinct, true)) {
                $distinct[] = $value;
            }
        }

        if (\count($distinct) <= self::MAX_DISTINCT_VALUES) {
            return ['shown' => $distinct, 'moreCount' => 0];
        }

        return [
            'shown' => \array_slice($distinct, 0, self::MAX_DISTINCT_VALUES),
            'moreCount' => \count($distinct) - self::MAX_DISTINCT_VALUES,
        ];
    }

    /**
     * The unit itself is a translated string, not a hardcoded English abbreviation — the
     * catalog holds one key per order of magnitude (see `widget.summary.size_unit_*`), and
     * this method only picks which one applies.
     *
     * @return array{size_value: string, size_unit_key: string}
     */
    private function formatBytes(int $bytes): array
    {
        $units = ['b', 'kb', 'mb', 'gb', 'tb'];
        $value = (float) $bytes;
        $unitIndex = 0;
        while ($value >= 1024.0 && $unitIndex < \count($units) - 1) {
            $value /= 1024.0;
            ++$unitIndex;
        }

        return [
            'size_value' => number_format($value, $unitIndex === 0 ? 0 : 2),
            'size_unit_key' => 'widget.summary.size_unit_'.$units[$unitIndex],
        ];
    }

    private function formatDuration(float $seconds): string
    {
        $total = (int) round($seconds);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        if ($hours > 0) {
            return \sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return \sprintf('%d:%02d', $minutes, $secs);
    }

    private function formatFrameRate(float $fps): string
    {
        $formatted = rtrim(rtrim(\sprintf('%.3f', $fps), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
