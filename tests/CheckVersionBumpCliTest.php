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
 * Smoke-tests tools/check-version-bump.php as an actual CLI process, on top of the unit
 * coverage of {@see \AnimeDb\Plugins\Tools\VersionBumpChecker} in
 * {@see VersionBumpCheckerTest}.
 *
 * Both scenarios here compare HEAD against itself, so the base and head manifest versions
 * are always identical — that alone is enough to fail the gate before it would ever need
 * to shell out to `gh` (see VersionBumpChecker::check(): the tag-existence lookup only runs
 * once the version-increase check already passed), so these tests need git but not gh.
 */
final class CheckVersionBumpCliTest extends TestCase
{
    public function testMissingBaseRefArgumentExitsNonZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        exec(
            'echo -n | '.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/check-version-bump.php').' 2>&1',
            $output,
            $exitCode,
        );

        self::assertNotSame(0, $exitCode);
    }

    public function testNoChangedPathsExitsZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        exec(
            'echo -n | '.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/check-version-bump.php').' HEAD 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testUnchangedVersionAgainstItsOwnHeadFails(): void
    {
        $repoRoot = \dirname(__DIR__);
        $cmd = 'printf %s '.escapeshellarg('plugins/animedb-shikimori/src/DoesNotNeedToExist.php')
            .' | '.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/check-version-bump.php').' HEAD 2>&1';
        exec($cmd, $output, $exitCode);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('animedb-shikimori', implode("\n", $output));
    }

    /**
     * Issue #123's repro: a published file changed without a version bump, but this time the
     * base ref itself cannot be resolved (a syntactically valid but unfetched commit sha,
     * standing in for a shallow clone that never fetched the base branch). The gate must
     * fail closed with exactly one violation, not silently pass with zero.
     */
    public function testUnresolvableBaseRefFailsClosedWithExactlyOneViolation(): void
    {
        $repoRoot = \dirname(__DIR__);
        $unresolvableBaseRef = '0000000000000000000000000000000000000000';
        $cmd = 'printf %s '.escapeshellarg('plugins/animedb-shikimori/src/DoesNotNeedToExist.php')
            .' | '.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/check-version-bump.php').' '
            .escapeshellarg($unresolvableBaseRef).' 2>&1';
        exec($cmd, $output, $exitCode);

        self::assertNotSame(0, $exitCode);
        $violationLines = array_values(array_filter($output, static fn (string $line): bool => str_starts_with($line, '  - ')));
        self::assertCount(1, $violationLines, implode("\n", $output));
        self::assertStringContainsString('animedb-shikimori', $violationLines[0]);
        self::assertStringContainsString('Failing closed', $violationLines[0]);
    }
}
