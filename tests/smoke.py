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

    status, parsed, art, _ = tool('get_article', {'id': article_id, 'fetch_full_content': True})
    check('get_article can ask FreshRSS for the full page', bool(art) and art.get('id') == article_id)

    feed_id = entries[0]['feed_id']
    status, parsed, data3, _ = tool('get_unread', {'feed_id': feed_id, 'limit': 5})
    narrowed = (data3 or {}).get('entries', [])
    check('get_unread narrows to one feed', all(e['feed_id'] == feed_id for e in narrowed) if narrowed else bool(narrowed))

status, parsed, data, text = tool('search', {'query': 'a'})
check('search runs server-side', 'entries' in (data or {}), text[:200])

status, parsed, data, text = tool('search', {'query': 'is:unread'})
check('search understands FreshRSS syntax', all(e['unread'] for e in (data or {}).get('entries', [])), text[:160])

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
      isinstance(found.get('ttlMs'), int) and found.get('cacheScope') == 'public', str(found)[:200])

status, parsed, _ = modern('tools/list')
found = (parsed or {}).get('result', {})
check('a modern result says it is complete', found.get('resultType') == 'complete', str(found)[:160])
check('a modern result names the server that sent it',
      found.get('_meta', {}).get('io.modelcontextprotocol/serverInfo', {}).get('name') == 'freshrss-mcp',
      str(found)[:200])
check('a modern tools/list carries cache hints',
      isinstance(found.get('ttlMs'), int) and found.get('cacheScope') == 'public', str(found)[:200])
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

# --- the PATH_INFO form ----------------------------------------------------------
status, parsed, _ = rpc('ping', url=PATH_INFO_ENDPOINT)
check('the /api/misc.php/MCP/ form works too', status == 200 and 'result' in (parsed or {}),
      f'HTTP {status} — if this fails, enable PATH_INFO on the web server')

print()
if failures:
    print(f'{len(failures)} check(s) failed: ' + ', '.join(failures))
    sys.exit(1)
print('all checks passed')
