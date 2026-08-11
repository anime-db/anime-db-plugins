# animedb-shikimori

Официальный плагин-источник **Shikimori** для AnimeDB v2 — поиск аниме и
заполнение карточек данными из Shikimori.

## Статус

**Фаза 5 — виджеты (related/similar/new).** `require.plugin-contracts` поднят до
`^0.13` (виджетам нужен `Widget\WidgetMetadata` + `static metadata()`, добавленные в
plugin-contracts v0.13.0). Версия самого плагина этим релизом **не бампается**
(релиз пачкой, см. issue #44). Три отдельных класса в `src/Widget/`, все выключены
по умолчанию (`features.related/similar/new: false` — юзер включает вручную):

| Виджет    | Интерфейс                 | Источник данных                                           | Отображение                        |
|-----------|----------------------------|-------------------------------------------------------------|-------------------------------------|
| `related` | `Widget\EntryWidgetInterface`   | GraphQL `Anime.related` (манга-связи дропаются, сортировка по `airedOn`) | CSS-only карусель, все совпадения    |
| `similar` | `Widget\EntryWidgetInterface`   | REST `GET /api/animes/:id/similar` (в GraphQL нет)           | CSS-only карусель, все совпадения    |
| `new`     | `Widget\CatalogWidgetInterface` | GraphQL `animes(order: aired_on, limit: 20)`                 | список, лимит 20                     |

`related`/`similar` резолвят Shikimori-id записи через `Catalog\CatalogReaderInterface`
(`AnimeView::$externalId`, уже резолвнутый хостом для этого плагина) — анонимно, без
Bearer. `new` — OAuth-aware: при наличии токена шлёт `mylist` (значение —
`!planned,!watching,!rewatching,!completed,!on_hold,!dropped`, синтаксис подтверждён
живой GraphQL-интроспекцией `MylistString`), Shikimori сам исключает уже добавленные в
список тайтлы; без токена — анонимные новинки; токен есть, но отклонён (401/протух) —
graceful fallback на анонимный запрос без попытки `refreshAccessToken()` (refresh — задача
sync-пути, `Sync\ShikimoriAuthRetrier`, виджет её не переиспользует).

Общий для трёх виджетов парсинг Shikimori-ссылок вынесен в
`ExternalId\ShikimoriIdResolver` (было приватным методом `ShikimoriFiller::resolveExternalId()`,
теперь общий статик-хелпер, чтобы regex не дублировался в четырёх местах).
`Http\ShikimoriRestClient::getSimilarAnimes()` — новый анонимный вызов REST v1
`/api/animes/:id/similar`; приватный `request()` клиента получил опциональный `$bearer`
(было обязательным строковым параметром) специально для этого публичного эндпоинта.

Рендер — через host-`Twig\Environment` (как и `Settings\ShikimoriSettingsPage`),
шаблоны в `templates/widget/`: `related.html.twig`/`similar.html.twig` оборачивают
host-хелпер `plugin/_widget_list.html.twig` в CSS-only горизонтальный скролл
(`templates/widget/_carousel.html.twig`, инлайновый `<style>` — плагин не везёт
отдельных ассетов), `new.html.twig` использует хелпер напрямую (обычный список, не
карусель).

Побочный эффект бампа `plugin-contracts` до v0.13.0: `Sync\SyncInterface::push()`
сменил сигнатуру `void` → `SyncItem` (breaking, не связано с виджетами — контракт
подрос в той же версии из-за `SyncItem::$updatedAt`/`$watchedEpisodes`).
`ShikimoriFiller::push()` теперь возвращает то, что реально отправил (`updatedAt: null`) —
REST v2 create/update ответы не парсятся на предмет подтверждённого таймстампа, это
предусмотренный контрактом fallback-путь, а не urgent-доработка.

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
(с фазы 4) — синхронизацию watch-листа (`push()`/`pull()`). `features.related`/
`features.similar`/`features.new` (с фазы 5) — по одному виджету каждый, все `false`
по умолчанию: юзер включает нужные вручную на странице настроек виджетов хоста.

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
