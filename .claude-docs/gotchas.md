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
