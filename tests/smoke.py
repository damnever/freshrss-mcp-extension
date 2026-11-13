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

failures: list[str] = []


def check(label: str, ok: bool, detail: str = '') -> None:
    print(f'{"PASS" if ok else "FAIL"}  {label}' + (f'  — {detail}' if detail and not ok else ''))
    if not ok:
        failures.append(label)


def post(payload=None, *, auth: bool = True, url: str = ENDPOINT, raw: str | None = None,
         creds: str | None = None):
    """POST one message; returns (status, parsed-body-or-None, raw-text)."""
    body = raw if raw is not None else json.dumps(payload).encode()
    req = urllib.request.Request(url, data=body, method='POST',
                                headers={'Content-Type': 'application/json'})
    if auth:
        token = base64.b64encode((creds or f'{USER}:{PASSWORD}').encode()).decode()
        req.add_header('Authorization', f'Basic {token}')
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
    payload = None
    if parsed and 'result' in parsed:
        content = parsed['result'].get('content') or []
        if content and content[0].get('type') == 'text':
            try:
                payload = json.loads(content[0]['text'])
            except json.JSONDecodeError:
                payload = None
    return status, parsed, payload, text


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

# --- the PATH_INFO form ----------------------------------------------------------
status, parsed, _ = rpc('ping', url=PATH_INFO_ENDPOINT)
check('the /api/misc.php/MCP/ form works too', status == 200 and 'result' in (parsed or {}),
      f'HTTP {status} — if this fails, enable PATH_INFO on the web server')

print()
if failures:
    print(f'{len(failures)} check(s) failed: ' + ', '.join(failures))
    sys.exit(1)
print('all checks passed')
