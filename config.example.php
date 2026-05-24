<?php

/**
 * config.example.php — MultiTenant DB-switching integration for /site/config.php
 *
 * Copy the block below into your /site/config.php, BEFORE any static $config->db*
 * assignments. ProcessWire establishes the PDO connection at bootstrap time using
 * whatever values are in $config at that moment, so the tenant credentials must be
 * written here — they cannot be applied inside a module's init().
 *
 * The MultiTenant module (loaded later) handles path isolation and the global
 * wire('tenant') API variable; it deliberately does not touch DB credentials.
 */

// ---------------------------------------------------------------------------
// Add the following block to /site/config.php
// ---------------------------------------------------------------------------

$multitenantFile = __DIR__ . '/tenants.php';

if (is_file($multitenantFile)) {

  $multitenantConfig = require $multitenantFile;

  // Sanitise the hostname: strip port, lowercase, remove invalid characters.
  $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
  $host = preg_replace('/[^a-z0-9.\-]/', '', $host) ?: 'localhost';

  foreach ($multitenantConfig['tenants'] as $tenant) {
    $domains = array_map('strtolower', $tenant['domains'] ?? []);

    if (in_array($host, $domains, true)) {
      $config->dbName = $tenant['dbName'];
      $config->dbUser = $tenant['dbUser'];
      $config->dbPass = $tenant['dbPass'];
      $config->dbHost = $tenant['dbHost'] ?? 'localhost';
      $config->dbPort = $tenant['dbPort'] ?? 3306;
      break;
    }
  }

  // Clean up — these variables should not leak into the rest of config.php.
  unset($multitenantConfig, $host, $tenant, $domains);
}
