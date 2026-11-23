#!/usr/bin/env python3
"""End-to-end checks for the write tools of the FreshRSS MCP extension.

This suite CHANGES the account it runs against: it marks entries read, adds labels, subscribes,
deletes. Run it only against a disposable FreshRSS, which is what tests/e2e/run.sh starts:

    sh tests/e2e/run.sh write

It expects the fixtures of tests/e2e: the account subscribed to Alpha News (category Tech, 30
entries) and Beta Blog (category Life, 8 entries), everything unread, and the feed server reachable
as http://feeds/. FRSS_USER2 / FRSS_API_PASSWORD2 name a second account with the same fixtures.
"""
import os
import sys

from mcp_client import USER, PASSWORD, URL, check, conforms, finish, modern_tool, post

USER2 = os.environ.get('FRSS_USER2', '')
PASSWORD2 = os.environ.get('FRSS_API_PASSWORD2', '')
if not (USER and PASSWORD and USER2 and PASSWORD2):
    sys.exit('Set FRSS_USER, FRSS_API_PASSWORD, FRSS_USER2 and FRSS_API_PASSWORD2; see tests/e2e/run.sh.')

print(f'Target: {URL}  users: {USER}, {USER2}\n')

status, parsed, _ = post({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'})
SCHEMAS = {t['name']: t.get('outputSchema') or {} for t in (parsed or {}).get('result', {}).get('tools', [])}


def call(name, arguments=None, *, creds=None):
    """Call a tool; returns (structured result, or {} on error; error text or None).

    Every successful answer is also checked against the outputSchema the tool advertised, so a
    handler that drifts from its declared shape fails here and not in a client.
    """
    status, parsed, text = post({'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call',
                                 'params': {'name': name, 'arguments': arguments or {}}}, creds=creds)
    result = (parsed or {}).get('result')
    if status != 200 or result is None:
        return {}, f'HTTP {status} {text[:200]}'
    if result.get('isError'):
        return {}, result['content'][0]['text']
    data = result.get('structuredContent') or {}
    why = conforms(SCHEMAS.get(name, {}), data, name)
    if why is not None:
        check(f'{name} answers in the shape it declared', False, why)
    return data, None


def refused(label, name, arguments, *, contains=''):
    data, error = call(name, arguments)
    check(label, error is not None and contains.lower() in error.lower(), f'data={data} error={error}')
    return error


def unread_ids(feed_id, *, creds=None, limit=100):
    data, _ = call('get_unread', {'feed_id': feed_id, 'limit': limit, 'sort': 'id'}, creds=creds)
    return [e['id'] for e in data.get('entries', [])]


def entry(entry_id, *, creds=None):
    data, _ = call('get_article', {'id': entry_id, 'max_chars': 10}, creds=creds)
    return data or {}


def feeds(*, include_hidden=True, creds=None):
    data, _ = call('list_feeds', {'include_hidden': include_hidden}, creds=creds)
    return {f['title']: {**f, 'category_id': c['id'], 'category': c['name']}
            for c in data.get('categories', []) for f in c['feeds']}


def category_ids():
    data, _ = call('list_feeds', {'include_hidden': True})
    return {c['name']: c['id'] for c in data.get('categories', [])}


def labels(*, creds=None):
    data, _ = call('list_labels', creds=creds)
    return {label['name']: label for label in data.get('labels', [])}


BOB = f'{USER2}:{PASSWORD2}'
start = feeds()
ALPHA, BETA = start.get('Alpha News', {}).get('id'), start.get('Beta Blog', {}).get('id')
if not (ALPHA and BETA and start['Alpha News']['unread'] == 30 and start['Beta Blog']['unread'] == 8):
    sys.exit(f'The fixtures are not as expected (fresh Alpha News and Beta Blog, all unread): {start}')
bob_unread_before = sum(f['unread'] for f in feeds(creds=BOB).values())

# --- mark_read -------------------------------------------------------------------
alpha_ids = unread_ids(ALPHA)
pair = alpha_ids[:2]
data, error = call('mark_read', {'ids': pair})
check('mark_read marks the entries read', data == {'changed': 2, 'not_found': []}, f'{data} {error}')
check('a marked entry reads back as read', entry(pair[0]).get('unread') is False, str(entry(pair[0])))
data, _ = call('mark_read', {'ids': pair})
check('marking read entries read again changes nothing and says so', data.get('changed') == 0, str(data))
data, _ = call('mark_read', {'ids': pair, 'read': False})
check('read: false marks them unread again',
      data.get('changed') == 2 and entry(pair[0]).get('unread') is True, str(data))
data, _ = call('mark_read', {'ids': [pair[0], '1']})
check('an id that is not an entry comes back under not_found, the others are done',
      data == {'changed': 1, 'not_found': ['1']}, str(data))
call('mark_read', {'ids': [pair[0]], 'read': False})
check('numeric ids are taken as well as strings',
      call('mark_read', {'ids': [int(pair[0])]})[0].get('changed') == 1)
call('mark_read', {'ids': [pair[0]], 'read': False})

refused('ids that are all unknown are refused', 'mark_read', {'ids': ['1', '2']}, contains='none of those ids')
refused('a non-numeric id is refused', 'mark_read', {'ids': ['abc']}, contains='not an entry id')
refused('an empty id list is refused', 'mark_read', {'ids': []}, contains='non-empty list')
refused('a single id not in a list is refused', 'mark_read', {'ids': pair[0]}, contains='list')
refused('a call without ids is refused', 'mark_read', {}, contains='needs ids')
refused('more ids than one call takes are refused', 'mark_read', {'ids': [str(i) for i in range(1, 300)]},
        contains='at most')
check('nothing was marked by the refused calls', feeds()['Alpha News']['unread'] == 30, str(feeds()['Alpha News']))

bob_beta = unread_ids(feeds(creds=BOB)['Beta Blog']['id'], creds=BOB)
refused("another account's entry id is not this account's entry", 'mark_read', {'ids': bob_beta[:1]},
        contains='none of those ids')
check("the other account's entry is still unread", entry(bob_beta[0], creds=BOB).get('unread') is True)

_, _, data, _ = modern_tool('mark_read', {'ids': pair[:1]})
check('a write tool works in the 2026-07-28 era too', data == {'changed': 1, 'not_found': []}, str(data))
call('mark_read', {'ids': pair[:1], 'read': False})

# --- set_starred -----------------------------------------------------------------
stats_before, _ = call('feed_stats')
data, _ = call('set_starred', {'ids': pair})
check('set_starred stars the entries', data == {'changed': 2, 'not_found': []}, str(data))
check('a starred entry reads back as starred', entry(pair[1]).get('starred') is True)
stats, _ = call('feed_stats')
check('feed_stats counts the new stars', stats.get('starred') == stats_before.get('starred', 0) + 2,
      f'{stats_before} -> {stats}')
data, _ = call('set_starred', {'ids': pair, 'starred': False})
check('starred: false takes the stars off', data.get('changed') == 2 and entry(pair[1]).get('starred') is False,
      str(data))

# --- labels on entries -----------------------------------------------------------
data, error = call('label_entries', {'ids': pair, 'label': 'Later & maybe'})
label = data.get('label', {})
check('label_entries creates a missing label and applies it',
      data.get('created') is True and data.get('changed') == 2 and label.get('entries') == 2, f'{data} {error}')
LABEL = label.get('id')
data, _ = call('label_entries', {'ids': pair, 'label': 'Later & maybe'})
check('labelling again finds the same label and changes nothing',
      data.get('created') is False and data.get('changed') == 0 and data['label']['id'] == LABEL, str(data))
data, _ = call('label_entries', {'ids': pair, 'label': (label.get('name') or '')})
check('the stored, escaped name from an answer names the same label',
      data.get('created') is False and data.get('label', {}).get('id') == LABEL, str(data))
listed = labels()
check('list_labels shows the label with its counts',
      any(v['id'] == LABEL and v['entries'] == 2 and v['unread'] == 2 for v in listed.values()), str(listed))
data, _ = call('search', {'query': f'L:{LABEL}'})
check('search L:<id> lists exactly the labelled entries',
      sorted(e['id'] for e in data.get('entries', [])) == sorted(pair), str(data)[:200])
data, _ = call('label_entries', {'ids': pair[:1], 'label_id': LABEL, 'remove': True})
check('remove: true takes the label off', data.get('changed') == 1 and data['label']['entries'] == 1, str(data))
refused('removing a label that does not exist is refused', 'label_entries',
        {'ids': pair, 'label': 'never made', 'remove': True}, contains='no label named')
check('…and does not create it', 'never made' not in labels())
refused('a label named like a category is refused', 'label_entries', {'ids': pair, 'label': 'Tech'},
        contains='category named Tech')
refused('label and label_id together are refused', 'label_entries', {'ids': pair, 'label': 'x', 'label_id': LABEL},
        contains='exactly one')
refused('an unknown label_id is refused', 'label_entries', {'ids': pair, 'label_id': 999999}, contains='no label')
refused('unknown ids do not create a label', 'label_entries', {'ids': ['1'], 'label': 'ghost'},
        contains='none of those ids')
check('…the label was not created', 'ghost' not in labels())

# --- label management --------------------------------------------------------------
data, _ = call('create_label', {'name': 'Temp'})
TEMP = data.get('label', {}).get('id')
check('create_label makes an empty label', data.get('label', {}).get('entries') == 0, str(data))
refused('creating a label that exists is refused', 'create_label', {'name': 'Temp'}, contains='already a label')
refused('a label may not take a category name', 'create_label', {'name': 'Life'}, contains='category named Life')
refused('a name with a line break is refused', 'create_label', {'name': 'two\nlines'}, contains='control')
refused('an empty name is refused', 'create_label', {'name': '  '}, contains='name')
refused('a name too long for FreshRSS is refused rather than cut', 'create_label', {'name': 'x' * 200},
        contains='too long')
data, _ = call('rename_label', {'label_id': TEMP, 'name': 'Temp 2'})
check('rename_label renames', data.get('label', {}).get('name') == 'Temp 2' and 'Temp' not in labels(), str(data))
data, _ = call('rename_label', {'label_id': TEMP, 'name': 'Temp 2'})
check('renaming to the current name is a no-op, not a clash', data.get('label', {}).get('id') == TEMP, str(data))
refused('renaming onto another label is refused', 'rename_label', {'label_id': TEMP, 'name': 'Later & maybe'},
        contains='already a label')
data, _ = call('delete_label', {'label_id': LABEL})
check('delete_label reports what it deleted', data.get('deleted', {}).get('entries') == 1, str(data))
check('the deleted label is gone, and its entries stay',
      all(v['id'] != LABEL for v in labels().values()) and entry(pair[1]).get('id') == pair[1])
refused('deleting it again is refused', 'delete_label', {'label_id': LABEL}, contains='no label')
call('delete_label', {'label_id': TEMP})

# --- mark_all_read -----------------------------------------------------------------
refused('mark_all_read with no scope is refused', 'mark_all_read', {}, contains='exactly one')
refused('mark_all_read with two scopes is refused', 'mark_all_read', {'feed_id': ALPHA, 'all': True},
        contains='exactly one')
refused('all: false is no scope', 'mark_all_read', {'all': False}, contains='exactly one')
refused('an unknown feed is refused', 'mark_all_read', {'feed_id': 999999}, contains='no feed')
refused('a malformed up_to_id is refused', 'mark_all_read', {'feed_id': BETA, 'up_to_id': 'yesterday'},
        contains='up_to_id')
check('the refused calls marked nothing', feeds()['Beta Blog']['unread'] == 8)

beta_ids = sorted(unread_ids(BETA), key=int)
cutoff = beta_ids[4]
data, _ = call('mark_all_read', {'feed_id': BETA, 'up_to_id': cutoff})
check('mark_all_read on a feed stops at up_to_id', data.get('marked') == 5 and data.get('scope') == f'feed {BETA}',
      str(data))
check('entries newer than up_to_id stay unread', sorted(unread_ids(BETA), key=int) == beta_ids[5:],
      str(unread_ids(BETA)))

call('label_entries', {'ids': alpha_ids[:3], 'label': 'Batch'})
batch = labels()['Batch']['id']
data, _ = call('mark_all_read', {'label_id': batch})
check('mark_all_read on a label marks only the labelled entries',
      data.get('marked') == 3 and feeds()['Alpha News']['unread'] == 27, f'{data} {feeds()["Alpha News"]}')

cats = category_ids()
data, _ = call('mark_all_read', {'category_id': cats['Tech']})
check('mark_all_read on a category marks its feeds',
      data.get('marked') == 27 and feeds()['Alpha News']['unread'] == 0 and feeds()['Beta Blog']['unread'] == 3,
      f'{data} {feeds()}')
data, _ = call('mark_all_read', {'all': True})
check('mark_all_read with all: true empties the queue',
      data.get('marked') == 3 and call('get_unread')[0].get('count') == 0, str(data))

# --- categories and feeds -------------------------------------------------------------
data, _ = call('create_category', {'name': 'Reading'})
reading = data.get('category', {})
check('create_category makes an empty category', reading.get('feeds') == 0 and reading.get('name') == 'Reading', str(data))
refused('a duplicate category is refused', 'create_category', {'name': 'Reading'}, contains='category named')
refused('a category may not take a label name', 'create_category', {'name': 'Batch'}, contains='label named')
data, _ = call('rename_category', {'category_id': reading.get('id'), 'name': 'Reading list'})
check('rename_category renames', data.get('category', {}).get('name') == 'Reading list', str(data))

refused('update_feed with nothing to change is refused', 'update_feed', {'feed_id': BETA}, contains='at least one')
refused('moving to a category that does not exist is refused', 'update_feed', {'feed_id': BETA, 'category_id': 999999},
        contains='no category')
refused('an unknown visibility is refused', 'update_feed', {'feed_id': BETA, 'visibility': 'muted'}, contains='one of')
check('the refused updates left the feed where it was', feeds()['Beta Blog']['category'] == 'Life')
data, _ = call('update_feed', {'feed_id': BETA, 'title': 'Beta & Co', 'category_id': reading.get('id'),
                               'visibility': 'hidden'})
row = data.get('feed', {})
check('update_feed renames, moves and hides in one call',
      row.get('category_id') == reading.get('id') and row.get('visibility') == 'hidden' and 'Beta' in row.get('title', ''),
      str(data))
check('a hidden feed leaves list_feeds unless include_hidden',
      all(f['id'] != BETA for f in feeds(include_hidden=False).values()) and
      any(f['id'] == BETA for f in feeds().values()))
call('update_feed', {'feed_id': BETA, 'visibility': 'main'})

data, _ = call('delete_category', {'category_id': reading.get('id')})
check('delete_category moves its feeds to the default category',
      data.get('feeds_moved') == 1 and [f['category_id'] for f in feeds().values() if f['id'] == BETA] == [1],
      str(data))
refused('deleting it again is refused', 'delete_category', {'category_id': reading.get('id')}, contains='no category')
refused('the default category cannot be deleted', 'delete_category', {'category_id': 1}, contains='default')

# --- subscribe, refresh, unsubscribe -------------------------------------------------
data, error = call('subscribe_feed', {'url': 'http://feeds/gamma.xml', 'category_id': cats['Tech']})
gamma = data.get('feed', {})
check('subscribe_feed adds the feed and fetches it',
      gamma.get('entries') == 3 and gamma.get('unread') == 3 and gamma.get('category_id') == cats['Tech'],
      f'{data} {error}')
refused('subscribing twice is refused and names the feed', 'subscribe_feed', {'url': 'http://feeds/gamma.xml'},
        contains=f'as feed {gamma.get("id")}')
refused('a URL that is not http(s) is refused', 'subscribe_feed', {'url': 'file:///etc/passwd'}, contains='http')
refused('a private address FreshRSS may not reach is refused', 'subscribe_feed',
        {'url': 'http://127.0.0.1/feed.xml'}, contains='could not subscribe')
refused('a missing category is refused rather than defaulted', 'subscribe_feed',
        {'url': 'http://feeds/alpha.xml?again', 'category_id': 999999}, contains='no category')
# FreshRSS stores names HTML-escaped, as its web interface does, and list_feeds returns them as stored.
check('no feed was added by the refusals', sorted(feeds()) == ['Alpha News', 'Beta &amp; Co', 'Gamma Weekly'],
      str(sorted(feeds())))

data, _ = call('refresh_feed', {'feed_id': gamma.get('id')})
check('refresh_feed fetches and reports nothing new', data.get('new_entries') == 0 and
      data.get('feed', {}).get('entries') == 3, str(data))
refused('refreshing an unknown feed is refused', 'refresh_feed', {'feed_id': 999999}, contains='no feed')

data, _ = call('unsubscribe_feed', {'feed_id': gamma.get('id')})
check('unsubscribe_feed reports what it deleted', data.get('deleted', {}).get('entries') == 3, str(data))
check('the feed is gone', 'Gamma Weekly' not in feeds())
refused('unsubscribing again is refused', 'unsubscribe_feed', {'feed_id': gamma.get('id')}, contains='no feed')

# --- the other account ----------------------------------------------------------------
bob_feeds = feeds(creds=BOB)
check('the other account saw none of this',
      sum(f['unread'] for f in bob_feeds.values()) == bob_unread_before and labels(creds=BOB) == {} and
      sorted(bob_feeds) == ['Alpha News', 'Beta Blog'], f'{bob_feeds} {labels(creds=BOB)}')

finish()
