# anime-db-plugins

Монорепо плагинов **AnimeDB v2** (официальных и принятых от сообщества) и
одновременно реестр маркета.

## Структура

```
anime-db-plugins/
├── plugins/
│   └── <plugin-id>/           # один каталог = один плагин
│       ├── manifest.json      # описание плагина (обязательно в корне)
│       ├── src/               # PHP-классы — у плагинов с кодом (integration/local)
│       └── translations/      # каталоги переводов — обязательны у translation
└── plugins-registry.json      # агрегат реестра (генерируется CI)
```

Плагин типа `translation` — декларативный ресурс без кода: у него нет `src/` вовсе, и
это не упущение, а требование валидатора.

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

| Плагин                                                             | Тип           | Назначение                                                                        |
|--------------------------------------------------------------------|---------------|-----------------------------------------------------------------------------------|
| [`animedb-shikimori`](plugins/animedb-shikimori/README.md)         | `integration` | Источник метаданных Shikimori: поиск, заполнение карточек, синхронизация, виджеты |
| [`animedb-language-pack`](plugins/animedb-language-pack/README.md) | `translation` | Языковой пакет: языки интерфейса `de`, `ja`                                       |

Колонка «Тип» — это поле `type` манифеста, и оно определяет не только назначение, но и
правила валидации: у `translation` не должно быть `src/`, зато обязателен `translations/`
и непустой `locales`; домен каталога переводов тоже зависит от типа (см.
[.claude-docs/conventions.md](.claude-docs/conventions.md)).

Плагин типа `translation` дополнительно обязан объявить в манифесте
`translation_keys_count` — целое число, равное фактическому числу листовых ключей его
каталога `translations/messages.<locale>.yaml` (число ключей одной локали, поскольку
валидатор уже требует их совпадения между локалями плагина). Поле нужно витрине маркета,
чтобы показать полноту перевода без скачивания ZIP; для `integration`/`local` оно
запрещено, как и `locales`. Подробности и формула подсчёта — в
[.claude-docs/conventions.md](.claude-docs/conventions.md).

## Форма плагина

Плагин с кодом (`integration`, `local`) — это `manifest.json` + `src/*.php`, без
собственного DI-конфига, без класса бандла и без тегов: всё это хост-приложение
настраивает само (регистрация сервисов, тегирование по контрактным интерфейсам,
автозагрузка изолированного namespace). Контракт (`anime-db/plugin-contracts`) в
рантайме предоставляет хост, поэтому `vendor/` в ZIP-сборку плагина не входит.

Плагин типа `translation` кода не имеет вовсе — это `manifest.json` + `translations/`.
Хост не регистрирует ему ни сервисов, ни маршрутов: он только подключает каталоги
переводов и расширяет список доступных локалей.

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

### Зеркала (`asset_mirrors`): раскладка ассетов и реестра

Ассеты версии (`plugin.zip`, `manifest.json`) раздаются клиенту с зеркал, а не только
из GitHub Releases. GitHub Releases — хардкод-константа
(`AssetMirrorsResolver::GITHUB_MIRROR`) и **всегда первый** элемент `asset_mirrors`: у
него нет FTP-кредов (заливка через `GH_TOKEN`, вне `MIRROR_CREDS`), это вечный
источник-истины, и первым местом в списке он задаёт клиенту детерминированный порядок
фолбэка (GitHub → реплики). Каждая реплика после него собирается из **двух** мест —
это и есть единый источник координат зеркала (issue #26):

- **`MIRROR_CREDS`** (GitHub Secret, JSON-объект, ключ — id зеркала) — и write-креды, и
  публичный URL-шаблон одной записью:

  ```json
  {
    "mirror1": {
      "host": "ftp.example.tld",
      "port": 21,
      "user": "mirror_user",
      "password": "***",
      "dir": "/public_html/mirror",
      "protocol": "ftps",
      "public_url": "https://mirror1.example.org/mirror/<id>/<version>/<file>"
    }
  }
  ```

  Поля: `host`, `port` (опц., по умолчанию 21), `user`, `password`, `dir` (корневой
  каталог на зеркале, **абсолютный путь** — с ведущим `/`), `protocol` (`ftps` — по
  умолчанию и предпочтительно, `ftp` — фолбэк для хостинга без FTPS), `public_url` —
  URL-шаблон с макросами `<id>`/`<version>`/`<file>`, под которым зеркало отдаёт ассеты
  клиенту. `public_url` обязателен структурно (непустая строка), но его *форма*
  (`https://` + все три макроса) не проверяется при парсинге `MirrorCredentialsParser` —
  это делает `AssetMirrorsResolver` при сборке `asset_mirrors` (см. ниже), и делает это
  fail-open, а не throw.

- **`active-mirrors`** (файл в корне репозитория, git-tracked, ревьюится PR'ами) — список
  id зеркал, один на строку (пустые строки и `#`-комментарии игнорируются):

  ```
  mirror1
  ```

  Зеркало попадает в `asset_mirrors`, только если его id **одновременно** есть и в
  `MIRROR_CREDS`, и в `active-mirrors`. Это разделяет две разные категории решений: что
  реклама клиентам (безопасно-значимо → ревьюится в git) и какие у зеркала координаты
  (секрет, без PR-гейта, одна правка задаёт и куда лить, и откуда качать). Де-листинг
  забаненного/протёкшего зеркала — это просто «убрать id из `active-mirrors`» и
  пересобрать реестр.

`AssetMirrorsResolver::resolve()` собирает итоговый `asset_mirrors` = `[GitHub] +
активные реплики` и **fail-open**: id из `active-mirrors` без соответствующей записи в
`MIRROR_CREDS`, или запись с невалидным `public_url` (не `https://`, или отсутствует
хотя бы один из макросов `<id>`/`<version>`/`<file>`), — выбрасываются из результата с
предупреждением (`::warning::`), а не валят сборку: `MIRROR_CREDS` — секрет без
PR-ревью, и опечатка в одной реплике не должна морозить пересборку/подпись реестра для
всех плагинов. Пустой `active-mirrors` → `asset_mirrors == [GitHub]`.

`tools/build-registry.php` подключает этот механизм двумя необязательными аргументами —
без них поведение не меняется (fallback на GitHub-only, как до issue #26):

```bash
php tools/build-registry.php plugins published-versions.json [<previous-registry.json>] \
  <active-mirrors-file> <mirror-creds-env-var-name>
```

`tools/push-mirror-assets.php` читает JSON `MIRROR_CREDS` из
переменной окружения (имя — четвёртый аргумент, не argv — секрет не должен попадать
в список процессов) и **в цикле** по всем ключам заливает `plugin.zip` и
`manifest.json` версии на каждое зеркало по FTP/FTPS:

```bash
MIRROR_CREDS='...' php tools/push-mirror-assets.php <id> <version> <dir-с-ассетами> MIRROR_CREDS
```

Раскладка на зеркале — версионное **неизменяемое** дерево `<dir>/<id>/<version>/<file>`
(тот же `<id>/<version>/<file>`, что и в `asset_mirrors`). Файлы заливаются **с
перезаписью**: версия неизменяема, поэтому повторная заливка байт-в-байт идентична, а
перезапись самоисцеляет файл, усечённый оборванной загрузкой (пропуск «уже есть»
оставил бы битый ассет навсегда). Безопасно перезапускать на уже опубликованную
версию. Если `MIRROR_CREDS` не задана или пуста —
это не ошибка, а «зеркала ещё не настроены»: скрипт завершается кодом 0, ничего не делая.

После успешной заливки на зеркало `push-mirror-assets.php` HEAD-проверяет `public_url`
каждого залитого файла (`MirrorAssetReachabilityVerifier` + `HttpMirrorReachabilityChecker`
— `HEAD` с ретраем/бэкоффом, фолбэк на ranged `GET` при `405`, поскольку часть
шаред-хостингов запрещает `HEAD`). В отличие от самой заливки (падение = код ≠0, релиз
не идёт дальше) HEAD-провал здесь — **soft-fail**: печатается громкая
`::warning::`-аннотация, но скрипт завершается кодом 0 и релиз продолжается — GitHub
(`asset_mirrors[0]`) остаётся доступен клиенту независимо от состояния реплики, а
пропагация FTP→web на шаред-хостинге может отставать на несколько секунд.

**Добавить зеркало = одна правка секрета (`MIRROR_CREDS`) + одна правка в git
(`active-mirrors`, через бэкофилл-джоб — см. ниже), не код.** Число секретов не растёт
с числом зеркал.

**Порядок публикации — инвариант:** реестр (`sequence`) ссылается на `sha256` уже
залитых ассетов, поэтому ассеты должны лечь на **все** активные зеркала раньше, чем
`plugins-registry.json` будет пересобран/подписан/закоммичен — иначе клиент может
увидеть в реестре версию, ассетов которой на зеркале ещё нет. `push-mirror-assets.php`
падает с кодом ≠0 при первой же неудаче ЗАЛИВКИ (сбой коннекта/логина/загрузки на любое
зеркало; HEAD-провал сюда не относится, см. выше) — вызывающий (CI) обязан трактовать
это как «реестр не публикуем». Сам скрипт не решает, когда его вызвать относительно
сборки реестра — эта последовательность (и переброс FTP-шага в CI) специфична для
`.github/workflows/`, вне зоны ответственности этого README (см. соответствующий
комментарий выше про `build-registry.php`/`sign-registry.php`).

#### Активация зеркала: бэкофилл из GitHub Releases

Новое (ещё не активное) зеркало не появляется в `asset_mirrors`, пока не пройдёт
бэкофилл — `tools/backfill-mirror.php` (`MirrorBackfillPublisher`):

```bash
MIRROR_CREDS='...' php tools/backfill-mirror.php <mirror-id> MIRROR_CREDS <active-mirrors-file>
```

Скрипт читает **все** теги Release через `gh` (топология «звезда»: GitHub — хаб,
зеркала — спицы; **никогда** не тянет с другого зеркала по FTP — read-креды зеркал не
нужны в принципе), заливает исторические ассеты каждой версии на целевое зеркало,
HEAD-верифицирует каждый залитый файл и — **только на полном успехе всех версий** —
дописывает `<mirror-id>` в `<active-mirrors-file>` (файл переписывается
отсортированным/дедуплицированным; при любой ошибке остаётся нетронутым). В отличие от
релизного HEAD-чека (soft-fail, см. выше) здесь HEAD — **hard-fail**: это не релиз под
давлением времени, поэтому безопасно (и правильно) отказаться активировать зеркало,
которое не может подтверждённо отдать то, что на него только что залили. Тот же джоб
служит само-исцелением: если зеркало «поплыло» (частичный вайп/prune хостингом),
повторный запуск на уже активном id пере-проецирует всё из GitHub заново (заливка
идемпотентна — см. выше про перезапись). Сам скрипт не коммитит `active-mirrors` в git —
это, как и вызов на `workflow_dispatch`, задача CI-обвязки (см. ниже).

> **Что уже вынесено в этот релиз, а что — нет.** `.github/workflows/` в этой задаче не
> менялся (правки туда не входили в согласованный объём этого PR): `public_url`/
> `active-mirrors`/`AssetMirrorsResolver`/`MirrorBackfillPublisher`/HEAD-verify — весь
> код и вся тестируемая логика уже здесь и покрыты тестами, а вот собственно
> `workflow_dispatch`-триггеры (`registry.yml`, бэкофилл-джоб) и проброс новых аргументов
> `build-registry.php`/шаг `git commit active-mirrors` в существующие воркфлоу — ещё
> предстоит добавить отдельно, тем, у кого есть право трогать `.github/workflows/`.

**Реестр на зеркалах.** Само `plugins-registry.json` (+ отсоединённая подпись
`plugins-registry.json.sig`) тоже льётся в **корень** каждого зеркала
(`<dir>/plugins-registry.json`), чтобы зеркало было самодостаточным источником:
клиент тянет и реестр, и ассеты с одного хоста. `tools/push-mirror-registry.php`
читает тот же `MIRROR_CREDS` и в цикле по зеркалам заливает оба файла:

```bash
MIRROR_CREDS='...' php tools/push-mirror-registry.php <registry.json> <signature.json.sig> MIRROR_CREDS
```

В отличие от ассетов версии (неизменяемое дерево, никогда не перезаписывается) реестр
**мутабелен** — меняется на каждый релиз (новый `sequence`/подпись) — поэтому оба
файла **перезаписываются**. Реестр заливается перед подписью (`registry` раньше
`.sig`): в короткое окно между двумя загрузками клиент видит свежий реестр против
старой подписи, проверка падает, и он остаётся на последнем валидном реестре из кэша —
безопасная деградация, а не доверие рассогласованию. Как и у ассетов, пустой/незаданный
`MIRROR_CREDS` → код 0 без действий. Заливка идёт после подписи и **до** коммита
реестра в master (провал → нет коммита → ретрай на следующем прогоне; последовательность
живёт в `.github/workflows/`, вне этого README).

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
# <active-mirrors-file>/<mirror-creds-env-var-name> опциональны: без них asset_mirrors —
# GitHub-only (как до issue #26). С ними — [GitHub] + активные реплики (см. "Зеркала").
php tools/build-registry.php plugins published-versions.json [<previous-registry.json>] \
  [<active-mirrors-file> <mirror-creds-env-var-name>]
php tools/sign-registry.php <registry.json> <secret-key-env-var-name>
php tools/verify-registry-signature.php <registry.json> <signature-file> <public-key-file>
php tools/generate-registry-keypair.php <public-key-out-file>

# Залить ассеты версии на все зеркала из MIRROR_CREDS (см. "Зеркала" выше). Код 0 без
# заливки, если переменная не задана/пуста или декодируется в пустой JSON-объект —
# зеркала опциональны. Код ≠0 при сбое ЗАЛИВКИ на любое зеркало (HEAD-проверка после
# заливки — soft-fail, только предупреждение, не влияет на код возврата).
php tools/push-mirror-assets.php <id> <version> <dir-с-ассетами> <mirror-creds-env-var-name>

# Залить подписанный реестр (plugins-registry.json + .sig) в корень каждого зеркала из
# MIRROR_CREDS — перезапись (реестр мутабелен). Те же коды возврата, что у ассетов.
php tools/push-mirror-registry.php <registry.json> <signature-file> <mirror-creds-env-var-name>

# Активировать/бэкофилльнуть зеркало: залить ВСЕ исторические версии из GitHub Releases
# на <mirror-id> и, только при полном успехе (заливка + HEAD-верификация каждой), дописать
# id в <active-mirrors-file> (см. "Активация зеркала" выше). HEAD здесь — hard-fail.
php tools/backfill-mirror.php <mirror-id> <mirror-creds-env-var-name> <active-mirrors-file>

# Сгенерировать markdown-тело релиза одного плагина — по аналогии с кнопкой GitHub
# «Generate release notes», но с фильтрацией по plugins/<id>/ (монорепо с несколькими
# плагинами и общей инфраструктурой) и с авторитетным commit→PR через `gh api
# .../commits/<sha>/pulls`, а не регуляркой по "#NN" в subject (см. issue #28: в этом
# репозитории "#NN" в subject часто ссылается на issue, а не на PR). Чистый инструмент:
# без сети и без git внутри себя, сбор сырья и вызов из релизного воркфлоу — за ментейнером.

# 1) выбрать предыдущий тег плагина (семантическое сравнение версий, не sort -V):
git tag --list 'animedb-shikimori/*' | php tools/build-release-notes.php pick-prev 0.2.0

# 2) отформатировать тело релиза из JSON коммитов диапазона (с полем "prs" на коммит):
echo "$COMMITS_JSON" | php tools/build-release-notes.php format \
  --id animedb-shikimori --version 0.2.0 --repo anime-db/anime-db-plugins \
  [--prev animedb-shikimori/0.1.0]

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

## Лицензия

[GNU General Public License v3.0 or later](LICENSE) (GPL-3.0-or-later). Полный текст —
в файле [`LICENSE`](LICENSE); он же кладётся в корень каждого собираемого ZIP плагина.
