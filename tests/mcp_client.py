"""HTTP and JSON-RPC plumbing shared by the end-to-end suites (smoke.py, write.py).

Configured from the environment: FRSS_URL, FRSS_USER, FRSS_API_PASSWORD.
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


def finish() -> None:
    """Print the verdict and exit non-zero when any check failed."""
    print()
    if failures:
        print(f'{len(failures)} check(s) failed: ' + ', '.join(failures))
        sys.exit(1)
    print('all checks passed')
