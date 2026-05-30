# MultiTenant

Minimal multi-tenant bootstrap layer for ProcessWire. Resolves the active tenant from the incoming hostname, isolates per-tenant filesystem paths (assets, cache, logs, sessions, files, backups), and exposes the tenant data as `wire('tenant')`.

This module contains two classes:
- **`MultiTenant`** — runtime bootstrap, runs on every request.
- **`ProcessMultiTenant`** — optional admin UI for managing `/site/tenants.php`.

## API variable

`wire('tenant')` — a `WireData` object for the matched tenant, or `null` if no tenant matched.

```php
$tenant = wire('tenant');

if ($tenant) {
    echo $tenant->get('id');          // e.g. "example"
    echo $tenant->get('title');       // e.g. "Example Tenant"
    echo $tenant->get('storagePath'); // e.g. "/storage/example/"
}
```

## Tenant configuration file

`/site/tenants.php` must return an array with a `tenants` key:

```php
<?php
return [
    'tenants' => [
        [
            'id'          => 'acme',
            'title'       => 'Acme Corp',
            'domains'     => ['acme.example.com', 'www.acme.example.com'],
            'dbHost'      => 'db',
            'dbPort'      => 3306,
            'dbName'      => 'acme_db',
            'dbUser'      => 'acme_user',
            'dbPass'      => 'secret',
            'storagePath' => '/storage/acme/',
        ],
    ],
];
```

### Tenant fields

| Field         | Description                                                          |
|---------------|----------------------------------------------------------------------|
| `id`          | Unique identifier. Lowercase letters, numbers, hyphens only. **Cannot be changed after creation.** |
| `title`       | Human-readable display name.                                         |
| `domains`     | Array of hostnames that resolve to this tenant (without port).       |
| `dbHost/Port/Name/User/Pass` | Per-tenant database credentials.                      |
| `storagePath` | Relative path for tenant assets. Format: `/storage/{id}/`.           |

## Required `site/config.php` integration

**Database credentials must be applied in `site/config.php`** before ProcessWire bootstraps — the PDO connection is created before any module `init()` runs.

Add these two lines to `site/config.php` before any `$config->db*` assignments:

```php
require_once __DIR__ . '/modules/MultiTenant/MultiTenantSiteConfig.php';
(new MultiTenantSiteConfig(__DIR__ . '/tenants.php', $config))->apply();
```

`MultiTenantSiteConfig::apply()` handles:
- Applying matched tenant DB credentials to `$config`.
- Registering all tenant domains in `$config->httpHosts` (prevents ProcessWire 403 on unrecognised hostnames).

## What the module overrides per tenant

```
$config->paths->assets    → /storage/{id}/assets/
$config->paths->cache     → /storage/{id}/cache/
$config->paths->logs      → /storage/{id}/logs/
$config->paths->sessions  → /storage/{id}/sessions/
$config->paths->files     → /storage/{id}/assets/files/
$config->paths->backups   → /storage/{id}/assets/backups/
$config->urls->assets     → /storage/{id}/assets/
$config->urls->files      → /storage/{id}/assets/files/
```

Directories are created automatically if they do not exist.

## Graceful degradation

- If `tenants.php` is missing, a log error is written and `wire('tenant')` is `null`. The platform admin remains accessible.
- If no tenant matches the hostname, paths are left at ProcessWire defaults and `wire('tenant')` is `null`.
- In CLI context, `$_SERVER['HTTP_HOST']` is absent — the module falls back to `localhost` and resolves no tenant.

## Admin UI (ProcessMultiTenant)

Optional. Provides **Setup → Multi Tenant** in the admin with:
- List, add, edit, delete tenants in `tenants.php` without editing the file directly.
- Domain conflict detection (prevents two tenants claiming the same hostname).
- Auto-generates `storagePath` from `id`.
- CSRF-protected deletion with confirmation.

## Installation checklist

1. Copy `MultiTenant/` folder into `site/modules/`.
2. Add `MultiTenantSiteConfig` bootstrap lines to `site/config.php`.
3. Create `site/tenants.php` with at least one tenant entry.
4. Admin → Modules → Refresh → Install **MultiTenant**.
5. Optionally install **ProcessMultiTenant** for the admin UI.
6. Create storage directories at the root level (e.g. `storage/acme/`) and ensure they are web-writable.

## Notes

- `storagePath` is always treated as relative to the ProcessWire root, regardless of leading slashes.
- Tenant `id` values should not be changed after creation — they form the storage path and renaming them requires moving filesystem directories and updating the config.
- Two tenants cannot share the same domain. The first matching tenant in the array wins.
- Database isolation is per-tenant: each tenant has its own DB, so `$pages` only sees that tenant's content.
