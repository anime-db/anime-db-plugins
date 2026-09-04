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

namespace AnimeDb\Plugins\Tools\Tests\Fixtures\GateProbe;

/**
 * Deliberate violator of NoDangerousPrimitivesRule: every method below must be reported by
 * the rule. Test data for {@see \AnimeDb\Plugins\Tools\Tests\AnalysePluginCliTest}, which
 * runs the real plugin gate over this directory and fails if any of the eight reports is
 * missing — that is what keeps the gate from silently pointing at nothing again (issue #111).
 *
 * Lives in tests/fixtures/, not in plugins/: a directory under plugins/ would be picked up
 * by the registry build and shipped as a release.
 *
 * This file is never executed. It is only ever read by PHPStan.
 */
final class Probe
{
    /** Forbidden: FORBIDDEN_FUNCTIONS. */
    public function runProcess(): string
    {
        return (string) exec('id');
    }

    /** Forbidden: FORBIDDEN_FUNCTIONS. */
    public function runShell(): string
    {
        return (string) shell_exec('uname -a');
    }

    /** Forbidden: FORBIDDEN_FUNCTION_PREFIXES ("curl_"). */
    public function openCurl(): void
    {
        $handle = curl_init('https://example.com/');
        curl_exec($handle);
    }

    /** Forbidden: URL_SCOPED_FUNCTIONS with a statically known URL. */
    public function fetchOverHttp(): string
    {
        return (string) file_get_contents('https://example.com/payload.json');
    }

    /** Forbidden: raw socket. */
    public function openSocket(): void
    {
        stream_socket_client('tcp://example.com:80');
    }

    /** Forbidden: eval. */
    public function evaluate(): void
    {
        eval('$x = 1;');
    }

    /** Forbidden: the shell exec operator (backticks). */
    public function backticks(): string
    {
        return (string) `whoami`;
    }
}
