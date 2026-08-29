# Conventions

## Any change to a published plugin needs a version bump

`manifest.json`'s `version` is not bookkeeping — it is the only thing that decides whether
a change ever reaches users. Change a plugin's contents without touching it, and the
change is **silently dropped at release time**.

The mechanism, from `.github/workflows/release.yml` (runs on every push to `master`):

```bash
version="$(jq -r '.version' "$manifest")"
tag="$id/$version"
if gh release view "$tag" >/dev/null 2>&1; then
  echo "Release $tag already exists — skipping."
  continue
fi
```

The release is keyed on the tag `<plugin-id>/<version>`. If that tag exists, the whole
publish step for that plugin is skipped: no new ZIP is built, no new `sha256` is computed,
and `plugins-registry.json` keeps pointing at the previous artifact. The repository holds
the new content, users keep getting the old one, and nothing anywhere reports a problem —
the only trace is one `already exists — skipping` line in a CI log nobody reads.

So the rule is mechanical: **the moment a plugin's published contents change, bump
`version` in the same pull request.** Whether a plugin is published is answered by
`plugins-registry.json` — an entry under `versions` with a `sha256` means it is.

Which part to bump:

| Change | Bump |
|---|---|
| new locale, new feature, new setting | minor — the plugin gained something, nothing broke |
| fixed translation wording, corrected typo, removed a stale key | patch |
| dropped a locale, renamed a setting key, anything an installed copy could trip over | major |

A plugin still absent from `plugins-registry.json` has never been published: reusing its
version number is fine until the first release lands.

There is no per-plugin changelog — the release notes are generated from commits, so the
commit message is where the "what changed" lives.

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

## A `translation` plugin declares its catalog's leaf key count in the manifest

The application and a `translation` plugin share one key space — the core `messages`
domain — but live in separate repositories, and the market registry has no way to see
inside a plugin's ZIP before it is installed (the registry carries the manifest, not the
catalogs). Without a declared number, a language pack could ship a handful of translated
keys out of several hundred, pass every other check, and the market's storefront would
have no way to tell it apart from a complete translation.

`manifest.json` for a `translation` plugin must therefore declare `translation_keys_count`:

```json
{
    "id": "animedb-language-pack",
    "type": "translation",
    "locales": ["de", "ja"],
    "translation_keys_count": 358
}
```

`tools/src/PluginValidator.php` requires the field for `type: translation`, checks that it
is an integer, and checks that it equals the number of leaf keys actually present in the
plugin's own catalog — the same flattened key union already computed for the
"`name`/`description` must not equal a catalog key" check below. Because the domain-per-type
check above already forces every locale of a `translation` plugin to carry the same key
set (a mismatch is its own separate error), that single count applies to every locale, not
just one — a per-locale map would only be needed if that key-parity guarantee were ever
relaxed.

The field is **rejected outright** for `integration`/`local`: those plugins' catalogs live
in their own id-derived domain, and "coverage of the application's interface" is not a
meaningful number for them. `locales` is a different story — since contract `v0.15` every
plugin type may declare it, see the section below.

A count is deliberately not a key list: 358 keys as compact JSON is several kilobytes,
against a few kilobytes for the entire current `plugins-registry.json` (parsed whole into
memory by the client). A single integer is also all the storefront needs — it shows
"64% translated", not the exact 15 keys still missing. This does allow the count to be
technically imprecise (equal counts do not guarantee an identical key *set*, and the count
can end up larger than the application's current key count if the application has since
dropped keys the pack still carries) — an accepted, documented trade-off; the exact diff is
computed by the application at install time against the catalogs actually installed, not by
this field.

## `locales` must match the catalogs the plugin actually ships

Since `anime-db/plugin-contracts` `v0.15` the manifest field `locales` is available to
**every** plugin type, with one meaning: the languages of the translation catalogs this
plugin ships. What follows from that is decided by `type`, not by the field — a
`translation` plugin's catalogs live in the `messages` domain and therefore extend the
application's language switcher, a feature plugin's catalogs live in its own domain and
do not.

The contract requires the field for `type: translation` and treats it as optional for
`integration`/`local`. `tools/src/PluginValidator.php` is stricter, and deliberately so:

| Catalogs in the type's domain | `locales` in the manifest | Verdict                                   |
|-------------------------------|---------------------------|-------------------------------------------|
| present                       | declared and matching     | valid                                     |
| present                       | missing                   | error — the plugin ships languages it never advertises |
| present                       | declared, sets differ     | error, in both directions                 |
| absent                        | absent                    | valid                                     |
| absent                        | declared                  | error — advertises a language it does not ship |

The comparison runs against the domain the plugin's `type` prescribes (see "A catalog's
domain depends on the plugin type"), not against a hardcoded `messages.<locale>.yaml`.
A catalog file already rejected by an earlier check (wrong domain, unsupported format,
symlink) is left out of the comparison, so one bad file does not also produce a
locale-mismatch error next to its own.

Why this is gated rather than left to the author: the application shows a plugin's
languages **from this field** — on the market storefront before installation (it reads
`locales` out of the registry's version record) and on the installed-plugins page. A field
that drifts from the catalogs on disk is invisible to every other check here — key parity,
placeholders, domain all stay green — and turns straight into a false promise on the
storefront.

## Adding a plugin requires a `.github/CODEOWNERS` entry

`manifest.json`'s `author` field is a display string (e.g. `"AnimeDB"`), not a GitHub
handle — the manifest is self-sufficient and read catalog-free, so it must stay that way.
Nothing else in the repository maps a plugin id to who owns it, so `.github/CODEOWNERS`
carries that map instead, one explicit line per plugin directory:

```
/plugins/animedb-language-pack/ @peter-gribanov
/plugins/animedb-shikimori/     @peter-gribanov
```

The PR that adds a new plugin must add its owner's line in the same PR — there is no
separate id-to-handle list to fall back on, and an omitted entry silently resolves to "no
owner" rather than failing loudly. There is deliberately no catch-all (`* @handle`) line:
one would request review on every unrelated PR and would mask a missing per-plugin entry
by silently resolving it to a default owner instead.

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
