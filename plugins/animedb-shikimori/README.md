# animedb-shikimori

Официальный плагин-источник **Shikimori** для AnimeDB v2 — поиск аниме и
заполнение карточек данными из Shikimori.

## Статус

**Фаза 2 — страница настроек.** `api_endpoint` теперь редактируется прямо в UI:
`Settings\ShikimoriSettingsPage` (`SettingsPageInterface::render()`) рендерит форму, а
`Settings\ShikimoriSettingsController` (первый собственный роут плагина,
`plugin-routing.yaml` в корне, CSRF через host-`CsrfTokenManagerInterface`) сохраняет её
read-modify-write в `SettingsStore` — так остальные ключи payload (в фазе 3 — OAuth-токены)
не затираются. Это же закладывает паттерн под OAuth (кнопка «Авторизоваться» — фаза 3).

**Фаза 1 — Filler.** Контрактная граница с хостом реализована целиком
(плагин — `filler`, а значит и `search`: `FillerInterface` наследует
`SearchByPluginInterface`). Поиск и заполнение карточки работают через
анонимные чтения GraphQL API Shikimori (без OAuth — токен не нужен, обязателен
только заголовок `User-Agent`):

| Метод                | Состояние                                                        |
|----------------------|------------------------------------------------------------------|
| `resolveExternalId()`| ✅ реализовано (распознавание id по ссылкам на Shikimori)         |
| `find()`             | ✅ реализовано (поиск через GraphQL `animes(search:)`)            |
| `findById()`         | ✅ реализовано (карточка через GraphQL `animes(ids:)`)            |
| `getFillableFields()`| ✅ реализовано (все поля, кроме `countries`/`images`)             |

`api_endpoint` читается из `SettingsStore` (дефолт `https://shikimori.io`), редактируется
на странице настроек плагина (фаза 2). OAuth, sync и виджеты появятся отдельными
релизами (на них же обкатывается механизм обновления плагина в маркете).

### Rate limiting

Shikimori ограничивает анонимные запросы 5 rps / 90 rpm. Хост не троттлит
HTTP-вызовы плагинов, поэтому клиент (`Http\GraphQlClient` + `Http\RateLimiter`)
сам держит client-side token-bucket ограничитель и на HTTP 429 паузит запрос
(уважая `Retry-After`, с ограниченным числом ретраев), а не бросает исключение
— бросает только после исчерпания бюджета ретраев.

## Устройство

Плагин состоит из `manifest.json` + `src/` + (с фазы 2) `templates/` и корневого
`plugin-routing.yaml`:

- **нет DI-конфига** — хост сам регистрирует классы из `src/` как сервисы (в т.ч.
  `#[AsController]`-контроллер);
- **нет класса бандла** — он не нужен, пока плагину не потребуется собственное
  DI-расширение / компайлер-пассы / Doctrine-маппинги;
- **нет тегов** — хост сам тегирует классы по реализованным контрактным
  интерфейсам;
- **`templates/`** — Twig-шаблоны плагина, рендерятся через host-`Twig\Environment`
  под неймспейсом `@AnimedbShikimori`;
- **`plugin-routing.yaml`** — собственные роуты плагина (сейчас только
  `POST /plugins/animedb-shikimori/settings`), хост читает файл из корня плагина,
  не из `config/`.

`features.filler: true` в манифесте включает функциональность филлера (поиск
активен вместе с ней — отдельного `search`-флага нет).

## Разработка

Зависимость от контракта нужна только для IDE/статанализа, `phpunit` — для
локальных unit-тестов:

```bash
composer install
composer test
```

`anime-db/plugin-contracts` пока не в Packagist и подключается из публичного
VCS-репозитория (см. `repositories` в `composer.json`); `composer install` работает
без токена и авторизации.

> **Важно:** `vendor/` в ZIP-сборку плагина **не попадает** — контрактные классы
> в рантайме предоставляет хост-приложение. Дублировать их в архиве нельзя.
> `tests/` тоже не попадает в сборку ZIP (`tools/build-plugin-zip.php` исключает
> её наравне с `vendor/`), unit-тесты гоняются только локально/в CI монорепо.
