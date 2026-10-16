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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\Controller;

use AnimeDb\PluginContracts\Catalog\AnimeView;
use AnimeDb\PluginContracts\Folder\ForeignFileException;
use AnimeDb\PluginContracts\Folder\RecordHasNoFolderException;
use AnimeDb\PluginContracts\Folder\StorageNotWritableException;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\Plugins\AnimedbFolderInfo\Controller\WriteFolderController;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderService;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeCatalog;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeFolderWriter;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakePluginDataStore;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbFolderInfo\Widget\FolderWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class WriteFolderControllerTest extends TestCase
{
    private FakeFolderWriter $writer;
    private bool $csrfValid = true;

    /**
     * @return iterable<string, array{\Throwable, string, bool}>
     */
    public static function exceptions(): iterable
    {
        yield 'foreign' => [new ForeignFileException(), 'The file was changed outside AnimeDB', true];
        yield 'no folder' => [new RecordHasNoFolderException(), 'The record has no folder of its own', false];
        yield 'unavailable' => [new StorageUnavailableException(), 'The storage is unavailable', true];
        yield 'not writable' => [new StorageNotWritableException(), 'The storage is read-only', false];
    }

    #[DataProvider('exceptions')]
    public function testWriterExceptionsBecomeWidgetStatusesAndCreateNoFiles(\Throwable $e, string $text, bool $hasButton): void
    {
        $this->writer = new FakeFolderWriter();
        $this->writer->throwOnWriteCover = $e;

        $html = $this->post([])->getContent();

        self::assertIsString($html);
        self::assertStringContainsString($text, $html);
        self::assertSame($hasButton, str_contains($html, '<button'));
        self::assertSame([], $this->writer->files);
    }

    public function testForeignFileOffersOverwriteWhichRetriesEverythingWithOverwrite(): void
    {
        $this->writer = new FakeFolderWriter();
        $this->writer->throwOnWriteCover = new ForeignFileException();
        $html = (string) $this->post([])->getContent();

        self::assertStringContainsString('name="overwrite" value="1"', $html);
        self::assertStringContainsString('Overwrite', $html);

        $this->writer->throwOnWriteCover = null;
        $this->writer->calls = [];
        $html = (string) $this->post(['overwrite' => '1'])->getContent();

        self::assertSame([['animedb-cover', true], ['animedb-info.html', true]], $this->writer->calls);
        self::assertStringContainsString('Written to the folder', $html);
        self::assertStringNotContainsString('name="overwrite"', $html);
    }

    public function testSuccessfulWriteRendersWrittenStatus(): void
    {
        $this->writer = new FakeFolderWriter();

        $html = (string) $this->post([])->getContent();

        self::assertStringContainsString('Written to the folder', $html);
        self::assertStringContainsString('animedb-info.html', implode(',', array_keys($this->writer->files)));
    }

    public function testInvalidCsrfWritesNothing(): void
    {
        $this->writer = new FakeFolderWriter();
        $this->csrfValid = false;

        $html = (string) $this->post([])->getContent();

        self::assertStringContainsString('Invalid CSRF token', $html);
        self::assertSame([], $this->writer->calls);
    }

    public function testUnknownRecordIs404(): void
    {
        $this->writer = new FakeFolderWriter();

        self::assertSame(404, $this->post([], false)->getStatusCode());
        self::assertSame([], $this->writer->files);
    }

    /**
     * @param array<string, string> $params
     */
    private function post(array $params, bool $recordExists = true): \Symfony\Component\HttpFoundation\Response
    {
        $twig = StubTwigFactory::create();
        $view = $recordExists ? new AnimeView('Title', [], null, [], [], null, [], null) : null;
        $folder = new FolderService($this->writer, new FakeCatalog($view), new FakePluginDataStore(), $twig);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(
            fn (CsrfToken $token): bool => $this->csrfValid && $token->getId() === WriteFolderController::CSRF_TOKEN_ID,
        );

        $controller = new WriteFolderController($folder, new FolderWidget($folder, $twig), $csrf);

        return $controller(new Request([], ['_token' => 't'] + $params), 7);
    }
}
