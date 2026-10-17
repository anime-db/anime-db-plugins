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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Settings;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use AnimeDb\PluginContracts\Settings\ConcurrentWriteException;
use AnimeDb\Plugins\AnimedbFolderInfo\Background\FolderTaskHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The settings page's CSRF-protected POST: saves the automation switch or starts a
 * "fill all folders" run. Answers with the re-rendered page for the HTMX swap; errors travel
 * in a 200 too, as HTMX swaps only 2xx responses.
 */
#[AsController]
final class FolderInfoSettingsController
{
    public function __construct(
        private readonly AutoFillSettings $settings,
        private readonly BackgroundTaskQueueInterface $tasks,
        private readonly FolderInfoSettingsPage $page,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $token = $request->request->getString('_token');
            $action = $request->request->getString('action');
            $auto = $request->request->getString('auto') === '1';
        } catch (\Throwable) {
            return $this->respond(null, 'settings.error.invalid_form');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SettingsFields::CSRF_TOKEN_ID, $token))) {
            return $this->respond(null, 'settings.error.invalid_csrf');
        }

        try {
            switch ($action) {
                case SettingsFields::ACTION_SAVE:
                    $this->settings->setAutoFill($auto);

                    return $this->respond('settings.saved', null);
                case SettingsFields::ACTION_FILL_ALL:
                    $run = $this->settings->startRun();
                    $this->tasks->submit(new BackgroundTask(FolderTaskHandler::FILL_ALL, payload: [
                        'run' => $run,
                        'after' => null,
                        'counters' => FolderTaskHandler::ZERO_COUNTERS,
                    ]));

                    return $this->respond('settings.fill_all.started', null);
                default:
                    return $this->respond(null, 'settings.error.invalid_form');
            }
        } catch (ConcurrentWriteException) {
            return $this->respond(null, 'settings.error.concurrent_write');
        } catch (\Throwable) {
            return $this->respond(null, 'settings.error.save_failed');
        }
    }

    private function respond(?string $message, ?string $error): Response
    {
        try {
            return new Response($this->page->renderWith($message, $error));
        } catch (\Throwable) {
            return new Response('<p class="error">Could not render the settings form.</p>');
        }
    }
}
