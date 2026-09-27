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

namespace AnimeDb\Plugins\AnimedbMediaDetails\Tests\Widget;

use AnimeDb\PluginContracts\Media\MediaLibraryInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\WidgetPendingUpdate;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture\FakeBackgroundTaskQueue;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture\FakeMediaProbe;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture\FakePluginDataStore;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Widget\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbMediaDetails\Widget\SummaryWidget;
use PHPUnit\Framework\TestCase;

final class SummaryWidgetTest extends TestCase
{
    public function testMetadataNameMatchesManifestFeatureKeyAndPattern(): void
    {
        $metadata = SummaryWidget::metadata();

        self::assertSame('summary', $metadata->name);
        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $metadata->name);
        self::assertSame('widget.summary.title', $metadata->titleKey);
        self::assertSame('widget.summary.description', $metadata->descriptionKey);
    }

    public function testConstructorNeverDependsOnMediaLibraryInterface(): void
    {
        $constructor = new \ReflectionMethod(SummaryWidget::class, '__construct');

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            self::assertNotSame(MediaLibraryInterface::class, $name, \sprintf(
                'Constructor parameter "%s" must not type-hint %s: this widget only reads the already-collected payload.',
                $parameter->getName(),
                MediaLibraryInterface::class,
            ));
        }
    }

    public function testRenderQueuesFirstProbeAndMarksPendingWhenPayloadIsEmpty(): void
    {
        $store = new FakePluginDataStore();
        $tasks = new FakeBackgroundTaskQueue();
        $widget = $this->buildWidget($store, $tasks, new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        self::assertStringStartsWith(WidgetPendingUpdate::MARKER, $html);
        self::assertCount(1, $tasks->submitted);
    }

    public function testRenderReturnsSummaryWithoutMarkerOrTaskWhenIdentityMatches(): void
    {
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), [
            'files_total' => 1,
            'files' => ['a.mkv' => $this->fileEntry()],
        ]);
        $tasks = new FakeBackgroundTaskQueue();
        $widget = $this->buildWidget($store, $tasks, new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        self::assertStringStartsNotWith(WidgetPendingUpdate::MARKER, $html);
        self::assertSame([], $tasks->submitted);
        self::assertStringContainsString('Files: 1', $html);
    }

    public function testRenderShowsExistingSummaryAndQueuesRefreshWhenIdentityDiverges(): void
    {
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), [
            'files_total' => 1,
            'files' => ['a.mkv' => $this->fileEntry(['probe_identity' => 'stale-identity'])],
        ]);
        $tasks = new FakeBackgroundTaskQueue();
        $widget = $this->buildWidget($store, $tasks, new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        self::assertStringStartsNotWith(WidgetPendingUpdate::MARKER, $html);
        self::assertCount(1, $tasks->submitted);
        self::assertStringContainsString('Files: 1', $html);
    }

    public function testRenderReturnsNeutralMessageWhenProberMarkedUnavailableInPayload(): void
    {
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), ['prober_unavailable_at' => '2026-01-01T00:00:00+00:00']);
        $tasks = new FakeBackgroundTaskQueue();
        $widget = $this->buildWidget($store, $tasks, new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        self::assertStringStartsNotWith(WidgetPendingUpdate::MARKER, $html);
        self::assertSame([], $tasks->submitted);
        self::assertStringContainsString('File details are unavailable.', $html);
    }

    public function testRenderDoesNotThrowAndReturnsNeutralMessageWhenProbeIdentityThrows(): void
    {
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), [
            'files_total' => 1,
            'files' => ['a.mkv' => $this->fileEntry()],
        ]);
        $tasks = new FakeBackgroundTaskQueue();
        $prober = new FakeMediaProbe();
        $prober->setUnavailable();
        $widget = $this->buildWidget($store, $tasks, $prober);

        $html = $widget->render(new AnimeId(1));

        self::assertStringStartsNotWith(WidgetPendingUpdate::MARKER, $html);
        self::assertSame([], $tasks->submitted);
        self::assertStringContainsString('File details are unavailable.', $html);
    }

    public function testRenderReportsUnparsedFilesWithoutQueuingWhenNothingCouldBeParsed(): void
    {
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), ['files_total' => 3, 'files' => []]);
        $tasks = new FakeBackgroundTaskQueue();
        $widget = $this->buildWidget($store, $tasks, new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        self::assertStringStartsNotWith(WidgetPendingUpdate::MARKER, $html);
        self::assertSame([], $tasks->submitted);
        self::assertStringContainsString('Could not read details for any file.', $html);
    }

    public function testRenderShowsBothValuesWhenTwoFilesDisagreeOnResolution(): void
    {
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), [
            'files_total' => 2,
            'files' => [
                'a.mkv' => $this->fileEntry(['video' => [$this->videoTrack(['width' => 1920, 'height' => 1080])]]),
                'b.mkv' => $this->fileEntry(['video' => [$this->videoTrack(['width' => 1280, 'height' => 720])]]),
            ],
        ]);
        $widget = $this->buildWidget($store, new FakeBackgroundTaskQueue(), new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('1920x1080', $html);
        self::assertStringContainsString('1280x720', $html);
        self::assertStringNotContainsString('and %count% more', $html);
    }

    public function testRenderShowsThreeValuesAndMoreCountWhenFiveFilesDisagree(): void
    {
        $resolutions = [
            [1920, 1080],
            [1280, 720],
            [720, 480],
            [640, 360],
            [3840, 2160],
        ];

        $files = [];
        foreach ($resolutions as $i => [$width, $height]) {
            $files['file'.$i.'.mkv'] = $this->fileEntry(['video' => [$this->videoTrack(['width' => $width, 'height' => $height])]]);
        }

        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), ['files_total' => 5, 'files' => $files]);
        $widget = $this->buildWidget($store, new FakeBackgroundTaskQueue(), new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('1920x1080', $html);
        self::assertStringContainsString('1280x720', $html);
        self::assertStringContainsString('720x480', $html);
        self::assertStringNotContainsString('640x360', $html);
        self::assertStringNotContainsString('3840x2160', $html);
        self::assertStringContainsString('and 2 more', $html);
    }

    public function testRenderMarksAudioLanguageAsNotPresentInEveryFile(): void
    {
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), [
            'files_total' => 2,
            'files' => [
                'a.mkv' => $this->fileEntry(['audio' => [$this->audioTrack(['language' => 'ja'])]]),
                'b.mkv' => $this->fileEntry(['audio' => []]),
            ],
        ]);
        $widget = $this->buildWidget($store, new FakeBackgroundTaskQueue(), new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        self::assertStringContainsString('ja', $html);
        self::assertStringContainsString('not present in every file', $html);
    }

    public function testRenderOutputNeverContainsDisallowedHtmlElements(): void
    {
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(1), [
            'files_total' => 1,
            'files' => [
                'a.mkv' => $this->fileEntry([
                    'video' => [$this->videoTrack()],
                    'audio' => [$this->audioTrack()],
                    'subtitles' => [$this->subtitleTrack()],
                ]),
            ],
        ]);
        $widget = $this->buildWidget($store, new FakeBackgroundTaskQueue(), new FakeMediaProbe());

        $html = $widget->render(new AnimeId(1));

        foreach (['<table', '<tr', '<td', '<dl', '<small', '<code'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }

    private function buildWidget(FakePluginDataStore $store, FakeBackgroundTaskQueue $tasks, FakeMediaProbe $prober): SummaryWidget
    {
        return new SummaryWidget($store, $tasks, $prober, StubTwigFactory::create());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function fileEntry(array $overrides = []): array
    {
        return array_replace([
            'handle_size' => 100,
            'handle_mtime' => 1_000,
            'probe_identity' => 'fake-prober-identity',
            'size' => 100,
            'container' => 'matroska,webm',
            'duration' => 120.0,
            'bit_rate' => 128_000,
            'track_count' => 0,
            'video' => [],
            'audio' => [],
            'subtitles' => [],
            'other' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function videoTrack(array $overrides = []): array
    {
        return array_replace([
            'index' => 0,
            'codec' => 'h264',
            'profile' => 'High',
            'width' => 1920,
            'height' => 1080,
            'pixel_format' => 'yuv420p',
            'frame_rate' => 23.976,
            'bit_rate' => 4_000_000,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function audioTrack(array $overrides = []): array
    {
        return array_replace([
            'index' => 1,
            'codec' => 'aac',
            'channels' => 2,
            'channel_layout' => 'stereo',
            'sample_rate' => 48_000,
            'bit_rate' => 192_000,
            'language' => 'ja',
            'title' => null,
            'is_default' => true,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function subtitleTrack(array $overrides = []): array
    {
        return array_replace([
            'index' => 2,
            'codec' => 'ass',
            'language' => 'ru',
            'title' => null,
            'is_default' => false,
            'is_forced' => false,
        ], $overrides);
    }
}
