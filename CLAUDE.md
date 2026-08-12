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

## Public, general-purpose posture

This repository is public.

**The safeguard is the code and design, not the wording.** Keep capabilities genuinely
general-purpose — e.g. a configurable endpoint that defaults to one host and otherwise
uses whatever the user enters. Do not add purpose-specific artifacts: hardcoded lists of
alternate target hosts, auto-detecting a host's availability and switching, or
region / geography-specific logic. Those, not descriptions, are what make a capability
special-purpose.

**Then describe it truthfully and plainly** in every public string — inline text, class /
field / plugin / manifest names, docblocks and comments, README, commit messages, and
PR / issue / review text. Neutral wording is legitimate only when it is *true* of a
genuinely general-purpose capability (say what the field does, e.g. "an alternative API
host"). Do not phrase a special-purpose feature as general-purpose — that masks rather
than fixes. A feature whose only real purpose does not fit a public, general-purpose
package is a boundary decision — it belongs in a privately-distributed plugin outside
this registry — not a wording change.

## Workflow

- Default branch: `master`.
