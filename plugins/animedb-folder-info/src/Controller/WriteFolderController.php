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


namespace AnimeDb\Plugins\AnimedbFolderInfo\Controller;

use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderService;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderStatus;
use AnimeDb\Plugins\AnimedbFolderInfo\Widget\FolderWidget;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The widget's button: a CSRF-protected POST that writes the files synchronously and answers
 * with the re-rendered widget, which the HTMX swap puts in place of the old one. With
 * `overwrite=1` (the "Overwrite" button) a file that is not ours is replaced.
 */
#[AsController]
final class WriteFolderController
{
    public const CSRF_TOKEN_ID = 'animedb_folder_info_write';

    public function __construct(
        private readonly FolderService $folder,
        private readonly FolderWidget $widget,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function __invoke(Request $request, int $id): Response
    {
        $anime = new AnimeId($id);

        try {
            $token = $request->request->getString('_token');
            $overwrite = $request->request->getString('overwrite') === '1';
        } catch (\Throwable) {
            return new Response($this->widget->renderStatus($anime, $this->safeStatus($anime), 'widget.folder.error.invalid_form'));
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            return new Response($this->widget->renderStatus($anime, $this->safeStatus($anime), 'widget.folder.error.invalid_csrf'));
        }

        try {
            $status = $this->folder->write($anime, $overwrite);
        } catch (\DomainException) {
            return new Response('', Response::HTTP_NOT_FOUND);
        } catch (\Throwable) {
            return new Response($this->widget->renderStatus($anime, $this->safeStatus($anime), 'widget.folder.error.write_failed'));
        }

        return new Response($this->widget->renderStatus($anime, $status, null));
    }

    /**
     * The status for an error response: inspecting the folder may fail for the very reason the
     * request did, and the error handler must not turn that into a 500.
     */
    private function safeStatus(AnimeId $anime): FolderStatus
    {
        try {
            return $this->folder->status($anime);
        } catch (\Throwable) {
            return FolderStatus::Unavailable;
        }
    }
}
