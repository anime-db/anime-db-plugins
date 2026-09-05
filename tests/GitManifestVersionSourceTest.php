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

use AnimeDb\Plugins\Tools\BaseManifestReadFailedException;
use AnimeDb\Plugins\Tools\GitManifestVersionSource;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two outcomes issue #123 requires {@see GitManifestVersionSource::baseVersion()}
 * to tell apart: a plugin genuinely absent from the base branch (legitimate `null`) versus
 * the base version being unreadable at all (an unresolvable base ref, e.g. from a shallow
 * clone that never fetched it) — which must surface as
 * {@see BaseManifestReadFailedException} rather than be folded into the same `null`.
 */
final class GitManifestVersionSourceTest extends TestCase
{
    private ?string $repoRoot = null;

    protected function tearDown(): void
    {
        if ($this->repoRoot !== null) {
            self::removeDirectory($this->repoRoot);
        }
        $this->repoRoot = null;
    }

    public function testBaseVersionReadsVersionFromAnExistingRef(): void
    {
        $repoRoot = $this->initRepo();
        $this->writeManifest($repoRoot, 'vendor-name', '0.2.0');
        $baseSha = $this->commit($repoRoot, 'add plugin');

        $source = new GitManifestVersionSource($baseSha, $repoRoot);

        self::assertSame('0.2.0', $source->baseVersion('vendor-name'));
    }

    public function testBaseVersionIsNullWhenThePluginDoesNotExistOnTheBaseRef(): void
    {
        $repoRoot = $this->initRepo();
        $this->writeManifest($repoRoot, 'other-plugin', '0.1.0');
        $baseSha = $this->commit($repoRoot, 'unrelated plugin only');

        $source = new GitManifestVersionSource($baseSha, $repoRoot);

        self::assertNull($source->baseVersion('new-plugin'));
    }

    public function testBaseVersionThrowsWhenTheBaseRefCannotBeResolved(): void
    {
        $repoRoot = $this->initRepo();
        $this->writeManifest($repoRoot, 'vendor-name', '0.2.0');
        $this->commit($repoRoot, 'add plugin');

        // A syntactically valid but unresolvable commit sha — simulates a shallow clone
        // that never fetched the base commit, the scenario issue #123 is about.
        $source = new GitManifestVersionSource('0000000000000000000000000000000000000000', $repoRoot);

        $this->expectException(BaseManifestReadFailedException::class);

        $source->baseVersion('vendor-name');
    }

    public function testHeadVersionReadsVersionFromTheWorkingTree(): void
    {
        $repoRoot = $this->initRepo();
        $this->writeManifest($repoRoot, 'vendor-name', '0.3.0');

        $source = new GitManifestVersionSource('HEAD', $repoRoot);

        self::assertSame('0.3.0', $source->headVersion('vendor-name'));
    }

    public function testHeadVersionIsNullWhenTheManifestIsMissing(): void
    {
        $repoRoot = $this->initRepo();

        $source = new GitManifestVersionSource('HEAD', $repoRoot);

        self::assertNull($source->headVersion('vendor-name'));
    }

    private function initRepo(): string
    {
        $repoRoot = sys_get_temp_dir().'/git-manifest-version-source-test-'.bin2hex(random_bytes(8));
        mkdir($repoRoot, 0o777, true);
        $this->repoRoot = $repoRoot;

        self::runGit($repoRoot, ['init', '--quiet']);
        self::runGit($repoRoot, ['config', 'user.email', 'test@example.com']);
        self::runGit($repoRoot, ['config', 'user.name', 'Test']);

        return $repoRoot;
    }

    private function writeManifest(string $repoRoot, string $pluginId, string $version): void
    {
        $dir = $repoRoot.'/plugins/'.$pluginId;
        mkdir($dir, 0o777, true);
        file_put_contents($dir.'/manifest.json', json_encode(['version' => $version], \JSON_THROW_ON_ERROR));
    }

    private function commit(string $repoRoot, string $message): string
    {
        self::runGit($repoRoot, ['add', '.']);
        self::runGit($repoRoot, ['commit', '--quiet', '-m', $message]);

        return trim(self::runGit($repoRoot, ['rev-parse', 'HEAD']));
    }

    /**
     * @param list<string> $args
     */
    private static function runGit(string $repoRoot, array $args): string
    {
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['git', ...$args], $descriptorSpec, $pipes, $repoRoot);

        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to spawn git '.implode(' ', $args));
        }

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException('git '.implode(' ', $args).' failed: '.$errorOutput);
        }

        return $output === false ? '' : $output;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
            } else {
                self::removeDirectory($path);
            }
        }

        rmdir($dir);
    }
}
