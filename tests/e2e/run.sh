#!/bin/sh
# Start a disposable FreshRSS with the extension mounted read-only, plus a static feed server it may
# reach, seed two accounts, run the end-to-end suites, and remove everything again.
# Needs only Docker and python3. KEEP=1 leaves the containers running for poking at them by hand.
#
#   sh tests/e2e/run.sh              # every suite
#   sh tests/e2e/run.sh write        # only tests/write.py
set -eu

here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
port=${E2E_PORT:-8099}
password=${E2E_API_PASSWORD:-alice-api-secret}
image=${E2E_IMAGE:-freshrss/freshrss:edge}
name=freshrss-mcp-e2e

teardown() {
	docker rm -f "$name-freshrss" "$name-feeds" >/dev/null 2>&1 || true
	docker network rm "$name" >/dev/null 2>&1 || true
}
teardown
trap '[ "${KEEP:-0}" = 1 ] || teardown' EXIT

docker network create "$name" >/dev/null
docker run -d --name "$name-feeds" --network "$name" --network-alias feeds \
	-v "$here/feeds:/usr/share/nginx/html:ro" nginx:1.29-alpine >/dev/null
# FreshRSS refuses private addresses when fetching feeds; the fixture server is one, hence the allowlist.
docker run -d --name "$name-freshrss" --network "$name" -p "127.0.0.1:$port:80" \
	-e TZ=UTC -e INTERNAL_HOST_ALLOWLIST=feeds:80 \
	-e FRESHRSS_INSTALL='--api-enabled --default-user alice --language en' \
	-e FRESHRSS_USER="--api-password $password --language en --password alice-login-secret --user alice --no-default-feeds" \
	-v "$root/xExtension-MCP:/var/www/FreshRSS/extensions/xExtension-MCP:ro" \
	-v "$here/subscriptions.opml:/e2e/subscriptions.opml:ro" \
	"$image" >/dev/null

printf 'waiting for FreshRSS'
tries=0
until curl -fsS -o /dev/null "http://127.0.0.1:$port/i/" 2>/dev/null; do
	tries=$((tries + 1))
	if [ "$tries" -gt 90 ]; then
		echo ' gave up'
		docker logs "$name-freshrss" 2>&1 | tail -40
		exit 1
	fi
	printf .
	sleep 1
done
echo ' up'

run() { docker exec -u www-data -w /var/www/FreshRSS "$name-freshrss" "$@"; }

# Enable the extension system-wide, the way the Extensions page would.
run php -r '
	$file = "data/config.php";
	$conf = include $file;
	$conf["extensions_enabled"]["MCP"] = true;
	file_put_contents($file, "<?php\nreturn " . var_export($conf, true) . ";\n");
'
# OPcache may keep serving the old config.php for a moment; the endpoint says 404 until it lets go.
tries=0
until [ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://127.0.0.1:$port/api/misc.php?ext=MCP")" = 401 ]; do
	tries=$((tries + 1))
	if [ "$tries" -gt 30 ]; then
		echo 'the MCP endpoint never came up'
		exit 1
	fi
	sleep 1
done
# A second account, to prove that one account cannot reach the other's data.
run php cli/create-user.php --user bob --password bob-login-secret --api-password bob-api-secret \
	--no-default-feeds >/dev/null
for user in alice bob; do
	run php cli/import-for-user.php --user "$user" --filename /e2e/subscriptions.opml >/dev/null 2>&1
	run php cli/actualize-user.php --user "$user" >/dev/null 2>&1
done

status=0
export FRSS_URL="http://127.0.0.1:$port" FRSS_USER=alice FRSS_API_PASSWORD="$password" \
	FRSS_USER2=bob FRSS_API_PASSWORD2=bob-api-secret
for suite in ${1:-smoke write}; do
	echo "== $suite"
	python3 "$root/tests/$suite.py" || status=1
done
if [ "$status" != 0 ]; then
	echo '== FreshRSS logs (tail)'
	run sh -c 'tail -n 30 data/users/alice/log.txt data/users/_/log.txt 2>/dev/null' || true
fi
exit "$status"
