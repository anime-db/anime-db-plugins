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

namespace AnimeDb\Plugins\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The root README table "Плагины" must list every official (`animedb-*`) plugin with its manifest type.
 *
 * The check is one-directional on purpose: a table row without a directory is fine, because the row
 * lands in an infrastructure PR before the plugin PR (the PR gate allows only one plugin per PR).
 */
final class ReadmePluginsTableTest extends TestCase
{
    public function testRootReadmeListsAllOfficialPlugins(): void
    {
        $root = \dirname(__DIR__);
        $readme = file_get_contents($root.'/README.md');
        self::assertIsString($readme);

        self::assertSame([], $this->findProblems($readme, $root.'/plugins'));
    }

    public function testMissingRowIsReported(): void
    {
        $dir = $this->makePlugins(['animedb-a' => 'integration', 'animedb-b' => 'local']);
        $readme = $this->readme(['| [`animedb-a`](plugins/animedb-a/README.md) | `integration` | x |']);

        $problems = $this->findProblems($readme, $dir);

        self::assertCount(1, $problems);
        self::assertStringContainsString('animedb-b', $problems[0]);
    }

    public function testTypeMismatchIsReported(): void
    {
        $dir = $this->makePlugins(['animedb-a' => 'integration']);
        $readme = $this->readme(['| [`animedb-a`](plugins/animedb-a/README.md) | `local` | x |']);

        $problems = $this->findProblems($readme, $dir);

        self::assertCount(1, $problems);
        self::assertStringContainsString('animedb-a', $problems[0]);
        self::assertStringContainsString('integration', $problems[0]);
        self::assertStringContainsString('local', $problems[0]);
    }

    public function testRowWithoutDirectoryAndForeignVendorAreIgnored(): void
    {
        $dir = $this->makePlugins(['animedb-a' => 'integration', 'johnsmith-example' => 'local']);
        $readme = $this->readme([
            '| [`animedb-a`](plugins/animedb-a/README.md) | `integration` | x |',
            '| [`animedb-future`](plugins/animedb-future/README.md) | `local` | x |',
        ]);

        self::assertSame([], $this->findProblems($readme, $dir));
    }

    public function testRowOutsidePluginsSectionDoesNotCount(): void
    {
        $dir = $this->makePlugins(['animedb-a' => 'integration']);
        $readme = $this->readme([])."\n## Другое\n\n| [`animedb-a`](x) | `integration` | x |\n";

        self::assertCount(1, $this->findProblems($readme, $dir));
    }

    /**
     * @return list<string>
     */
    private function findProblems(string $readme, string $pluginsDir): array
    {
        $types = [];
        $inSection = false;
        foreach (preg_split('/\R/', $readme) ?: [] as $line) {
            if (str_starts_with($line, '## ')) {
                $inSection = trim(substr($line, 3)) === 'Плагины';

                continue;
            }
            if (!$inSection || !str_starts_with(ltrim($line), '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            if (\count($cells) < 2) {
                continue;
            }
            if (preg_match('/`([^`]+)`/', $cells[0], $id) === 1 && preg_match('/`([^`]+)`/', $cells[1], $type) === 1) {
                $types[$id[1]] = $type[1];
            }
        }

        $problems = [];
        $manifests = glob($pluginsDir.'/animedb-*/manifest.json') ?: [];
        sort($manifests);
        foreach ($manifests as $manifestPath) {
            $id = basename(\dirname($manifestPath));
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $expected = \is_array($manifest) && \is_string($manifest['type'] ?? null) ? $manifest['type'] : null;
            if (!isset($types[$id])) {
                $problems[] = \sprintf('Plugin "%s" has no row in the README "Плагины" table.', $id);
            } elseif ($types[$id] !== $expected) {
                $problems[] = \sprintf(
                    'Plugin "%s": README table type "%s", manifest type "%s".',
                    $id,
                    $types[$id],
                    (string) $expected,
                );
            }
        }

        return $problems;
    }

    /**
     * @param list<string> $rows
     */
    private function readme(array $rows): string
    {
        return "# Title\n\n## Плагины\n\n| Плагин | Тип | Назначение |\n|---|---|---|\n".implode("\n", $rows)."\n";
    }

    /**
     * @param array<string, string> $plugins id => type
     */
    private function makePlugins(array $plugins): string
    {
        $dir = sys_get_temp_dir().'/readme-table-'.bin2hex(random_bytes(6));
        foreach ($plugins as $id => $type) {
            mkdir($dir.'/'.$id, 0o777, true);
            file_put_contents($dir.'/'.$id.'/manifest.json', json_encode(['id' => $id, 'type' => $type]));
        }
        $this->tmp[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $dir) {
            foreach (glob($dir.'/*/manifest.json') ?: [] as $f) {
                unlink($f);
                rmdir(\dirname($f));
            }
            @rmdir($dir);
        }
    }
}
