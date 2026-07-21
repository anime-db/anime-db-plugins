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

use AnimeDb\PluginContracts\Manifest\ManifestValidator;

/**
 * Validates a single plugin directory of this monorepo/market registry.
 *
 * Checks, in order: `manifest.json` presence and JSON syntax, its content via the shared
 * {@see ManifestValidator} from `anime-db/plugin-contracts`, that the manifest `id` matches
 * the plugin's directory name, that a `src/` directory exists, that every class declared
 * under `src/**.php` sits in the namespace PSR-4 derives from the plugin id, that
 * `composer.json` declares a matching `autoload.psr-4` mapping to `src/`, and finally that
 * every `*.php` file in the plugin is syntactically valid (`php -l`).
 *
 * Collects every problem instead of stopping at the first one (mirrors
 * {@see ManifestValidator}'s own "report everything" approach), so a plugin author sees the
 * full list of what to fix in one CI run.
 */
final class PluginValidator
{
    /**
     * Vendor prefix reserved for plugins maintained in this monorepo. A community PR must not
     * be able to claim it for a new plugin id and impersonate an official one.
     */
    private const RESERVED_VENDOR = 'animedb';

    /**
     * Plugin ids already using {@see self::RESERVED_VENDOR} that are genuinely official.
     * Extend this list in the same commit that adds a new official plugin.
     *
     * @var list<string>
     */
    private const OFFICIAL_PLUGIN_IDS = ['animedb-shikimori'];

    public function __construct(
        private readonly ManifestValidator $manifestValidator = new ManifestValidator(),
    ) {
    }

    /**
     * @return list<string> human-readable problem descriptions; empty when the plugin is valid
     */
    public function validate(string $pluginDir): array
    {
        $pluginDir = rtrim($pluginDir, '/');

        if (!is_dir($pluginDir)) {
            return [\sprintf('Plugin directory "%s" does not exist.', $pluginDir)];
        }

        // is_dir() above follows symlinks, so a symlinked plugin root would otherwise let
        // findPhpFiles() scan a directory outside the intended plugin tree entirely (its
        // entries are real files, not links, so the recursive symlink filter never sees them).
        if (is_link($pluginDir)) {
            return [\sprintf('Plugin directory "%s" must not be a symlink.', $pluginDir)];
        }

        $errors = [];

        [$manifestErrors, $manifestId] = $this->validateManifest($pluginDir);
        $errors = [...$errors, ...$manifestErrors];

        $pluginId = $manifestId ?? basename($pluginDir);
        $errors = [...$errors, ...$this->validateSource($pluginDir, $pluginId)];
        $errors = [...$errors, ...$this->validateComposerJson($pluginDir, $pluginId)];
        $errors = [...$errors, ...$this->lintPhpFiles($pluginDir)];

        return $errors;
    }

    /**
     * @return array{0: list<string>, 1: ?string} error list and the manifest `id` (if readable)
     */
    private function validateManifest(string $pluginDir): array
    {
        $manifestPath = $pluginDir.'/manifest.json';
        if (!is_file($manifestPath)) {
            return [['manifest.json is missing in the plugin root.'], null];
        }

        $json = file_get_contents($manifestPath);
        if ($json === false) {
            return [[\sprintf('Failed to read "%s".', $manifestPath)], null];
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [[\sprintf('manifest.json is not valid JSON: %s', $exception->getMessage())], null];
        }

        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            return [['manifest.json must contain a JSON object at the top level.'], null];
        }

        $errors = array_map(
            static fn ($error): string => \sprintf('%s: %s', $error->field, $error->message),
            $this->manifestValidator->validate($data),
        );

        $manifestId = \is_string($data['id'] ?? null) ? $data['id'] : null;
        if ($manifestId !== null && $manifestId !== basename($pluginDir)) {
            $errors[] = \sprintf(
                'Manifest id "%s" does not match plugin directory name "%s".',
                $manifestId,
                basename($pluginDir),
            );
        }

        if ($manifestId !== null
            && explode('-', $manifestId, 2)[0] === self::RESERVED_VENDOR
            && !\in_array($manifestId, self::OFFICIAL_PLUGIN_IDS, true)
        ) {
            $errors[] = \sprintf(
                'Manifest id "%s" uses the reserved "%s" vendor, which is limited to official plugins of this monorepo.',
                $manifestId,
                self::RESERVED_VENDOR,
            );
        }

        return [$errors, $manifestId];
    }

    /**
     * @return list<string>
     */
    private function validateSource(string $pluginDir, string $pluginId): array
    {
        $srcDir = $pluginDir.'/src';
        if (!is_dir($srcDir)) {
            return ['Plugin is missing a "src/" directory.'];
        }

        // Same reasoning as the $pluginDir check in validate(): a symlinked "src/" is itself
        // the scan root passed to findPhpFiles(), so the recursive symlink filter (which only
        // inspects entries found *inside* the scan) never gets a chance to reject it.
        if (is_link($srcDir)) {
            return ['Plugin "src/" must not be a symlink.'];
        }

        $expectedRootNamespace = self::expectedRootNamespace($pluginId);
        $errors = [];

        foreach (self::findPhpFiles($srcDir) as $file) {
            $namespace = self::declaredNamespace($file);
            $relativeDir = trim(substr(\dirname($file), \strlen($srcDir)), '/');
            $expectedNamespace = $relativeDir === ''
                ? $expectedRootNamespace
                : $expectedRootNamespace.'\\'.str_replace('/', '\\', $relativeDir);

            if ($namespace !== $expectedNamespace) {
                $errors[] = \sprintf(
                    'File "%s" declares namespace "%s", expected "%s".',
                    substr($file, \strlen($pluginDir) + 1),
                    $namespace ?? '(none)',
                    $expectedNamespace,
                );
            }
        }

        return $errors;
    }

    /**
     * The host loads a plugin's classes via its own `composer.json` `autoload.psr-4` map, not
     * via {@see self::validateSource()}'s namespace-vs-id check on the source files themselves.
     * A plugin can pass that check yet still fail to load at runtime if `composer.json` is
     * missing or maps the expected namespace to something other than `src/`.
     *
     * @return list<string>
     */
    private function validateComposerJson(string $pluginDir, string $pluginId): array
    {
        $composerPath = $pluginDir.'/composer.json';
        if (!is_file($composerPath)) {
            return ['composer.json is missing in the plugin root.'];
        }

        $json = file_get_contents($composerPath);
        if ($json === false) {
            return [\sprintf('Failed to read "%s".', $composerPath)];
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [\sprintf('composer.json is not valid JSON: %s', $exception->getMessage())];
        }

        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            return ['composer.json must contain a JSON object at the top level.'];
        }

        $expectedNamespace = self::expectedRootNamespace($pluginId).'\\';
        $psr4 = $data['autoload']['psr-4'] ?? null;

        if (!\is_array($psr4) || ($psr4[$expectedNamespace] ?? null) !== 'src/') {
            return [\sprintf(
                'composer.json "autoload.psr-4" must map "%s" to "src/" for the plugin host to load its classes.',
                $expectedNamespace,
            )];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function lintPhpFiles(string $pluginDir): array
    {
        $errors = [];

        foreach (self::findPhpFiles($pluginDir) as $file) {
            $output = [];
            exec(escapeshellarg(\PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                $errors[] = \sprintf(
                    'Syntax error in "%s": %s',
                    substr($file, \strlen($pluginDir) + 1),
                    implode(' ', $output),
                );
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function findPhpFiles(string $dir): array
    {
        $files = [];
        // Plugin source is untrusted: a symlink (e.g. pointing at "/" or "..") must not be
        // followed, or the scan could read files outside $dir or loop forever on a symlink
        // cycle. The callback filter excludes symlinks from the recursion itself, not just
        // from the result, so a symlinked directory is never descended into.
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $fileInfo): bool => !$fileInfo->isLink(),
        );
        $iterator = new \RecursiveIteratorIterator($filter);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function declaredNamespace(string $file): ?string
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $tokens = token_get_all($content);
        $namespace = null;
        $collecting = false;

        foreach ($tokens as $token) {
            if (\is_array($token) && $token[0] === \T_NAMESPACE) {
                $namespace = '';
                $collecting = true;
                continue;
            }

            if (!$collecting) {
                continue;
            }

            if (\is_array($token)) {
                if (\in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NS_SEPARATOR], true)) {
                    $namespace .= $token[1];
                }
            } elseif ($token === ';' || $token === '{') {
                break;
            }
        }

        return $namespace;
    }

    private static function expectedRootNamespace(string $pluginId): string
    {
        $studly = str_replace('-', '', ucwords($pluginId, '-'));

        return 'AnimeDb\\Plugins\\'.$studly;
    }
}
