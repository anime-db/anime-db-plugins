# Conventions

## A catalog's domain depends on the plugin type

Symfony derives a catalog's translation domain from its file name — `<domain>.<locale>.<format>`
— so the file name is not cosmetic: it decides which domain the strings land in, and a
catalog in a domain nobody resolves is dead weight that no check can distinguish from a
working one.

| Plugin type             | Catalog file name           | Domain      | What it translates                                        |
|-------------------------|-----------------------------|-------------|-----------------------------------------------------------|
| `integration` / `local` | `<plugin-id>.<locale>.yaml` | plugin id   | the plugin's **own** strings: settings labels, OAuth pages, widget titles |
| `translation`           | `messages.<locale>.yaml`    | `messages`  | the **core** interface — the plugin extends the core catalog rather than shipping its own |

The split follows from what each plugin type is for. An `integration`/`local` plugin adds
strings of its own, so it owns a domain named after itself. A `translation` plugin is a
language pack: it exists to translate the application's interface, and the core resolves
that interface from a single `messages` domain (core decision, issue #84 of the
application repository — `TranslationController` serves
`getCatalogue($locale)->all('messages')`).

Both directions are wrong and both are rejected by `tools/src/PluginValidator.php`:

- a `translation` plugin naming its catalog `<plugin-id>.<locale>.yaml` creates a domain
  nothing resolves — the file is in place, every other check passes, and not a single
  string gets translated;
- an `integration`/`local` plugin shipping `messages.<locale>.yaml` reaches into the
  core's own domain, which is not a feature plugin's business.

Note that the guarantee "the core wins a key collision" covers the `messages` domain only.
A plugin catalog placed in a third party's domain (say `security.<locale>.yaml`, owned by
`symfony/security-core`) will override that package's strings: vendor catalogs are added
before plugin paths in the container. Nothing depends on this today, but it is what the
mechanism does, not a promise it makes.

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

- **Shared Russian terminology — recommendation, not a gate.** The core has already
  settled on Russian terms for several UI concepts; a plugin's Russian catalog should
  reuse them instead of a synonym, or the same concept reads under two different names
  depending on whether the string came from the core or a plugin. This glossary matches
  **concept to term, not word to word** — apply it by what the string means, not by which
  substring appears in it:

  | Concept | Russian | English | Avoid |
  |---|---|---|---|
  | a watchable unit | серия | episode | эпизод |
  | a work's title (as a name/label) | название | name / title | тайтл (when it means "title") |
  | a user-assigned label | метка | label | тег |

  This is deliberately **not** enforced by `PluginValidator`: a word-level stop-list was
  tried and reverted (issue #60). A word can carry a different concept depending on
  context — "тайтл" can mean either "an anime" (the work itself) or "a title/heading" (a
  string) depending on the sentence, and only the second sense maps to "название". A
  mechanical replacement of every "тайтл" with "название" is wrong for the first sense:
  e.g. *"Показывает похожие тайтлы, подобранные Shikimori."* (a widget description saying
  the results are similar *anime*) must become *"Показывает похожие аниме, подобранные
  Shikimori."*, not *"...похожие названия..."* — "названия" reads as "similar strings",
  which is not what the widget shows. A regex also cannot reliably tell the two senses
  apart (and a word-form-aware pattern for "тег" flags unrelated words like "Тегеран"), so
  a human editorial judgment call is required instead of a mechanical gate.

## `manifest.json` `name`/`description` must be literals, never translation keys

See the "Manifest `name`/`description` are literals, not translation keys" entry in
[gotchas.md](gotchas.md) for the full reasoning (manifest is read catalog-free by the
registry build, the pre-activation install UI, and the validator itself). `PluginValidator`
now also rejects a `name`/`description` value equal to a key already present in the
plugin's own `translations/` catalog (source: issue #60). A shape-based heuristic
(`word.word`-looking values) was tried and reverted: it false-positived on real values
like a plugin's own domain name (e.g. `MyAnimeList.net`), which is a plain literal, not a
key.
