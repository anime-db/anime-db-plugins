<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *
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

namespace AnimeDb\Plugins\GateProbe;

/**
 * Намеренный нарушитель NoDangerousPrimitivesRule — проба, применяется ли правило
 * пакета контрактов к коду плагинов в CI монорепо. Каждый метод ниже обязан быть
 * пойман правилом; зелёный CI на этом плагине означает, что правило не применяется.
 *
 * НЕ ДЛЯ МЕРЖА.
 */
final class Probe
{
    /** Запрещено: FORBIDDEN_FUNCTIONS. */
    public function runProcess(): string
    {
        return (string) exec('id');
    }

    /** Запрещено: FORBIDDEN_FUNCTIONS. */
    public function runShell(): string
    {
        return (string) shell_exec('uname -a');
    }

    /** Запрещено: FORBIDDEN_PREFIXES ("curl_"). */
    public function openCurl(): void
    {
        $handle = curl_init('https://example.com/');
        curl_exec($handle);
    }

    /** Запрещено: URL_SCOPED_FUNCTIONS со статической строкой-URL. */
    public function fetchOverHttp(): string
    {
        return (string) file_get_contents('https://example.com/payload.json');
    }

    /** Запрещено: raw socket. */
    public function openSocket(): void
    {
        stream_socket_client('tcp://example.com:80');
    }

    /** Запрещено: eval. */
    public function evaluate(): void
    {
        eval('$x = 1;');
    }

    /** Запрещено: shell exec operator (backticks). */
    public function backticks(): string
    {
        return (string) `whoami`;
    }
}
