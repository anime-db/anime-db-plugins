# CLAUDE.md — anime-db-plugins

Entry point for Claude Code agents working in this monorepo. For deeper reference see
[`.claude-docs/`](.claude-docs/gotchas.md).

## Documentation index

- [.claude-docs/gotchas.md](.claude-docs/gotchas.md) — non-obvious footguns, in particular
  how the host actually loads plugin classes

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

## Workflow

- Default branch: `master`.
