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
 * domain), must define the same set of keys as its siblings, must not use curly-brace
 * `{name}` syntax in any value, and, for every key shared by more than one locale, must use
 * the same set of `%name%` placeholders across all of them.
 *
 * The same catalogs are also checked against core-wide translation conventions (issue #60),
 * since a plugin's catalog and the core's are the same user-facing interface split across two
 * repositories: no `|` character in any value (this project has no Symfony pluralization
 * anywhere — a countable string is phrased without grammatical agreement instead, e.g.
 * `"label: %count%"`, which stays valid regardless of how many plural forms a locale's grammar
 * has); no catalog file name carrying the `+intl-icu` suffix (the ICU MessageFormat domain, the
 * other half of that same "no pluralization" decision); and no empty value for any key (a
 * missing translation is a defect, not a valid fallback to another locale). All of these are
 * errors, not warnings. Shared Russian terminology is documented in `.claude-docs/conventions.md`
 * as a recommendation rather than gated here — see that file for why.
 *
 * A manifest `name`/`description` equal to a key present in the plugin's own catalog is also an
 * error: the manifest is a self-sufficient descriptor consumed with no translation catalog loaded
 * at all (market registry build, the pre-activation install UI, this validator itself), so a key
 * placed there would render literally instead of resolving to anything.
 *
 * For `translation` plugins specifically, the manifest `locales` list must match the set of
 * locales actually shipped as `translations/messages.<locale>.yaml` files, in both directions: a
 * declared locale with no catalog, and a catalog with no declared locale, are both errors. The
 * application resolves the language switcher and `Accept-Language` negotiation from the manifest
 * `locales` alone, so a mismatch is invisible to every other check here (key parity, placeholders,
 * domain) yet silently breaks the language a user picks. A catalog file already rejected by an
 * earlier check (wrong domain, unsupported format, symlink) is excluded from this comparison, so
 * one bad file does not also produce a locale-mismatch error alongside its own.
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
     *
     * Extend this list in a **separate, plugin-free pull request that lands before** the one
     * adding the plugin itself — not in the same commit. The `Gate — one plugin / only its code`
     * step in `.github/workflows/pr-validation.yml` rejects any pull request that touches
     * `plugins/<id>/` together with a path outside it, and this file is such a path. A pull
     * request doing both at once cannot pass CI, however correct its contents are.
     *
     * Adding an id here before the plugin exists is harmless: the entry only lifts the reserved
     * vendor restriction for that exact id, it does not assert that the plugin is present.
     *
     * @var list<string>
     */
    private const OFFICIAL_PLUGIN_IDS = ['animedb-shikimori', 'animedb-language-pack'];

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
        [$translationErrors, $catalogKeys] = $this->validateTranslations($pluginDir, $pluginId, $pluginType, $manifestData);
        $errors = [...$errors, ...$translationErrors];
        $errors = [...$errors, ...self::validateManifestLiterals($manifestData, $catalogKeys)];

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
     * File-name suffix (before the ".<locale>.yaml" segment) Symfony uses to mark an ICU
     * MessageFormat catalog. The core has no pluralized strings at all — no ICU domain, no "|"
     * alternatives, no `transChoice` call — precisely so a countable string like "N of M" stays
     * "label: %count%" across every locale regardless of how many plural forms that locale's
     * grammar has (Russian: 3, German: 2, Japanese: 1, Arabic: 6). Introducing pluralization is a
     * project-wide architectural decision (it also implies switching to curly-brace ICU
     * placeholder syntax), so a single plugin must not be able to opt into it on its own. See
     * issue #60.
     */
    private const ICU_DOMAIN_SUFFIX = '+intl-icu';

    /**
     * @param array<string, mixed> $manifestData
     *
     * @return array{0: list<string>, 1: list<string>} errors, and the union of every translation
     *                                                 key found across all locales of this
     *                                                 plugin's own catalog (used by the caller to
     *                                                 flag a manifest "name"/"description" that
     *                                                 duplicates one of them)
     */
    private function validateTranslations(string $pluginDir, string $pluginId, ?PluginType $type, array $manifestData): array
    {
        $translationsDir = $pluginDir.'/translations';

        // A plugin without a "translations/" directory is valid; the check is inapplicable.
        if (!is_dir($translationsDir)) {
            return [[], []];
        }

        // Same reasoning as the "src/" checks above: a symlinked "translations/" must not be
        // followed, and is_dir() alone would follow it.
        if (is_link($translationsDir)) {
            return [['Plugin "translations/" must not be a symlink.'], []];
        }

        $expectedDomain = match ($type) {
            PluginType::Translation => self::CORE_TRANSLATION_DOMAIN,
            PluginType::Integration, PluginType::Local => $pluginId,
            null => null,
        };

        $errors = [];
        $catalogs = [];
        $catalogValues = [];

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

            if (str_ends_with($domain, self::ICU_DOMAIN_SUFFIX)) {
                $errors[] = \sprintf(
                    'Translation file "translations/%s" uses the ICU domain suffix "%s"; this project has no pluralized strings — phrase a countable string without grammatical agreement instead, e.g. "label: %%count%%".',
                    $entry,
                    self::ICU_DOMAIN_SUFFIX,
                );
                continue;
            }

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

            $flat = self::flattenTranslationKeyValues($data);

            foreach ($flat as $key => $value) {
                if ($value === null || $value === '') {
                    $errors[] = \sprintf(
                        'Translation value for key "%s" in "translations/%s" is empty; a missing translation is a defect, not a valid fallback to another locale.',
                        $key,
                        $entry,
                    );
                    continue;
                }

                if (!\is_string($value)) {
                    continue;
                }

                $foundBraces = array_filter(['{', '}'], static fn (string $char): bool => str_contains($value, $char));
                if ($foundBraces !== []) {
                    $errors[] = \sprintf(
                        'Translation value for key "%s" in "translations/%s" contains "%s"; this project uses the "%%name%%" placeholder syntax, not curly-brace "{name}" syntax.',
                        $key,
                        $entry,
                        implode('", "', $foundBraces),
                    );
                }

                if (str_contains($value, '|')) {
                    $errors[] = \sprintf(
                        'Translation value for key "%s" in "translations/%s" contains "|"; this project has no Symfony pluralization — phrase a countable string without grammatical agreement instead, e.g. "label: %%count%%".',
                        $key,
                        $entry,
                    );
                }
            }

            $keys = array_keys($flat);
            sort($keys);
            $catalogs[$locale] = $keys;
            $catalogValues[$locale] = $flat;
        }

        $allKeys = array_values(array_unique(array_merge([], ...array_values($catalogs))));

        // Only for "translation" plugins: their manifest "locales" is the single source the
        // application uses to populate the language switcher (AvailableLocalesProvider), so it
        // must match the catalogs actually shipped, in both directions — see the class docblock.
        // $catalogs only contains locales of files that passed every check above (domain, ICU
        // suffix, naming pattern, YAML syntax, symlink), so a file already rejected for one of
        // those reasons does not also trigger a locale-mismatch error here.
        $declaredLocales = $type === PluginType::Translation ? self::declaredLocales($manifestData) : null;

        return [
            [
                ...$errors,
                ...self::compareTranslationCatalogs($catalogs),
                ...self::comparePlaceholders($catalogValues),
                ...($declaredLocales !== null ? self::compareDeclaredLocales($declaredLocales, array_keys($catalogs)) : []),
            ],
            $allKeys,
        ];
    }

    /**
     * @param array<string, mixed> $manifestData
     *
     * @return ?list<string> the declared "locales" as a plain string list, or null when the field
     *                       is missing or malformed ({@see ManifestValidator} already reports that
     *                       separately, so {@see self::compareDeclaredLocales()} must not run
     *                       against it)
     */
    private static function declaredLocales(array $manifestData): ?array
    {
        $locales = $manifestData['locales'] ?? null;
        if (!\is_array($locales) || !array_is_list($locales) || $locales === []) {
            return null;
        }

        foreach ($locales as $locale) {
            if (!\is_string($locale) || $locale === '') {
                return null;
            }
        }

        return $locales;
    }

    /**
     * @param list<string> $declaredLocales locales from the manifest "locales" field
     * @param list<string> $catalogLocales  locales of "translations/messages.<locale>.yaml" files
     *                                      that passed every other check
     *
     * @return list<string>
     */
    private static function compareDeclaredLocales(array $declaredLocales, array $catalogLocales): array
    {
        $onlyDeclared = array_values(array_diff($declaredLocales, $catalogLocales));
        $onlyCataloged = array_values(array_diff($catalogLocales, $declaredLocales));

        if ($onlyDeclared === [] && $onlyCataloged === []) {
            return [];
        }

        sort($onlyDeclared);
        sort($onlyCataloged);

        $details = [];
        if ($onlyDeclared !== []) {
            $details[] = \sprintf(
                'declared in manifest "locales" but missing a "translations/messages.<locale>.yaml" catalog: %s',
                implode(', ', $onlyDeclared),
            );
        }
        if ($onlyCataloged !== []) {
            $details[] = \sprintf(
                'has a "translations/messages.<locale>.yaml" catalog but is not declared in manifest "locales": %s',
                implode(', ', $onlyCataloged),
            );
        }

        return [\sprintf('Plugin of type "translation" locale mismatch: %s.', implode('; ', $details))];
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
     * Flattens a nested translation catalog into a map of dot-notation leaf key paths to their
     * (still untranslated-syntax) leaf values.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function flattenTranslationKeyValues(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (\is_array($value)) {
                $flat = [...$flat, ...self::flattenTranslationKeyValues($value, $path)];
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
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

    /**
     * Compares the `%name%` placeholder composition of translation values across locales, for
     * every key present in more than one locale. A key missing from one of the two locales
     * being compared is silently skipped here — that gap is {@see self::compareTranslationCatalogs()}'s
     * job, and this check must not duplicate it.
     *
     * The comparison is order-independent (the target language's grammar may require a
     * different placeholder order within the sentence, which is not a defect), but
     * count-sensitive: a placeholder repeated twice in one locale and once in another is
     * treated as a mismatch, since losing a duplicate is normally a copy-paste typo rather than
     * an intentional per-language difference.
     *
     * @param array<string, array<string, mixed>> $catalogValues keys are locales, values are
     *                                                           flat key => translation value maps
     *
     * @return list<string>
     */
    private static function comparePlaceholders(array $catalogValues): array
    {
        $locales = array_keys($catalogValues);
        sort($locales);

        $errors = [];

        foreach ($locales as $i => $localeA) {
            foreach (\array_slice($locales, $i + 1) as $localeB) {
                $keys = array_values(array_intersect(
                    array_keys($catalogValues[$localeA]),
                    array_keys($catalogValues[$localeB]),
                ));
                sort($keys);

                foreach ($keys as $key) {
                    $valueA = $catalogValues[$localeA][$key];
                    $valueB = $catalogValues[$localeB][$key];

                    if (!\is_string($valueA) || !\is_string($valueB)) {
                        continue;
                    }

                    $placeholdersA = self::extractPlaceholders($valueA);
                    $placeholdersB = self::extractPlaceholders($valueB);

                    $onlyInA = self::multisetDiff($placeholdersA, $placeholdersB);
                    $onlyInB = self::multisetDiff($placeholdersB, $placeholdersA);

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
                        'Placeholder mismatch for key "%s" between "%s" and "%s" locales: %s.',
                        $key,
                        $localeA,
                        $localeB,
                        implode('; ', $details),
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string> placeholder names found in $value, in occurrence order, duplicates kept
     */
    private static function extractPlaceholders(string $value): array
    {
        preg_match_all('/%([A-Za-z_][A-Za-z0-9_]*)%/', $value, $matches);

        return $matches[1];
    }

    /**
     * Multiset difference: the items of $a left over once each of them has been paired off
     * against a matching (but not yet claimed) item of $b. Unlike {@see array_diff()}, a value
     * repeated more times in $a than in $b yields that many leftover entries instead of none.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    private static function multisetDiff(array $a, array $b): array
    {
        $remaining = $b;
        $diff = [];

        foreach ($a as $item) {
            $index = array_search($item, $remaining, true);
            if ($index === false) {
                $diff[] = $item;
            } else {
                unset($remaining[$index]);
            }
        }

        return $diff;
    }

    /**
     * The manifest is a self-sufficient descriptor (see the class docblock and the "Manifest
     * `name`/`description` are literals, not translation keys" gotcha in this repo's
     * `.claude-docs/gotchas.md`): it is read in places with no translation catalog loaded at
     * all, so a translation key placed in "name" or "description" would render literally.
     *
     * @param array<string, mixed> $manifestData
     * @param list<string>         $catalogKeys  every key from the plugin's own "translations/"
     *                                           catalog, across all locales
     *
     * @return list<string>
     */
    private static function validateManifestLiterals(array $manifestData, array $catalogKeys): array
    {
        $errors = [];

        foreach (['name', 'description'] as $field) {
            $value = $manifestData[$field] ?? null;
            if (!\is_string($value)) {
                continue;
            }

            if (\in_array($value, $catalogKeys, true)) {
                $errors[] = \sprintf(
                    'manifest.json "%s" is "%s", which is also a key in this plugin\'s own translation catalog; the manifest is a self-sufficient descriptor consumed with no translations catalog loaded (market registry build, the pre-activation install UI, this validator), so it must be a literal string, not a key.',
                    $field,
                    $value,
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
