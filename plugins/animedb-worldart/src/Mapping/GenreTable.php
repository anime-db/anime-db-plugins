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

namespace AnimeDb\Plugins\AnimedbWorldart\Mapping;

use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;

/**
 * The site's dictionary of genres (`public_genre`, ids below 100) and tags (`public_keyword`)
 * against the contract's axes, keyed by the numeric id of the dictionary, NOT by the name.
 *
 * The ids of the two dictionaries do not overlap, so one table serves both. The audience
 * dictionary has its own ids that DO overlap with these ({@see AudienceTable}) and is never
 * looked up here. An id without a row is deliberately not mapped: either there is no clear
 * counterpart, or the contract excludes it (the Explicit Genres section: `36` erotica, `41` ecchi,
 * `851` nudity / explicit scenes).
 */
final class GenreTable
{
    /** @var array<int, GenreCode|ThemeCode> */
    public const MAP = [
        2 => ThemeCode::MartialArts, // боевые искусства
        5 => GenreCode::Mystery, // детектив
        7 => GenreCode::Drama, // драма
        10 => GenreCode::Comedy, // комедия
        11 => ThemeCode::MahouShoujo, // махо-сёдзё
        12 => ThemeCode::Mecha, // меха
        14 => GenreCode::Supernatural, // мистика
        15 => ThemeCode::Music, // музыкальный
        16 => ThemeCode::Educational, // образовательный
        19 => GenreCode::SliceOfLife, // повседневность
        22 => GenreCode::Adventure, // приключения
        24 => GenreCode::Romance, // романтика
        31 => GenreCode::Sports, // спорт
        32 => GenreCode::Suspense, // триллер
        34 => GenreCode::SciFi, // фантастика
        35 => GenreCode::Fantasy, // фэнтези
        37 => GenreCode::Horror, // ужасы
        620 => ThemeCode::TeamSports, // футбол
        623 => ThemeCode::Racing, // автогонки
        624 => ThemeCode::TeamSports, // баскетбол
        625 => ThemeCode::TeamSports, // бейсбол
        626 => ThemeCode::CombatSports, // бокс
        692 => ThemeCode::TeamSports, // волейбол
        793 => ThemeCode::StrategyGame, // сёги
        801 => ThemeCode::TeamSports, // регби
        843 => ThemeCode::CombatSports, // спортивная борьба
        848 => ThemeCode::Racing, // мотогонки
        783 => ThemeCode::Historical, // период Ямато
        784 => ThemeCode::Historical, // период Нара
        770 => ThemeCode::Historical, // период Хэйан
        778 => ThemeCode::Historical, // период Камакура
        779 => ThemeCode::Historical, // период Муромати
        734 => ThemeCode::Historical, // период Сэнгоку
        680 => ThemeCode::Historical, // период Эдо
        773 => ThemeCode::Historical, // период Бакумацу
        780 => ThemeCode::Historical, // период Мэйдзи
        781 => ThemeCode::Historical, // первая половина 20 века
        694 => ThemeCode::Historical, // вторая мировая война
        771 => ThemeCode::Historical, // Викторианская эпоха
        635 => ThemeCode::AdultCast, // взрослый герой
        636 => ThemeCode::AdultCast, // взрослая героиня
        698 => ThemeCode::SuperPower, // супергерой
        631 => ThemeCode::OtakuCulture, // отаку
        912 => ThemeCode::Delinquents, // хулиганы
        632 => ThemeCode::Military, // военные
        661 => ThemeCode::Space, // космические авантюристы
        707 => ThemeCode::Samurai, // самураи
        733 => ThemeCode::Medical, // врачи
        627 => ThemeCode::Vampire, // вампиры
        747 => ThemeCode::Mythology, // божества
        628 => GenreCode::SciFi, // киберпанк
        644 => ThemeCode::Space, // космическая опера
        645 => ThemeCode::Mecha, // пилотируемые роботы
        639 => GenreCode::Fantasy, // тёмное фэнтези
        741 => GenreCode::Fantasy, // технофэнтези
        743 => GenreCode::Fantasy, // классическое фэнтези
        642 => ThemeCode::ReverseHarem, // реверс-гарем
        643 => ThemeCode::Harem, // гарем
        669 => ThemeCode::LovePolygon, // любовный треугольник
        748 => ThemeCode::Parody, // сатира / пародия
        696 => ThemeCode::VideoGame, // mmorpg
        686 => ThemeCode::Space, // космический корабль
        852 => ThemeCode::PerformingArts, // театр кабуки
        647 => ThemeCode::School, // академия магии
        702 => ThemeCode::Music, // музыкальная группа
        713 => ThemeCode::School, // школьный клуб
        722 => ThemeCode::School, // школа
        749 => ThemeCode::School, // школьный совет
        810 => ThemeCode::Gore, // жестокие сцены
        638 => ThemeCode::Isekai, // путешествие в другой мир
        641 => GenreCode::Gourmet, // кулинария
        651 => ThemeCode::TimeTravel, // путешествие во времени
        656 => ThemeCode::SuperPower, // суперсила
        664 => ThemeCode::Workplace, // работа и карьера
        731 => ThemeCode::OrganizedCrime, // преступные организации
        755 => ThemeCode::Music, // классическая музыка
        765 => ThemeCode::Survival, // королевская битва
        766 => ThemeCode::Survival, // выживание
    ];
}
