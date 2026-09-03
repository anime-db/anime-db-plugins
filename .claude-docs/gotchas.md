# Gotchas

## Plugin class loading does NOT go through `composer.json`

The host application (`anime-db-desktop`, `PluginLoader`) loads a plugin's classes via its
own `spl_autoload_register`, not via the plugin's `composer.json`:

- the plugin's root namespace is **derived from its id**
  (`AnimeDb\Plugins\<StudlyCase(id)>`, e.g. `animedb-shikimori` → `AnimeDb\Plugins\AnimedbShikimori`);
- the host's autoloader maps that derived prefix directly to the plugin's `src/` directory;
- `manifest.json` intentionally carries neither a namespace nor a bundle class name;
- the host never opens the plugin's `composer.json` at runtime, and `vendor/` is not part of
  the plugin ZIP build.

**Do not** make `PluginValidator` (or the README's description of "plugin shape") treat
`composer.json` `autoload.psr-4` as a runtime loading mechanism. A plugin is `manifest.json`
+ `src/*.php`; that's the full runtime contract.

`composer.json` in a plugin, if present, is a **dev-only artifact** — it pulls in
`anime-db/plugin-contracts` and lets you run phpunit/phpstan/php-cs-fixer locally against
`src/`. It has no bearing on the host or on how the plugin loads.

This was implemented as a mandatory requirement once (commit `672cd74`, PR #5) on the
mistaken premise that the host reads `composer.json` for autoloading, then reverted
(issue #6) once that premise was shown to be false. If a future change wants to check
`composer.json` for dev convenience, keep it an optional warning, not a required-shape
check — and don't describe it as how the host loads classes.

## Never pipe `build-registry.php`'s stdout onto its own `<previous-registry.json>` argument

`tools/build-registry.php plugins published-versions.json plugins-registry.json` reads
its 3rd argument to derive the next `sequence` (`previous.sequence + 1`, anti-rollback).
If a CI step naively redirects `> plugins-registry.json` using that *same* path as both
the previous-registry argument and the output target, the shell truncates the file
(setting up the redirect) **before** exec'ing PHP — so the script reads back an empty
file instead of the real previous registry, and the rollback check is silently defeated.

Always write to a new path first, then move it into place:

```bash
php tools/build-registry.php plugins published-versions.json plugins-registry.json \
  > plugins-registry.json.new
mv plugins-registry.json.new plugins-registry.json
```

See issue #12 and the README's "Маркет и версии" section.

## Issue #26 (mirror `public_url`/`active-mirrors`): tooling is done, workflow wiring is not

`MIRROR_CREDS` now carries a `public_url` per mirror (`MirrorCredential`,
`MirrorCredentialsParser`), a git-tracked `active-mirrors` file gates which of those get
advertised (`ActiveMirrorsFile`, `AssetMirrorsResolver`), `tools/build-registry.php` takes
two new optional argv (`<active-mirrors-file> <mirror-creds-env-var-name>`) to wire that
in, `tools/push-mirror-assets.php` now does a soft-fail HEAD-verify after upload, and
`tools/backfill-mirror.php` (`MirrorBackfillPublisher`) re-projects a mirror's full
history from GitHub Releases and hard-fails on any unreachable asset. All of this is
implemented and unit/CLI-tested (see README's "Зеркала" section for the full picture).

**What is deliberately still missing:** nothing in `.github/workflows/` calls any of
this. `registry.yml` has no `workflow_dispatch` trigger, its "Build registry" step does
not pass the two new argv to `build-registry.php`, and there is no dispatch job wired to
`tools/backfill-mirror.php` (which would also need a `git add active-mirrors && git
commit && git push` step after it succeeds — the script itself never touches git, same as
every other tool here). This was a boundary of the task that implemented the above (an
automated PR forbidden from touching `.github/workflows/`), not a design decision — a
future change wiring these up should read this file's tools before writing new CI logic,
not reimplement the resolution/verification logic inline in YAML.

## Manifest `name`/`description` are literals, not translation keys

A plugin's `manifest.json` `name` and `description` are self-sufficient literal strings in
the plugin's own default language — **never** translation keys. The manifest is a standalone
descriptor read catalog-free: the market registry build (`tools/build-registry.php` embeds
`name`/`description` into `plugins-registry.json` with no translator or locale), the host's
pre-activation install UI, and the manifest validator all consume it without any translation
catalog loaded. A `plugin.description` key would land in the registry and UI verbatim.

Localizable strings belong to the *UI* class instead, resolved by the host in the plugin's
`translations/` catalog (domain = plugin id): settings/OAuth template labels, and widget
`WidgetMetadata::$titleKey`/`$descriptionKey`. Do not try to unify the two — the split is the
manifest-self-sufficiency invariant, not an oversight (same reasoning as "the contract does
not know about the host").

If a multilingual manifest `description` is ever needed, the only compatible design is inline
localized variants inside the manifest itself (top-level literal = default/fallback), not
external keys.

## The root `composer.lock` is untracked **on purpose** — do not "fix" it

`.gitignore` excludes `composer.lock` at every level, so every `composer install` in CI runs
on a clean clone with no lock — effectively a `composer update`. The root `composer.json` is
`"type": "project"`, so this reads like an oversight against Composer's own guidance. It is
not. Committing the lock was proposed and rejected (PR #83).

Why floating dependencies are the right call **for this repository specifically**:

- **Nobody would keep the lock fresh.** This is not an application: it is not deployed, and
  its plugins change far more often than its tooling. There is no event that would prompt
  anyone to run `composer update` here, so a committed lock would silently pin ancient
  tooling while CI stayed green. A stale lock is worse than no lock.
- **The drift *is* the maintenance trigger.** When PHPStan or PHP-CS-Fixer ships new rules,
  the findings land on the next PR, which is the cue to fix them. With a lock they would
  wait for an update that never comes.
- **Nothing published depends on it.** `symfony/yaml` is used only by
  `tools/src/PluginValidator.php`, which runs only in `pr-validation.yml`. The publishing
  path — `registry.yml` (`build-registry.php`, `sign-registry.php`,
  `verify-registry-signature.php`, `push-mirror-registry.php`) and `release.yml`
  (`build-plugin-zip.php`, `push-mirror-assets.php`, `build-release-notes.php`) — handles
  JSON, libsodium, ZIP and FTP, and never touches YAML. So dependency drift can only turn a
  PR red; it cannot alter a signed artifact. Registry integrity rests on the Ed25519
  signature, not on dependency pinning.

Known cost, accepted: an unrelated tooling bump reddens a PR whose author changed nothing
related. That is the intended trigger, but it collides with the rule that an incidental
finding gets its own task rather than being folded into an in-flight one. When it happens,
fix it in a separate PR.

## `anime-db/plugin-contracts` is pinned `~0.14` on purpose — a *wide* range, not a typo

`~0.14` means `>=0.14.0 <1.0.0`, so every pre-1.0 minor of the contract flows in
automatically. That is wider than `^0.14` (`>=0.14.0 <0.15.0`), and it is deliberate. Do not
"tighten" it to `^0.14` to match the host.

Why a wide range is right *while the contract is pre-1.0*:

- Contract minors are breaking only because there has been no 1.0 yet. That is the phase, not
  the policy. The `1.0` release is precisely where breaking changes start deserving a detailed
  BC review — and where `^1.0` will give this same "minors flow in" behaviour safely.
- The tooling's contract surface is narrow: `ManifestValidator` and `PluginType`, nothing
  else. It is manifest validation, not the plugin runtime API that a breaking minor actually
  churns.
- Following the contract forward is the point. The constraint sat at `^0.6` for months while
  the contract reached `v0.14.0`, and nothing ever went red — the same "falls behind while CI
  stays green" failure that motivated leaving `composer.lock` untracked (see above). A narrow
  range froze it just as effectively as a lock would have.

Do not conclude from "it worked fine on `^0.6`" that the dependency is unnecessary. It is
load-bearing for the opposite reason: `ManifestValidator` and `PluginType` are the single
definition of what a valid manifest is, shared with the host. Reimplementing manifest
validation inside `tools/` would create a second definition that drifts from the host's, and
the failure mode is a plugin that passes CI and then refuses to install (or the reverse).

**The host pins narrowly (`^0.14`) and that asymmetry is correct — do not harmonise the two.**
The rule is *implementers pin narrow, readers may float*:

| Consumer                  | Relationship to the contract                      | Constraint |
|---------------------------|---------------------------------------------------|------------|
| `anime-db-desktop` (host) | **implements** it — 34 contract types in use      | `^0.14`    |
| plugins (`manifest.json`) | **implement** it — that is what a plugin *is*     | `^0.14`    |
| this repo (tooling)       | **reads** it — `ManifestValidator` + `PluginType` | `~0.14`    |

The host sits on both sides of the contract: it implements the interfaces a plugin calls
(`QbittorrentDownloadService implements DownloadServiceInterface`, `PluginDataStore`,
`SettingsStore`, `OwnManifest`) and consumes the ones a plugin implements (`FillerInterface`,
`SearchByPluginInterface`, widget interfaces). A breaking contract minor forces code changes
wherever the contract is implemented, so those consumers must bump deliberately and see the
break. Here it changes only which manifests are considered valid, and following it forward is
the desired behaviour.

Known residual, accepted: a new contract minor therefore moves this repo ahead of the host
until the host is bumped too. A stricter new minor shows up as a red PR; a *more permissive*
one would pass green and accept a manifest the host still rejects at install. Revisit at
contract `1.0`, where `^1.0` gives the same "minors flow in" behaviour to everyone safely.

## `translation_keys_count` is a deliberate second manifest definition, not an oversight

The previous section establishes `ManifestValidator` + `PluginType` from
`anime-db/plugin-contracts` as **the** single definition of a valid manifest, shared with
the host, precisely so `tools/` never drifts from what the application accepts. The
`translation_keys_count` field (see `.claude-docs/conventions.md`, "A `translation` plugin
declares its catalog's leaf key count in the manifest") is an intentional exception to that
rule, not a violation someone should "fix" by moving it into the contract:

- `ManifestValidator` rejects specific known-but-inapplicable fields per type (e.g. `features`
  for `translation`/`local`) — the same mechanism `PluginValidator` reuses on its own terms to
  reject `translation_keys_count` for `integration`/`local`. Since contract `v0.15`, `locales`
  is no longer one of those rejected fields for `integration`/`local`: it is now a field the
  contract itself validates when present for every type (required for `translation`, optional
  otherwise) — see `PluginValidator`'s own `locales`-vs-catalogs check for why it still matters
  there. An *unrecognised* field like `translation_keys_count` simply passes through
  `ManifestValidator` unvalidated.
- `ManifestParser::buildManifest()` (also in the contract) assembles a `Manifest` DTO from a
  fixed set of known fields, so an unrecognised field never reaches the DTO either — nothing
  downstream that consumes a `Manifest` object would ever see it.
- The application does not read this field from the manifest at all: it reads a plugin's
  translation coverage from the registry's version record, populated by this repo's own
  `tools/build-registry.php`, not from `Manifest`.

So the field is deliberately validated **only** by `tools/src/PluginValidator.php`, entirely
outside the shared contract. If a future change ever needs the contract to know about this
field too, that is a new decision requiring its own contract release and version bumps in
both repositories — do not assume the current gap is accidental and paper over it by either
loosening `PluginValidator` or trying to smuggle the field through `ManifestParser`.

## This repo's CI is stricter than the application's plugin installer

`tools/src/PluginValidator.php` runs only in this monorepo's own CI
(`pr-validation.yml`), against the plugin a pull request touches. The application's
installer, which accepts a plugin ZIP from *anywhere* (not just this repo's market), never
runs it. Concretely, for `translation_keys_count`: the application would accept a
`translation` plugin ZIP with `"translation_keys_count": "95"` (a string, not an integer), a
count three times too large or too small, or the field missing outright — none of that is
checked at install time. This repo's CI, by contrast, **must** reject all three; that is the
entire point of the check (see `.claude-docs/conventions.md` for why the storefront needs
the number to be trustworthy before install).

This asymmetry is harmless today only because the market registry is the sole path
`plugins-registry.json` is built from, and this validator is the only gate on that path.
It stops being harmless the moment anyone treats "the installer didn't complain" as
evidence a manifest is valid — it is not, and was never meant to be.

## `tools/check-version-bump.php` (issue #87): the "published content" equivalence is not total

`tools/src/PublishedContentRules.php` is the single definition of which files inside a
plugin directory are "published content" — the ones `PluginZipBuilder` archives into the
distributable ZIP. `PluginZipBuilder` and `VersionBumpChecker` (the CI gate requiring a
`manifest.json` version bump whenever that content changes) both read this one class, so
the exclusion list itself cannot drift between the two. But "read the same list" is not
the same as "compute the exact same set of files", for two reasons the list itself cannot
close:

- **Path-shape mismatch.** `PublishedContentRules::isExcluded()` takes a path relative to
  the *plugin* directory (`PluginZipBuilder`'s own frame, e.g. `"tests/Foo.php"`); the gate
  only has repo-relative paths from `git diff` (e.g. `"plugins/<id>/tests/Foo.php"`). The
  conversion is not implicit — `PublishedContentRules::isExcludedRepoRelative($pluginId,
  $repoRelativePath)` does it explicitly and is the only method the gate is meant to call.
  Do not have the gate strip the `plugins/<id>/` prefix inline and call `isExcluded()`
  directly; that duplicates the one line of logic this method exists to own.
- **Symlinks are invisible to a path-list check.** `PluginZipBuilder::collectFiles()`
  additionally drops any path that is a symlink (`!$fileInfo->isLink()`), independent of
  `PublishedContentRules` — a symlink pointing outside the plugin directory must never be
  archived, regardless of what its own path looks like. `VersionBumpChecker` only ever sees
  a list of path strings (from `git diff --name-only`), which carries no filesystem
  metadata — it cannot know a given path is a symlink versus a regular file. A PR that
  turns a real file into a symlink to the same relative path changes what
  `PluginZipBuilder` would put in the archive but leaves `VersionBumpChecker` seeing the
  "same" path, so it can under-flag.

There is a third gap the shared list does not even attempt to model, because it is not
about a *plugin's own* files at all: `PluginZipBuilder::build()` injects the
monorepo-root `LICENSE` into every plugin's archive (see `LICENSE_ENTRY_NAME` and the
`$licenseFile` parameter). Editing that one root `LICENSE` changes the content of every
published plugin ZIP, but it is not a path under any `plugins/<id>/`, so `git diff`
touching only `LICENSE` never engages `VersionBumpChecker` for any plugin at all — no
version bump is asked for, for any of them. Cost today is zero (the file has never
changed since plugins started shipping it), but a future edit to the license text would
need a manual, out-of-band decision about which plugins (all of them) need a release, and
this gate would not raise that question on its own.

**Accepted cost, not a bug:** `README.md` is published content — `PluginZipBuilder`
archives it, so `PublishedContentRules` does not exclude it, so fixing a typo in a
plugin's `README.md` requires bumping its `version` and produces a new release. This
follows directly from "published content" being a real definition (what actually ships)
rather than a hand-tuned exception list; narrowing it to spare README-only edits would
make "published content" mean two different things in two different places again — see
issue #87 for the reasoning.

**Not wired into `pr-validation.yml`.** `tools/check-version-bump.php` is a complete,
independently runnable CLI (`git diff --name-only <base>...<head> | php
tools/check-version-bump.php <base-ref>`, exit 0/1) with its own test coverage of all
seven edge cases (new plugin, removed plugin, tests-only change, non-`plugins/` change,
same version, lowered version, already-tagged version) plus the tag-existence-check-itself-
failed case: `GitTagExistenceChecker` (`git ls-remote --exit-code --tags origin`) tells
"tag not found" (exit `2`) apart from "could not check" (any other non-zero exit — network,
missing `git`, etc.) via `TagExistenceCheckFailedException`, and `VersionBumpChecker`
reports that as a violation rather than treating it as "not found" — fail-closed, not
fail-open. Whether and where to call it from
`pr-validation.yml` is left to whoever integrates it — same split the README's "Тулинг"
section already documents for every other tool here ("чистый CLI ... без обвязки CI"),
and the same boundary already hit once before for the mirror `public_url` tooling (see
"Issue #26" above in this file).

## The plugin gate: separate entry point, and the analysis environment is *this repo's* vendor

`phpstan.neon.dist` covers `tools/` only. Plugin code is analysed by a second entry point —
`tools/analyse-plugin.php` with `tools/phpstan-plugin.neon.dist`. The two configs answer
different questions: the root one holds this repository's own tooling to its own standard,
the other holds a plugin to the contract with the rules of `anime-db/plugin-contracts`
switched on.

**`symfony/http-foundation`, `symfony/http-kernel`, `symfony/security-csrf` and `twig/twig`
are in this repository's `require-dev` even though `tools/` never touches them.** They are not
leftovers and must not be pruned as unused: they *are* the analysis environment. A plugin
ships no `vendor/` — `PluginZipBuilder` excludes it, the host supplies every class the plugin
uses — so a plugin can only rely on what the host has. Declaring the host's surface once, here,
is what makes that limit checkable. It follows that the gate runs **no `composer install`
inside a plugin directory and never reads the plugin's own `vendor/`**: installing what a
plugin asked for in its own (untrusted, same-pull-request) `composer.json` would check it
against a surface that will not exist at runtime. A plugin needing something else is red until
a maintainer deliberately widens the surface — which is the point of a curated market.

Three more things look like implementation detail and are not:

- **The working directory is the repository root, not the plugin directory.** Run from inside
  the plugin, PHPStan takes that plugin's `vendor/` as the analysed project — its dependencies
  *and* its own copy of `anime-db/plugin-contracts`, rules included, would be what judges it.
  `--autoload-file` has the same effect for the same reason: Composer registers its autoloader
  with `prepend = true`, so the plugin's loader wins. Verified by planting a marker method in a
  tampered contract copy under `plugins/<id>/vendor/`: from the plugin directory it is what
  gets reflected, from the repository root it is invisible.
- **The analysed set is the published set, not `src/`.** The file list comes from
  `PublishedContentRules` — the same definition `PluginZipBuilder` archives and the
  version-bump gate watches. Narrowing it to `src/` opens a hole exactly the width of a
  `require`: a *local* include is legitimate (`NoDangerousPrimitivesRule` forbids only URL
  ones), so a plugin can keep `src/` spotless and put `exec()` in a `templates/*.php` that
  ships in the very same ZIP. `tests/fixtures/gate-probe/templates/inline.php` fails the moment
  someone narrows it back.
- **It rejects PHPStan's inline ignore annotation in published files.** One comment above a
  line suppresses every rule on it, these two included, and a green run keeps no trace of it.
  Silencing a linter is the ordinary reaction to a red build, not an obfuscated bypass, so a
  gate that accepts one gates nothing. A plugin's own `tests/` are unaffected — they are not
  published and not analysed. Note the annotation cannot be *named* in a comment inside
  `tools/analyse-plugin.php` itself: PHPStan reads that file too and parses the mention as a
  real annotation. It lives in a string literal for that reason.

**Known and accepted: the analysis environment is Symfony `^6.4` while the host runs Symfony
`8.1.*` on PHP 8.5.** Not a choice — measured. `phpstan/phpstan` here is `^1.10`, and PHPStan
1.12 cannot read Symfony 8.1 sources at all: every `Symfony\Component\HttpFoundation\*`
comes back as "unknown class" even though PHP parses those files fine, which turns 2 real
reports into 23. Raising the environment to the host's means PHPStan `^2.2` first — and the
contract's own rules are written against the PHPStan 1.x API, so that is a change in
`anime-db/plugin-contracts`, not here. Until then the gate checks plugin code against a
Symfony two majors older than the one it will run on: fine for what the two contract rules
look at, wrong for anything level 8 concludes about Symfony APIs specifically.

**Not a problem, and settled — do not re-open it:** the floating `~0.14` means a new contract
minor changes which signatures every plugin is judged by. The red only ever lands on the plugin
a pull request is editing, and that is the prompt for its author to bring it up to the current
contract. This is the same "following the contract forward is the point" the `~0.14` section
above already settles; the registry recording `plugin_contracts` per published version (issue
#108) does not conflict with it either — that field describes releases already published, not
the bar for the next change.

## A gate nobody points at anything is indistinguishable from a gate that finds nothing

`NoDangerousPrimitivesRule` and `ContractConformanceRule` were written, released, wired into
`extension.neon`, and applied to exactly zero plugins for the whole life of the repository
(issue #111). Nothing was broken: the rules worked, the config just listed `tools/`, and
`extension.neon` was never included at all — no `phpstan/extension-installer`, no `includes`.
Every CI run was green and every green run meant both "no violations" and "nothing analysed".

Hence `tests/AnalysePluginCliTest.php` and the fixtures under `tests/fixtures/`, which violate
the rules on purpose. Four properties of that test are load-bearing:

- **It asserts the reported messages, not the exit code.** An exit-code-only assertion would
  pass just as happily if the analysed path had been broken — PHPStan exits non-zero for that
  too — which is the same false green in a new costume.
- **It reads `.github/workflows/pr-validation.yml` as data** and asserts a step actually
  invokes `tools/analyse-plugin.php`, after the "one plugin / only its code" gate, under the
  same `touches_plugins` condition, against the plugin named in `affected-plugin.txt`. Drop any
  one of those and the step still "runs the gate" while being green forever; and that half
  cannot be checked by running the analysis.
- **It runs the gate over every plugin in `plugins/`.** In CI the gate only ever sees the
  plugin a pull request touches, so nothing otherwise notices when a plugin nobody is editing
  falls out of conformance — the first author to open an unrelated pull request inherits the
  red. This is also the only consumer of the framework packages in `require-dev`.
- **The contract drift it pins is a widened *parameter*, not a narrowed *return*.** The first
  run of the gate over `animedb-shikimori` reported its `list<X>` annotations against the
  contract's `X[]` — the plugin being *more* precise than the interface. That turned out to be
  a defect in the contract, not in the plugin: the host already declares
  `list<SearchByPluginCandidate>` in `SearchByPluginChain` and normalises the plugin's result
  with `array_values()` precisely because the contract promises a possibly-keyed array. Fixed
  in the contract (`anime-db/anime-db-plugin-contracts#69`, minor `v0.17.0`), which the
  floating `~0.14` picks up on its own.

  The general shape survives that fix and is worth knowing: `ContractConformanceRule` compares
  rendered signatures for **exact equality**, so a plugin can never be more precise than the
  contract *anywhere* — the contract's phpdoc precision is a ceiling for every plugin at once.
  Whether the rule should allow legal variance instead is an open question about the rule, and
  a fixture must not quietly turn one answer into the specification. Hence the fixture drifts
  in the direction nobody disputes.

The fixtures live under `tests/fixtures/`, never under `plugins/`: a directory under
`plugins/` is picked up by the registry build and shipped as a release. `gate-probe` is also
excluded from `.php-cs-fixer.dist.php` — `@Symfony` enables `backtick_to_shell_exec`, which
would rewrite `` `whoami` `` into `shell_exec('whoami')` and collapse two of the expected
reports into one.
