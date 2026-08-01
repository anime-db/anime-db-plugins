# anime-db-plugins

Монорепо плагинов **AnimeDB v2** (официальных и принятых от сообщества) и
одновременно реестр маркета.

## Структура

```
anime-db-plugins/
├── plugins/
│   └── <plugin-id>/           # один каталог = один плагин
│       ├── manifest.json      # описание плагина (обязательно в корне)
│       └── src/               # PHP-классы плагина
└── plugins-registry.json     # агрегат реестра (генерируется CI) — TODO
```

Id плагина имеет вид `<vendor>-<name>`, где `<vendor>` — **префикс вендора
(автора)**: он обеспечивает уникальность id и защищает имя от занятия другими.
Официальные плагины идут с вендором `animedb` (например `animedb-shikimori`);
плагины сообщества — со **своим** вендором (например `johnsmith-example`). Вендор
`animedb` зарезервирован за официальными плагинами; для своих плагинов авторы
используют собственный вендор.

Namespace классов выводится из id детерминированно (каждый сегмент id — в
StudlyCase, слитно, под общим префиксом `AnimeDb\Plugins\`):

- `animedb-shikimori` → `AnimeDb\Plugins\AnimedbShikimori\`
- `johnsmith-example` → `AnimeDb\Plugins\JohnsmithExample\`

Префикс `AnimeDb\Plugins\` — общий для всех плагинов, включая комьюнити (вендор
влияет только на сегмент studly, не на корень).

## Плагины

| Плагин              | Источник  | Состояние |
|---------------------|-----------|-----------|
| `animedb-shikimori` | Shikimori | скелет (см. [README](plugins/animedb-shikimori/README.md)) |

## Форма плагина

Плагин — это `manifest.json` + `src/*.php`, без собственного DI-конфига, без
класса бандла и без тегов: всё это хост-приложение настраивает само (регистрация
сервисов, тегирование по контрактным интерфейсам, автозагрузка изолированного
namespace). Контракт (`anime-db/plugin-contracts`) в рантайме предоставляет хост,
поэтому `vendor/` в ZIP-сборку плагина не входит.

## Маркет и версии

Готовые сборки версий плагинов публикуются в GitHub Releases, а `plugins-registry.json`
в корне репозитория агрегирует реестр для витрины в приложении. Инфраструктура
сборки/реестра (CI, генерация реестра, зеркала) — в разработке.

Реестр содержит поле `sequence` — монотонный счётчик поколений (anti-rollback): клиент
отвергает реестр с `sequence`, меньшим уже увиденного. `tools/build-registry.php` выводит
его как `previous.sequence + 1`, если ему передан путь к предыдущему опубликованному
`plugins-registry.json` (третий аргумент), иначе — `1` (первая генерация). Полей
`valid_until`/freshness в реестре сознательно нет — см. issue #12.

Реестр подписывается отсоединённой Ed25519-подписью (`tools/sign-registry.php`) —
дешёвая страховка от компрометации хостинга-зеркала: `plugins-registry.json.sig`
проверяется `sodium_crypto_sign_verify_detached` против публичного ключа (`tools/verify-registry-signature.php`).
Приватный ключ никогда не в git — он живёт только как секрет GitHub Actions и
передаётся `sign-registry.php` через переменную окружения (имя которой — второй
аргумент), не через argv. Публичный ключ, наоборот, безопасно коммитить в репозиторий —
он предназначен для зашивки в клиент (issue #292).

Сгенерировать новую пару ключей (разово, при первичной настройке или ротации):

```bash
php tools/generate-registry-keypair.php plugins-registry.pub
# stdout — секретный ключ (base64): положить в секрет GitHub Actions (например
# REGISTRY_SIGNING_KEY) и не сохранять больше нигде.
```

Собрать и подписать реестр вручную (то, что должен делать CI-воркфлоу — здесь не
описан, т.к. `.github/workflows/` вне зоны ответственности этого README):

```bash
php tools/build-registry.php plugins published-versions.json plugins-registry.json \
  > plugins-registry.json.new
mv plugins-registry.json.new plugins-registry.json
REGISTRY_SIGNING_KEY="$(cat secret.key)" \
  php tools/sign-registry.php plugins-registry.json REGISTRY_SIGNING_KEY \
  > plugins-registry.json.sig
```

Плагины сообщества попадают в маркет через **Pull Request** в этот монорепо
(со своим вендором в id) — так их можно проверить и собрать общим CI. Автор может
и не публиковать плагин в маркете, а распространять свой ZIP самостоятельно: тогда
пользователь ставит его как сторонний архив (минуя маркет), а не из витрины.

## Тулинг

Каркас проверки плагинов на PHP, самодостаточно вызываемый из CLI:

```bash
composer install

# Проверить один плагин: манифест, соответствие id каталогу, namespace классов
# в src/, синтаксис PHP. Код возврата 0 — плагин валиден, иначе печатает список проблем.
php tools/validate-plugin.php plugins/animedb-shikimori

# Гейт «PR трогает ровно один плагин и только его файлы». На вход — список путей
# (аргументами или через stdin, например из `git diff --name-only`), на выход —
# id единственного затронутого плагина (код 0) либо причина отказа (код ≠0).
git diff --name-only master... | php tools/check-pr-changes.php

# Собрать детерминированный ZIP плагина (manifest.json в корне, src/, README.md;
# без vendor/, composer.lock, tests/, .git*, .php-cs-fixer.*) и посчитать sha256.
# Хеш печатается в stdout и кладётся рядом с архивом в файл "sha256".
php tools/build-plugin-zip.php plugins/animedb-shikimori /tmp/animedb-shikimori.zip

# Собрать plugins-registry.json (см. "Маркет и версии" выше) и подписать его Ed25519.
php tools/build-registry.php plugins published-versions.json [<previous-registry.json>]
php tools/sign-registry.php <registry.json> <secret-key-env-var-name>
php tools/verify-registry-signature.php <registry.json> <signature-file> <public-key-file>
php tools/generate-registry-keypair.php <public-key-out-file>

composer test      # PHPUnit
composer phpstan    # статический анализ
composer cs-check   # проверка стиля кода (php-cs-fixer, --dry-run)
```

Все скрипты — чистый CLI (вход → вывод/код возврата), без обвязки CI: воркфлоу
`.github/workflows`, который их дёргает (git diff → `check-pr-changes` → `validate-plugin` →
`build-plugin-zip` + создание Release на затронутом плагине), интегрирует ментейнер отдельно.

`anime-db/plugin-contracts` пока не опубликован в Packagist, поэтому подключается из
публичного VCS-репозитория (см. `repositories` в `composer.json`). Репозиторий
публичный — `composer install` работает без аутентификации и без токена, как локально,
так и в CI (в т.ч. на PR из форков).
