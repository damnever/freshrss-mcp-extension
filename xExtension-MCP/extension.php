<?php
declare(strict_types=1);

/**
 * Model Context Protocol (MCP) server for FreshRSS, read-only.
 *
 * FreshRSS routes extension-owned HTTP requests to `p/api/misc.php`, which fires the
 * `api_misc` hook for the extension named in the URL:
 *
 *     /api/misc.php?ext=MCP        (works everywhere)
 *     /api/misc.php/MCP/           (needs PATH_INFO support on the web server)
 *
 * That entry point performs no authentication, so every request is authenticated here
 * with a FreshRSS API password before any data is touched. See src/Auth.php.
 */
final class MCPExtension extends Minz_Extension {
	public const VERSION = '0.3.2';

	/** Class name prefix of the files in src/, used by the autoloader below. */
	public const CLASS_PREFIX = 'FreshMcp_';

	public function init(): void {
		parent::init();

		$this->registerHook(Minz_HookType::ApiMisc, $this->handleApi(...));
	}

	/**
	 * Autoload this extension’s classes.
	 * Registered by FreshRSS when the extension is enabled, before init() is called.
	 */
	public function autoload(string $class): void {
		if (!str_starts_with($class, self::CLASS_PREFIX)) {
			return;
		}
		$filename = __DIR__ . '/src/' . substr($class, strlen(self::CLASS_PREFIX)) . '.php';
		if (is_file($filename)) {
			require_once $filename;
		}
	}

	/**
	 * Answer one MCP request. Reached only through the api_misc hook.
	 */
	public function handleApi(): void {
		FreshMcp_Server::serve();
	}
}
