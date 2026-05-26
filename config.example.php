<?php

/**
 * config.example.php — MultiTenant DB-switching integration for /site/config.php
 *
 * Copy the two lines below into your /site/config.php, BEFORE any static
 * $config->db* assignments. ProcessWire establishes the PDO connection at
 * bootstrap time, so the tenant credentials must be written in config.php —
 * they cannot be applied inside a module's init().
 *
 * MultiTenantConfig is a plain PHP class (no namespace, no ProcessWire
 * dependencies). It handles:
 *   - registering all tenant domains in $config->httpHosts
 *   - applying the matching tenant's DB credentials to $config
 *
 * The MultiTenant module (loaded later) handles path isolation and the global
 * wire('tenant') API variable; it deliberately does not touch DB credentials.
 */

// ---------------------------------------------------------------------------
// Add the following two lines to /site/config.php
// ---------------------------------------------------------------------------

require_once __DIR__ . '/modules/MultiTenant/MultiTenantSiteConfig.php';

(new MultiTenantSiteConfig(__DIR__ . '/tenants.php', $config))->apply();
