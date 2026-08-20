# Conventions

## Plugin translation catalogs must follow the core's translation conventions

A plugin's `translations/` catalog and the core application's own catalogs are the same
user-facing interface, split across two repositories that ship on different schedules.
Without a shared, enforced convention they drift silently — a plugin catalog can pick up
a pattern the core has deliberately never used, and nobody notices until it's on screen.
`tools/src/PluginValidator.php` gates the following (source: issue #60):

- **No pluralization.** The core has no ICU domain, no `|` alternative-forms syntax, and
  no `transChoice` call anywhere — a countable string is phrased without grammatical
  agreement instead, e.g. `sync_review_badge: 'Требует внимания: %count%'`. This phrasing
  stays correct for any number of plural forms a locale's grammar has (3 in Russian, 2 in
  German, 1 in Japanese, 6 in Arabic). The validator rejects:
  - any translation value containing a `|` character;
  - any catalog file whose name carries the `+intl-icu` suffix before `.<locale>.yaml`
    (checked by suffix, not by a specific domain name, since a plugin's own domain varies:
    the plugin id for `integration`/`local`, `messages` for `translation`).

  Introducing pluralization is a project-wide architectural decision — it also implies
  switching to curly-brace ICU placeholder syntax — and a single plugin must not be able
  to opt into it unilaterally.

- **No empty values.** An empty string is not equivalent to a missing key: the
  cross-locale fallback never kicks in for a key that *exists* with an empty value, so the
  user sees a blank instead of falling back to another locale. A missing translation is a
  defect to fix, not a valid placeholder state.

- **Shared Russian terminology.** The core has already settled on Russian terms for
  several UI concepts; a plugin's Russian catalog must reuse them instead of a synonym, or
  the same concept reads under two different names depending on whether the string came
  from the core or a plugin:

  | Concept | Russian | English | Forbidden |
  |---|---|---|---|
  | a watchable unit | серия | episode | эпизод |
  | an anime's title | название | name / title | тайтл |
  | a user-assigned label | метка | label | тег |

  The stop-list (forbidden stems plus their word forms) lives in
  `PluginValidator::FORBIDDEN_RU_TERMS`, checked against `.ru.yaml` catalogs only. This is
  an error, not a warning: the list is short and unambiguous, and a warning nobody reads
  is not a gate. Extend the constant as the glossary grows.

## `manifest.json` `name`/`description` must be literals, never translation keys

See the "Manifest `name`/`description` are literals, not translation keys" entry in
[gotchas.md](gotchas.md) for the full reasoning (manifest is read catalog-free by the
registry build, the pre-activation install UI, and the validator itself). `PluginValidator`
now also rejects a `name`/`description` value that *looks* like it was meant to be a key:
equal to a key already present in the plugin's own `translations/` catalog, or shaped like
`word.word` with no spaces (source: issue #60).
