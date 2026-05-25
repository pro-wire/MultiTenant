<?php

/**
 * MultiTenantConfig — Tenant DB-switching helper for /site/config.php.
 *
 * This is intentionally a plain PHP class with no namespace and no ProcessWire
 * dependencies. It is required early in /site/config.php, before ProcessWire
 * is bootstrapped and before the module autoloader is available.
 *
 * Responsibilities:
 *   - Load /site/tenants.php
 *   - Register every tenant domain with $config->httpHosts so ProcessWire's
 *     host-validation does not reject legitimate tenant requests with a 403
 *   - Apply the matching tenant's database credentials to $config
 *
 * Usage in /site/config.php (before any static $config->db* assignments):
 *
 *   require_once __DIR__ . '/modules/MultiTenant/MultiTenantConfig.php';
 *   (new MultiTenantConfig(__DIR__ . '/tenants.php', $config))->apply();
 */
class MultiTenantConfig {
  /**
   * @param string $tenantsFile Absolute path to /site/tenants.php
   * @param object $config      The ProcessWire $config object from config.php
   */
  public function __construct(
    private readonly string $tenantsFile,
    private readonly object $config,
  ) {
  }

  /**
   * Load tenant definitions, extend httpHosts with all tenant domains, and
   * write the matching tenant's DB credentials into $config.
   */
  public function apply(): void {
    if (!is_file($this->tenantsFile)) {
      return;
    }

    $data    = require $this->tenantsFile;
    $tenants = $data['tenants'] ?? [];

    $host = $this->resolveHost();

    $this->registerHttpHosts($tenants);
    $this->applyTenantDatabase($tenants, $host);
  }

  /**
   * Sanitise the incoming hostname: strip port, lowercase, remove non-hostname chars.
   */
  private function resolveHost(): string {
    $raw  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = strtolower(explode(':', $raw)[0]);

    return preg_replace('/[^a-z0-9.\-]/', '', $host) ?: 'localhost';
  }

  /**
   * Extend $config->httpHosts with every domain declared across all tenants.
   *
   * ProcessWire validates the Host header before any module initialises, so
   * all tenant domains must be present here or PW will return a 403.
   *
   * @param array<int, array<string, mixed>> $tenants
   */
  private function registerHttpHosts(array $tenants): void {
    $domains = [];

    foreach ($tenants as $tenant) {
      foreach ($tenant['domains'] ?? [] as $domain) {
        $domains[] = strtolower((string) $domain);
      }
    }

    if (empty($domains)) {
      return;
    }

    $this->config->httpHosts = array_values(array_unique(
      array_merge((array) ($this->config->httpHosts ?? []), $domains)
    ));
  }

  /**
   * Find the first tenant whose domain list contains $host and write its
   * database credentials into $config.
   *
   * If no tenant matches, $config is left unchanged and ProcessWire will
   * use whatever static db credentials are defined later in config.php.
   *
   * @param array<int, array<string, mixed>> $tenants
   * @param string                           $host    Sanitised current hostname
   */
  private function applyTenantDatabase(array $tenants, string $host): void {
    foreach ($tenants as $tenant) {
      $domains = array_map('strtolower', $tenant['domains'] ?? []);

      if (!in_array($host, $domains, true)) {
        continue;
      }

      $this->config->dbName = $tenant['dbName'];
      $this->config->dbUser = $tenant['dbUser'];
      $this->config->dbPass = $tenant['dbPass'];
      $this->config->dbHost = $tenant['dbHost'] ?? 'localhost';
      $this->config->dbPort = $tenant['dbPort'] ?? 3306;
      break;
    }
  }
}
