#!/usr/bin/env python3
"""End-to-end checks for the FreshRSS MCP extension.

Usage:
    FRSS_URL=https://freshrss.example.com FRSS_USER=alice FRSS_API_PASSWORD=… \
        python3 tests/smoke.py

The API password is the one shown by FreshRSS under Authentication, or the `Auth` token
returned by the Google Reader API. Nothing here writes to FreshRSS.
"""
import base64
import json
import os
import ssl
import sys
import urllib.error
import urllib.request

URL = os.environ.get('FRSS_URL', 'http://127.0.0.1:8099').rstrip('/')
USER = os.environ.get('FRSS_USER', '')
PASSWORD = os.environ.get('FRSS_API_PASSWORD', '')
ENDPOINT = f'{URL}/api/misc.php?ext=MCP'
PATH_INFO_ENDPOINT = f'{URL}/api/misc.php/MCP/'

# The newest revision the extension speaks; the other era is everything up to 2025-11-25.
MODERN = '2026-07-28'

failures: list[str] = []


def check(label: str, ok: bool, detail: str = '') -> None:
    print(f'{"PASS" if ok else "FAIL"}  {label}' + (f'  — {detail}' if detail and not ok else ''))
    if not ok:
        failures.append(label)


def post(payload=None, *, auth: bool = True, url: str = ENDPOINT, raw: str | None = None,
         creds: str | None = None, headers: dict | None = None):
    """POST one message; returns (status, parsed-body-or-None, raw-text)."""
    body = raw if raw is not None else json.dumps(payload).encode()
    req = urllib.request.Request(url, data=body, method='POST',
                                headers={'Content-Type': 'application/json'})
    if auth:
        token = base64.b64encode((creds or f'{USER}:{PASSWORD}').encode()).decode()
        req.add_header('Authorization', f'Basic {token}')
    for name, value in (headers or {}).items():
        req.add_header(name, value)
    ctx = ssl.create_default_context()
    ctx.check_hostname = False  # self-signed installs are the norm on a homelab
    ctx.verify_mode = ssl.CERT_NONE
    try:
        with urllib.request.urlopen(req, timeout=30, context=ctx) as reply:
            text = reply.read().decode('utf-8', 'replace')
            status = reply.status
    except urllib.error.HTTPError as e:
        text = e.read().decode('utf-8', 'replace')
        status = e.code
    try:
        parsed = json.loads(text) if text else None
    except json.JSONDecodeError:
        parsed = None
    return status, parsed, text


def rpc(method, params=None, msg_id=1, *, url: str = ENDPOINT):
    return post({'jsonrpc': '2.0', 'id': msg_id, 'method': method, 'params': params or {}}, url=url)


def tool(name, arguments=None):
    status, parsed, text = post({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/call',
                                 'params': {'name': name, 'arguments': arguments or {}}})
    return status, parsed, payload_of(parsed), text


def modern(method, params=None, *, version=MODERN, msg_id=7, version_header=True, method_header=True,
           name_header=True, name=None, url=ENDPOINT):
    """One request of the 2026-07-28 era, with the headers that era mirrors from the body.

    version_header and method_header may be False to leave a header out, or a string to
    send a value that disagrees with the body on purpose.
    """
    arguments = dict(params or {})
    arguments['_meta'] = {
        'io.modelcontextprotocol/protocolVersion': version,
        'io.modelcontextprotocol/clientInfo': {'name': 'smoke', 'version': '0'},
        'io.modelcontextprotocol/clientCapabilities': {},
    }
    headers = {}
    for header, wanted, label in (('MCP-Protocol-Version', version_header, version), ('Mcp-Method', method_header, method)):
        if wanted is True:
            headers[header] = label
        elif wanted is not False:
            headers[header] = str(wanted)
    mirrored = name if name is not None else arguments.get('name', arguments.get('uri'))
    if name_header and mirrored is not None:
        headers['Mcp-Name'] = str(mirrored)
    return post({'jsonrpc': '2.0', 'id': msg_id, 'method': method, 'params': arguments}, headers=headers, url=url)


def modern_tool(name, arguments=None, **kwargs):
    status, parsed, text = modern('tools/call', {'name': name, 'arguments': arguments or {}}, **kwargs)
    return status, parsed, payload_of(parsed), text


def encoded(value):
    """A header value in the =?base64?…?= sentinel form."""
    return '=?base64?' + base64.b64encode(value.encode()).decode() + '?='


def payload_of(parsed):
    """The JSON a tool result carried, or None."""
    if parsed and 'result' in parsed:
        content = parsed['result'].get('content') or []
        if content and content[0].get('type') == 'text':
            try:
                return json.loads(content[0]['text'])
            except json.JSONDecodeError:
                return None
    return None


def conforms(schema, value, path='result'):
    """Why a value does not match a schema, or None. Type and required are all these schemas use."""
    kind = schema.get('type')
    if isinstance(kind, list):
        for one in kind:
            if conforms({**schema, 'type': one}, value, path) is None:
                return None
        return f'{path} is none of {kind}'
    if kind == 'object':
        if not isinstance(value, dict):
            return f'{path} is not an object'
        for key in schema.get('required', []):
            if key not in value:
                return f'{path}.{key} is missing'
        for key, sub in (schema.get('properties') or {}).items():
            if key in value:
                why = conforms(sub, value[key], f'{path}.{key}')
                if why:
                    return why
        return None
    if kind == 'array':
        if not isinstance(value, list):
            return f'{path} is not a list'
        for index, item in enumerate(value[:3]):
            why = conforms(schema.get('items') or {}, item, f'{path}[{index}]')
            if why:
                return why
        return None
    if kind == 'string':
        return None if isinstance(value, str) else f'{path} is not a string but {type(value).__name__}'
    if kind == 'integer':
        return None if isinstance(value, int) and not isinstance(value, bool) else \
            f'{path} is not an integer but {type(value).__name__}'
    if kind == 'boolean':
        return None if isinstance(value, bool) else f'{path} is not a boolean but {type(value).__name__}'
    return None

if not USER or not PASSWORD:
    sys.exit('Set FRSS_USER and FRSS_API_PASSWORD (see --help in the README).')

print(f'Target: {URL}  user: {USER}\n')

# --- transport and authentication ------------------------------------------------
with urllib.request.urlopen(f'{URL}/i/', timeout=30, context=ssl._create_unverified_context()) as r:
    check('FreshRSS is up', r.status == 200, f'HTTP {r.status}')

status, parsed, text = post(None, auth=False, raw=json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}).encode())
check('anonymous request is refused', status == 401, f'got HTTP {status}')

status, parsed, text = post({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, creds=f'{USER}:not-the-api-password')
check('a wrong API password is refused', status == 401, f'got HTTP {status}')

req = urllib.request.Request(ENDPOINT, data=b'{}', method='GET')
try:
    with urllib.request.urlopen(req, timeout=30, context=ssl._create_unverified_context()) as r:
        get_status = r.status
except urllib.error.HTTPError as e:
    get_status = e.code
check('GET is refused with 405', get_status == 405, f'got HTTP {get_status}')

# --- handshake -------------------------------------------------------------------
status, parsed, _ = post({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
    'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 'smoke', 'version': '0'}}})
result = (parsed or {}).get('result', {})
check('initialize answers 200', status == 200, f'HTTP {status}')
check('initialize keeps the client protocol version', result.get('protocolVersion') == '2025-06-18', str(result))
check('initialize announces the server', result.get('serverInfo', {}).get('name') == 'freshrss-mcp', str(result.get('serverInfo')))
check('initialize declares tools', bool(result.get('capabilities', {}).get('tools')))
check('initialize explains the read-only scope', 'ead-on' in (result.get('instructions') or ''))

status, parsed, text = post({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
check('notifications get an empty 202', status == 202 and not text.strip(), f'HTTP {status} body={text[:40]!r}')

status, parsed, _ = rpc('ping')
check('ping', status == 200 and 'result' in (parsed or {}), str(parsed))

# --- tools/list ------------------------------------------------------------------
status, parsed, _ = rpc('tools/list')
tools = {t['name']: t for t in (parsed or {}).get('result', {}).get('tools', [])}
expected = {'list_feeds', 'get_unread', 'search', 'get_article', 'feed_stats'}
check('tools/list returns the five read-only tools', set(tools) == expected, str(sorted(tools)))
check('every tool has a schema and a description',
      all(t.get('inputSchema', {}).get('type') == 'object' and t.get('description') for t in tools.values()))

# --- tools -----------------------------------------------------------------------
status, parsed, data, text = tool('list_feeds', {'include_totals': True})
feeds = [f for c in (data or {}).get('categories', []) for f in c.get('feeds', [])]
check('list_feeds returns feeds', bool(feeds), text[:200])
check('list_feeds rows carry ids and unread counts',
      all({'id', 'title', 'unread'} <= set(f) for f in feeds) if feeds else False)
check('include_totals added the totals', all('total' in f for f in feeds) if feeds else False)

status, parsed, data, text = tool('get_unread', {'limit': 3})
entries = (data or {}).get('entries', [])
check('get_unread returns unread entries', bool(entries), text[:200])
check('get_unread respects the limit', len(entries) <= 3, f'{len(entries)} entries')
check('get_unread rows have the fields a triage agent needs',
      all({'id', 'title', 'link', 'feed', 'published', 'unread'} <= set(e) for e in entries) if entries else False)
check('get_unread leaves bodies out by default', all('content' not in e for e in entries) if entries else False)
check('every returned entry is unread', all(e['unread'] for e in entries) if entries else False)

status, parsed, wide, _ = tool('get_unread', {'limit': 2, 'include_content': True, 'max_chars': 120})
wider = (wide or {}).get('entries', [])
check('include_content adds a body', bool(wider) and all('content' in e for e in wider))
check('max_chars is honoured', all(len(e.get('content', '')) <= 120 + 60 for e in wider),
      str([len(e.get('content', '')) for e in wider]))

if entries:
    status, parsed, data2, text = tool('get_unread', {'limit': 3, 'continuation': (data or {}).get('continuation', entries[-1]['id'])})
    page2 = (data2 or {}).get('entries', [])
    check('continuation gives a different page',
          bool(page2) and {e['id'] for e in page2} != {e['id'] for e in entries},
          f'{[e["id"] for e in page2]} vs {[e["id"] for e in entries]}')

    article_id = entries[0]['id']
    status, parsed, art, text = tool('get_article', {'id': article_id})
    check('get_article returns one entry', bool(art) and art.get('id') == article_id, text[:200])
    check('get_article includes body text', bool((art or {}).get('content')), str((art or {}).get('content'))[:80])
    check('get_article keeps the canonical link', bool((art or {}).get('link')))

    status, parsed, art, text = tool('get_article', {'id': article_id, 'fetch_full_content': True})
    # Only a feed with an article CSS selector can be fetched; any other says so instead of
    # pretending it fetched.
    check('get_article fetches the full page, or says why it cannot',
          (bool(art) and art.get('id') == article_id) or
          ((parsed or {}).get('result', {}).get('isError') is True and 'CSS selector' in text), text[:200])
    status, parsed, _, text = tool('get_article', {'id': article_id, 'guid': 'x', 'feed_id': entries[0]['feed_id']})
    check('get_article refuses an id together with feed_id and guid',
          (parsed or {}).get('result', {}).get('isError') is True, text[:160])

    feed_id = entries[0]['feed_id']
    status, parsed, data3, _ = tool('get_unread', {'feed_id': feed_id, 'limit': 5})
    narrowed = (data3 or {}).get('entries', [])
    check('get_unread narrows to one feed', all(e['feed_id'] == feed_id for e in narrowed) if narrowed else bool(narrowed))
    status, parsed, _, text = tool('get_unread', {'feed_id': feed_id, 'category_id': 1})
    check('get_unread refuses feed_id and category_id together',
          (parsed or {}).get('result', {}).get('isError') is True, text[:160])

status, parsed, data, text = tool('search', {'query': 'a'})
check('search runs server-side', 'entries' in (data or {}), text[:200])

status, parsed, data, text = tool('search', {})
check('search without a query fails as a tool error', (parsed or {}).get('result', {}).get('isError') is True, str(parsed)[:160])

status, parsed, data, text = tool('feed_stats')
check('feed_stats reports the account shape',
      bool(data) and {'feeds', 'unread', 'entries_stored'} <= set(data), text[:200])

# --- protocol errors -------------------------------------------------------------
status, parsed, _ = rpc('resources/list')
check('unknown method is a JSON-RPC error', (parsed or {}).get('error', {}).get('code') == -32601, str(parsed)[:160])

status, parsed, _, text = tool('mark_all_as_read')
check('unknown tool is a JSON-RPC error, not a result', (parsed or {}).get('error', {}).get('code') == -32602, str(parsed)[:160])

status, parsed, _ = post(raw=b'{not json')
check('malformed JSON is a parse error', (parsed or {}).get('error', {}).get('code') == -32700, str(parsed)[:160])

status, parsed, _ = post({'jsonrpc': '1.0', 'id': 1, 'method': 'ping'})
check('non-2.0 request is rejected', (parsed or {}).get('error', {}).get('code') == -32600, str(parsed)[:160])

status, parsed, data, text = tool('get_unread', {'limit': 5000})
check('an absurd limit is refused politely', (parsed or {}).get('result', {}).get('isError') is True, str(parsed)[:160])

status, parsed, text = post({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/call',
                             'params': {'name': 'feed_stats', 'arguments': ['x']}})
check('arguments given as a list are refused, not read as none',
      (parsed or {}).get('error', {}).get('code') == -32602, f'HTTP {status} {text[:160]}')

for asked, expected in (('1999-01-01', '2025-11-25'), (MODERN, '2025-11-25'), ('2024-11-05', '2024-11-05')):
    status, parsed, _ = post({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
        'protocolVersion': asked, 'capabilities': {}, 'clientInfo': {'name': 'smoke', 'version': '0'}}})
    got = (parsed or {}).get('result', {}).get('protocolVersion')
    check(f'initialize asking for {asked} is answered with {expected}', status == 200 and got == expected,
          f'HTTP {status} {str(parsed)[:160]}')

status, parsed, data, text = tool('get_article', {'id': '999999999999'})
check('a missing entry is a tool error, not a crash', (parsed or {}).get('result', {}).get('isError') is True, str(parsed)[:160])

# --- the handshakeless era (2026-07-28) ---------------------------------------------
status, parsed, _ = modern('server/discover')
found = (parsed or {}).get('result', {})
check('server/discover answers 200', status == 200, f'HTTP {status}')
check('server/discover lists the versions we speak',
      found.get('supportedVersions', [None])[0] == MODERN and '2024-11-05' in found.get('supportedVersions', []),
      str(found.get('supportedVersions')))
check('server/discover announces the server',
      found.get('_meta', {}).get('io.modelcontextprotocol/serverInfo', {}).get('name') == 'freshrss-mcp',
      str(found)[:200])
check('server/discover declares tools', bool(found.get('capabilities', {}).get('tools')), str(found)[:200])
check('server/discover explains the read-only scope', 'ead-on' in (found.get('instructions') or ''))
check('server/discover says how long to remember it',
      isinstance(found.get('ttlMs'), int) and found.get('cacheScope') == 'private', str(found)[:200])

status, parsed, _ = modern('tools/list')
found = (parsed or {}).get('result', {})
check('a modern result says it is complete', found.get('resultType') == 'complete', str(found)[:160])
check('a modern result names the server that sent it',
      found.get('_meta', {}).get('io.modelcontextprotocol/serverInfo', {}).get('name') == 'freshrss-mcp',
      str(found)[:200])
check('a modern tools/list carries cache hints',
      isinstance(found.get('ttlMs'), int) and found.get('cacheScope') == 'private', str(found)[:200])
status, parsed, data, text = modern_tool('get_unread', {'limit': 3})
modern_entries = (data or {}).get('entries', [])
check('a modern tool call works',
      status == 200 and (parsed or {}).get('result', {}).get('resultType') == 'complete' and bool(modern_entries),
      f'HTTP {status} {text[:160]}')
check('a modern tool call answers with the same rows as a legacy one',
      all({'id', 'title', 'link', 'feed', 'published', 'unread'} <= set(e) for e in modern_entries)
      if modern_entries else False, str(modern_entries)[:200])

status, parsed, _ = modern('tools/list', version='1900-01-01')
error = (parsed or {}).get('error', {})
check('an unknown protocol version is refused with -32022',
      status == 400 and error.get('code') == -32022, f'HTTP {status} {str(parsed)[:160]}')
check('the refusal lists the versions we do speak',
      MODERN in (error.get('data') or {}).get('supported', [])
      and (error.get('data') or {}).get('requested') == '1900-01-01', str(error)[:200])

status, parsed, _ = modern('tools/list', version_header=False)
check('a modern request without MCP-Protocol-Version is refused with -32020',
      status == 400 and (parsed or {}).get('error', {}).get('code') == -32020, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = modern('tools/list', version_header='2025-06-18')
check('a header version that contradicts _meta is refused with -32020',
      status == 400 and (parsed or {}).get('error', {}).get('code') == -32020, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = modern('tools/list', method_header=False)
check('a modern request without Mcp-Method is refused with -32020',
      status == 400 and (parsed or {}).get('error', {}).get('code') == -32020, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = modern('tools/list', method_header='tools/call')
check('an Mcp-Method that contradicts the body is refused with -32020',
      status == 400 and (parsed or {}).get('error', {}).get('code') == -32020, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = modern('tools/call', {'name': 'feed_stats', 'arguments': {}}, name_header=False)
check('a tool call without Mcp-Name is refused with -32020',
      status == 400 and (parsed or {}).get('error', {}).get('code') == -32020, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = modern('tools/call', {'name': 'feed_stats', 'arguments': {}}, name='list_feeds')
check('an Mcp-Name that contradicts the body is refused with -32020',
      status == 400 and (parsed or {}).get('error', {}).get('code') == -32020, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = modern('tools/call', {'name': 'feed_stats', 'arguments': {}}, name=encoded('feed_stats'))
check('an Mcp-Name in the base64 sentinel form is decoded before it is compared',
      status == 200 and (parsed or {}).get('result') is not None, f'HTTP {status} {str(parsed)[:160]}')

body = {'jsonrpc': '2.0', 'id': 7, 'method': 'tools/list', 'params': {'_meta': {
    'io.modelcontextprotocol/protocolVersion': MODERN, 'io.modelcontextprotocol/clientInfo': {'name': 'smoke'}}}}
status, parsed, _ = post(body, headers={'MCP-Protocol-Version': MODERN, 'Mcp-Method': 'tools/list'})
check('a modern request has to declare its capabilities',
      status == 400 and (parsed or {}).get('error', {}).get('code') == -32602, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = modern('does/not-exist')
check('an unknown method is a 404 carrying -32601 in the modern era',
      status == 404 and (parsed or {}).get('error', {}).get('code') == -32601, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = modern('initialize')
error = (parsed or {}).get('error', {})
check('initialize is not part of the modern era',
      status == 404 and error.get('code') == -32601, f'HTTP {status} {str(parsed)[:160]}')
check('the refusal tells a legacy client what else is on offer',
      '2025-11-25' in (error.get('data') or {}).get('supported', []), str(error)[:200])

status, parsed, _ = modern('ping')
check('ping is gone in the modern era',
      status == 404 and (parsed or {}).get('error', {}).get('code') == -32601, f'HTTP {status} {str(parsed)[:160]}')

status, parsed, text = post({'jsonrpc': '2.0', 'method': 'notifications/cancelled',
                             'params': {'requestId': '1', 'reason': 'checking', '_meta': {
                                 'io.modelcontextprotocol/protocolVersion': MODERN,
                                 'io.modelcontextprotocol/clientCapabilities': {}}}},
                            headers={'MCP-Protocol-Version': MODERN})
check('a modern notification still gets an empty 202', status == 202 and not text.strip(),
      f'HTTP {status} body={text[:40]!r}')

status, parsed, _ = post(raw=json.dumps([{'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}]).encode(),
                         headers={'MCP-Protocol-Version': MODERN, 'Mcp-Method': 'ping'})
check('a batch is refused instead of half-run',
      status == 400 and (parsed or {}).get('error', {}).get('code') == -32600, f'HTTP {status} {str(parsed)[:160]}')

# A request naming no version at all is the oldest clients, which predate the version
# header: they get the handshake era, and no resultType to confuse them with.
status, parsed, _ = rpc('tools/list')
check('a request naming no version at all is served the handshake era',
      status == 200 and 'resultType' not in (parsed or {}).get('result', {}), f'HTTP {status} {str(parsed)[:160]}')

status, parsed, _ = post({'jsonrpc': '2.0', 'id': 3, 'method': 'tools/list'},
                         headers={'MCP-Protocol-Version': '2025-11-25'})
check('a 2025-11-25 request is served the handshake era too',
      status == 200 and 'resultType' not in (parsed or {}).get('result', {})
      and len((parsed or {}).get('result', {}).get('tools', [])) == 5, f'HTTP {status} {str(parsed)[:160]}')

req = urllib.request.Request(ENDPOINT, method='DELETE', data=b'')
req.add_header('Authorization', 'Basic ' + base64.b64encode(f'{USER}:{PASSWORD}'.encode()).decode())
try:
    with urllib.request.urlopen(req, timeout=30, context=ssl._create_unverified_context()) as r:
        delete_status = r.status
except urllib.error.HTTPError as e:
    delete_status = e.code
check('the old session DELETE is refused with 405', delete_status == 405, f'got HTTP {delete_status}')

# --- the envelope ---------------------------------------------------------------
status, parsed, _ = rpc('ping', msg_id='abc-123')
check('a string id comes back unchanged', (parsed or {}).get('id') == 'abc-123', str(parsed)[:120])
status, parsed, _ = rpc('ping', msg_id=42)
check('an integer id comes back unchanged', (parsed or {}).get('id') == 42, str(parsed)[:120])
for bad, label in ((None, 'null'), (1.5, 'a fraction'), (True, 'a boolean')):
    status, parsed, _ = rpc('ping', msg_id=bad)
    check(f'a {label} id is refused instead of run and echoed as null',
          (parsed or {}).get('error', {}).get('code') == -32600 and 'result' not in (parsed or {}),
          f'HTTP {status} {str(parsed)[:120]}')

# --- what the client says it will accept ----------------------------------------
for accept, allowed in (('*/*', True), ('application/json', True), ('application/*', True),
                        ('text/html, application/json;q=0.9', True), ('text/plain', False),
                        ('text/event-stream', False), ('application/json, text/event-stream;q=0', True),
                        ('application/json;q=0', False), ('application/xml', False)):
    status, parsed, _ = post({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, headers={'Accept': accept})
    check(f'Accept: {accept} is ' + ('served' if allowed else 'answered 406'),
          status == (200 if allowed else 406), f'got HTTP {status}')

status, parsed, _ = post({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, headers={'Accept': 'text/plain'})
check('the 406 is itself JSON, so a client can read why',
      status == 406 and (parsed or {}).get('error', {}).get('code') == -32600, f'HTTP {status} {str(parsed)[:160]}')

# --- authentication -------------------------------------------------------------
for header, value, label in (('Authorization', f'Bearer {USER}/{PASSWORD}', 'Bearer user/secret'),
                             ('Authorization', f'GoogleLogin auth={USER}/{PASSWORD}', 'GoogleLogin auth'),
                             ('Authorization', f'Token {USER}/{PASSWORD}', 'Token user/secret'),
                             ('X-Api-Key', f'{USER}/{PASSWORD}', 'X-Api-Key')):
    status, parsed, _ = post({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, auth=False,
                             headers={header: value})
    check(f'{label} authenticates', status == 200 and 'result' in (parsed or {}), f'HTTP {status}')

for creds, label in ((f'%74est:{PASSWORD}', 'a percent-encoded account name'),
                     (f'{USER}: {PASSWORD}', 'a password with a leading space'),
                     (f'{USER}%3A{PASSWORD}', 'an account name holding an encoded colon'),
                     ('nosuchuser:' + PASSWORD, 'an account that does not exist'),
                     ('No Such User:whatever', 'a name that is not a valid account name')):
    status, parsed, text = post({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, creds=creds)
    check(f'{label} is refused with 401, not with a server error', status == 401,
          f'got HTTP {status} {text[:80]!r}')

refused = {}
for creds, label in ((f'{USER}:not-the-api-password', 'wrong password'),
                     ('nosuchuser:not-the-api-password', 'absent account')):
    status, parsed, text = post({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, creds=creds)
    refused[label] = (status, text)
check('an absent account is refused exactly like a wrong password, so the endpoint is not a user list',
      refused['wrong password'] == refused['absent account'], str(refused)[:200])

status, parsed, _ = post({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, auth=False,
                         headers={'Authorization': f'Bearer {PASSWORD}'})
check('a secret with no account name is refused', status == 401, f'got HTTP {status}')
status, parsed, _ = rpc('ping', url=f'{ENDPOINT}&user={USER}')
check('the account name is not taken from the URL', status == 200 and 'result' in (parsed or {}),
      f'HTTP {status}')

# --- continuation tokens --------------------------------------------------------
status, parsed, data, text = tool('get_unread', {'limit': 2})
token = (data or {}).get('continuation')
check('a page with more behind it hands back a continuation', bool(token), text[:120])
if token:
    mid = len(token) // 2
    tampered = token[:mid] + ('A' if token[mid] != 'A' else 'B') + token[mid + 1:]
    for forged, label in ((tampered, 'a token with one character changed'),
                          ('mcp1.' + token.split('.')[1] + '.' + '0' * 64, 'a token with someone else signature'),
                          ('mcp1.' + base64.b64encode(b'date|99999999999|-').decode().rstrip('=') + '.x',
                           'a token made up from scratch'),
                          ('not-a-token-at-all', 'a token that is not one')):
        status, parsed, data, text = tool('get_unread', {'limit': 2, 'continuation': forged})
        check(f'{label} is refused', (parsed or {}).get('result', {}).get('isError') is True, text[:160])
        check(f'{label} is refused in JSON and not with a PHP warning',
              parsed is not None and 'Warning' not in text and 'Fatal' not in text, text[:160])

    status, parsed, whole, _ = tool('get_unread', {'limit': 4})
    status, parsed, rest, text = tool('get_unread', {'limit': 2, 'continuation': token})
    first_ids = [e['id'] for e in (whole or {}).get('entries', [])]
    rest_ids = [e['id'] for e in (rest or {}).get('entries', [])]
    check('the next page starts right after the last one, with no entry skipped',
          bool(rest_ids) and rest_ids == first_ids[2:4], f'{rest_ids} vs {first_ids}')

    status, parsed, data, text = tool('get_unread', {'limit': 2, 'continuation': token, 'sort': 'id'})
    check('a cursor from one sort order is not honoured in another',
          (parsed or {}).get('result', {}).get('isError') is True, text[:160])

    second = os.environ.get('FRSS_USER2', '')
    second_secret = os.environ.get('FRSS_API_PASSWORD2', '')
    if second and second_secret:
        status, parsed, text = post({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/call',
                                           'params': {'name': 'get_unread',
                                                      'arguments': {'limit': 2, 'continuation': token}}},
                                          creds=f'{second}:{second_secret}')
        check("another account cannot use this account's cursor",
              (parsed or {}).get('result', {}).get('isError') is True, f'HTTP {status} {text[:160]}')
    else:
        print('SKIP  another account cannot use this account\'s cursor  — set FRSS_USER2 and FRSS_API_PASSWORD2')
else:
    print('SKIP  the continuation checks  — nothing enough unread to page through')

# --- structured results ---------------------------------------------------------
status, parsed, _ = rpc('tools/list')
listed = (parsed or {}).get('result', {}).get('tools', [])
check('every tool declares the shape of its answer',
      bool(listed) and all(t.get('outputSchema', {}).get('type') == 'object' for t in listed),
      str([t.get('name') for t in listed])[:200])
schemas = {t['name']: t for t in listed}
for name, arguments in (('list_feeds', {}),
                        ('get_unread', {'limit': 2, 'include_content': True}),
                        ('feed_stats', {})):
    status, parsed, data, text = tool(name, arguments)
    why = conforms(schemas.get(name, {}), data) if isinstance(data, dict) else 'no result to check'
    check(f'{name} answers in the shape it declared', why is None, str(why))

status, parsed, data, _ = tool('feed_stats')
structured = (parsed or {}).get('result', {}).get('structuredContent')
check('a result carries structuredContent next to the text', isinstance(structured, dict), str(parsed)[:160])
check('the structured answer and the text answer agree',
      isinstance(structured, dict) and structured == data and bool(structured), str(parsed)[:200])
check('a count that could not be read is null and said so, not zero',
      all(isinstance(structured.get(k), int) or structured.get(k) is None
          for k in ('starred', 'starred_unread')) if isinstance(structured, dict) else False,
      str(structured)[:200])

# --- search, against something that is really there -----------------------------
status, parsed, data, _ = tool('get_unread', {'limit': 1})
first = ((data or {}).get('entries') or [{}])[0]
word = ''
for candidate in (first.get('title') or '').split():
    cleaned = candidate.strip('.,:;()[]!?"\'')
    if len(cleaned) > 5:
        word = cleaned
        break
if word:
    status, parsed, data, _ = tool('search', {'query': word})
    hits = (data or {}).get('entries', [])
    check(f'a search for a word that is in a title ({word!r}) finds entries',
          bool(hits), f'{str(data)[:160]}')
    check('the answer names the search it carried out', (data or {}).get('query') == word, str(data)[:160])

    status, parsed, data, text = tool('search', {'query': word, 'unread_only': True})
    unread_hits = (data or {}).get('entries', [])
    check('unread_only really filters, and does not answer with everything',
          bool(unread_hits) and all(e['unread'] for e in unread_hits), f'{len(unread_hits)} hits')
    check('unread_only is not wider than the same search without it',
          {e['id'] for e in unread_hits} <= {e['id'] for e in hits}, f'{len(unread_hits)} of {len(hits)}')
else:
    print('SKIP  the search checks  — no word long enough in the first title')

for query, label in (('is:unread', 'a state operator FreshRSS does not have'),
                     ('date:garbage', 'a date FreshRSS cannot read'),
                     ('pubdate:notadate', 'a publication date it cannot read'),
                     ('intitle:', 'an operator with nothing after it'),
                     ('(unclosed', 'a parenthesis left open'),
                     ('feed:4', 'a feed operator spelled wrong'),
                     ('tag:news', 'a tag operator spelled wrong'),
                     ('after:2026-01-01', 'an after operator that does not exist')):
    status, parsed, data, text = tool('search', {'query': query})
    check(f'{label} is refused rather than quietly dropped',
          (parsed or {}).get('result', {}).get('isError') is True, f'HTTP {status} {text[:140]}')
    check(f'{label} does not come back as an unfiltered list', data is None or 'entries' not in data,
          str(data)[:140])

status, parsed, _, _ = tool('search', {'query': 'f:' + str(first.get('feed_id') or 0)})
check('a search narrowed to one feed by operator still works',
      (parsed or {}).get('result', {}).get('isError') is not True, str(parsed)[:140])

# --- date bounds and argument types --------------------------------------------
status, parsed, data, _ = tool('get_unread', {'since': 4102444800})
check('a window no entry falls in answers empty, and not with an error',
      (data or {}).get('entries') == [] and (data or {}).get('count') == 0, str(data)[:140])
for arguments, label in (({'since': 99999999999}, 'a since beyond the year 2100'),
                         ({'since': -5}, 'a since before the epoch'),
                         ({'since': 4102444800, 'until': 1}, 'since after until'),
                         ({'limit': 'thirty'}, 'a limit that is not a number'),
                         ({'max_chars': 0}, 'a max_chars of zero'),
                         ({'unread': True}, 'an argument no tool takes')):
    status, parsed, data, text = tool('get_unread', arguments)
    check(f'{label} is refused', (parsed or {}).get('result', {}).get('isError') is True, text[:140])

status, parsed, data, text = tool('get_unread', {'limitt': 3})
check('a misspelled argument is refused and the message names it',
      (parsed or {}).get('result', {}).get('isError') is True and 'limitt' in text, text[:160])
status, parsed, data, text = tool('feed_stats', {'limit': 3})
check('feed_stats takes no arguments and says so',
      (parsed or {}).get('result', {}).get('isError') is True and 'no arguments' in text, text[:160])

# --- the PATH_INFO form ----------------------------------------------------------
status, parsed, _ = rpc('ping', url=PATH_INFO_ENDPOINT)
check('the /api/misc.php/MCP/ form works too', status == 200 and 'result' in (parsed or {}),
      f'HTTP {status} — if this fails, enable PATH_INFO on the web server')

print()
if failures:
    print(f'{len(failures)} check(s) failed: ' + ', '.join(failures))
    sys.exit(1)
print('all checks passed')
