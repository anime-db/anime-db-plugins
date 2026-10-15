# animedb-link-rules

Официальный плагин AnimeDB типа `local`. Содержит только данные (`manifest.json`,
`link-rules.yaml`, `README.md`) и не содержит кода.

Правила задают нормализованную ссылку **только для сравнения** записей каталога:
отображаемая ссылка не меняется. Правила лишь переписывают строку адреса, в сеть плагин
не ходит и данные не заполняет.

## Сайты

Википедия (все языки, мобильная версия), ANN, syoboi, allcinema, AniList, AniDB, MAL,
Shikimori, World-Art, Кинопоиск, fansubs.ru.

## Ограничения

- Онлайн-разрешения нет: ни редиректов, ни переименований статей.
- Пользовательский `api_endpoint` плагина Shikimori правила не покрывают; покрыты только
  домены `shikimori.org`, `.one`, `.me`, `.io`.
- Формат пути Кинопоиска (`/film/N`) не сверен с реальными данными.

## Примеры

Выражения сопоставляются со ссылкой после общих шагов приложения (схема `https`, без `www.`,
без `#…` и завершающего `/`, параметры запроса отсортированы по имени).

| Ссылка | Нормализованная ссылка |
| --- | --- |
| `http://www.allcinema.net/prog/show_c.php?num_c=241928` | `https://allcinema.net/cinema/241928` |
| `https://allcinema.net/cinema/241928` | `https://allcinema.net/cinema/241928` |
| `http://anidb.net/perl-bin/animedb.pl?show=anime&aid=959` | `https://anidb.net/anime/959` |
| `https://anidb.net/a959` | `https://anidb.net/anime/959` |
| `http://shikimori.org/animes/6746-durarara` | `https://shikimori.io/animes/6746` |
| `https://shikimori.one/animes/z6746-durarara` | `https://shikimori.io/animes/6746` |
| `https://shikimori.io/animes/6746` | `https://shikimori.io/animes/6746` |
| `http://myanimelist.net/anime/57/` | `https://myanimelist.net/anime/57` |
| `https://myanimelist.net/anime/1/Cowboy_Bebop` | `https://myanimelist.net/anime/1` |
| `https://myanimelist.net/anime.php?id=1` | `https://myanimelist.net/anime/1` |
| `http://cal.syoboi.jp/tid/115/time` | `https://cal.syoboi.jp/tid/115` |
| `https://ru.m.wikipedia.org/wiki/Hikaru_no_Go` | `https://ru.wikipedia.org/wiki/Hikaru_no_Go` |
| `http://www.world-art.ru/animation/animation.php?id=2425` | `https://world-art.ru/animation/animation.php?id=2425` |
| `http://www.animenewsnetwork.com/encyclopedia/anime.php?id=155` | `https://animenewsnetwork.com/encyclopedia/anime.php?id=155` |
| `http://www.fansubs.ru/base.php?id=1137` | `https://fansubs.ru/base.php?id=1137` |
