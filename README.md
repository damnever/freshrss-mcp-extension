# FreshRSS MCP extension

A [Model Context Protocol](https://modelcontextprotocol.io/) server that runs *inside* FreshRSS.
It is an ordinary PHP system extension: no sidecar, no second container, no extra database. An MCP
client that speaks Streamable HTTP can list the feeds, pull the unread queue, run a search, and read
one article. Everything is read-only, and everything is scoped to the user whose API password the
client was given.

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

If the extension does not appear on the Extensions page, FreshRSS cannot read the folder: check the
name (`xExtension-MCP`), and that `metadata.json` parses.

### 3. Point a client at it

```
https://freshrss.example.net/api/misc.php?ext=MCP
```

The `PATH_INFO` form `https://freshrss.example.net/api/misc.php/MCP/` works too, for clients that
cannot keep a query string. Replies are plain `application/json`; the optional SSE stream is not
used, and the server keeps no session, so any single request can hit any worker.

## Credentials

`p/api/misc.php` authenticates nothing on its own, so this extension does. Accepted headers:

| Header | Value |
| --- | --- |
| `Authorization: Basic …` | `base64("<user>:<api-password>")` |
| `Authorization: Bearer …` | `<user>/<secret>` |
| `Authorization: GoogleLogin auth=…` | `<user>/<secret>`, exactly as `clientLogin` returns it |
| `X-Api-Key: …` | `<user>/<secret>` |

`<secret>` is either the API password itself, or the 40-character `Auth` token that the Google Reader
API hands out from `clientLogin` — the same value the mobile apps store. Prefer the token: it cannot
be used to sign in to the web interface, and revoking it does not disturb anything else. Both are
secrets in transit, so serve FreshRSS over TLS.

```sh
# The handshake, with curl
curl -s -u 'alice:secret' -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl"}}}' \
  'https://freshrss.example.net/api/misc.php?ext=MCP'
```

## Tools

| Tool | What it gives back | Notes |
| --- | --- | --- |
| `list_feeds` | every feed, grouped by category, with unread counts | `category` filters by name, `include_totals` adds stored counts, `include_hidden` adds hidden feeds |
| `get_unread` | the unread queue: id, title, link, feed, publication time | `feed_id` / `category_id` narrow it, `limit` caps it, `continuation` pages |
| `search` | the same shape, over all stored entries | `query` in FreshRSS syntax, evaluated by the database |
| `get_article` | one entry as plain text | `id`, or `feed_id` + `guid`; `fetch_full_content` asks FreshRSS to grab the article page |
| `feed_stats` | totals plus the ten noisiest feeds | a cheap way to open a triage conversation |

Shared paging arguments of `get_unread` and `search`: `limit` (default 20, max 100), `since`, `until`,
`sort` (`date` or `id`), `continuation`. Bodies are left out unless `include_content` is set, and are
truncated to `max_chars` (default 4000) — a queue is usually wide, and one article is where the text
belongs.

`search` speaks FreshRSS' own syntax: bare words match title and body, and `search:`, `intitle:`,
`inurl:`, `author:`, `feed_id:`, `label:`, `tag:`, `date:`, `after:`, `before:`, `is:read`,
`is:unread`, `is:favorite` and Boolean groups all work; a leading `-` negates a term.

A tool that fails answers with `isError: true` and a sentence a model can act on, which is what the
MCP spec asks for; only protocol mistakes become JSON-RPC errors.

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
time, and `get_article` only for the items worth reading in full."*

## Read-only, on purpose

There is no tool that marks anything read, stars it, adds a feed, or deletes anything. Triage is
useful; an agent that can silently empty an inbox is not. Nothing here calls a write path, and the
tools only ever go through the same DAOs the web reader uses, so a client sees exactly what that user
would see when logged in.

## Security notes

* The API password is scoped to one user, but it does expose that user's whole archive and search to
  whoever holds it. Treat it as a credential, not as a preference.
* Failures are logged through `Minz_Log::warning`, so they land in the user's FreshRSS log.
* No CSRF token and no cookie is involved: the endpoint is a plain authenticated API, and it answers
  `405` to anything but POST.
* Give the agent its own FreshRSS user if you want to keep your starred items and read state out of
  reach — the feeds can be shared by importing the same OPML.

## Development

```
xExtension-MCP/
├── metadata.json          # type: system, so it is reachable from /api/misc.php
├── extension.php          # Minz_Extension: registers the ApiMisc hook, nothing else
└── src/
    ├── Auth.php           # API password / Auth token → FreshRSS_Context::initUser()
    ├── Server.php         # JSON-RPC 2.0: initialize, ping, notifications, tools/list, tools/call
    ├── Tools.php          # the five tools, on top of the FreshRSS DAOs
    └── ProtocolError.php  # internal way to raise a JSON-RPC error code
```

Syntax-check without a host PHP:

```sh
docker run --rm -v "$PWD/xExtension-MCP:/p:ro" --entrypoint php freshrss/freshrss:edge -l /p/src/Tools.php
```

End-to-end checks, against any FreshRSS (they only read):

```sh
FRSS_URL=https://freshrss.example.net FRSS_USER=alice FRSS_API_PASSWORD=secret python3 tests/smoke.py
```

### Things worth knowing before editing

* `Minz_HookType::ApiMisc` gives the extension the whole HTTP response: no controller, no user
  context, no template. `Auth::authenticate()` is what stands between the network and the account, so
  it must fail closed.
* `FreshRSS_Context::initUser($user)` is needed before any DAO call; the DAOs read the user's own
  configuration.
* Configuration objects are magic: `empty($conf->enabled)` is **wrong**, because `__isset()` only
  reports keys stored in that user's file, and defaults then look unset. Write `!$conf->enabled`, the
  way the core does.
* `FreshRSS_EntryDAO::listWhere()` starts a continuation page *at* the entry named by the token, so
  ask for one row more than you return and drop the first. With `sort: 'date'` the DAO also needs that
  entry's date as `continuation_values[0]`, which is why the token is `id:date`.
* `listWhere()` with `type: 'a'` or `'A'` consults `FreshRSS_Context::$search` for feed visibility, so
  always pass a `FreshRSS_BooleanSearch`, even an empty one.

## License

AGPL-3.0-or-later, the same as FreshRSS, since this runs inside it and uses its code.
