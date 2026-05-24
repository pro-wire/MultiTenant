<?php

namespace ProcessWire;

/**
 * MultiTenant
 *
 * Minimal multi-tenant bootstrap layer for ProcessWire.
 *
 * Resolves the current tenant from the incoming hostname, isolates per-tenant
 * file-system paths (assets, cache, logs, sessions), and exposes the active
 * tenant as a global ProcessWire API variable: wire('tenant').
 *
 * ⚠ DATABASE CREDENTIALS must be applied in /site/config.php — ProcessWire
 *   establishes the PDO connection before any module's init() is called, so
 *   setting $config->dbName etc. here would have no effect. See the bundled
 *   config.example.php for the required config.php integration snippet.
 *
 * @see tenants.example.php   Platform config format.
 * @see config.example.php        Required config.php DB-switching snippet.
 */
class MultiTenant extends WireData implements Module {

	/** @var WireData|null Active tenant for the current request. */
	protected $tenant = null;

	/** @var array Full platform config loaded from /site/tenants.php. */
	protected $platformConfig = [];

	// -------------------------------------------------------------------------
	// Module metadata
	// -------------------------------------------------------------------------

	public static function getModuleInfo(): array {
		return [
			'title'    => 'MultiTenant',
			'version'  => 1,
			'summary'  => 'Minimal multi-tenant bootstrap layer for ProcessWire',
			'autoload' => true,
			'singular' => true,
		];
	}

  // -------------------------------------------------------------------------
  // Lifecycle
  // -------------------------------------------------------------------------

	/**
	 * Module initialisation.
	 *
	 * Loads the platform config, resolves the active tenant, applies
	 * per-tenant file-system paths, and registers the tenant as an API var.
	 *
	 * When no tenant matches the current hostname the module leaves all
	 * ProcessWire paths at their defaults (i.e. /site/assets/) and registers
	 * wire('tenant') as null.
	 *
	 * @throws WireException If the platform config file is missing.
	 */
	public function init(): void {
		$host       = $this->resolveHost();
		$configFile = $this->wire('config')->paths->site . 'tenants.php';

		if (!is_file($configFile)) {
			throw new WireException('MultiTenant: missing config file at: ' . $configFile);
		}

		$this->platformConfig = require $configFile;

		$tenantData = $this->resolveTenant($host);

		// No matching tenant — keep default /site/assets/ paths and bail out.
		if ($tenantData === null) {
			$this->wire('tenant', null);
			return;
		}

		// WireData::__construct() does not accept an array — populate via setArray().
		$this->tenant = new WireData();
		$this->tenant->setArray($tenantData);

		$this->applyTenantPaths();

		// Register as a ProcessWire fuel/API variable accessible via wire('tenant').
		$this->wire('tenant', $this->tenant);
	}

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

	/**
	 * Returns a sanitised, lowercase hostname without a port number.
	 *
	 * Falls back to 'localhost' when HTTP_HOST is absent (e.g. CLI runs).
	 */
	protected function resolveHost(): string {
		$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

		// Remove port suffix: "example.com:8080" → "example.com".
		$host = explode(':', $host)[0];

		// Lowercase and strip any character that is not valid in a hostname.
		$host = preg_replace('/[^a-z0-9.\-]/i', '', strtolower($host));

		return $host !== '' ? $host : 'localhost';
	}

	/**
	 * Finds the first tenant whose domain list contains the given hostname.
	 *
	 * @param  string     $host Sanitised hostname.
	 * @return array|null       Matching tenant config array, or null.
	 */
	protected function resolveTenant(string $host): ?array {
		foreach ($this->platformConfig['tenants'] ?? [] as $tenant) {
			// Normalise stored domains to lowercase before comparing.
			$domains = array_map('strtolower', $tenant['domains'] ?? []);

			if (in_array($host, $domains, /* strict */ true)) {
				return $tenant;
			}
		}

		return null;
	}

	/**
	 * Overrides ProcessWire's default paths with tenant-specific directories.
	 *
	 * Only file-system paths and asset URLs are set here. DB credentials must
	 * already be applied in /site/config.php before this point (see README).
	 */
	protected function applyTenantPaths(): void {
		$config      = $this->wire('config');
		$storageBase = rtrim((string) $this->tenant->get('storagePath'), '/') . '/';
		$storageBase = $config->paths->root . $storageBase;
		$storageBase = str_replace('//', '/', $storageBase); // Prevent double-slashes

		// Redirect the four writable directory groups to tenant storage.
		$config->paths->assets   = $storageBase . 'assets/';
		$config->paths->cache    = $storageBase . 'cache/';
		$config->paths->logs     = $storageBase . 'logs/';
		$config->paths->sessions = $storageBase . 'sessions/';

		// Derive files and backups from the tenant assets path, overriding
		// the values ProcessWire pre-computed before this module was loaded.
		$config->paths->files   = $config->paths->assets . 'files/';
		$config->paths->backups = $config->paths->assets . 'backups/';

		// Assets URL
		$assetsUrl = $config->urls->root . $storageBase . 'assets/';
		$config->urls->assets = $assetsUrl;
		$config->urls->files  = $assetsUrl . 'files/';

		$this->ensureDirectories([
			$config->paths->assets,
			$config->paths->files,
			$config->paths->backups,
			$config->paths->cache,
			$config->paths->logs,
			$config->paths->sessions,
		]);
	}

	/**
	 * Creates any directories in the list that do not yet exist.
	 *
	 * @param  string[] $directories Absolute directory paths to ensure.
	 * @throws WireException         If a directory cannot be created.
	 */
	protected function ensureDirectories(array $directories): void {
		foreach ($directories as $dir) {
			if (is_dir($dir)) {
				continue;
			}

			// mkdir() can race with another request; re-check after the call.
			$created = mkdir($dir, 0775, /* recursive */ true);

			if (!$created && !is_dir($dir)) {
				throw new WireException('MultiTenant: could not create directory: ' . $dir);
			}
		}
	}
}
