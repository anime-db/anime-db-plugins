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

namespace AnimeDb\Plugins\Tools;

use AnimeDb\PluginContracts\Manifest\ManifestValidator;
use AnimeDb\PluginContracts\Manifest\PluginType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates a single plugin directory of this monorepo/market registry.
 *
 * Checks, in order: `manifest.json` presence and JSON syntax, its content via the shared
 * {@see ManifestValidator} from `anime-db/plugin-contracts`, that the manifest `id` matches
 * the plugin's directory name, and then a source-shape check that branches on the manifest
 * `type`:
 *
 * - `integration` (a regular code plugin) and `local` (a code plugin that only reacts to
 *   catalog events, see {@see PluginType}) are both treated as code plugins: a `src/`
 *   directory is required, every class declared under `src/**.php` must sit in the
 *   namespace PSR-4 derives from the plugin id, and every `*.php` file in the plugin must
 *   be syntactically valid (`php -l`). `local` has no `type`-specific contract requirement
 *   beyond that (it declares neither `features` nor `locales`), so it falls back to the
 *   same code-plugin checks as `integration`.
 * - `translation` is a purely declarative resource with no code (see {@see PluginType}):
 *   a `src/` directory is an error (there is nothing to run PHP-syntax or namespace checks
 *   against), while a `translations/` directory and a non-empty manifest `locales` list are
 *   required instead.
 *
 * An unrecognised/missing `type` (already reported by {@see ManifestValidator} itself) falls
 * back to the code-plugin checks, matching this validator's behaviour before `type` existed.
 *
 * Independently of the source-shape branch, if a `translations/` directory is present every
 * catalog it contains must use the domain the manifest `type` expects
 * (`<plugin-id>.<locale>.yaml` for `integration`/`local`, `messages.<locale>.yaml` for
 * `translation`, since the application resolves the core UI under a single `messages`
 * domain) and must define the same set of keys as its siblings.
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

        [$manifestErrors, $manifestId, $manifestData] = $this->validateManifest($pluginDir);
        $errors = [...$errors, ...$manifestErrors];

        $pluginId = $manifestId ?? basename($pluginDir);
        $pluginType = self::pluginType($manifestData);

        if ($pluginType === PluginType::Translation) {
            $errors = [...$errors, ...$this->validateTranslationSource($pluginDir, $manifestData)];
        } else {
            $errors = [...$errors, ...$this->validateSource($pluginDir, $pluginId)];
            $errors = [...$errors, ...$this->lintPhpFiles($pluginDir)];
        }

        // Каталоги переводов проверяются у плагина любого типа: у "translation" это его
        // единственное содержимое, у "integration"/"local" — его собственный домен.
        $errors = [...$errors, ...$this->validateTranslations($pluginDir, $pluginId, $pluginType)];

        return $errors;
    }

    /**
     * Returns the decoded manifest rather than just its `type`: callers need `locales` too,
     * and the type is derived from the same data via {@see self::pluginType()}.
     *
     * @return array{0: list<string>, 1: ?string, 2: array<string, mixed>} error list, the manifest
     *                                                                     `id` (if readable) and the
     *                                                                     decoded manifest data
     *                                                                     (empty when unreadable)
     */
    private function validateManifest(string $pluginDir): array
    {
        $manifestPath = $pluginDir.'/manifest.json';
        if (!is_file($manifestPath)) {
            return [['manifest.json is missing in the plugin root.'], null, []];
        }

        $json = file_get_contents($manifestPath);
        if ($json === false) {
            return [[\sprintf('Failed to read "%s".', $manifestPath)], null, []];
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [[\sprintf('manifest.json is not valid JSON: %s', $exception->getMessage())], null, []];
        }

        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            return [['manifest.json must contain a JSON object at the top level.'], null, []];
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

        return [$errors, $manifestId, $data];
    }

    /**
     * @param array<string, mixed> $manifestData
     */
    private static function pluginType(array $manifestData): ?PluginType
    {
        $type = $manifestData['type'] ?? null;

        return \is_string($type) ? PluginType::tryFrom($type) : null;
    }

    /**
     * @param array<string, mixed> $manifestData
     *
     * @return list<string>
     */
    private function validateTranslationSource(string $pluginDir, array $manifestData): array
    {
        $errors = [];

        $srcDir = $pluginDir.'/src';
        if (file_exists($srcDir) || is_link($srcDir)) {
            $errors[] = 'Plugin of type "translation" must not contain a "src/" directory: it is a declarative resource with no code.';
        }

        $translationsDir = $pluginDir.'/translations';
        if (!is_dir($translationsDir)) {
            $errors[] = 'Plugin of type "translation" is missing a "translations/" directory.';
        }

        $locales = $manifestData['locales'] ?? null;
        if (!\is_array($locales) || $locales === []) {
            $errors[] = 'Plugin of type "translation" must declare a non-empty "locales" list in manifest.json.';
        }

        return $errors;
    }

    /**
     * Source-shape check for code plugins (`integration` and `local`, see the class docblock).
     *
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

    /**
     * The application resolves the core UI's own catalogs under a single "messages" domain
     * (Symfony derives a catalog's domain from its file name: "<domain>.<locale>.<format>").
     * A "translation" plugin is a pure language pack that extends that core UI, so its
     * catalogs must use that same domain, or the application never loads them. An
     * "integration"/"local" plugin instead carries its own strings (settings, OAuth, widgets)
     * under a domain derived from its own id, and must not reach into the core's "messages"
     * domain.
     */
    private const CORE_TRANSLATION_DOMAIN = 'messages';

    /**
     * @return list<string>
     */
    private function validateTranslations(string $pluginDir, string $pluginId, ?PluginType $type): array
    {
        $translationsDir = $pluginDir.'/translations';

        // A plugin without a "translations/" directory is valid; the check is inapplicable.
        if (!is_dir($translationsDir)) {
            return [];
        }

        // Same reasoning as the "src/" checks above: a symlinked "translations/" must not be
        // followed, and is_dir() alone would follow it.
        if (is_link($translationsDir)) {
            return ['Plugin "translations/" must not be a symlink.'];
        }

        $expectedDomain = match ($type) {
            PluginType::Translation => self::CORE_TRANSLATION_DOMAIN,
            PluginType::Integration, PluginType::Local => $pluginId,
            null => null,
        };

        $errors = [];
        $catalogs = [];

        foreach (scandir($translationsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $file = $translationsDir.'/'.$entry;

            // Same reasoning as the "src/" checks above: a symlinked catalog must not be
            // followed.
            if (is_link($file)) {
                $errors[] = \sprintf('Translation catalog "translations/%s" must not be a symlink.', $entry);
                continue;
            }

            if (!is_file($file)) {
                continue;
            }

            if (!str_ends_with($entry, '.yaml')) {
                $errors[] = \sprintf(
                    'Translation catalog "translations/%s" has an unsupported format; only ".yaml" catalogs are supported.',
                    $entry,
                );
                continue;
            }

            $parsed = self::parseTranslationFileName($entry);
            if ($parsed === null) {
                $errors[] = \sprintf(
                    'Translation file "translations/%s" does not match the "<domain>.<locale>.yaml" naming pattern.',
                    $entry,
                );
                continue;
            }

            [$domain, $locale] = $parsed;

            if ($expectedDomain !== null && $type !== null && $domain !== $expectedDomain) {
                $errors[] = $type === PluginType::Translation
                    ? \sprintf(
                        'Translation file "translations/%s" uses domain "%s" instead of "%s"; a "translation" plugin catalog must be named "%s.<locale>.yaml", or the application never resolves it and none of its strings get translated.',
                        $entry,
                        $domain,
                        self::CORE_TRANSLATION_DOMAIN,
                        self::CORE_TRANSLATION_DOMAIN,
                    )
                    : \sprintf(
                        'Translation file "translations/%s" uses domain "%s" instead of "%s"; a "%s" plugin catalog must be named "%s.<locale>.yaml" — the "%s" domain belongs to the core application, not to a plugin.',
                        $entry,
                        $domain,
                        $expectedDomain,
                        $type->value,
                        $expectedDomain,
                        self::CORE_TRANSLATION_DOMAIN,
                    );
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                $errors[] = \sprintf('Failed to read "translations/%s".', $entry);
                continue;
            }

            try {
                $data = Yaml::parse($content);
            } catch (ParseException $exception) {
                $errors[] = \sprintf(
                    'Translation file "translations/%s" is not valid YAML: %s',
                    $entry,
                    $exception->getMessage(),
                );
                continue;
            }

            if (!\is_array($data)) {
                $errors[] = \sprintf(
                    'Translation file "translations/%s" must contain a mapping at the top level.',
                    $entry,
                );
                continue;
            }

            $keys = self::flattenTranslationKeys($data);
            sort($keys);
            $catalogs[$locale] = $keys;
        }

        return [...$errors, ...self::compareTranslationCatalogs($catalogs)];
    }

    /**
     * @return ?array{0: string, 1: string} [domain, locale], or null when $fileName doesn't
     *                                      match the "<domain>.<locale>.yaml" pattern
     */
    private static function parseTranslationFileName(string $fileName): ?array
    {
        $suffix = '.yaml';
        if (!str_ends_with($fileName, $suffix)) {
            return null;
        }

        $withoutSuffix = substr($fileName, 0, -\strlen($suffix));
        $firstDot = strpos($withoutSuffix, '.');
        if ($firstDot === false || $firstDot === 0) {
            return null;
        }

        $domain = substr($withoutSuffix, 0, $firstDot);
        $locale = substr($withoutSuffix, $firstDot + 1);

        return $locale === '' || str_contains($locale, '.') ? null : [$domain, $locale];
    }

    /**
     * Flattens a nested translation catalog into dot-notation leaf key paths.
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<string>
     */
    private static function flattenTranslationKeys(array $data, string $prefix = ''): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (\is_array($value)) {
                $keys = [...$keys, ...self::flattenTranslationKeys($value, $path)];
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, list<string>> $catalogs keys are locales, values are sorted flat key lists
     *
     * @return list<string>
     */
    private static function compareTranslationCatalogs(array $catalogs): array
    {
        $locales = array_keys($catalogs);
        sort($locales);

        $errors = [];

        foreach ($locales as $i => $localeA) {
            foreach (\array_slice($locales, $i + 1) as $localeB) {
                $onlyInA = array_values(array_diff($catalogs[$localeA], $catalogs[$localeB]));
                $onlyInB = array_values(array_diff($catalogs[$localeB], $catalogs[$localeA]));

                if ($onlyInA === [] && $onlyInB === []) {
                    continue;
                }

                $details = [];
                if ($onlyInA !== []) {
                    $details[] = \sprintf('only in "%s": %s', $localeA, implode(', ', $onlyInA));
                }
                if ($onlyInB !== []) {
                    $details[] = \sprintf('only in "%s": %s', $localeB, implode(', ', $onlyInB));
                }

                $errors[] = \sprintf(
                    'Translation key mismatch between "%s" and "%s" locales: %s.',
                    $localeA,
                    $localeB,
                    implode('; ', $details),
                );
            }
        }

        return $errors;
    }

    private static function expectedRootNamespace(string $pluginId): string
    {
        $studly = str_replace('-', '', ucwords($pluginId, '-'));

        return 'AnimeDb\\Plugins\\'.$studly;
    }
}
