# animedb-shikimori

Официальный плагин-источник **Shikimori** для AnimeDB v2 — поиск аниме и
заполнение карточек данными из Shikimori.

## Статус

**Фаза 4 — Sync (push REST v2 find-or-create, pull GraphQL `userRates`).**
`ShikimoriFiller` реализует `Sync\SyncInterface` (plugin-contracts v0.12.0) вместо
`FillerInterface` напрямую — единственный filler-совместимый сервис плагина (второй
filler-класс на тот же id дал бы коллизию тегов `app.filler`/`app.sync` на хосте).

- **`push(SyncItem $item)`** — REST v2 (`Http\ShikimoriRestClient`), find-or-create:
  `GET /api/v2/user_rates?user_id=&target_id=&target_type=Anime` ищет существующую
  запись пользователя, `PATCH /api/v2/user_rates/:id` обновляет статус или
  `POST /api/v2/user_rates` создаёт новую (REST v2 `create` — без upsert). Идемпотентно:
  повторный push того же статуса резолвится в существующий rate и `PATCH`-ится тем же
  значением (no-op) — этим полагается безопасность повторов `PushSyncMessageHandler`
  хоста. `user_id` в теле `create` обязателен по живой (неавторизованной) API-доке
  Shikimori (`/api/doc/2.0/user_rates`, поле `user_rate[user_id]` помечено
  `"required": true`) и резолвится один раз через GraphQL `currentUser { id }`,
  кэшируется на время жизни `ShikimoriFiller`.
- **`pull(): iterable<SyncItem>`** — GraphQL `userRates` (переиспользует `Http\GraphQlClient`,
  `userId` не передаётся — по схеме дефолтится на Bearer-пользователя), постранично
  (лимит страницы — 50, максимум по схеме Shikimori). Реализовано генератором: HTTP-запрос
  за следующую страницу уходит только когда вызывающий код запросил следующий элемент —
  `SyncInterface::pull()` не принимает heartbeat-колбэк, так что именно эта ленивость и
  играет его роль.
- **Маппинг статусов** — `Mapping\SyncStatusMapper`. Словари не симметричны: у Shikimori
  есть `rewatching`, у контракта — нет, `fromShikimori()` схлопывает его в `Watching`;
  `toShikimori()`, соответственно, никогда `rewatching` не производит.
- **401 → refresh → retry** — общая для REST push и GraphQL pull политика,
  `Sync\ShikimoriAuthRetrier`: оба транспорта на HTTP 401 бросают один и тот же
  `Http\UnauthorizedHttpException`. При 401: пробуем `refreshAccessToken()`; успех —
  повтор запроса с новым токеном; сбой (`OAuthTokenExchangeException`/`\LogicException`,
  включая «нет refresh-токена») — сверяем токен до/после: изменился (кто-то другой уже
  обновил, гонка ротации) → повтор с текущим токеном, стор не трогаем; не изменился →
  сессия подтверждённо мертва → `disconnect()` + `OAuth\ReauthRequiredException`. Сетевой
  сбой самого refresh-запроса (`ClientExceptionInterface`) ничего не говорит о состоянии
  сессии — не трактуется как «мертво», токены не трогаются, ошибка просто пробрасывается.
  Отсутствие токена вовсе (`accessToken() === null`) — сразу `ReauthRequiredException`, без
  попытки refresh.

`Http\GraphQlClient::query()` получил опциональный `$bearer` (передаётся вызывающим кодом
за каждый вызов, не читается из настроек самим клиентом) — так анонимные вызовы фазы 1
(`find()`/`findById()` в каталожном импорте) остаются анонимными, а не начинают внезапно
слать чужой OAuth-токен.

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
активен вместе с ней — отдельного `search`-флага нет). `features.sync: true`
(с фазы 4) — синхронизацию watch-листа (`push()`/`pull()`).

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
