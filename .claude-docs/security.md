# Security model (monorepo side)

## 1. Purpose

A security auditor agent reads this file before reviewing a diff or the repository. It describes
the model — which invariants must hold, where the PHPStan gate stops protecting, which risks are
already accepted by a public decision — not a list of findings. Invariants are anchored to
symbols, files and config keys; look them up by name, not by position.

This file covers the monorepo side only. How the application loads and executes plugins
(loading in the core process, marketplace trust, user-supplied ZIP) is described in
<https://github.com/anime-db/anime-db-desktop/blob/master/.claude-docs/security.md> and is not
restated here.

## 2. PHPStan gate and its limits

The gate is `php tools/analyse-plugin.php plugins/<id>`. It analyses every file the plugin
publishes (`PublishedContentRules`) with the rules declared in the `extension.neon` of
`anime-db/plugin-contracts`, taken from this repository's `vendor/` (config:
`tools/phpstan-plugin.neon.dist`). Rules:

- `NoDangerousPrimitivesRule` — a **denylist** of functions, classes and constructs (`exec`
  family, raw sockets, `curl_*`, `eval`, backticks, variable variables, URL `include`, URL
  arguments of file functions, instantiating a PSR-18 / Symfony HTTP client, etc.). It does not
  protect against obfuscation: it cannot see a call it cannot resolve statically. Passing the
  gate is not proof that a plugin is safe.
- `NoNetworkAccessInLocalPluginsRule` — a plugin of `type: local` must not declare a
  constructor parameter or property typed as a PSR-18 client, a PSR-17 request factory or a
  contract interface that talks to an external source.
- `ContractConformanceRule` — signatures of classes implementing a contract interface must match
  the installed contracts version; it reports drift, it is not a safety check on the behaviour.

`tools/analyse-plugin.php` additionally refuses any published file that is not `.php` but
contains a PHP open tag, because `require` executes any file name. Symlinks are skipped, the same
way `PluginValidator` skips them. Inline ignore annotations are refused.

Limit: the application's installer does not run this gate. It is a registry-side check on this
repository's PRs; a plugin installed by other means is not analysed. The execution model is in
the anime-db-desktop file linked above.

## 3. Invariants

Weakening any invariant below is a blocker/major finding.

Data from external metadata sources is untrusted:

- HTML is parsed with `Dom\HTMLDocument` (`WorldartPage`), never with `DOMDocument` or regexes
  over markup.
- XML is parsed with `LIBXML_NONET` and without `LIBXML_NOENT` (`AniDbApiClient`): no network, no
  external entity substitution.
- JSON is decoded with `json_decode` (`JSON_THROW_ON_ERROR` in the API clients) and the result is
  treated as untrusted shape, not as a typed value.
- gz data is decompressed with a size limit: `gzdecode($body, <max length>)` bounded by
  `AniDbApiClient::MAX_XML_BYTES` and `DumpDownloader::MAX_DUMP_BYTES`, after the compressed body
  is bounded by `DumpDownloader::MAX_BODY_BYTES`.

Plugin I/O:

- Network access only through the core's PSR-18 client (`Psr\Http\Client\ClientInterface`
  injected into the plugin's HTTP classes); a plugin does not build its own transport.
- Writes only into the directory provided by `PluginCacheDirectoryInterface::path()` (cards,
  dumps, limiter slots, lock files, temporary files created with `tempnam()` in that directory
  and moved with `rename()`). This one is a convention: the PHPStan gate does not check where a
  plugin writes, so a reviewer must.

## 4. Registry signing and assets

- `plugins-registry.json` is signed (detached Ed25519) by `tools/sign-registry.php`; the secret
  key comes from the CI secret `REGISTRY_SIGNING_KEY` (`.github/workflows/registry.yml`) via an
  environment variable, never argv or a committed file. The signature
  `plugins-registry.json.sig` is verified in CI by `tools/verify-registry-signature.php` against
  `plugins-registry.pub`.
- The public key `plugins-registry.pub` must match the key embedded in the application. The
  application side is outside this repository and is not checked here; changing the key without
  changing the application breaks verification for every client.
- Each ZIP's sha256 (written by `tools/build-plugin-zip.php`) is recorded in the registry; the
  client pins it from the signed registry, so a mirror is untrusted transport.
- The mirror is served over FTPS (`protocol: ftps`, `FtpMirrorTransport`). Note that ext-ftp
  encrypts the channel but does not validate the server certificate; see the comment in
  `FtpMirrorTransport::connect()`.

## 5. Accepted risks

A finding that matches an accepted risk and does not weaken it further is not a finding.

### AniDB password stored in plain text in settings

- Class: secret stored unencrypted at rest (plugin settings).
- Why accepted: the settings live in the user's own profile on their own machine; the threat
  model does not include other local users or the user themselves.
- Decision: #226 (https://github.com/anime-db/anime-db-plugins/issues/226).
- Revisit when: a change takes data or access beyond the user's profile on their machine —
  settings sync or export that includes secrets, multi-user installs.

## 6. Maintenance rule

A PR that changes the code of an invariant (section 3), of the signing flow (section 4) or of an
accepted risk (section 5) updates this file in the same PR.
