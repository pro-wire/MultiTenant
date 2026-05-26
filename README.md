# ProcessWire MultiTenant

This folder contains two related modules for multi-tenant ProcessWire setups:

- `MultiTenant` — the bootstrap module that resolves the current tenant from the request hostname, isolates tenant-specific filesystem paths, and exposes the active tenant as `wire('tenant')`.
- `ProcessMultiTenant` — an optional admin UI module that lets you manage `/site/tenants.php` from the ProcessWire admin.

---

## Chapter 1 — MultiTenant Bootstrap

The `MultiTenant` module is the runtime layer for tenant isolation.

### What it does

- loads `/site/tenants.php`
- resolves the active tenant using the incoming hostname
- sets tenant-specific paths for `assets`, `cache`, `logs`, `sessions`, `files`, and `backups`
- exposes the active tenant data as `wire('tenant')`

### What it does not do

- it does not set ProcessWire DB credentials inside `init()` because the PDO connection is created before module initialization
- it does not provide tenant editing UI on its own
- it does not automatically create tenant storage folders outside the directories it manages

### Why `site/config.php` must handle DB switching

ProcessWire establishes the database connection during bootstrap before module `init()` runs. Therefore the tenant database credentials must be applied in `/site/config.php` using the bundled `MultiTenantSiteConfig` class.

The logic lives in `MultiTenantSiteConfig.php` (inside the module folder). It is a plain PHP class — no namespace, no ProcessWire dependencies — so it can be safely loaded with `require_once` before ProcessWire bootstraps.

Add these two lines to `/site/config.php`, before any static `$config->db*` assignments:

```php
require_once __DIR__ . '/modules/MultiTenant/MultiTenantSiteConfig.php';
(new MultiTenantSiteConfig(__DIR__ . '/tenants.php', $config))->apply();
```

`MultiTenantSiteConfig::apply()` handles:

- registering every tenant domain in `$config->httpHosts` (prevents ProcessWire's host-validation 403)
- applying the matching tenant's DB credentials to `$config`

### Install the bootstrap module

1. Copy the `MultiTenant` folder into `/site/modules/`.
2. Install the `MultiTenant` module in ProcessWire admin.
3. Ensure `/site/tenants.php` exists and the module can read it.

---

## Tenant configuration format

`/site/tenants.php` must return an array with a top-level `tenants` key:

```php
<?php
return [
  'tenants' => [
    [
      'id'          => 'example',
      'title'       => 'Example Tenant',
      'domains'     => ['example.ddev.site', 'www.example.ddev.site'],
      'dbHost'      => 'db',
      'dbPort'      => 3306,
      'dbName'      => 'db',
      'dbUser'      => 'db',
      'dbPass'      => 'db',
      'storagePath' => '/storage/example/',
    ],
  ],
];
```

### Tenant fields

- `id` — unique tenant identifier and storage folder name. It should contain only lowercase letters, numbers, and hyphens, and it cannot be changed after creation.
- `title` — human-readable label for the tenant.
- `domains` — hostname list that resolves to this tenant. Enter values as comma-separated hostnames.
- `dbHost`, `dbPort`, `dbName`, `dbUser`, `dbPass` — tenant-specific database connection details.
- `storagePath` — relative path used for tenant assets, cache, logs, sessions, files, and backups. Recommended format: `/storage/<id>/`.

### How tenant resolution works

1. `site/config.php` loads tenant definitions and applies DB credentials for the current hostname.
2. ProcessWire initializes the database connection.
3. `MultiTenant` runs in `init()` and resolves the active tenant again.
4. It overrides filesystem paths and exposes `wire('tenant')`.

If no tenant matches the hostname, the module leaves ProcessWire paths unchanged and `wire('tenant')` is `null`.

---

## Chapter 2 — Multi-Tenant Manager UI

The `ProcessMultiTenant` module is optional. It provides an admin UI for editing `/site/tenants.php` without touching code.

### What it provides

- tenant list table with `Edit` and `Delete`
- tenant creation form
- tenant edit form
- safe save back to `/site/tenants.php`

### How to use it

1. Install the `ProcessMultiTenant` module in ProcessWire admin.
2. Visit Setup → Multi-Tenant.
3. Click `Add Tenant` to create a new tenant.
4. Click `Edit` to modify an existing tenant.
5. Click `Delete` to remove the tenant definition.

### Form behavior

- `Tenant ID` is required and becomes read-only on edits.
- `Title` is a display label only.
- `Domains` are entered as comma-separated hostnames.
- `Database Connection` is grouped into `dbHost`, `dbPort`, `dbName`, `dbUser`, and `dbPass`.
- `Storage Path` can be left blank to auto-generate from the tenant ID.

### Important UI details

- Leaving `dbPass` blank on edit preserves the tenant’s existing password.
- Deleting a tenant only removes the entry from `/site/tenants.php`.
- Tenant IDs should remain stable because they are used for storage isolation.

---

## Recommended directory structure

```text
/platform
  /wire
  /site
    /assets
    /modules
    /templates
    /tenants.php
  /storage
    /client-a
    /client-b
```

---

## Troubleshooting

- If the wrong tenant loads, verify the hostname in `domains` matches `HTTP_HOST` exactly.
- If `MultiTenant` throws a missing file error, ensure `/site/tenants.php` exists.
- If the admin manager cannot save, ensure `/site/tenants.php` is writable by PHP.
- If tenant storage paths are not isolated, confirm the `MultiTenant` module is installed and autoloaded.
