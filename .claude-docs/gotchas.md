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

Known residual, accepted: the host (`anime-db-desktop/app/composer.json`) pins `^0.14`, so a
new contract minor moves this repo ahead of the host until the host is bumped too. A stricter
new minor shows up as a red PR; a *more permissive* one would pass green and accept a manifest
the host still rejects at install. Revisit the constraint at contract `1.0`.
