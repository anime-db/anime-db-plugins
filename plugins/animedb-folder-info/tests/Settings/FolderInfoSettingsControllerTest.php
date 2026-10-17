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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\Settings;

use AnimeDb\Plugins\AnimedbFolderInfo\Settings\AutoFillSettings;
use AnimeDb\Plugins\AnimedbFolderInfo\Settings\FolderInfoSettingsController;
use AnimeDb\Plugins\AnimedbFolderInfo\Settings\FolderInfoSettingsPage;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeQueue;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeSettingsStore;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\StubTwigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class FolderInfoSettingsControllerTest extends TestCase
{
    private FakeSettingsStore $store;
    private FakeQueue $queue;

    public function testInvalidCsrfChangesAndQueuesNothing(): void
    {
        foreach (['save', 'fill_all'] as $action) {
            $response = $this->post(['action' => $action, 'auto' => '0'], 'wrong');

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Invalid CSRF token', (string) $response->getContent());
        }
        self::assertSame([], $this->store->data);
        self::assertSame([], $this->queue->tasks);
    }

    public function testSaveStoresTheSwitchAndKeepsOtherKeys(): void
    {
        $this->post(['action' => 'save'], 'ok', ['run_id' => 'r']);
        self::assertSame(['run_id' => 'r', 'auto_fill' => false], $this->store->data);

        $this->post(['action' => 'save', 'auto' => '1'], 'ok');
        self::assertTrue($this->store->data['auto_fill']);
    }

    public function testFillAllStartsANewRunWithAResetCursor(): void
    {
        $this->post(['action' => 'fill_all'], 'ok', ['run_id' => 'old', 'run_cursor' => 99, 'last_run' => ['written' => 1]]);

        $run = $this->store->data['run_id'];
        self::assertNotSame('old', $run);
        self::assertNull($this->store->data['run_cursor']);
        self::assertArrayNotHasKey('last_run', $this->store->data);
        self::assertCount(1, $this->queue->tasks);
        $task = $this->queue->tasks[0];
        self::assertSame('fill-all', $task->name);
        self::assertSame(['run' => $run, 'after' => null, 'counters' => [
            'written' => 0, 'modified' => 0, 'no_folder' => 0, 'unavailable' => 0, 'not_writable' => 0,
        ]], $task->payload);
    }

    public function testUnknownActionChangesNothing(): void
    {
        $this->post(['action' => 'nope'], 'ok');

        self::assertSame([], $this->store->data);
        self::assertSame([], $this->queue->tasks);
    }

    public function testPageShowsTheScanHintOnlyForANonZeroNotWritableCounter(): void
    {
        $withHint = $this->page(['last_run' => ['written' => 1, 'not_writable' => 2, 'finished_at' => 1700000000]]);
        self::assertStringContainsString('has not been scanned yet', $withHint);
        self::assertStringContainsString('Writing not allowed: 2', $withHint);

        $without = $this->page(['last_run' => ['written' => 1, 'not_writable' => 0, 'finished_at' => 1700000000]]);
        self::assertStringNotContainsString('has not been scanned yet', $without);
    }

    public function testPageSwitchIsOnByDefaultAndOffWhenStoredOff(): void
    {
        self::assertStringContainsString(' checked', $this->page([]));
        self::assertStringNotContainsString(' checked', $this->page(['auto_fill' => false]));
    }

    public function testPageFormsCarryTheTokenTheControllerAccepts(): void
    {
        $html = $this->page([]);

        self::assertStringContainsString('value="stub-csrf-token-for-animedb_folder_info_settings"', $html);
    }

    public function testPageIsASingleFragmentRootedAtItsOwnContainer(): void
    {
        $html = $this->page([]);

        self::assertStringStartsWith('<div id="animedb-folder-info-settings">', ltrim($html));
        self::assertStringNotContainsString('shikimori', $html);
        self::assertSame(substr_count($html, '<div'), substr_count($html, '</div>'));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function page(array $settings): string
    {
        return (new FolderInfoSettingsPage(new AutoFillSettings(new FakeSettingsStore($settings)), StubTwigFactory::create()))->render();
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $initial
     */
    private function post(array $params, string $token, array $initial = []): \Symfony\Component\HttpFoundation\Response
    {
        $this->store = new FakeSettingsStore($initial);
        $this->queue = new FakeQueue();
        $settings = new AutoFillSettings($this->store);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $t): bool => $t->getId() === 'animedb_folder_info_settings' && $t->getValue() === 'ok');
        $controller = new FolderInfoSettingsController(
            $settings,
            $this->queue,
            new FolderInfoSettingsPage($settings, StubTwigFactory::create()),
            $csrf,
        );

        return $controller(new Request([], ['_token' => $token] + $params));
    }
}
