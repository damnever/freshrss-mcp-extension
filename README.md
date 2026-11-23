# FreshRSS MCP extension

A [Model Context Protocol](https://modelcontextprotocol.io/) server that runs *inside* FreshRSS.
It is an ordinary PHP system extension: no sidecar, no second container, no extra database. An MCP
client that speaks Streamable HTTP can list the feeds, pull the unread queue, run a search and read
an article; triage entries by marking them read, starring and labelling them; and manage the
subscriptions: subscribe, rename, move, hide, refresh and unsubscribe feeds, and create, rename and
delete categories and labels. Everything is scoped to the user whose API password the client was
given.

This is a fork of [samteezy/freshrss-mcp-extension](https://github.com/samteezy/freshrss-mcp-extension),
which is read-only by design; the read side, the protocol handling and the authentication are its.
See [Write access](#write-access) for what the write tools do and do not do.

It answers both eras of the protocol on the one URL: revision **2026-07-28**, where every request
carries its own version and there is no handshake, and the earlier handshake revisions back to
**2024-11-05**. See [Protocol versions](#protocol-versions).

```
MCP client ──POST /api/misc.php?ext=MCP──▶ FreshRSS ──▶ FreshRSS_EntryDAO / FeedDAO / CategoryDAO
             (FreshRSS API password)         (this extension)
```

## Why an extension rather than a sidecar

* **One secret.** Requests are checked against the same API password that the Google Reader API and
  the mobile apps already use, so there is no second credential to rotate.
* **One process.** Nothing to deploy, supervise, or keep in step with FreshRSS upgrades.
* **Free multi-user scoping.** The user names itself in the credential, and the extension loads that
  user's context before touching the database.

## Requirements

* FreshRSS **1.30.4** or newer — needs `p/api/misc.php` and the `ApiMisc` hook.
* PHP 8.2 or newer (the version FreshRSS 1.30 requires).
* The FreshRSS API enabled (`api_enabled`); the extension has no other dependency.

Tested against `freshrss/freshrss:edge` (PHP 8.4) and against the released 1.30.4 auth code.

## Install

### 1. Put the code where FreshRSS looks for it

The extension must land in `extensions/xExtension-MCP`, i.e. **the folder keeps its `xExtension-` prefix**.

With Docker, either copy it into the extensions volume:

```sh
git clone https://github.com/samteezy/freshrss-mcp-extension
docker cp -a freshrss-mcp-extension/xExtension-MCP freshrss:/var/www/FreshRSS/extensions/
docker restart freshrss
```

…or bind-mount it read-only, which makes upgrades a `git pull`:

```yaml
services:
  freshrss:
    image: freshrss/freshrss:edge
    volumes:
      - data:/var/www/FreshRSS/data
      - ./xExtension-MCP:/var/www/FreshRSS/extensions/xExtension-MCP:ro
volumes:
  data:
```

A bare-metal install is the same copy into `<install>/extensions/`, followed by
`cli/access-permissions.sh` so that the web server user can read it.

### 2. Turn it on, inside FreshRSS

1. **Administration → Authentication** → tick *Enable API* (`api_enabled`). Without it
   `/api/misc.php` answers 503.
2. **Administration → Extensions** → enable **MCP**. System extensions are listed under their own
   heading; enabling one writes `extensions_enabled => ['MCP' => true]` to `data/config.php`.
3. **Settings → Profile → API** → set an **API password** for the account the agent will use. It is
   separate from the login password.

The extension has no settings of its own: nothing to fill in beyond those three. If it does not
appear on the Extensions page, FreshRSS cannot read the folder — check the name (`xExtension-MCP`)
and that `metadata.json` parses.

### 3. Point a client at it

```
https://freshrss.example.net/api/misc.php?ext=MCP
```

The `PATH_INFO` form `https://freshrss.example.net/api/misc.php/MCP/` works too, for clients that
cannot keep a query string. Replies are plain `application/json`; the optional SSE stream is not
used, and the server keeps no session, so any single request can hit any worker. An `Accept` header
that rules JSON out (a client insisting on `text/event-stream`, say) gets `406` instead of a body it
said it did not want.

## Credentials

`p/api/misc.php` authenticates nothing on its own, so this extension does. Accepted headers:

| Header | Value |
| --- | --- |
| `Authorization: Basic …` | `base64("<user>:<api-password>")` |
| `Authorization: Bearer …` | `<user>/<secret>` |
| `Authorization: GoogleLogin auth=…` | `<user>/<secret>`, exactly as `clientLogin` returns it |
| `Authorization: Token …` | `<user>/<secret>` |
| `X-Api-Key: …` | `<user>/<secret>` |

`<secret>` is either the API password itself, or the 40-character `Auth` token that the Google Reader
API hands out from `clientLogin` — the same value the mobile apps store. Prefer the token: it cannot
be used to sign in to the web interface, and revoking it does not disturb anything else. Both are
secrets in transit, so serve FreshRSS over TLS.

**Credentials are read verbatim.** Nothing is percent-decoded, nothing is trimmed, and a credential
holding whitespace or a control character is refused, so every secret has exactly one spelling.
`%74est` is not `test`, and a leading space is not a password. Percent-decoding first would let
whatever a proxy in front happened to do to the bytes decide which account a request belonged to.

**The account name travels in the header, never in the URL.** There is no `?user=` parameter: request
lines end up in access logs, and an account name in them is a leak that buys nothing. A secret with
no account name in it is refused for the same reason.

**A refusal is a refusal.** An account that does not exist, one with the API switched off, and a right
name with a wrong secret all get the same `401` with `WWW-Authenticate`, so the endpoint is not a list
of the accounts. Anything that goes wrong inside authentication is logged through
`Minz_Log::warning` and answered the same way: a failure to decide is a refusal.

```sh
URL='https://freshrss.example.net/api/misc.php?ext=MCP'

# 2026-07-28: no handshake, the request says everything itself
curl -s -u 'alice:secret' -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: server/discover' \
  -d '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{
        "io.modelcontextprotocol/protocolVersion":"2026-07-28",
        "io.modelcontextprotocol/clientCapabilities":{}}}}' "$URL"

# 2025-06-18 and older: open with the handshake, then ask for the tools
curl -s -u 'alice:secret' -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl"}}}' \
  "$URL"
```

## Protocol versions

One endpoint, both eras, chosen per request and remembered for nothing — which is how a stateless PHP
endpoint wants to work anyway:

| The request looks like | Served as |
| --- | --- |
| `params._meta` names `2026-07-28` | the handshakeless era: `server/discover`, `resultType`, cache hints |
| `MCP-Protocol-Version: 2025-11-25` … `2024-11-05` | the handshake era, under the revision named |
| nothing names a version | `2025-03-26`, the last revision before the version header existed |
| `initialize` asking for a revision this server does not speak, or for `2026-07-28` | answered with `2025-11-25`, as the handshake revisions require; the client decides whether to go on |

**The modern era.** `server/discover` answers with `supportedVersions`, `capabilities`, the server
identity under `_meta['io.modelcontextprotocol/serverInfo']`, `instructions`, and the cache fields
`ttlMs` / `cacheScope`. Every result carries `resultType: "complete"` and the same `serverInfo`, so a
client needs no prior state to know who it is talking to. `tools/list` adds `ttlMs` and `cacheScope`
(`private`, since every answer belongs to one account and every reply says `Cache-Control: no-store`).
`initialize` and `ping` are gone, as they are in the revision, and the error says what to call instead.

The transport headers a modern request mirrors from its body are checked, and must agree: a gateway may
route on `Mcp-Method` while this server acts on the body, and a difference between the two is a
security problem and not a curiosity. The checks run before the notification short-circuit too, so a
`notifications/…` message cannot smuggle a header that contradicts itself.

| Situation | Answer |
| --- | --- |
| a header is missing, or contradicts the body | `400` with `-32020` (HeaderMismatch) |
| a protocol version this server does not speak | `400` with `-32022` and `data.supported` |
| `_meta` lacks a required field, or one of them is the wrong shape | `400` with `-32602` |
| a method that does not exist | `404` with `-32601` |
| `Accept` that will not take JSON | `406` with `-32600` |
| an `id` that is not a string or a whole number | `400` with `-32600` |
| a batch, or a response object instead of a request | `400` with `-32600` |

`Mcp-Name` is decoded from the `=?base64?…?=` sentinel form before it is compared. No tool parameter
is annotated with `x-mcp-header`, so there are no `Mcp-Param-*` headers to validate.

**The handshake era.** `initialize` answers with the revision the client asked for, `ping` works, and
results carry no `resultType` — a client of that era treats an absent one as `complete`, and does not
expect to see other fields either.

**Deliberately not implemented:** the per-request SSE stream (every reply is one JSON object), the GET
stream and `Last-Event-ID` resumption that 2026-07-28 removed, protocol-level sessions and the `DELETE`
that ended them, tasks, and `subscriptions/listen`. An `Origin` allowlist is also not checked: this
endpoint is behind the FreshRSS API password and, as FreshRSS itself is, it is meant to sit behind TLS
and, ideally, not be world-readable.

## Tools

| Tool | What it gives back | Arguments |
| --- | --- | --- |
| `list_feeds` | every feed, grouped by category, with unread counts | `category` filters by name, `include_totals` adds stored counts, `include_hidden` adds hidden feeds |
| `get_unread` | the unread queue: id, title, link, feed, publication time | `feed_id` or `category_id` (not both) narrows it, plus the paging arguments below |
| `search` | the same shape, over all stored entries | `query` (required) in FreshRSS syntax, `unread_only`, plus the paging arguments |
| `get_article` | one entry as plain text | `id`, or `feed_id` + `guid` (not both); `max_chars`; `fetch_full_content` (feeds with an article CSS selector only) |
| `feed_stats` | totals plus the ten noisiest feeds | none |

Shared by `get_unread` and `search`: `limit` (default 20, max 100), `since`, `until`, `sort` (`date`
or `id`), `continuation`, `include_content`, `max_chars` (default 4000, max 20000). Bodies are left
out unless `include_content` is set — a queue is usually wide, and one article is where the text
belongs.

Every argument is type-checked and every argument a tool does not declare is refused, by name, with
the list of the ones it does take. A `limit` of `"lots"`, a `feed_id` of `12.5` and a `since` in the
year 3000 are refusals and not silent defaults, and `since` may not be later than `until`.

### Search syntax

`search` speaks FreshRSS' own syntax and the database does the matching:

| Write | Meaning |
| --- | --- |
| `two words` | both anywhere in title or body |
| `f:123`, `c:12`, `e:1639310674957894` | one feed, one category, one entry (`f:1,2` for several) |
| `L:3`, `S:1`, `L:*` | by label id, by saved-search id, any label |
| `label:name`, `search:name` | by label name, by saved-search name |
| `author:name` | the author field |
| `intitle:word`, `intext:word`, `inurl:word` | a word in the title, the body, the link |
| `#tag` | a tag |
| `date:`, `pubdate:`, `mdate:`, `userdate:` | an ISO 8601 interval: `2026-11-01`, `2026-11-01/2026-11-08`, `PT12H` |
| `( a b ) OR c` | a group, joined or nested |
| `!term` or `-term` | everything except the term |

**Nothing is quietly ignored.** FreshRSS reads the operators it knows and drops the rest, which is the
wrong failure for a tool a model drives: a `date:` it cannot parse would drop the date constraint and
return everything, looking exactly like a search that worked. So a query is checked first, and
`is:unread`, `tag:name`, `after:2026-11-01`, a bad `f:` id, an unbalanced `(` and friends come back as
a refusal naming the word and saying what to write instead.

There is deliberately no `is:unread`: FreshRSS keeps read and starred as a *state* of an entry, not as
part of a search string. Pass `unread_only: true` to `search`, or use `get_unread`.

### Paging

The `continuation` token is signed (HMAC-SHA256 over the entry key, the sort order and the account,
with a key derived from the installation salt). A token is therefore something only this server could
have issued, to this account, for this ordering: one forged or edited by hand, one from another
account and one issued for the other sort order are all refused as a tool error, and none of them
reaches the database as a value nobody checked. It is an integrity check and not a session — nothing is
stored, so a token stays valid until the entry it names is gone.

### Answers

Every tool declares an `outputSchema`, and a successful call answers with both `content` — one text
block, the JSON, which is what a model reads — and `structuredContent`, the same object already decoded
for a client that wants to check it. A tool that fails answers `isError: true` with a sentence a model
can act on, which is what the spec asks for; only protocol mistakes become JSON-RPC errors.

`feed_stats` reports `null` for a count it could not read, with the reason in `warnings`, rather than
a confident zero.

### Triage and organising tools

| Tool | What it does | Arguments |
| --- | --- | --- |
| `mark_read` | marks entries read, or unread with `read: false` | `ids` (required, up to 200), `read` |
| `set_starred` | stars entries, or unstars them with `starred: false` | `ids` (required), `starred` |
| `label_entries` | puts a label on entries, or takes it off with `remove: true`; creates a label named for the first time | `ids` (required), `label` (a name) or `label_id`, `remove` |
| `mark_all_read` | marks everything unread in one scope read | exactly one of `feed_id`, `category_id`, `label_id`, `all: true`; `up_to_id` |
| `list_labels` | every label, with entry and unread counts | none |
| `create_label`, `rename_label`, `delete_label` | manage labels; deleting one keeps its entries | `name`, `label_id` |
| `subscribe_feed` | adds a feed by its URL and fetches it once | `url` (required), `category_id`, `title` |
| `update_feed` | renames, moves or changes the visibility of a feed | `feed_id` (required), `title`, `category_id`, `visibility` (`important`, `main`, `category`, `feed`, `hidden`) |
| `refresh_feed` | fetches one feed now | `feed_id` |
| `unsubscribe_feed` | deletes a feed **and all of its entries**, starred ones included | `feed_id` |
| `create_category`, `rename_category`, `delete_category` | manage categories; deleting one moves its feeds to the default category | `name`, `category_id` |

`ids` are the entry ids `get_unread` and `search` return. Counts in the answers are what actually
changed: marking an entry that is already read reports `changed: 0`, and an id that is not an entry of
this account comes back under `not_found` instead of being skipped silently. A call where none of the
ids is an entry is refused, and writes nothing.

`mark_all_read` takes `up_to_id`, the newest entry id the agent has seen, so that entries arriving
between reading the queue and clearing it stay unread; without it, everything up to now is marked.
`all: true` covers the feeds `get_unread` covers: all but those whose visibility is `feed` or `hidden`.

Names are stored the way the web interface stores them, HTML-escaped: a category created as `A & B`
over MCP is the same as one created as `A & B` in the browser, and is listed back as `A &amp; B`. A
name copied back from an answer is not escaped twice. Labels and categories share one namespace in
FreshRSS, so a name taken by one is refused for the other, with a message saying which.

## Client configuration

Claude Code:

```sh
claude mcp add --transport http freshrss \
  'https://freshrss.example.net/api/misc.php?ext=MCP' \
  --header 'Authorization: Bearer alice/<api-password>'
```

Generic JSON config (the shape most MCP clients accept):

```json
{
  "mcpServers": {
    "freshrss": {
      "type": "http",
      "url": "https://freshrss.example.net/api/misc.php?ext=MCP",
      "headers": { "Authorization": "Bearer alice/<api-password>" }
    }
  }
}
```

Clients that only speak stdio (Claude Desktop, for instance) need a bridge such as
[`mcp-remote`](https://github.com/modelcontextprotocol/ext-apps):

```sh
npx mcp-remote 'https://freshrss.example.net/api/misc.php?ext=MCP' \
  --header 'Authorization: Bearer alice/<api-password>'
```

Suggested framing for the agent: *"`feed_stats` and `list_feeds` first, then `get_unread` a feed at a
time, and `get_article` only for the items worth reading in full. Mark what you have dealt with read,
star what deserves a second look, and ask me before unsubscribing from anything."*

## Write access

The upstream extension refuses to write, on the grounds that an agent which can silently empty an
inbox is not useful. This fork writes, and keeps the part of that argument that matters: nothing is
silent.

* **The same code paths as a click.** Entry state goes through `FreshRSS_EntryDAO::markRead()` and
  `markFavorite()`, feeds through `FreshRSS_feed_Controller::addFeed()`, `moveFeed()`, `renameFeed()`
  and `deleteFeed()`, exactly as in the web interface and the Google Reader API. Unread counters,
  account limits (`max_feeds`, `max_categories`), saved-search cleanup and the hooks other extensions
  listen on (`EntriesRead`, `EntriesFavorite`, `CheckUrlBeforeAdd`, `FeedBeforeInsert`…) all behave
  as they do for the user. After every write the HTTP cache of the web interface is invalidated, so an
  open browser tab shows the change on its next load.
* **Annotated.** Every tool declares `readOnlyHint`; `mark_all_read`, `unsubscribe_feed`,
  `delete_label` and `delete_category` declare `destructiveHint`, so a client that asks before
  destructive calls asks for those. `unsubscribe_feed` cannot be undone: the entries go with the feed.
* **Checked before written.** Every id is looked up in the account first, a category given to
  `subscribe_feed` or `update_feed` has to exist instead of quietly becoming the default one, and
  `mark_all_read` needs exactly one scope. A refusal writes nothing.

If an agent should read but not write, give it a FreshRSS user of its own (import the same OPML), or
use the upstream read-only extension.

## Security notes

* The API password is scoped to one user, but it does expose that user's whole archive and search to
  whoever holds it. Treat it as a credential, not as a preference.
* The same credential grants the write tools. Give the agent its own FreshRSS user if you want to keep
  your starred items, read state and subscriptions out of its reach — the feeds can be shared by
  importing the same OPML.
* **`subscribe_feed` and `refresh_feed` make FreshRSS fetch a URL**, and the URL of `subscribe_feed`
  comes from the client. That fetch is FreshRSS' own, with its own SSRF rules: private, loopback and
  link-local addresses are refused unless the administrator allowed them in `internal_host_allowlist`
  (or `INTERNAL_HOST_ALLOWLIST`). This is the same policy as for a subscription made in the browser,
  deliberately, so that an allowlisted RSS-Bridge on the LAN works here too; it also means that what
  the allowlist opens to the user, it opens to the agent.
* Failures are logged through `Minz_Log::warning`, so they land in the FreshRSS log. Nothing that
  looks like a secret is written there: usernames appear only where FreshRSS itself would name them.
* No CSRF token and no cookie is involved: the endpoint is a plain authenticated API, and it answers
  `405` to anything but POST.
* **`fetch_full_content` is the one outbound request.** FreshRSS fetches the article page and extracts
  the text with the feed's article CSS selector, so it works only for feeds that have one set; any
  other feed gets a refusal that says so. The URL comes from a feed rather than from us, so it is
  checked first: `http` and `https` only, no credentials in the URL, and every address the host
  resolves to must be public — private, loopback, link-local, CGNAT, multicast and reserved ranges are
  refused, as is a NAT64 address carrying one of them, so this endpoint will not read the LAN, the
  router or a cloud metadata address on behalf of a publisher. FreshRSS then makes the request, checks
  every redirection against its own private-range rules and pins the connection to the addresses it
  checked. Two FreshRSS settings weaken that, and this check then covers only the first URL: an
  outbound proxy (FreshRSS checks the proxy's address, not the page's) and `internal_host_allowlist`.
  A host that must never be reachable this way belongs on a different network, not merely on another
  port.
* **Refusals take the same time.** An account that does not exist, or has no API password, is refused
  after the same bcrypt check a wrong password costs, so response times do not reveal which accounts
  exist.
* Replies carry `Cache-Control: no-store` and the tool catalogue advertises `cacheScope: 'private'`, so
  an intermediate cache is told the truth about content that belongs to one account.

## Development

```
xExtension-MCP/
├── metadata.json          # type: system, so it is reachable from /api/misc.php
├── extension.php          # Minz_Extension: registers the ApiMisc hook, nothing else
└── src/
    ├── Auth.php              # API password / Auth token → FreshRSS_Context::initUser()
    ├── Server.php            # JSON-RPC 2.0 routing, header checks, and the two eras' envelopes
    ├── ProtocolVersion.php   # which revision one request is speaking, and what that implies
    ├── ProtocolError.php     # a JSON-RPC error, with the HTTP status that goes with it
    ├── Http.php              # HTTP primitives: body, headers, Accept, the answer
    ├── Query.php             # refuses a search string FreshRSS would have partly ignored
    ├── Continuation.php      # the signed, account- and order-bound paging cursor
    ├── UrlPolicy.php         # which URLs fetch_full_content may fetch
    ├── Arguments.php         # typed, refusing reads of tool arguments, shared by every tool
    ├── Tools.php             # the tool catalogue, one place where calls are checked; the read tools
    ├── EntryTools.php        # mark_read, set_starred, mark_all_read, label_entries
    ├── LabelTools.php        # list/create/rename/delete labels
    └── SubscriptionTools.php # subscribe/update/refresh/unsubscribe feeds; create/rename/delete categories
```

Syntax-check without a host PHP:

```sh
docker run --rm -v "$PWD:/w:ro" php:8.4-cli sh -c \
  'for f in /w/xExtension-MCP/extension.php /w/xExtension-MCP/src/*.php; do php -l "$f"; done'
```

The URL policy has its own harness, which needs no FreshRSS and no server: 79 cases of schemes,
literals, encodings and reserved ranges.

```sh
docker run --rm -v "$PWD:/w:ro" php:8.4-cli php /w/tests/url_policy.php
```

End-to-end checks in two suites. `tests/smoke.py` only reads, so it runs against any FreshRSS: both
eras, the legacy handshake and a modern `server/discover`, every header-mismatch and version-refusal
path, each read tool, the Accept matrix, every credential form, the strict-credential and enumeration
probes, and forged, edited and cross-account continuation tokens.

```sh
FRSS_URL=https://freshrss.example.net FRSS_USER=alice FRSS_API_PASSWORD=secret python3 tests/smoke.py
```

`tests/write.py` changes the account it runs against, so it runs against a disposable one.
`tests/e2e/run.sh` starts `freshrss/freshrss:edge` with the extension mounted read-only and a static
feed server beside it, seeds two accounts from `tests/e2e/subscriptions.opml`, runs both suites and
removes everything again. It needs Docker and `python3`, nothing else; `KEEP=1` leaves the containers
running, `E2E_IMAGE` picks another FreshRSS image.

```sh
sh tests/e2e/run.sh          # smoke and write
sh tests/e2e/run.sh write    # one suite
```

Mount an extension read-only when you test it. A container that can write to the source tree will
turn a stray `echo` into a mystery bug.

### Things worth knowing before editing

* `Minz_HookType::ApiMisc` gives the extension the whole HTTP response: no controller, no user
  context, no template. `Auth::authenticate()` is what stands between the network and the account, so
  it must fail closed — and the FreshRSS context layer throws things it does not document, hence the
  broad `catch` there.
* `FreshRSS_Context::initUser($user)` is needed before any DAO call; the DAOs read the user's own
  configuration.
* Configuration objects are magic: `empty($conf->enabled)` is **wrong**, because `__isset()` only
  reports keys stored in that user's file, and defaults then look unset. Write `!$conf->enabled`, the
  way the core does.
* `json_decode(..., true)` makes `{}` and `[]` the same empty PHP array. `Http::object()` treats an
  empty array as the empty object it usually is — a request with `"params": {}` is an ordinary
  request — and only rejects a list that actually has members.
* `FreshRSS_EntryDAO::listWhere()` starts a continuation page *at* the entry named by the token, so
  ask for one row more than you return and drop the first, but only when it is the token's entry: in an
  unread queue that entry may have been read since, and is then not returned at all. With `sort: 'date'` the DAO also needs that
  entry's date as `continuation_values[0]`, which is why the token carries `id:date`.
* A `Throwable` cannot be cloned in PHP, so `FreshMcp_ProtocolError::withId()` mutates and returns
  `$this`. One error answers one request, once, which is all the chaining has to survive.
* `listWhere()` with `type: 'a'` or `'A'` consults `FreshRSS_Context::$search` for feed visibility, so
  always pass a `FreshRSS_BooleanSearch`, even an empty one.
* Anything memoised per process must be keyed by user or reset per request: `Server::serve()` calls
  `FreshMcp_Tools::reset()` because a PHP worker lives on to serve the next account.

## Changelog

**0.4.0** — write access.

* Fifteen new tools, all but `list_labels` writing: `mark_read`, `set_starred`, `mark_all_read`, `label_entries`; `list_labels`,
  `create_label`, `rename_label`, `delete_label`; `subscribe_feed`, `update_feed`, `refresh_feed`,
  `unsubscribe_feed`; `create_category`, `rename_category`, `delete_category`. Each goes through the
  FreshRSS code path the web interface uses, declares `readOnlyHint`/`destructiveHint`, checks ids and
  names before writing, and answers with what actually changed.
* Required arguments are checked for every tool before its handler runs.
* Tests: `tests/write.py` against a disposable FreshRSS started by `tests/e2e/run.sh`, which also runs
  the smoke suite; the shared client code is in `tests/mcp_client.py`.

**0.3.1** — fixes from a review.

* `get_article(fetch_full_content)` now actually fetches. It used to reload the stored text and
  report success; it now forces FreshRSS to fetch, refuses feeds with no article CSS selector, and
  reports a fetch that failed.
* Paging no longer skips an unread entry when the entry a token points to was marked read or purged
  between two pages.
* `initialize` asking for a revision this server does not speak is answered with `2025-11-25` instead
  of an error, as the handshake revisions require.
* `feed_id` with `category_id`, an `id` with `feed_id`/`guid`, and `arguments` sent as a list are
  refused instead of partly ignored.
* An unknown account or one with no API password costs the same bcrypt check as a wrong password, so
  timing no longer tells which accounts exist.
* The URL check reads the same DNS records FreshRSS connects to, blocks `::/96` and `64:ff9b:1::/48`,
  and judges a NAT64 address by the IPv4 address inside it.
* The request body is read only up to the size limit; `_meta` on a result is merged rather than
  replaced.
* Tests: 86 URL-policy cases; the end-to-end suite checks for gaps between pages and for each refusal
  above.

**0.3.0** — a hardening pass over the whole surface.

* Authentication fails closed and no longer distinguishes a missing account from a wrong password; an
  internal error during authentication is a refusal and not a `500`.
* Credentials are verbatim: no percent-decoding, no trimming, no whitespace or control characters, so
  `%74est` and a leading-space password are refused. `Authorization: Token …` is accepted, and the
  account name is no longer taken from the URL.
* `get_article(fetch_full_content)` fetches only `http`/`https` links whose every address is public.
* Continuation tokens are signed and bound to the account and the sort order.
* `search` refuses a query FreshRSS would have partly ignored, and `unread_only` replaces the `is:`
  operators that do not exist. The tool description now states the syntax that actually works.
* Arguments are type-checked, timestamps bounded and compared, and undeclared arguments refused.
* `Accept` is honoured (`406`), request ids are validated instead of echoed, notifications no longer
  get a result body, and header/body mirror checks apply to notifications too.
* Every tool declares an `outputSchema`, and a successful call returns `structuredContent` beside the
  text. `cacheScope` is `private`, matching `Cache-Control: no-store`, and a count that could not be
  read is `null` in `feed_stats` rather than `0`.
* Tests: 79 URL-policy cases on their own, and the end-to-end suite grew from 70 checks to 144.

**0.2.0** — both protocol eras on one endpoint: `2026-07-28` with `server/discover`, and the handshake
revisions back to `2024-11-05`.

**0.1.0** — the five read-only tools, over the FreshRSS API password.

## License

AGPL-3.0-or-later, the same as FreshRSS, since this runs inside it and uses its code.
