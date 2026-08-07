# animedb-shikimori

Официальный плагин-источник **Shikimori** для AnimeDB v2 — поиск аниме и
заполнение карточек данными из Shikimori.

## Статус

**Фаза 3 — OAuth (Authorization Code + PKCE S256).** `OAuth\ShikimoriOAuthClient` расширяет
контрактный `AbstractOAuthClient` (plugin-contracts v0.11.0): эндпоинты авторизации/обмена
токена захардкожены на `shikimori.io` (никогда не берутся из `api_endpoint` — иначе плановый
`refreshAccessToken()`, фаза 4, мог бы утечь refresh-токен на подменный домен), клиент —
confidential (Shikimori app #2212), скоуп `user_rates`, `callbackPath()` — `/oauth/shikimori`
байт-в-байт совпадает с зарегистрированным `redirect_uri`. `tokenRequestHeaders()` шлёт
`User-Agent` (Shikimori банит token/refresh без него) через новый `Http\UserAgent::forManifest()`.

Три собственных роута (`plugin-routing.yaml`, `#[AsController]`):
`GET /oauth/shikimori/start` (302 на вендора, top-level-навигация, не HTMX — так Electron
открывает системный браузер), `GET /oauth/shikimori` (callback: до `handleCallback()`
проверяет `error`/отсутствие `code`, чтобы не сжечь одноразовый `state`; после успеха —
живая проба `GET /api/users/whoami` через `OAuth\ShikimoriTokenProbe`, не фатальная) и
`POST /plugins/animedb-shikimori/oauth/disconnect` (CSRF, `disconnect()`).

`refreshAccessToken()` в этой фазе не вызывается — это задача фазы 4 (sync).

**Фаза 2 — страница настроек.** `api_endpoint` редактируется прямо в UI:
`Settings\ShikimoriSettingsPage` (`SettingsPageInterface::render()`) рендерит форму (и, с
фазы 3, статус авторизации), а `Settings\ShikimoriSettingsController` (первый собственный
роут плагина, CSRF через host-`CsrfTokenManagerInterface`) сохраняет её read-modify-write в
`SettingsStore` — так остальные ключи payload (OAuth-токены) не затираются. Это же
закладывало паттерн, который фаза 3 переиспользует для `oauth/disconnect`.

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
  `#[AsController]`-контроллеры);
- **нет класса бандла** — он не нужен, пока плагину не потребуется собственное
  DI-расширение / компайлер-пассы / Doctrine-маппинги;
- **нет тегов** — хост сам тегирует классы по реализованным контрактным
  интерфейсам;
- **`templates/`** — Twig-шаблоны плагина, рендерятся через host-`Twig\Environment`
  под неймспейсом `@AnimedbShikimori`;
- **`plugin-routing.yaml`** — собственные роуты плагина (`POST
  /plugins/animedb-shikimori/settings`, `GET /oauth/shikimori/start`, `GET
  /oauth/shikimori`, `POST /plugins/animedb-shikimori/oauth/disconnect`), хост читает
  файл из корня плагина, не из `config/`.

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
