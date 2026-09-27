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

namespace AnimeDb\Plugins\AnimedbMediaDetails\Tests\Widget\Fixture;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * A minimal stand-in for the host's Twig environment, for widget tests: real
 * `@AnimedbMediaDetails` template loading (against the plugin's actual `templates/` directory)
 * plus a stub `trans` filter, the real implementation of which comes from
 * `symfony/translation`, a dependency this plugin's tests deliberately do not carry (only
 * `twig/twig` itself is a declared dev dependency).
 *
 * The `trans` stub resolves against {@see self::CATALOG}, kept in lockstep with
 * `translations/animedb-media-details.en.yaml` (the source-of-truth English strings) so tests
 * assert on the same display text a user would see, not on raw translation keys, and
 * substitutes `%placeholder%` parameters the same way the real translator does.
 */
final class StubTwigFactory
{
    /**
     * @var array<string, string> flattened `translations/animedb-media-details.en.yaml`
     */
    private const CATALOG = [
        'widget.summary.title' => 'Media details',
        'widget.summary.description' => "Shows container, track and subtitle details collected from the record's files.",
        'widget.summary.pending' => 'Collecting file details…',
        'widget.summary.unavailable' => 'File details are unavailable.',
        'widget.summary.parse_failed' => 'Could not read details for any file.',
        'widget.summary.files_count' => 'Files: %count%',
        'widget.summary.files_unparsed' => 'Not parsed: %count%',
        'widget.summary.size_label' => 'Size',
        'widget.summary.duration_label' => 'Duration',
        'widget.summary.video_codec_label' => 'Video codec',
        'widget.summary.video_resolution_label' => 'Resolution',
        'widget.summary.video_frame_rate_label' => 'Frame rate',
        'widget.summary.audio_tracks_label' => 'Audio tracks',
        'widget.summary.audio_languages_label' => 'Audio languages',
        'widget.summary.subtitle_languages_label' => 'Subtitles',
        'widget.summary.not_all_files' => 'not present in every file',
        'widget.summary.more_values' => 'and %count% more',
    ];

    private function __construct()
    {
    }

    public static function create(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3).'/templates', 'AnimedbMediaDetails');

        $twig = new Environment($loader);
        $twig->addFilter(new TwigFilter(
            'trans',
            static fn (string $key, array $params = [], ?string $domain = null): string => strtr(self::CATALOG[$key] ?? $key, $params),
        ));

        return $twig;
    }
}
