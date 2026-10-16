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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\Folder;

use AnimeDb\PluginContracts\Catalog\AnimeView;
use AnimeDb\PluginContracts\Model\AnimeName;
use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\StubTwigFactory;
use PHPUnit\Framework\TestCase;

final class InfoTemplateTest extends TestCase
{
    private function view(): AnimeView
    {
        return new AnimeView(
            title: 'Cowboy Bebop',
            alternativeNames: [new AnimeName('Ковбой Бибоп', 'ru', NameRole::Official)],
            type: AnimeType::Tv,
            genres: [GenreCode::Action],
            themes: [ThemeCode::AdultCast],
            episodesCount: 26,
            sources: ['https://secret.example/anime/1', 'https://another.example/x'],
            externalId: 'ext-77',
            description: "First line <script>alert(1)</script>\nSecond line",
            datePremiere: new \DateTimeImmutable('1998-04-03'),
            dateEnd: new \DateTimeImmutable('1999-04-24'),
            durationMinutes: 24,
            studios: ['Sunrise'],
            countries: ['JP'],
        );
    }

    private function render(string $locale, ?string $coverFile): string
    {
        return StubTwigFactory::create($locale)->render('@AnimedbFolderInfo/info.html.twig', ['anime' => $this->view(), 'coverFile' => $coverFile]);
    }

    public function testRendersAllowedFields(): void
    {
        $html = $this->render('en', 'animedb-cover.png');

        foreach (['Cowboy Bebop', 'Ковбой Бибоп', 'TV series', '26', '24 min.', '1998-04-03', '1999-04-24', 'Sunrise', 'JP', 'First line'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringContainsString('<img class="cover" src="animedb-cover.png"', $html);
    }

    public function testGenresAndThemesComeFromTheApplicationMessagesDomain(): void
    {
        foreach (['en', 'ru'] as $locale) {
            $html = $this->render($locale, null);

            self::assertStringContainsString('app:genre.action', $html);
            self::assertStringContainsString('app:theme.adult-cast', $html);
            self::assertStringNotContainsString('Adult cast', $html);
        }
    }

    public function testDoesNotLeakSourcesExternalIdOrAnyUrl(): void
    {
        $html = $this->render('en', 'animedb-cover.jpg');

        self::assertStringNotContainsString('secret.example', $html);
        self::assertStringNotContainsString('another.example', $html);
        self::assertStringNotContainsString('ext-77', $html);
        self::assertDoesNotMatchRegularExpression('~https?://|//[a-z]~i', $html);
    }

    public function testIsSelfContainedAndEscapesDescription(): void
    {
        $html = $this->render('en', null);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<link', $html);
        self::assertStringContainsString('<style>', $html);
        self::assertStringContainsString('<br />', $html);
    }

    public function testHasNoImageWhenCoverWasNotWritten(): void
    {
        self::assertStringNotContainsString('<img', $this->render('en', null));
    }

    public function testLabelsFollowTheLocale(): void
    {
        $en = $this->render('en', null);
        $ru = $this->render('ru', null);

        self::assertStringContainsString('Duration', $en);
        self::assertStringNotContainsString('Длительность', $en);
        self::assertStringContainsString('Длительность', $ru);
        self::assertStringContainsString('ТВ-сериал', $ru);
        self::assertStringNotContainsString('Duration', $ru);
    }

    public function testCatalogsHaveTheSameKeys(): void
    {
        self::assertSame(
            array_keys(StubTwigFactory::catalog('en')),
            array_keys(StubTwigFactory::catalog('ru')),
        );
    }
}
