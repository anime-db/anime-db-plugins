#!/usr/bin/env php
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

/*
 * Compiles the dependency-injection container of a single plugin the way the host registers it.
 *
 * Usage: php tools/compile-plugin-container.php plugins/<id>
 * Exit code: 0 when the container compiles or the plugin ships no `src/`, 1 otherwise.
 *
 * The host registers every class of a code plugin's `src/` as an autowired, autoconfigured
 * service: `->defaults()->autowire()->autoconfigure()->load($namespacePrefix, $srcDir)`. No
 * other gate models that step, so a plugin whose container does not compile (for example a
 * constructor argument typed with a class whose own constructor is private) passes tests,
 * static analysis and the validator, and breaks the whole user's container at install time.
 *
 * Only the registration step is modelled, nothing of the host is copied:
 *  - roots: implementations of the seven plugin interfaces are tagged and consumed through a
 *    tagged iterator (what the host's pass does), and so are `#[AsController]` services and
 *    `EventSubscriberInterface` implementations (kept by the framework). Without roots an
 *    unused service is removed on compile and the error would never surface;
 *  - what the constructors of plugin services ask for from outside the plugin namespace is
 *    registered as a synthetic service only if it is on the allow-list of the host's surface
 *    (PSR interfaces, plugin contracts, Twig\Environment, CsrfTokenManagerInterface); any other external type stays
 *    unresolved and fails compilation, as it does in the host. Classes of the plugin
 *    namespace are never replaced: those are exactly what is being checked.
 *
 * Known limits: only constructor parameters of plain and union types are scanned. Members of
 * intersection / DNF types, `#[Required]` setters and `#[Autowire]` are not, so a plugin that
 * injects an allow-listed host service that way fails this gate although the host would
 * compile it.
 *
 * As in analyse-plugin.php / test-plugin.php, the environment is this repository's vendor/;
 * the plugin's own autoloading is only its `src/` mapped to `AnimeDb\Plugins\<StudlyId>\`.
 * The plugin's classes are loaded (reflected) by the compiler, so symlinks under `src/` are
 * refused rather than followed.
 */

function locateAutoloader(): string
{
    foreach (['/../vendor/autoload.php', '/../../../autoload.php'] as $candidate) {
        $path = __DIR__.$candidate;
        if (is_file($path)) {
            return $path;
        }
    }

    fwrite(\STDERR, "Could not find vendor/autoload.php. Run `composer install` first.\n");
    exit(1);
}

require locateAutoloader();

use AnimeDb\PluginContracts\Background\BackgroundTaskHandlerInterface;
use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;

const ROOT_TAG = 'animedb.plugin_container_gate.root';

/** `animedb-wikidata` => `AnimedbWikidata`. */
function studly(string $id): string
{
    return implode('', array_map(ucfirst(...), preg_split('/[^A-Za-z0-9]+/', $id, -1, \PREG_SPLIT_NO_EMPTY) ?: []));
}

/** What the host provides to plugin services (exact names); anything else is not injectable. */
const HOST_SERVICES = [
    Twig\Environment::class,
    Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class,
    // PSR interfaces the host aliases.
    Psr\Log\LoggerInterface::class,
    Psr\Http\Client\ClientInterface::class,
    Psr\Http\Message\RequestFactoryInterface::class,
    Psr\Http\Message\StreamFactoryInterface::class,
    Psr\Http\Message\UriFactoryInterface::class,
    // Host aliases in services.yaml.
    AnimeDb\PluginContracts\Download\DownloadServiceInterface::class,
    AnimeDb\PluginContracts\Media\MediaLibraryInterface::class,
    AnimeDb\PluginContracts\Media\MediaProbeInterface::class,
    // Services injected by the host's scope passes.
    AnimeDb\PluginContracts\Catalog\CatalogReaderInterface::class,
    AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface::class,
    AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface::class,
    AnimeDb\PluginContracts\Settings\SettingsStoreInterface::class,
    AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface::class,
    AnimeDb\PluginContracts\Folder\RecordFolderIndexInterface::class,
    AnimeDb\PluginContracts\Folder\RecordFolderWriterInterface::class,
    AnimeDb\PluginContracts\Manifest\OwnManifestInterface::class,
];

function isHostService(string $name): bool
{
    return \in_array($name, HOST_SERVICES, true);
}

function containsSymlink(string $directory): bool
{
    if (is_link($directory)) {
        return true;
    }

    $found = false;
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $fileInfo) use (&$found): bool {
            if ($fileInfo->isLink()) {
                $found = true;

                return false;
            }

            return true;
        },
    );

    // The callback does the inspecting; see the same walk in test-plugin.php.
    foreach (new RecursiveIteratorIterator($filter) as $ignored) {
    }

    return $found;
}

$pluginDir = $_SERVER['argv'][1] ?? null;
if ($pluginDir === null || $pluginDir === '') {
    fwrite(\STDERR, "Usage: php tools/compile-plugin-container.php <plugin-dir>\n");
    exit(1);
}

$resolvedPluginDir = realpath($pluginDir);
if ($resolvedPluginDir === false || !is_dir($resolvedPluginDir)) {
    fwrite(\STDERR, \sprintf("Plugin directory \"%s\" does not exist.\n", $pluginDir));
    exit(1);
}

$srcDir = $resolvedPluginDir.'/src';
if (!is_dir($srcDir)) {
    fwrite(\STDOUT, \sprintf("SKIP: \"%s\" ships no src/ — no container to compile.\n", $pluginDir));
    exit(0);
}

if (containsSymlink($srcDir)) {
    fwrite(\STDERR, \sprintf("\"%s\" contains a symlink under src/, which the gate does not follow.\n", $pluginDir));
    exit(1);
}

$namespacePrefix = 'AnimeDb\\Plugins\\'.studly(basename($resolvedPluginDir)).'\\';

spl_autoload_register(static function (string $class) use ($namespacePrefix, $srcDir): void {
    if (!str_starts_with($class, $namespacePrefix)) {
        return;
    }

    $file = $srcDir.'/'.str_replace('\\', '/', substr($class, \strlen($namespacePrefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
});

try {
    $container = new ContainerBuilder();

    $rootInterfaces = [
        SyncInterface::class,
        FillerInterface::class,
        SearchByPluginInterface::class,
        EntryWidgetInterface::class,
        CatalogWidgetInterface::class,
        SettingsPageInterface::class,
        BackgroundTaskHandlerInterface::class,
        EventSubscriberInterface::class,
    ];
    foreach ($rootInterfaces as $interface) {
        if (!interface_exists($interface)) {
            throw new LogicException(\sprintf('Root interface "%s" is not available in this repository\'s vendor/.', $interface));
        }

        $container->registerForAutoconfiguration($interface)->addTag(ROOT_TAG);
    }
    $container->registerAttributeForAutoconfiguration(
        AsController::class,
        static function (ChildDefinition $definition): void {
            $definition->addTag(ROOT_TAG);
        },
    );

    $container->register('animedb.plugin_container_gate.consumer', stdClass::class)
        ->setPublic(true)
        ->setArguments([new TaggedIteratorArgument(ROOT_TAG)]);

    $prototype = (new Definition())->setAutowired(true)->setAutoconfigured(true);
    $loader = new PhpFileLoader($container, new FileLocator($srcDir));
    $loader->registerClasses($prototype, $namespacePrefix, $srcDir.'/*');

    // What the host provides: the classes and interfaces from HOST_SERVICES a plugin service
    // asks for in its constructor. Anything else outside the plugin namespace stays unresolved,
    // so compile() fails the way the host does.
    foreach ($container->getDefinitions() as $definition) {
        $class = $definition->getClass();
        if ($class === null || !str_starts_with($class, $namespacePrefix) || !class_exists($class)) {
            continue;
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $types = match (true) {
                $type instanceof ReflectionNamedType => [$type],
                $type instanceof ReflectionUnionType => $type->getTypes(),
                default => [],
            };

            foreach ($types as $member) {
                if (!$member instanceof ReflectionNamedType) {
                    continue;
                }

                $name = $member->getName();
                if ($member->isBuiltin() || str_starts_with($name, $namespacePrefix) || $container->hasDefinition($name)) {
                    continue;
                }

                if (isHostService($name) && (class_exists($name) || interface_exists($name))) {
                    $container->register($name, $name)->setSynthetic(true);
                }
            }
        }
    }

    $container->compile();
} catch (Throwable $e) {
    fwrite(\STDERR, \sprintf("The container of \"%s\" does not compile:\n%s\n", $pluginDir, $e->getMessage()));
    exit(1);
}

fwrite(\STDOUT, \sprintf("OK: the container of \"%s\" compiles.\n", $pluginDir));
exit(0);
