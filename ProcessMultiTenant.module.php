<?php

namespace ProcessWire;

/**
 * ProcessMultiTenant
 *
 * Admin UI for managing the multi-tenant configuration stored in /site/tenants.php.
 * Provides a table listing all tenants with links to create, edit, and delete entries.
 *
 * URL structure (relative to the module's admin page):
 *   /                       → tenant list
 *   edit/                   → create new tenant
 *   edit/?tenant_id=<id>    → edit existing tenant
 *   delete/?tenant_id=<id>  → delete tenant (GET + redirect)
 *
 * @author Ivan Milincic
 */
class ProcessMultiTenant extends Process {

  // -------------------------------------------------------------------------
  // Module metadata
  // -------------------------------------------------------------------------

  public static function getModuleInfo(): array {
    return [
      'title'      => 'Multi-Tenant Manager',
      'version'    => 100,
      'summary'    => 'Manage tenants defined in /site/tenants.php.',
      'icon'       => 'sitemap',
      'author'     => 'Ivan Milincic',
      'permission' => 'superuser',
      'page'       => [
        'name'  => 'multi-tenant',
        'title' => 'Multi-Tenant',
        'parent' => 'setup',
      ],
      'singular' => true,
      'autoload' => false,
    ];
  }

  // -------------------------------------------------------------------------
  // Lifecycle
  // -------------------------------------------------------------------------

  public function init(): void {
    parent::init();
  }

	// -------------------------------------------------------------------------
	// Execute methods (URL segments)
	// -------------------------------------------------------------------------

  /**
   * List all configured tenants in a data table.
   */
  public function ___execute(): string {
    $modules   = $this->wire()->modules;
    $sanitizer = $this->wire()->sanitizer;
    $pageUrl   = $this->wire()->page->url;
    $tenants   = $this->readTenants();

    $this->headline($this->_('Multi-Tenant Manager'));
    $this->browserTitle($this->_('Multi-Tenant'));

    /** @var MarkupAdminDataTable $table */
    $table = $modules->get('MarkupAdminDataTable');
    $table->setEncodeEntities(false);
    $table->headerRow([
      $this->_('ID'),
      $this->_('Title'),
      $this->_('Domains'),
      $this->_('Database'),
      $this->_('Storage Path'),
      $this->_('Actions'),
    ]);

    foreach ($tenants as $tenant) {
      $id        = $sanitizer->entities((string) ($tenant['id'] ?? ''));
      $title     = $sanitizer->entities((string) ($tenant['title'] ?? $tenant['id'] ?? ''));
      $domains   = $sanitizer->entities(implode(', ', (array) ($tenant['domains'] ?? [])));
      $dbName    = $sanitizer->entities((string) ($tenant['dbName'] ?? ''));
      $storage   = $sanitizer->entities((string) ($tenant['storagePath'] ?? ''));
      $editUrl   = $pageUrl . 'edit/?tenant_id=' . $id;

      // Delete uses a POST form + CSRF token to prevent CSRF attacks.
      // A GET link would allow any page to trigger deletion by tricking an
      // authenticated admin into following a crafted URL.
      $csrf         = $this->wire()->session->CSRF;
      $confirmMsg   = addslashes(sprintf($this->_('Delete tenant "%s"? This cannot be undone.'), $id));
      $deleteAction = $pageUrl . 'delete/';

      $deleteForm = '<form method="post" action="' . $deleteAction . '" style="display:inline"'
        . ' onsubmit="return confirm(\'' . $confirmMsg . '\')">'
        . '<input type="hidden" name="tenant_id" value="' . $id . '">'
        . '<input type="hidden" name="' . $sanitizer->entities($csrf->getTokenName()) . '"'
        . ' value="' . $sanitizer->entities($csrf->getTokenValue()) . '">'
        . '<button type="submit"'
        . ' style="background:none;border:none;color:#c00;cursor:pointer;padding:0;font:inherit">'
        . '<i class="fa fa-trash"></i> ' . $this->_('Delete')
        . '</button></form>';

      $actions = '<a href="' . $editUrl . '"><i class="fa fa-pencil"></i> ' . $this->_('Edit') . '</a>'
        . ' &nbsp; '
        . $deleteForm;

      // First column value linked to edit page.
      $table->row([
        $id      => $editUrl,
        $title,
        $domains,
        $dbName,
        $storage,
        $actions,
      ]);
    }

    /** @var InputfieldButton $btn */
    $btn = $modules->get('InputfieldButton');
    $btn->value = $this->_('Add Tenant');
    $btn->icon  = 'plus-circle';
    $btn->href  = $pageUrl . 'edit/';

    $out = '<p>' . $btn->render() . '</p>';

    if (empty($tenants)) {
      $out .= '<p class="description">'
        . $this->_('No tenants configured yet. Click "Add Tenant" to get started.')
        . '</p>';
    } else {
      $out .= $table->render();
    }

    return $out;
  }

  /**
   * Create or edit a tenant.
   *
   * GET  (no tenant_id)         → blank form for new tenant
   * GET  (?tenant_id=xxx)       → pre-filled form for existing tenant
   * POST (?tenant_id=xxx or /)  → process form submission
   */
  public function ___executeEdit(): string {
    $input     = $this->wire()->input;
    $modules   = $this->wire()->modules;
    $sanitizer = $this->wire()->sanitizer;
    $pageUrl   = $this->wire()->page->url;

    $tenantId = $sanitizer->name((string) ($input->get('tenant_id') ?? ''));
    $tenants  = $this->readTenants();
    $existing = $this->findTenant($tenants, $tenantId);
    $isNew    = ($tenantId === '' || $existing === null);

    if ($isNew) {
      $this->headline($this->_('Add Tenant'));
      $this->browserTitle($this->_('Add Tenant'));
      $tenant = [
        'id'          => '',
        'title'       => '',
        'domains'     => [],
        'dbHost'      => '',
        'dbPort'      => 3306,
        'dbName'      => '',
        'dbUser'      => '',
        'dbPass'      => '',
        'storagePath' => '',
      ];
    } else {
      $this->headline(sprintf($this->_('Edit Tenant: %s'), $sanitizer->entities($tenantId)));
      $this->browserTitle($this->_('Edit Tenant'));
      $tenant = $existing;
    }

    // Add breadcrumb back to tenant list.
    $this->wire()->breadcrumbs->add(new Breadcrumb($pageUrl, $this->_('Multi-Tenant')));

    $form = $this->buildTenantForm($tenant, $isNew);

    // Handle POST.
    if ($input->post('submit_tenant') !== null) {
      $form->processInput($input->post);

      if (!count($form->getErrors())) {
        $result = $this->processTenantForm($form, $tenants, $isNew, $tenant);
        if ($result === null) {
          return '';  // redirect was issued
        }
        return $result;
      }
    }

    /** @var InputfieldButton $back */
    $back = $modules->get('InputfieldButton');
    $back->value = $this->_('All Tenants');
    $back->icon  = 'angle-left';
    $back->href  = $pageUrl;
    $back->setSecondary();

    return '<p>' . $back->render() . '</p>' . $form->render();
  }

  /**
   * Delete a tenant by ID (POST request only, CSRF-protected).
   *
   * Accepts only POST submissions from the inline delete form rendered in
   * ___execute(). GET requests are silently redirected to the tenant list.
   */
  public function ___executeDelete(): string {
    $input     = $this->wire()->input;
    $sanitizer = $this->wire()->sanitizer;
    $pageUrl   = $this->wire()->page->url;

    // Reject non-POST requests — a GET to this URL is either a direct visit
    // or a CSRF attempt; neither should delete anything.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      $this->wire()->session->redirect($pageUrl);
      return '';
    }

    // Validate the CSRF token embedded in the delete form.
    $this->wire()->session->CSRF->validate();

    $tenantId = $sanitizer->name((string) ($input->post('tenant_id') ?? ''));

    if ($tenantId === '') {
      $this->error($this->_('No tenant ID specified.'));
      $this->wire()->session->redirect($pageUrl);
      return '';
    }

    $tenants = $this->readTenants();
    $found   = false;

    $tenants = array_values(array_filter(
      $tenants,
      function (array $t) use ($tenantId, &$found): bool {
        if ((string) ($t['id'] ?? '') === $tenantId) {
          $found = true;
          return false;
        }
        return true;
      }
    ));

    if ($found) {
      $this->saveTenants($tenants);
      $this->message(sprintf($this->_('Tenant "%s" has been deleted.'), $sanitizer->entities($tenantId)));
    } else {
      $this->error(sprintf($this->_('Tenant "%s" was not found.'), $sanitizer->entities($tenantId)));
    }

    $this->wire()->session->redirect($pageUrl);
    return '';
  }

	// -------------------------------------------------------------------------
	// Form builder
	// -------------------------------------------------------------------------

  /**
   * Build and return the tenant InputfieldForm.
   *
   * @param  array<string, mixed> $tenant  Tenant data to pre-populate.
   * @param  bool                 $isNew   True when creating a new tenant.
   * @return InputfieldForm
   */
  protected function buildTenantForm(array $tenant, bool $isNew): InputfieldForm {
    $modules = $this->wire()->modules;

    /** @var InputfieldForm $form */
    $form = $modules->get('InputfieldForm');
    $form->method = 'post';
    $form->action = '';  // empty = current URL (preserves ?tenant_id= query string)
    $form->attr('id', 'ProcessMultiTenantForm');

		// --- Tenant ID ---
    /** @var InputfieldText $f */
    $f = $modules->get('InputfieldText');
    $f->attr('name', 'tenant_id_field');
    $f->attr('value', (string) ($tenant['id'] ?? ''));
    $f->label       = $this->_('Tenant ID');
    $f->description = $this->_('Unique machine identifier used as the storage folder name. Lowercase letters, numbers, and hyphens only.');
    $f->required    = true;
    if (!$isNew) {
      // ID maps to a storage folder — prevent accidental renaming.
      $f->attr('readonly', 'readonly');
      $f->notes = $this->_('The ID cannot be changed after the tenant has been created.');
    }
    $form->add($f);

		// --- Title ---
    /** @var InputfieldText $f */
    $f = $modules->get('InputfieldText');
    $f->attr('name', 'tenant_title');
    $f->attr('value', (string) ($tenant['title'] ?? ''));
    $f->label       = $this->_('Title');
    $f->description = $this->_('Human-readable display name for this tenant.');
    $form->add($f);

		// --- Domains ---
    /** @var InputfieldText $f */
    $f = $modules->get('InputfieldText');
    $f->attr('name', 'tenant_domains');
    $f->attr('value', implode(', ', (array) ($tenant['domains'] ?? [])));
    $f->label       = $this->_('Domains');
    $f->description = $this->_('Comma-separated list of hostnames that resolve to this tenant (e.g. example.com, www.example.com).');
    $f->required    = true;
    $form->add($f);

		// --- Database fieldset ---
    /** @var InputfieldFieldset $fs */
    $fs = $modules->get('InputfieldFieldset');
    $fs->label = $this->_('Database Connection');
    $fs->icon  = 'database';

    $dbFields = [
      'dbHost' => [
        $this->_('Host'),
        $this->_('Database server hostname or IP address.'),
        'text',
        (string) ($tenant['dbHost'] ?? '')
      ],
      'dbPort' => [
        $this->_('Port'),
        $this->_('Database server port (default: 3306).'),
        'text',
        (string) ($tenant['dbPort'] ?? 3306)
      ],
      'dbName' => [
        $this->_('Name'),
        $this->_('Name of the database.'),
        'text',
        (string) ($tenant['dbName'] ?? '')
      ],
      'dbUser' => [
        $this->_('Username'),
        $this->_('Database username.'),
        'text',
        (string) ($tenant['dbUser'] ?? '')
      ],
      'dbPass' => [
        $this->_('Password'),
        $this->_('Database password. Leave blank to keep the existing value.'),
        'password',
        ''
      ],
    ];

    foreach ($dbFields as $fieldName => [$label, $desc, $type, $value]) {
      /** @var InputfieldText $f */
      $f = $modules->get('InputfieldText');
      $f->attr('name', $fieldName);
      $f->attr('value', $value);
      $f->required    = ($fieldName !== 'dbPass' || $isNew);
      $f->label       = $label;
      $f->description = $desc;
      $f->columnWidth = 50;
      if ($type === 'password') {
        $f->attr('type', 'password');
        $f->attr('autocomplete', 'new-password');
      }
      $fs->add($f);
    }

    $form->add($fs);

		// --- Storage path ---
    /** @var InputfieldText $f */
    $f = $modules->get('InputfieldText');
    $f->attr('name', 'storagePath');
    $f->attr('value', (string) ($tenant['storagePath'] ?? ''));
    $f->label       = $this->_('Storage Path');
    $f->description = $this->_('Path relative to the ProcessWire root used for tenant assets, cache, logs, and sessions. Example: /storage/example/');
    $f->notes       = $this->_('Leave blank to auto-generate from the Tenant ID.');
    $form->add($f);

		// Hidden original ID (needed to locate the correct entry during an edit save).
    /** @var InputfieldHidden $f */
    $f = $modules->get('InputfieldHidden');
    $f->attr('name', 'original_id');
    $f->attr('value', $isNew ? '' : (string) ($tenant['id'] ?? ''));
    $form->add($f);

		// --- Submit ---
    /** @var InputfieldSubmit $f */
    $f = $modules->get('InputfieldSubmit');
    $f->attr('name', 'submit_tenant');
    $f->value = $isNew ? $this->_('Create Tenant') : $this->_('Save Tenant');
    $f->icon  = 'save';
    $form->add($f);

    return $form;
  }

	// -------------------------------------------------------------------------
	// Form processing
	// -------------------------------------------------------------------------

  /**
   * Validate and persist the submitted tenant form.
   *
   * Issues a session redirect on success and returns null.
   * Returns rendered form HTML (with error notices) when validation fails.
   *
   * @param  InputfieldForm       $form
   * @param  array<int, array>    $tenants   Full current tenant list.
   * @param  bool                 $isNew     True when creating a new tenant.
   * @param  array<string, mixed> $existing  Original tenant data (used for password fallback on edit).
   * @return string|null
   */
  protected function processTenantForm(InputfieldForm $form, array $tenants, bool $isNew, array $existing = []): ?string {
    $sanitizer = $this->wire()->sanitizer;
    $input     = $this->wire()->input;
    $pageUrl   = $this->wire()->page->url;

    $newId       = $sanitizer->name((string) $input->post('tenant_id_field'));
    $originalId  = $sanitizer->name((string) $input->post('original_id'));
    $title       = $sanitizer->text((string) $input->post('tenant_title'));
    $domainsRaw  = $sanitizer->text((string) $input->post('tenant_domains'));
    $dbHost      = $sanitizer->text((string) $input->post('dbHost'));
    $dbPort      = abs((int) $input->post('dbPort'));
    $dbName      = $sanitizer->text((string) $input->post('dbName'));
    $dbUser      = $sanitizer->text((string) $input->post('dbUser'));
    $dbPassRaw   = (string) $input->post('dbPass');

    // Normalize storage path and reject unsafe values.
    $storagePathRaw = trim((string) $input->post('storagePath'));
    $storagePathRaw = str_replace('\\', '/', $storagePathRaw);
    $storagePathRaw = preg_replace('#/+#', '/', $storagePathRaw);
    $storagePath = preg_replace('/[^a-zA-Z0-9\/\-_\.]/', '', $storagePathRaw);

    if ($storagePath !== '' && preg_match('#(^|/)\.\.($|/)#', $storagePath)) {
      $this->error($this->_('Storage Path is invalid. It must not contain parent directory references.'));
      return $form->render();
    }

    if ($storagePath !== '') {
      $storagePath = '/' . trim($storagePath, '/') . '/';
      if ($storagePath === '//') {
        $storagePath = '';
      }
    }

    // Validate ID.
    if ($newId === '') {
      $this->error($this->_('Tenant ID is required and may only contain lowercase letters, numbers, and hyphens.'));
      return $form->render();
    }

    // Prevent duplicate IDs for new tenants.
    if ($isNew && $this->findTenant($tenants, $newId) !== null) {
      $this->error(sprintf($this->_('A tenant with ID "%s" already exists.'), $sanitizer->entities($newId)));
      return $form->render();
    }

    // Parse and normalise domain list.
    $domains = array_values(array_filter(
      array_map(
        fn(string $d): string => strtolower(trim($d)),
        explode(',', $domainsRaw)
      ),
      fn(string $d): bool => $d !== ''
    ));

    if (empty($domains)) {
      $this->error($this->_('At least one domain is required.'));
      return $form->render();
    }

    // Validate each domain as a legal hostname (labels separated by dots,
    // each label: 1–63 chars of [a-z0-9-], not starting/ending with a hyphen).
    foreach ($domains as $domain) {
      if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?$/', $domain)) {
        $this->error(sprintf($this->_('"%s" is not a valid hostname.'), $sanitizer->entities($domain)));
        return $form->render();
      }
    }

    // Ensure no domain is already claimed by another tenant. Two tenants
    // sharing a domain silently hides one of them (first-match wins in
    // resolveTenant()), so we reject the duplicate here.
    $currentId = $isNew ? '' : ($originalId !== '' ? $originalId : $newId);
    foreach ($domains as $domain) {
      foreach ($tenants as $existingTenant) {
        // Skip the tenant being edited — it may keep its own domains.
        if (!$isNew && (string) ($existingTenant['id'] ?? '') === $currentId) continue;
        $existingDomains = array_map('strtolower', (array) ($existingTenant['domains'] ?? []));
        if (in_array($domain, $existingDomains, true)) {
          $this->error(sprintf(
            $this->_('Domain "%s" is already registered to tenant "%s".'),
            $sanitizer->entities($domain),
            $sanitizer->entities((string) ($existingTenant['id'] ?? ''))
          ));
          return $form->render();
        }
      }
    }

    // Retain existing password when the field was left blank.
    $dbPass = ($dbPassRaw !== '') ? $dbPassRaw : (string) ($existing['dbPass'] ?? '');

    // Auto-generate storage path from ID when not provided.
    if ($storagePath === '') {
      $id          = $isNew ? $newId : ($originalId !== '' ? $originalId : $newId);
      $storagePath = '/storage/' . $id . '/';
    }

    $tenantData = [
      'id'          => $isNew ? $newId : ($originalId !== '' ? $originalId : $newId),
      'title'       => $title,
      'domains'     => $domains,
      'dbHost'      => $dbHost,
      'dbPort'      => $dbPort > 0 ? $dbPort : 3306,
      'dbName'      => $dbName,
      'dbUser'      => $dbUser,
      'dbPass'      => $dbPass,
      'storagePath' => $storagePath,
    ];

    if ($isNew) {
      $tenants[] = $tenantData;
    } else {
      // Replace in-place to preserve ordering.
      foreach ($tenants as $i => $t) {
        if ((string) ($t['id'] ?? '') === $originalId) {
          $tenants[$i] = $tenantData;
          break;
        }
      }
    }

    $this->saveTenants(array_values($tenants));

    $this->message(
      $isNew
        ? sprintf($this->_('Tenant "%s" has been created.'), $sanitizer->entities($tenantData['id']))
        : sprintf($this->_('Tenant "%s" has been saved.'), $sanitizer->entities($tenantData['id']))
    );

    $this->wire()->session->redirect($pageUrl);
    return null;
  }

	// -------------------------------------------------------------------------
	// tenants.php I/O
	// -------------------------------------------------------------------------

  /**
   * Load all tenants from /site/tenants.php.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function readTenants(): array {
    $configFile = $this->wire()->config->paths->site . 'tenants.php';

    if (!is_file($configFile)) {
      return [];
    }

    $data = require $configFile;
    return array_values((array) ($data['tenants'] ?? []));
  }

  /**
   * Write all tenants back to /site/tenants.php as a PHP return array.
   *
   * Values are serialised with var_export() which handles all PHP string
   * edge cases (backslashes, single quotes, multi-byte sequences) correctly.
   * file_put_contents() is called with LOCK_EX to prevent file corruption
   * when two admin sessions save simultaneously.
   *
   * @param  array<int, array<string, mixed>> $tenants
   * @throws WireException If the file cannot be written.
   */
  protected function saveTenants(array $tenants): void {
    $configFile = $this->wire()->config->paths->site . 'tenants.php';

    $lines = ["<?php", "return [", "  'tenants' => ["];

    foreach ($tenants as $t) {
      // Build domains list as var_export'd scalars: 'a', 'b', ...
      $domainItems = array_map(
        fn(string $d): string => var_export($d, true),
        (array) ($t['domains'] ?? [])
      );
      $domainsStr = implode(', ', $domainItems);

      $lines[] = "    [";
      $lines[] = "      'id'          => " . var_export((string) ($t['id'] ?? ''), true) . ",";
      $lines[] = "      'title'       => " . var_export((string) ($t['title'] ?? ''), true) . ",";
      $lines[] = "      'domains'     => [" . $domainsStr . "],";
      $lines[] = "      'dbHost'      => " . var_export((string) ($t['dbHost'] ?? ''), true) . ",";
      $lines[] = "      'dbPort'      => " . (int) ($t['dbPort'] ?? 3306) . ",";
      $lines[] = "      'dbName'      => " . var_export((string) ($t['dbName'] ?? ''), true) . ",";
      $lines[] = "      'dbUser'      => " . var_export((string) ($t['dbUser'] ?? ''), true) . ",";
      $lines[] = "      'dbPass'      => " . var_export((string) ($t['dbPass'] ?? ''), true) . ",";
      $lines[] = "      'storagePath' => " . var_export((string) ($t['storagePath'] ?? ''), true) . ",";
      $lines[] = "    ],";
    }

    $lines[] = "  ],";
    $lines[] = "];";
    $lines[] = "";

    // LOCK_EX ensures the write is atomic under concurrent admin saves,
    // preventing partial reads that would cause a PHP parse error on every
    // subsequent request.
    $written = file_put_contents($configFile, implode("\n", $lines), LOCK_EX);

    if ($written === false) {
      throw new WireException('ProcessMultiTenant: could not write to: ' . $configFile);
    }
  }

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

  /**
   * Find a single tenant by ID in the given list.
   *
   * @param  array<int, array<string, mixed>> $tenants
   * @param  string                           $id
   * @return array<string, mixed>|null  Matching tenant data, or null if not found.
   */
  protected function findTenant(array $tenants, string $id): ?array {
    foreach ($tenants as $t) {
      if ((string) ($t['id'] ?? '') === $id) {
        return $t;
      }
    }
    return null;
  }
}
