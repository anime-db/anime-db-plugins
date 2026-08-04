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
 * Publishes the signed registry (`plugins-registry.json` + `plugins-registry.json.sig`) to the
 * root `dir` of every configured mirror, so a mirror is a self-sufficient source: a client can
 * fetch both the registry and the assets it references from the same host.
 *
 * Unlike {@see MirrorAssetPublisher} (version-immutable `<id>/<version>/<file>` tree, never
 * overwritten), the registry is **mutable** — it changes on every release (new content, new
 * `sequence`, new signature) — so both files are uploaded **unconditionally, overwriting** the
 * previous copy. There is no cross-file atomicity over FTP; the registry is uploaded before its
 * signature, so a client hitting the brief window sees a fresh registry against a stale signature,
 * fails verification, and safely keeps its last valid cached registry (see anime-db-desktop#292).
 *
 * Ordering "assets before registry" is the caller's concern (CI): assets are pushed in the
 * release workflow before this runs in the registry workflow. This class only pushes the registry.
 */
final class MirrorRegistryPublisher
{
    public const REGISTRY_FILE = 'plugins-registry.json';
    public const SIGNATURE_FILE = 'plugins-registry.json.sig';

    public function __construct(
        private readonly MirrorTransport $transport,
    ) {
    }

    /**
     * @param array<string, MirrorCredential> $mirrors as returned by
     *                                                 {@see MirrorCredentialsParser::parse()}
     */
    public function publish(array $mirrors, string $registryPath, string $signaturePath): void
    {
        foreach ([$registryPath, $signaturePath] as $localPath) {
            if (!is_file($localPath)) {
                throw new \RuntimeException(\sprintf('File "%s" does not exist.', $localPath));
            }
        }

        foreach ($mirrors as $credential) {
            $root = rtrim($credential->dir, '/');
            // Registry first, signature last: the signature "activates" trust, so a client in the
            // between-uploads window degrades to its cached registry rather than trusting a mismatch.
            $this->transport->uploadFile($credential, $registryPath, $root.'/'.self::REGISTRY_FILE);
            $this->transport->uploadFile($credential, $signaturePath, $root.'/'.self::SIGNATURE_FILE);
        }
    }
}
