# CLAUDE.md — anime-db-plugins

Entry point for Claude Code agents working in this monorepo. For deeper reference see
[`.claude-docs/`](.claude-docs/gotchas.md).

## Documentation index

- [.claude-docs/gotchas.md](.claude-docs/gotchas.md) — non-obvious footguns: how the host
  actually loads plugin classes, why `composer.lock` is untracked on purpose, why the
  `plugin-contracts` constraint is not stale, why `translation_keys_count` bypasses that
  contract, why this repo's CI is stricter than the application's installer, and why plugin
  code is analysed by a separate per-plugin entry point judged by *this* repo's contract
- [.claude-docs/conventions.md](.claude-docs/conventions.md) — plugin conventions:
  version bumps for published plugins, catalog domain per plugin type, the
  `translation_keys_count` and `locales` manifest fields, pluralization, empty values,
  recommended Russian terminology

## Commands

```bash
composer install
php tools/validate-plugin.php plugins/<plugin-id>   # validate a single plugin
git diff --name-only master... | php tools/check-pr-changes.php  # PR-touches-one-plugin gate
composer test       # PHPUnit
composer phpstan     # static analysis
composer cs-check    # code style check (php-cs-fixer, --dry-run)

# Analyse a plugin's own code with the plugin-contracts rules (the registry gate).
# `composer phpstan` above covers tools/ only and never touches plugins/.
composer install --working-dir=plugins/<plugin-id> --no-scripts --no-plugins
php tools/analyse-plugin.php plugins/<plugin-id>
```

## Boundaries

### MUST

- Bump `version` in a plugin's `manifest.json` in the same pull request that changes its
  published contents. `release.yml` keys the release on the tag `<id>/<version>` and skips
  the plugin entirely if that tag exists — the change never reaches users and nothing
  reports it. See [.claude-docs/conventions.md](.claude-docs/conventions.md).
- Keep `tools/src/PluginValidator.php` in sync with the actual host loading mechanism
  documented in [.claude-docs/gotchas.md](.claude-docs/gotchas.md) before adding new
  validation rules tied to "runtime loading".

### MUST NOT

- Do not require or validate a plugin's `composer.json` as a runtime class-loading
  mechanism — the host does not read it. See
  [.claude-docs/gotchas.md](.claude-docs/gotchas.md).
- Do not put translation keys in a plugin's `manifest.json` (`name`/`description`) — the
  manifest is self-sufficient and read catalog-free (registry/install/validator).
  Localizable strings use the plugin's `translations/` catalog (settings labels, widget
  `titleKey`/`descriptionKey`). See [.claude-docs/gotchas.md](.claude-docs/gotchas.md).
- Do not introduce Symfony pluralization (`|` syntax, an ICU `+intl-icu` domain,
  `transChoice`) or leave a translation key with an empty value. See
  [.claude-docs/conventions.md](.claude-docs/conventions.md) (that file also documents
  recommended, but not gated, shared Russian terminology).
- Do not name a catalog after the wrong domain: `<plugin-id>.<locale>.yaml` for
  `integration`/`local`, `messages.<locale>.yaml` for `translation`. A catalog in a domain
  nothing resolves passes every other check and translates nothing. See
  [.claude-docs/conventions.md](.claude-docs/conventions.md).
- Do not fold plugin analysis into `phpstan.neon.dist` (adding `plugins/` to its `paths`), and
  do not point `tools/phpstan-plugin.neon.dist` at a plugin's own
  `vendor/anime-db/plugin-contracts`. The first cannot resolve plugin code at all; the second
  lets a pull request supply the rules that judge it. Do not narrow the analysed set to
  `src/` either — it is the published set for a reason. See
  [.claude-docs/gotchas.md](.claude-docs/gotchas.md).
- Do not commit `composer.lock`, and do not "fix" `.gitignore` to allow it. Floating
  dependencies are deliberate here: nothing would keep a lock fresh, the drift is what
  prompts tooling maintenance, and no published artifact depends on it. Rejected in PR #83.
  See [.claude-docs/gotchas.md](.claude-docs/gotchas.md).
- Do not add `translation_keys_count` to a plugin of type `integration`/`local`, and do not
  omit it (or let it drift from the actual catalog) for `type: translation` — both are
  gated by `PluginValidator`. See [.claude-docs/conventions.md](.claude-docs/conventions.md).
- Do not let manifest `locales` drift from the catalogs the plugin ships. Since contract
  `v0.15` the field is available to every plugin type; `PluginValidator` compares it with
  the catalogs of the domain the type prescribes, in both directions, and requires it
  whenever such catalogs exist. The application shows a plugin's languages from this field,
  so drift becomes a false promise on the market storefront. See
  [.claude-docs/conventions.md](.claude-docs/conventions.md).

## Workflow

- Default branch: `master`.
