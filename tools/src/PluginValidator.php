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
use Composer\Semver\Semver;
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
 * The manifest `locales` list must match the set of locales actually shipped as catalogs in the
 * domain this plugin's `type` resolves (`translations/messages.<locale>.yaml` for `translation`,
 * `translations/<plugin-id>.<locale>.yaml` for `integration`/`local`), in both directions: a
 * declared locale with no catalog, and a catalog with no declared locale, are both errors. The
 * application resolves the language switcher, `Accept-Language` negotiation and the plugin
 * listing from the manifest `locales` alone, so a mismatch is invisible to every other check here
 * (key parity, placeholders, domain) yet silently breaks the language a user picks, or advertises
 * one the plugin never ships. A catalog file already rejected by an earlier check (wrong domain,
 * unsupported format, symlink) is excluded from this comparison, so one bad file does not also
 * produce a locale-mismatch error alongside its own.
 *
 * For `translation`, `locales` is required by the shared {@see ManifestValidator} contract
 * itself, so this validator only compares it once it parses cleanly. For `integration`/`local`
 * the contract allows the field to be entirely absent — it only matters once the plugin ships
 * catalogs of its own — so this validator additionally requires it exactly when such catalogs
 * are present, and forbids it when they are not.
 *
 * A `translation` plugin's manifest must also declare `translation_keys_count`, an integer equal
 * to the number of leaf keys in its own catalog (the same union used for the `name`/`description`
 * check above); the field is rejected outright for `integration`/`local`, mirroring how the shared
 * {@see ManifestValidator} contract itself gates its own known fields per type (e.g. `features` is
 * required for `integration` but rejected for `translation`/`local`). This field has no
 * counterpart in that contract — it is validated only here, by this monorepo's own tooling.
 *
 * A manifest `ui` block is checked against the plugin directory's actual contents — something
 * {@see ManifestValidator} cannot do itself, since it validates decoded manifest data alone
 * and also parses manifests from `plugins-registry.json`, where no plugin directory sits
 * alongside it (see the class-level docblock of {@see \AnimeDb\PluginContracts\Manifest\PluginUi}).
 * Every path listed in `ui.css`/`ui.js` must exist as a file, and its `realpath()` must resolve
 * inside `<pluginDir>/assets/` (guards against a symlink escaping that directory; the path shape
 * itself — relative, `assets/`-prefixed, no `..` segments — is already {@see ManifestValidator}'s
 * job). A plugin declaring `ui` must also pin `require.plugin-contracts` to a range covering
 * `0.19`, the contract version that introduced the field — an older pin promises a host that
 * cannot read it. Independently of whether `ui` is declared at all, every file actually present
 * under `assets/` must carry one of the extensions the host serves (`.css`, `.js`, `.svg`,
 * `.png`, `.webp`, `.woff2`); anything else would ship inside the plugin ZIP
 * ({@see PublishedContentRules} does not exclude `assets/`) with no route ever serving it.
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

    /**
     * Extensions the application actually has a route for. A file under `assets/` with any
     * other extension still ships inside the plugin ZIP (see {@see PublishedContentRules}) but
     * is dead weight nothing ever serves.
     *
     * @var list<string>
     */
    private const ALLOWED_ASSET_EXTENSIONS = ['css', 'js', 'svg', 'png', 'webp', 'woff2'];

    /**
     * Lowest `anime-db/plugin-contracts` version whose manifest schema knows the `ui` field.
     * A plugin declaring `ui` while pinning a `require.plugin-contracts` range that excludes
     * this version promises a host old enough to never read it.
     */
    private const UI_CONTRACT_VERSION = '0.19.0';

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
        $errors = [...$errors, ...self::validateTranslationKeysCount($manifestData, $pluginType, $catalogKeys)];
        $errors = [...$errors, ...$this->validateUiAssets($pluginDir, $manifestData)];

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

        // array_values(): ManifestValidator::validate() объявляет ManifestValidationError[],
        // то есть массив с произвольными ключами, а метод обещает список. Под PHPStan 1 это
        // не проверялось.
        $errors = array_values(array_map(
            static fn ($error): string => \sprintf('%s: %s', $error->field, $error->message),
            $this->manifestValidator->validate($data),
        ));

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
        return array_values(array_filter(
            self::findFiles($dir),
            static fn (string $file): bool => pathinfo($file, \PATHINFO_EXTENSION) === 'php',
        ));
    }

    /**
     * @return list<string> every file under $dir, recursively, sorted
     */
    private static function findFiles(string $dir): array
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
            if ($fileInfo->isFile()) {
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
     * The single definition of which translation domain a plugin's catalogs must use, driven by
     * its manifest `type` (see the class docblock and {@see self::CORE_TRANSLATION_DOMAIN}).
     * Used both to validate each catalog file's own name and, via the same value, to know which
     * catalogs the manifest "locales" field is compared against — one domain computation, reused
     * for both checks.
     */
    private static function expectedTranslationDomain(?PluginType $type, string $pluginId): ?string
    {
        return match ($type) {
            PluginType::Translation => self::CORE_TRANSLATION_DOMAIN,
            PluginType::Integration, PluginType::Local => $pluginId,
            null => null,
        };
    }

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
        $expectedDomain = self::expectedTranslationDomain($type, $pluginId);

        // A plugin without a "translations/" directory is valid for "translation" (reported
        // separately by validateTranslationSource()) and for a plugin of unrecognised type; for
        // "integration"/"local" no catalogs at all still means the "locales" presence rule
        // below applies (no catalogs -> the field must be absent).
        if (!is_dir($translationsDir)) {
            $localeErrors = [];
            if ($type === PluginType::Integration || $type === PluginType::Local) {
                \assert($expectedDomain !== null);
                $localeErrors = self::validateDeclaredLocales($type, $manifestData, [], $expectedDomain);
            }

            return [$localeErrors, []];
        }

        // Same reasoning as the "src/" checks above: a symlinked "translations/" must not be
        // followed, and is_dir() alone would follow it.
        if (is_link($translationsDir)) {
            return [['Plugin "translations/" must not be a symlink.'], []];
        }

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

        // The manifest "locales" field is the single source the application uses to populate
        // the language switcher and plugin listing, so it must match the catalogs actually
        // shipped in the domain this plugin type resolves ($expectedDomain, computed above for
        // the per-file domain check) — see the class docblock. $catalogs only contains locales
        // of files that passed every check above (domain, ICU suffix, naming pattern, YAML
        // syntax, symlink), so a file already rejected for one of those reasons does not also
        // trigger a locale-mismatch error here.
        $localeErrors = $type !== null && $expectedDomain !== null
            ? self::validateDeclaredLocales($type, $manifestData, array_keys($catalogs), $expectedDomain)
            : [];

        return [
            [
                ...$errors,
                ...self::compareTranslationCatalogs($catalogs),
                ...self::comparePlaceholders($catalogValues),
                ...$localeErrors,
            ],
            $allKeys,
        ];
    }

    /**
     * Dispatches the manifest "locales" vs. catalog comparison per plugin type.
     *
     * For "translation" the field is required by the shared {@see ManifestValidator} contract
     * itself, so a missing/malformed field is already reported there and this method only
     * compares it against the catalogs when it parses cleanly.
     *
     * For "integration"/"local" the contract allows the field to be entirely absent (it is
     * only meaningful when the plugin ships catalogs of its own), so this method additionally
     * enforces the presence rule the contract does not: catalogs present makes the field
     * required, catalogs absent makes it forbidden.
     *
     * @param array<string, mixed> $manifestData
     * @param list<string>         $catalogLocales locales of catalog files that passed every
     *                                             earlier check (domain, format, symlink, ...)
     *
     * @return list<string>
     */
    private static function validateDeclaredLocales(
        PluginType $type,
        array $manifestData,
        array $catalogLocales,
        string $expectedDomain,
    ): array {
        $hasLocalesField = \array_key_exists('locales', $manifestData);
        $declaredLocales = self::declaredLocales($manifestData);
        $catalogFilePattern = \sprintf('translations/%s.<locale>.yaml', $expectedDomain);

        if ($type === PluginType::Integration || $type === PluginType::Local) {
            if ($catalogLocales === []) {
                return $hasLocalesField
                    ? [\sprintf(
                        'Plugin of type "%s" declares a "locales" field in manifest.json but has no "%s" catalogs; remove the field, or add the corresponding catalogs.',
                        $type->value,
                        $catalogFilePattern,
                    )]
                    : [];
            }

            if (!$hasLocalesField) {
                return [\sprintf(
                    'Plugin of type "%s" has "%s" catalogs but is missing a "locales" field in manifest.json.',
                    $type->value,
                    $catalogFilePattern,
                )];
            }
        }

        // $declaredLocales is null both when the field is missing and when it is present but
        // malformed (not a list of non-empty strings); the latter is already reported by
        // ManifestValidator, so this comparison must not run against it either way.
        return $declaredLocales !== null
            ? self::compareDeclaredLocales($type, $declaredLocales, $catalogLocales, $catalogFilePattern)
            : [];
    }

    /**
     * @param array<string, mixed> $manifestData
     *
     * @return ?list<string> the declared "locales" as a plain string list (possibly empty), or
     *                       null when the field is missing or malformed — not a list of
     *                       non-empty strings ({@see ManifestValidator} already reports the
     *                       malformed case separately, so {@see self::compareDeclaredLocales()}
     *                       must not run against it either way). Callers that need to tell
     *                       "missing" apart from "malformed" check `array_key_exists('locales',
     *                       ...)` themselves; this method deliberately does not conflate that
     *                       distinction away.
     */
    private static function declaredLocales(array $manifestData): ?array
    {
        $locales = $manifestData['locales'] ?? null;
        if (!\is_array($locales) || !array_is_list($locales)) {
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
     * @param list<string> $catalogLocales  locales of catalog files in the domain this plugin
     *                                      type resolves that passed every other check
     *
     * @return list<string>
     */
    private static function compareDeclaredLocales(
        PluginType $type,
        array $declaredLocales,
        array $catalogLocales,
        string $catalogFilePattern,
    ): array {
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
                'declared in manifest "locales" but missing a "%s" catalog: %s',
                $catalogFilePattern,
                implode(', ', $onlyDeclared),
            );
        }
        if ($onlyCataloged !== []) {
            $details[] = \sprintf(
                'has a "%s" catalog but is not declared in manifest "locales": %s',
                $catalogFilePattern,
                implode(', ', $onlyCataloged),
            );
        }

        return [\sprintf('Plugin of type "%s" locale mismatch: %s.', $type->value, implode('; ', $details))];
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

    /**
     * The `translation_keys_count` manifest field has no place in the shared
     * {@see ManifestValidator} contract from `anime-db/plugin-contracts` — an unknown field
     * simply passes it through unvalidated — so it is checked only here, the market
     * registry's own tooling (see the "second manifest definition" gotcha in this repo's
     * `.claude-docs/gotchas.md`).
     *
     * For `type: translation` it is required, must be an integer, and must equal the number
     * of leaf keys in the plugin's own catalog — the same union {@see self::validateTranslations()}
     * already computed for {@see self::validateManifestLiterals()}. For `integration`/`local`
     * it must be absent, the same way the contract itself rejects `locales` for those types.
     *
     * @param array<string, mixed> $manifestData
     * @param list<string>         $catalogKeys  every key from the plugin's own "translations/"
     *                                           catalog, across all locales
     *
     * @return list<string>
     */
    private static function validateTranslationKeysCount(array $manifestData, ?PluginType $type, array $catalogKeys): array
    {
        $hasField = \array_key_exists('translation_keys_count', $manifestData);

        if ($type === PluginType::Integration || $type === PluginType::Local) {
            return $hasField
                ? [\sprintf('Field "translation_keys_count" is not allowed for type "%s".', $type->value)]
                : [];
        }

        if ($type !== PluginType::Translation) {
            return [];
        }

        if (!$hasField) {
            return ['Plugin of type "translation" is missing a "translation_keys_count" field in manifest.json.'];
        }

        $value = $manifestData['translation_keys_count'];
        if (!\is_int($value)) {
            return [\sprintf(
                'manifest.json "translation_keys_count" must be an integer, got %s.',
                get_debug_type($value),
            )];
        }

        $actualCount = \count($catalogKeys);
        if ($value !== $actualCount) {
            return [\sprintf(
                'manifest.json "translation_keys_count" is %d, but the "translations/" catalog actually has %d leaf key(s).',
                $value,
                $actualCount,
            )];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $manifestData
     *
     * @return list<string>
     */
    private function validateUiAssets(string $pluginDir, array $manifestData): array
    {
        $errors = [];

        $ui = $manifestData['ui'] ?? null;
        if (\is_array($ui)) {
            $errors = [...$errors, ...$this->validateDeclaredUiPaths($pluginDir, $ui)];
            $errors = [...$errors, ...self::validateUiContractPin($manifestData)];
        }

        $errors = [...$errors, ...$this->validateAssetsAllowlist($pluginDir)];

        return $errors;
    }

    /**
     * Every path declared in `ui.css`/`ui.js` must exist as a file inside the plugin
     * directory, and must not resolve (after following symlinks via `realpath()`) outside
     * `<pluginDir>/assets/`. The path *shape* — relative, `assets/`-prefixed, matching
     * extension, no `..` segments — is already {@see ManifestValidator}'s job; this only
     * checks it against the filesystem, which that shared package cannot do (see the class
     * docblock).
     *
     * @param array<string, mixed> $ui decoded manifest "ui" object
     *
     * @return list<string>
     */
    private function validateDeclaredUiPaths(string $pluginDir, array $ui): array
    {
        $errors = [];
        $assetsRoot = realpath($pluginDir.'/assets');

        foreach (['css', 'js'] as $key) {
            $paths = $ui[$key] ?? null;
            if (!\is_array($paths)) {
                // Malformed "ui.css"/"ui.js" shape is already reported by ManifestValidator.
                continue;
            }

            foreach ($paths as $path) {
                if (!\is_string($path)) {
                    continue;
                }

                $fullPath = $pluginDir.'/'.$path;
                if (!is_file($fullPath)) {
                    $errors[] = \sprintf('Field "ui.%s" declares "%s", which does not exist in the plugin directory.', $key, $path);
                    continue;
                }

                $realPath = realpath($fullPath);
                if ($realPath === false || $assetsRoot === false || !str_starts_with($realPath, $assetsRoot.\DIRECTORY_SEPARATOR)) {
                    $errors[] = \sprintf('Field "ui.%s" entry "%s" resolves outside the plugin\'s "assets/" directory.', $key, $path);
                }
            }
        }

        return $errors;
    }

    /**
     * A plugin declaring `ui` relies on a host new enough to read the field, so it must pin
     * `require.plugin-contracts` to a range that covers {@see self::UI_CONTRACT_VERSION}.
     *
     * @param array<string, mixed> $manifestData
     *
     * @return list<string>
     */
    private static function validateUiContractPin(array $manifestData): array
    {
        $require = $manifestData['require'] ?? null;
        $constraint = \is_array($require) ? ($require['plugin-contracts'] ?? null) : null;

        if (!\is_string($constraint) || $constraint === '') {
            return [\sprintf(
                'Plugin declares "ui" but is missing a "require.plugin-contracts" constraint covering %s, the contract version that introduced the field.',
                self::UI_CONTRACT_VERSION,
            )];
        }

        try {
            $covers = Semver::satisfies(self::UI_CONTRACT_VERSION, $constraint);
        } catch (\UnexpectedValueException) {
            // Malformed constraint is already reported by ManifestValidator.
            return [];
        }

        if (!$covers) {
            return [\sprintf(
                'Plugin declares "ui" but "require.plugin-contracts" constraint "%s" does not cover %s, the contract version that introduced the field.',
                $constraint,
                self::UI_CONTRACT_VERSION,
            )];
        }

        return [];
    }

    /**
     * Independently of whether `ui` is declared at all, every file actually *published*
     * under `assets/` must carry an extension the host serves. Files {@see
     * PublishedContentRules} excludes from the plugin ZIP (e.g. ".gitkeep") are skipped: the
     * rule's rationale is that an unlisted extension ships dead weight with no route ever
     * serving it, which is false for a file that never ships. The check is also
     * case-sensitive: the host route only matches a lower-case extension, and {@see
     * ManifestValidator} already rejects an upper-case one in "ui.css"/"ui.js", so allowing
     * it here for an undeclared asset would be a second, looser rule for the same directory.
     *
     * @return list<string>
     */
    private function validateAssetsAllowlist(string $pluginDir): array
    {
        $assetsDir = $pluginDir.'/assets';

        // Same reasoning as the "src/"/"translations/" checks above: a symlinked "assets/"
        // must not be followed.
        if (is_link($assetsDir)) {
            return ['Plugin "assets/" must not be a symlink.'];
        }

        if (!is_dir($assetsDir)) {
            return [];
        }

        $errors = [];
        foreach (self::findFiles($assetsDir) as $file) {
            $relativePath = substr($file, \strlen($pluginDir) + 1);
            if (PublishedContentRules::isExcluded($relativePath)) {
                continue;
            }

            $extension = pathinfo($file, \PATHINFO_EXTENSION);
            if (!\in_array($extension, self::ALLOWED_ASSET_EXTENSIONS, true)) {
                $errors[] = \sprintf(
                    'File "%s" has an extension not on the host\'s allow-list ("%s"); it would ship inside the plugin ZIP with no route ever serving it.',
                    $relativePath,
                    implode('", "', self::ALLOWED_ASSET_EXTENSIONS),
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
