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
