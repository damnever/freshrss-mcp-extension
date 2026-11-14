<?php
declare(strict_types=1);

/**
 * Checks the address gate behind get_article(fetch_full_content).
 *
 * Standalone on purpose: the gate is pure arithmetic on addresses, so it is checked here rather than
 * through a live FreshRSS, and it needs no database, no session and no network for the part that
 * matters. Run it with any PHP 8.2 or newer:
 *
 *   php tests/url_policy.php
 *
 * Exits non-zero when a case fails, so it belongs in front of a release.
 */

require __DIR__ . '/../xExtension-MCP/src/UrlPolicy.php';

$failed = 0;
$checks = 0;

/**
 * @param list<string> $want addresses that must be reachable
 * @param list<string> $refuse addresses that must not be
 */
function expect(array $want, array $refuse = []): void {
	global $failed, $checks;
	foreach ($want as $ip) {
		$checks++;
		if (FreshMcp_UrlPolicy::isPublic($ip) !== true) {
			$failed++;
			echo "FAIL  {$ip} should be public\n";
		}
	}
	foreach ($refuse as $ip) {
		$checks++;
		if (FreshMcp_UrlPolicy::isPublic($ip) === true) {
			$failed++;
			echo "FAIL  {$ip} should be refused\n";
		}
	}
}

// The loopback, the private ranges of RFC 1918 and everything a container runtime likes to hand out.
expect([], ['0.0.0.0', '0.255.255.255', '10.0.0.1', '10.255.255.255', '127.0.0.1', '127.1.2.3',
	'100.64.0.1', '100.127.255.255', '169.254.1.1', '172.16.0.1', '172.31.255.255', '192.0.0.1',
	'192.0.2.1', '192.88.99.1', '192.168.0.1', '192.168.99.254', '198.18.0.1', '198.19.255.255',
	'198.51.100.1', '203.0.113.1', '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255']);

// Just outside those ranges, so that a block cannot widen itself by one address.
expect(['9.255.255.255', '11.0.0.1', '100.63.255.255', '100.128.0.1', '128.0.0.1', '172.15.255.255',
	'172.32.0.1', '192.0.1.1', '192.0.3.1', '192.88.98.1', '192.167.255.255', '192.169.0.1',
	'198.17.255.255', '198.20.0.1', '223.255.255.255']);

// The same story in sixes, including the forms that hide a v4 address inside a v6 one.
expect([], ['::', '::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:192.168.1.1', '64:ff9b::1',
	'100::1', '2001::1', '2001:2::1', '2002:7f00:1::1', '2001:db8::1', 'fd00::1', 'fc00::1',
	'fe80::1', 'ff02::1']);
expect(['2606:4700:4700::1111', '2001:4860:4860::8888', '2003:db8::1']);

// Not an address at all is not a permission either.
expect([], ['', ' ', 'not-an-ip', '999.1.1.1', '1.2.3', '1.2.3.4.5', 'gzip']);

// The URL shapes around the address check.
function expectRefusal(string $url, string $because): void {
	global $failed, $checks;
	$checks++;
	$refusal = FreshMcp_UrlPolicy::refusal($url);
	if ($refusal === null) {
		$failed++;
		echo "FAIL  {$url} should be refused: {$because}\n";
	} elseif (!str_contains($refusal, $because)) {
		$failed++;
		echo "FAIL  {$url} refused for the wrong reason: {$refusal}\n";
	}
}

expectRefusal('file:///etc/passwd', 'only http and https');
expectRefusal('gopher://10.10.3.1:70/', 'only http and https');
expectRefusal('HTTP://127.0.0.1/', 'not a public address');
expectRefusal('http://127.0.0.1:8099/', 'not a public address');
expectRefusal('http://[::1]:80/', 'not a public address');
expectRefusal('http://[::ffff:127.0.0.1]/', 'not a public address');
expectRefusal('http://10.10.3.213/api/', 'not a public address');
expectRefusal('http://192.168.1.1/', 'not a public address');
expectRefusal('http://169.254.169.254/latest/meta-data/', 'not a public address');
expectRefusal('http://100.100.100.100/', 'not a public address');
expectRefusal('http://user:secret@example.com/', 'credentials');
// PHP itself refuses this shape before we ever look at the host, which is answer enough.
expectRefusal('http:///no-host', 'not a valid URL');
expectRefusal('not a url at all', 'names no scheme');

// Names, only when this machine can actually resolve them: a test that cannot ask DNS says so
// rather than passing quietly.
$naming = [
	'localhost' => false,
	'localhost.localdomain' => false,
	'example.com' => true,
];
foreach ($naming as $host => $shouldBePublic) {
	$resolves = gethostbynamel($host) !== false;
	if (!$resolves) {
		echo "SKIP  {$host} (no name resolution here)\n";
		continue;
	}
	$checks++;
	$refusal = FreshMcp_UrlPolicy::refusal('http://' . $host . '/');
	if ($shouldBePublic && $refusal !== null) {
		$failed++;
		echo "FAIL  {$host} should be fetchable: {$refusal}\n";
	} elseif (!$shouldBePublic && $refusal === null) {
		$failed++;
		echo "FAIL  {$host} should be refused\n";
	}
}

echo $failed === 0 ?
	"url policy: {$checks} cases passed\n" :
	"url policy: {$failed} of {$checks} cases FAILED\n";
exit($failed === 0 ? 0 : 1);
