<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\Tools;

/**
 * Write-side transport to one mirror, abstracted away from {@see MirrorAssetPublisher} so its
 * publish-order/idempotency logic can be unit-tested without a real FTP(S) server. The only
 * production implementation is {@see FtpMirrorTransport}.
 */
interface MirrorTransport
{
    public function fileExists(MirrorCredential $credential, string $remotePath): bool;

    /**
     * Must create any missing intermediate directories of $remotePath itself — a mirror is a
     * plain FTP root with no directory structure beyond what gets created on demand.
     */
    public function uploadFile(MirrorCredential $credential, string $localPath, string $remotePath): void;
}
