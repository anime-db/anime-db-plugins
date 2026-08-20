# CLAUDE.md — anime-db-plugins

Entry point for Claude Code agents working in this monorepo. For deeper reference see
[`.claude-docs/`](.claude-docs/gotchas.md).

## Documentation index

- [.claude-docs/gotchas.md](.claude-docs/gotchas.md) — non-obvious footguns, in particular
  how the host actually loads plugin classes
- [.claude-docs/conventions.md](.claude-docs/conventions.md) — translation catalog
  conventions shared with the core (pluralization, empty values, Russian terminology)

## Commands

```bash
composer install
php tools/validate-plugin.php plugins/<plugin-id>   # validate a single plugin
git diff --name-only master... | php tools/check-pr-changes.php  # PR-touches-one-plugin gate
composer test       # PHPUnit
composer phpstan     # static analysis
composer cs-check    # code style check (php-cs-fixer, --dry-run)
```

## Boundaries

### MUST

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
  `transChoice`) or leave a translation key with an empty value, and do not use a Russian
  term the core has already retired (`эпизод`, `тайтл`, `тег`) in a plugin's `.ru.yaml`
  catalog. See [.claude-docs/conventions.md](.claude-docs/conventions.md).

## Workflow

- Default branch: `master`.
