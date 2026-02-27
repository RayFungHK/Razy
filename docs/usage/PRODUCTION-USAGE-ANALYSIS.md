# Razy v0.5 Production Usage Analysis

Real-world production usage patterns from `razy-sample` site and shared `rayfungpi` modules. Provides concrete examples for module architecture, API patterns, and workflows.

**Source**: development-razy0.4 | **Updated**: February 8, 2026

---

### Architecture Overview

### 1.1 Production Site Structure

```
development-razy0.4/
├── sites/
│   └── razy-sample/              # Production site
│       ├── dist.php              # Distribution config
│       └── rayfungpi/            # Site-specific modules
│           ├── company/          # Company management
│           ├── task/             # Task workflow system
│           ├── individual/       # Individual member management
│           ├── webpage/          # CMS web pages
│           └── [25+ modules]     # Other business modules
└── shared/
    └── module/
        └── rayfungpi/            # Shared cross-site modules
            ├── razit/            # Core API framework
            ├── razit-user/       # User authentication
            ├── razit-group/      # Permission system
            ├── razit-multilang/  # i18n system
            ├── razit-logging/    # Logging system
            └── razit-department/ # Department management
```

### 1.2 Distribution Configuration

**File:** `sites/razy-sample/dist.php`

```php
return [
    'dist' => 'razy-sample',
    'repo' => [],
    'global_module' => true,  // Enable shared modules
    'enable_module' => [
        '*' => [],            // Enable all modules
    ],
    'exclude_module' => [],
];
```

**Key Points:**
- `global_module: true` enables loading shared modules from `shared/module/rayfungpi`
- All modules under `sites/razy-sample/rayfungpi` are site-specific
- Shared modules are reusable across multiple sites

---

## 2. Core Shared Modules

### 2.1 Razit (Core API Framework)

**Module:** `shared/module/rayfungpi/razit`

**API Name:** `razit`

**Purpose:** Provides core backend services for all razit-based modules.

#### Key Responsibilities

1. **Database Connection Management**
   ```php
   // In razit controller __onInit()
   $this->database = Database::GetInstance('main');
   $this->database->connect($host, $user, $pass, $db, $port);
   $this->database->setPrefix($configuration['prefix']);
   ```

2. **Menu System**
   ```php
   // Used by modules to register menu items
   $this->api('razit')->setupCategory('category_key', 'Category Label');
   $menu = $this->api('razit')->addMenu('category_key', 'module_code', 'Menu Title');
   $menu->setURL($this->getModuleURL());
   ```

3. **Header/Footer Rendering**
   ```php
   // Standard page structure in modules
   $this->api('razit')->header();
   // ... render page content ...
   $this->api('razit')->footer();
   ```

4. **Configuration Management**
   ```php
   // Get module configuration
   $config = $this->api('razit')->getConfig($moduleInfo);
   
   // Update module configuration
   $this->api('razit')->updateConfig($moduleCode, $data);
   ```

#### API Commands

- `getPrefix()` - Get database table prefix
- `getDB()` - Get database instance
- `addMenu($category, $code, $title)` - Register menu item
- `setActiveMenu($code)` - Set active menu
- `#updateConfig($code, $data)` - Update module config (private)
- `#setupCategory($key, $label)` - Setup menu category (private)
- `#getConfig($moduleInfo)` - Get module config (private)
- `#addBreadcrumb($item)` - Add breadcrumb (private)
- `#header()` - Render header (private)
- `#footer()` - Render footer (private)

---

### 2.2 Razit-User (Authentication)

**Module:** `shared/module/rayfungpi/razit-user`

**API Name:** `razit_user`

**Purpose:** User authentication and session management.

#### Key Features

1. **Session Management**
   ```php
   // Check if user is logged in
   $this->api('razit_user')->checkSession();
   
   // Get current user data
   $userdata = $this->api('razit_user')->getUser();
   ```

2. **User Restriction**
   ```php
   // Restrict access to logged-in users
   $this->api('razit_user')->restrict();
   
   // Bypass restriction temporarily
   $this->api('razit_user')->bypass();
   ```

3. **Configuration Storage**
   ```php
   // Save user-specific module config
   $this->api('razit_user')->saveConfig($moduleInfo, $data);
   
   // Get user-specific module config
   $config = $this->api('razit_user')->getConfig($moduleInfo);
   ```

#### API Commands

- `getUser()` - Get current logged-in user data
- `getUsers()` - Get list of users
- `#checkSession()` - Verify user session (private)
- `#restrict()` - Enforce login requirement (private)
- `#bypass()` - Temporarily bypass restrictions (private)
- `saveConfig($moduleInfo, $data)` - Save user config
- `getConfig($moduleInfo)` - Get user config

---

### 2.3 Razit-Group (Authorization)

**Module:** `shared/module/rayfungpi/razit-group`

**API Name:** `razit_group`

**Purpose:** Permission-based access control.

#### Key Features

1. **Permission Setup**
   ```php
   // In module __onReady()
   $permission = $this->api('razit_group')->set($moduleInfo, 'Module Title');
   $permission->setLabel('View Permission');
   $permission->fork('create')->setLabel('Create Permission');
   $permission->fork('edit')->setLabel('Edit Permission');
   $permission->fork('delete')->setLabel('Delete Permission');
   ```

2. **Authorization Checks**
   ```php
   // Check if user has permission
   if (!$this->api('razit_group')->auth($this->getModuleCode(), 'view')) {
       Error::Show404();
   }
   
   // Check nested permission
   if ($this->api('razit_group')->auth($moduleCode, 'view/create')) {
       // User has create permission
   }
   ```

3. **Get Permission Details**
   ```php
   // Get all permissions for a module
   $auth = $this->api('razit_group')->get($this->getModuleCode());
   
   // Returns: ['view' => true, 'create' => true, 'edit' => false, ...]
   ```

#### API Commands

- `#set($moduleInfo, $label)` - Setup permissions (private)
- `#get($moduleCode)` - Get permission status (private)
- `auth($moduleCode, $path)` - Check authorization
- `getAuth($moduleCode)` - Get authorization details

#### Permission Hierarchy Example

```
module_code
├── view (base permission)
│   ├── create
│   ├── edit
│   └── delete
```

---

### 2.4 Razit-Multilang (i18n)

**Module:** `shared/module/rayfungpi/razit-multilang`

**API Name:** `i18n`

**Purpose:** Internationalization and localization.

#### Key Features

1. **Load Language Pack**
   ```php
   // In module __onInit()
   $agent->await('rayfungpi/razit/multilang', function () {
       $this->api('i18n')?->addLangPack($this->getModuleInfo());
   });
   ```

2. **Get Translated Text**
   ```php
   // Get language pack for module
   $api = $this->api('i18n')?->getPack($this->getModuleInfo());
   
   // Get single translation
   $title = $api->getText('title');
   
   // Get multiple translations
   $statusList = $api->getText($this->status);
   // If $this->status = ['active' => 'status_active', 'inactive' => 'status_inactive']
   // Returns: ['active' => 'Active', 'inactive' => 'Inactive']
   ```

3. **Template Integration**
   ```tpl
   <!-- In .tpl templates -->
   {@ml key_name}
   ```

---

## 3. Production Module Patterns

### 3.1 Standard Module Structure

Every production module follows this pattern:

```
module_name/
├── module.php              # Module metadata
└── default/                # Default version
    ├── package.php         # Package configuration
    ├── controller/         # Controllers
    │   ├── module_name.php           # Main controller (lifecycle)
    │   ├── module_name.main.php      # Main page handler
    │   ├── module_name.install.php   # Installation handler
    │   └── api/                      # API endpoints
    ├── view/               # Templates
    │   ├── index.tpl
    │   └── include/
    └── lang/               # Language files
        ├── en.php
        └── zh-TW.php
```

---

### 3.2 Package Configuration Pattern

**Example:** `sites/razy-sample/rayfungpi/company/default/package.php`

```php
<?php
return [
    'api_name' => 'company',        // API identifier for this module
    'version' => '1.0.0',
    'require' => [                  // Module dependencies
        'rayfungpi/razit' => '*',
        'rayfungpi/razit/user' => '*',
        'rayfungpi/razit/group' => '*',
        'rayfungpi/task' => '*',
    ],
];
```

**Key Points:**
- `api_name` is used when calling `$this->api('company')`
- `require` ensures dependencies load first
- Version constraints: `'*'` = any version, `'>=1.0.0'` = minimum version

---

### 3.3 Controller Lifecycle Pattern

**Example:** `sites/razy-sample/rayfungpi/company/default/controller/company.php`

```php
<?php
use Razy\Agent;
use Razy\Controller;

return new class () extends Controller {
    // 1. INITIALIZATION - Runs first
    public function __onInit(Agent $agent): bool
    {
        // Wait for dependencies
        $agent->await('rayfungpi/razit/multilang', function () {
            $this->api('i18n')?->addLangPack($this->getModuleInfo());
        });
        
        // Wait for multiple dependencies
        $agent->await('rayfungpi/razit/multilang,rayfungpi/task', function () use ($agent) {
            // Setup task integration
            $api = $this->api('i18n')?->getPack($this->getModuleInfo());
            $this->api('rayfungpi/task')->register('company', $agent, 
                $api->getText('title'), ['register' => $api->getText('register')],
                function ($type, $entity_id) {
                    // Return entity creation date for task workflow
                    return $datetime->format('Y-m-d');
                }
            );
        });
        
        // Register routes
        $agent->addLazyRoute([
            '/' => 'main',
            'api' => [
                'list' => 'list',
                'create' => 'process',
                'edit' => 'process',
                'delete' => 'delete',
            ],
        ]);
        
        return true;
    }
    
    // 2. READY - Runs after init
    public function __onReady(): void
    {
        $configuration = $this->getModuleConfig();
        $api = $this->api('i18n')?->getPack($this->getModuleInfo());
        
        // Check if module is installed
        if (!isset($configuration['install'])) {
            if ($this->handshake('rayfungpi/razit')) {
                $this->install();
            }
        } else {
            // Setup permissions
            $permission = $this->api('razit_group')->set($this->getModuleInfo(), 
                                                          $api?->getText('title'));
            $permission->setLabel($api?->getText('view'));
            $permission->fork('create')->setLabel($api?->getText('create'));
            $permission->fork('edit')->setLabel($api?->getText('edit'));
            $permission->fork('delete')->setLabel($api?->getText('delete'));
        }
        
        // Register menu
        if ($this->handshake('rayfungpi/razit')) {
            $this->api('razit')->setupCategory('membership', $api?->getText('category'));
            $menu = $this->api('razit')->addMenu('membership', $this->getModuleCode(), 
                                                  $api?->getText('title'));
            $menu->setURL($this->getModuleURL());
        }
    }
    
    // 3. LOAD - Runs last (optional)
    public function __onLoad(Agent $agent): bool
    {
        // Optional late-stage initialization
        return true;
    }
};
```

**Key Lifecycle Methods:**

1. **`__onInit(Agent $agent): bool`**
   - First lifecycle hook
   - Register routes and API commands
   - Setup dependencies with `await()`
   - Return `true` to continue, `false` to halt

2. **`__onReady(): void`**
   - Runs after all modules initialized
   - Check installation status
   - Setup permissions
   - Register menus
   - Perform handshakes with dependencies

3. **`__onLoad(Agent $agent): bool`** (optional)
   - Late-stage initialization
   - Access to fully loaded system

---

### 3.4 Route Handler Pattern

**Example:** `sites/razy-sample/rayfungpi/company/default/controller/company.main.php`

```php
<?php
namespace Razy;

return function () {
    // 1. Authorization check
    if (!$this->api('razit_group')->auth($this->getModuleCode(), 'view')) {
        Error::Show404();
    }
    
    // 2. Load header
    $this->api('razit')->header();
    
    // 3. Load template
    $source = $this->loadTemplate('index');
    $source->queue('body');
    
    // 4. Prepare data
    $source->assign([
        'material' => $this->api('task')->material($this->getModuleURL()),
        'module_root' => $this->getModuleURL(),
        'auth' => $this->api('razit_group')->get($this->getModuleCode()),
    ])->getRoot();
    
    // 5. Load footer (triggers output)
    $this->api('razit')->footer();
    return true;
};
```

**Standard Page Rendering Flow:**
1. Check authorization
2. Call `header()`
3. Load and configure template
4. Assign data to template
5. Call `footer()` (auto-renders queued templates)

---

### 3.5 Task Workflow Integration

**Example:** Task registration in company module

```php
// In __onInit() after awaiting dependencies
$agent->await('rayfungpi/razit/multilang,rayfungpi/task', function () use ($agent) {
    $api = $this->api('i18n')?->getPack($this->getModuleInfo());
    
    $this->api('rayfungpi/task')->register(
        'company',                       // Entity type
        $agent,                          // Agent reference
        $api->getText('title'),          // Display name
        [                                // Task types
            'register' => $api->getText('register'),
        ],
        function (string $type, string $entity_id) use (&$cached) {
            // Return entity reference date for task workflow
            if (!isset($cached[$entity_id])) {
                $dba = $this->api('razit')->getDB();
                $result = $dba->prepare()
                    ->select('created_time')
                    ->from('company')
                    ->where('company_id=?')
                    ->lazy(['company_id' => $entity_id]);
                
                $cached[$entity_id] = (new \DateTime($result['created_time'] ?? 'now'))
                    ->format('Y-m-d');
            }
            return $cached[$entity_id];
        }
    );
});
```

**Task Module API:**
- `register($type, $agent, $title, $types, $dateCallback)` - Register entity type for task workflow
- `material($moduleURL)` - Get task UI components for module

---

## 4. Common Production Patterns

### 4.1 Database Access

```php
// Get database instance
$dba = $this->api('razit')->getDB();

// Query builder (Statement)
$result = $dba->prepare()
    ->select('company_id, chinese_name, english_name')
    ->from('company')
    ->where('status=?,!disabled')
    ->lazy(['status' => 'active']);

// Insert
$dba->insert('company', ['chinese_name', 'english_name'], ['company_code'])
    ->query([
        'chinese_name' => '公司名',
        'english_name' => 'Company Ltd',
        'company_code' => 'ABC123',
    ]);

// Update
$dba->prepare()
    ->update('company', ['chinese_name', 'english_name'])
    ->where('company_id=?')
    ->query([
        'chinese_name' => '新公司名',
        'english_name' => 'New Company Ltd',
        'company_id' => 123,
    ]);

// Delete (soft delete with 'disabled' flag)
$dba->prepare()
    ->update('company', ['disabled'])
    ->where('company_id=?')
    ->query([
        'disabled' => 1,
        'company_id' => 123,
    ]);

// Select with prefix
$prefix = $this->api('razit')->getPrefix();
$dba->prepare()
    ->from($prefix . 'company')
    ->where('company_id=?')
    ->lazy(['company_id' => 123]);
```

---

### 4.2 Template Rendering

```php
// Load template
$source = $this->loadTemplate('index');

// Queue in a placeholder
$source->queue('body');

// Get root block
$root = $source->getRoot();

// Assign variables
$source->assign([
    'module_root' => $this->getModuleURL(),
    'auth' => $this->api('razit_group')->get($this->getModuleCode()),
]);

// Loop data with newBlock
foreach ($items as $item) {
    $root->newBlock('item')->assign([
        'id' => $item['id'],
        'name' => $item['name'],
    ]);
}

// Output (optional - footer() usually triggers)
echo $source->output();
```

**Template Syntax (.tpl files):**
```tpl
<div class="list">
    {@ item}
        <div class="item" data-id="{$id}">{$name}</div>
    {/}
</div>

<!-- Conditional -->
{@if $auth.create}
    <button>Create</button>
{/if}

<!-- Translation -->
<h1>{@ml title}</h1>
```

---

### 4.3 API Endpoint Pattern

**Example:** `sites/razy-sample/rayfungpi/company/default/controller/api/list.php`

```php
<?php
namespace Razy;

return function () {
    // Get XHR helper
    $xhr = $this->xhr();
    
    // Get database
    $dba = $this->api('razit')->getDB();
    
    // Get request params
    $page = (int)($_POST['page'] ?? 1);
    $keyword = trim($_POST['keyword'] ?? '');
    
    // Query
    $statement = $dba->prepare()
        ->select('company_id, company_code, chinese_name, english_name, status')
        ->from('company')
        ->where('!disabled')
        ->page($page, 20);  // 20 items per page
    
    if ($keyword) {
        $statement->where('company_code~?|chinese_name~?|english_name~?', 'AND');
        $params = ['keyword' => '%' . $keyword . '%'];
    }
    
    $result = $statement->all($params ?? []);
    
    // Return JSON
    $xhr->response([
        'list' => $result,
        'total' => $statement->getTotal(),
        'page' => $page,
    ]);
};
```

---

### 4.4 Handshake Pattern

```php
// Check if dependency is available
if ($this->handshake('rayfungpi/razit')) {
    // razit module is loaded and ready
    $dba = $this->api('razit')->getDB();
}

// Multiple dependencies
if ($this->handshake('rayfungpi/razit,rayfungpi/task')) {
    // Both modules are ready
}
```

**Purpose:** Safely check if a module is loaded before accessing its API.

---

### 4.5 Installation Pattern

**Example:** `sites/razy-sample/rayfungpi/company/default/controller/company.install.php`

```php
<?php
use Razy\Controller;
use Razy\Database;

return function () {
    /** @var Controller $this */
    $xhr = $this->xhr();
    
    try {
        $prefix = $this->api('razit')->getPrefix();
        $dba = $this->api('razit')->getDB();
        
        // Create table
        $table = new Database\Table($prefix . 'company');
        $table->addColumn('company_id=type(auto)');
        $table->addColumn('company_code=key(index)');
        $table->addColumn('chinese_name');
        $table->addColumn('english_name');
        $table->addColumn('addresses=type(json)');
        $table->addColumn('br_no');
        $table->addColumn('ci_no');
        $table->addColumn('created_by=type(int),nullable,reference(' . $prefix . 'user,user_id)');
        $table->addColumn('created_time=type(timestamp),oncreate');
        $table->addColumn('admission_date=type(date)');
        $table->addColumn('remarks');
        $table->addColumn('status');
        $table->addColumn('disabled=type(bool)');
        $table->create();
        
        // Save installation config
        $config = $this->getModuleConfig();
        $config['install'] = [
            'version' => $this->getModuleVersion(),
            'timestamp' => time(),
        ];
        $this->updateModuleConfig($config);
        
        $xhr->response(['success' => true]);
    } catch (\Exception $e) {
        $xhr->response(['error' => $e->getMessage()]);
    }
};
```

---

## 5. Module Dependency Graph

### 5.1 Core Dependency Tree

```
rayfungpi/razit (Core)
├── rayfungpi/razit/multilang (i18n)
│   └── rayfungpi/razit
├── rayfungpi/razit/user (Auth)
│   └── rayfungpi/razit
├── rayfungpi/razit/group (Authz)
│   ├── rayfungpi/razit
│   ├── rayfungpi/razit/user
│   └── rayfungpi/razit/logging
├── rayfungpi/razit/logging (Logging)
│   ├── rayfungpi/razit
│   └── rayfungpi/razit/user
└── rayfungpi/razit/department (Departments)
    ├── rayfungpi/razit
    ├── rayfungpi/razit/user
    └── rayfungpi/razit/group
```

### 5.2 Production Module Dependencies

**Typical business module stack:**

```
rayfungpi/company
├── rayfungpi/razit (Core)
├── rayfungpi/razit/user (Auth)
├── rayfungpi/razit/group (Authz)
└── rayfungpi/task (Workflow)
    ├── rayfungpi/razit
    ├── rayfungpi/razit/user
    ├── rayfungpi/razit/group
    └── rayfungpi/appform (Form builder)
```

---

## 6. Best Practices from Production

### 6.1 Module Design

1. **Always use shared modules for cross-site functionality**
   - Place in `shared/module/rayfungpi/`
   - Use clear API names
   - Version properly

2. **Follow naming conventions**
   - Module code: `rayfungpi/module-name`
   - API name: `module_name` (underscores)
   - Controller files: `module_name.action.php`

3. **Use await() for dependencies**
   ```php
   $agent->await('dependency1,dependency2', function () {
       // Safe to use dependency APIs here
   });
   ```

4. **Always register permissions in __onReady()**
   ```php
   $permission = $this->api('razit_group')->set($moduleInfo, $title);
   $permission->setLabel('View');
   $permission->fork('create')->setLabel('Create');
   ```

### 6.2 Security Patterns

1. **Always check authorization**
   ```php
   if (!$this->api('razit_group')->auth($this->getModuleCode(), 'view')) {
       Error::Show404();
   }
   ```

2. **Validate user sessions**
   ```php
   $this->api('razit_user')->checkSession();
   ```

3. **Use prepared statements**
   ```php
   $dba->prepare()->where('id=?')->lazy(['id' => $id]);
   ```

4. **Soft delete with disabled flag**
   ```php
   $dba->prepare()
       ->update('table', ['disabled'])
       ->where('id=?')
       ->query(['disabled' => 1, 'id' => $id]);
   ```

### 6.3 Performance Patterns

1. **Cache expensive operations**
   ```php
   if (!isset($cached[$key])) {
       $cached[$key] = $expensiveOperation();
   }
   return $cached[$key];
   ```

2. **Use lazy loading for routes**
   ```php
   $agent->addLazyRoute([...]);  // Not loaded until accessed
   ```

3. **Paginate large datasets**
   ```php
   $statement->page($page, $perPage);
   ```

---

## 7. Migration Guide: Development → Production

### 7.1 Convert Standalone Module to Shared Module

**Before:** `sites/site1/rayfungpi/common-module/`

**After:** `shared/module/rayfungpi/common-module/`

**Steps:**
1. Move module to `shared/module/rayfungpi/`
2. Update `dist.php` in each site:
   ```php
   'global_module' => true,
   ```
3. Update dependencies in other modules
4. Test all sites using the shared module

### 7.2 Adding New Business Module

1. **Create module structure**
   ```
   sites/razy-sample/rayfungpi/mymodule/
   ├── module.php
   └── default/
       ├── package.php
       ├── controller/
       │   ├── mymodule.php
       │   ├── mymodule.main.php
       │   └── api/
       ├── view/
       └── lang/
   ```

2. **Define package.php**
   ```php
   return [
       'api_name' => 'mymodule',
       'version' => '1.0.0',
       'require' => [
           'rayfungpi/razit' => '*',
           'rayfungpi/razit/user' => '*',
           'rayfungpi/razit/group' => '*',
       ],
   ];
   ```

3. **Implement lifecycle**
   - `__onInit()`: routes, dependencies
   - `__onReady()`: permissions, menus, handshakes

4. **Test installation flow**

---

## 8. Real-World Examples

### 8.1 Company Management Module

**Location:** `sites/razy-sample/rayfungpi/company`

**Features:**
- Company registration workflow
- Task integration for approval process
- Multi-language support
- Permission-based access control

**Key APIs Used:**
- `razit` - Database, menus
- `razit_user` - User context
- `razit_group` - Authorization
- `task` - Workflow management
- `i18n` - Translations

### 8.2 Task Workflow Module

**Location:** `sites/razy-sample/rayfungpi/task`

**Features:**
- Workflow registration system
- Task status tracking
- Audit trail
- Department assignment
- Form integration

**Key Patterns:**
- Plugin architecture for entity types
- Event-driven status updates
- Template material provisioning

### 8.3 Web Page CMS Module

**Location:** `sites/razy-sample/rayfungpi/webpage`

**Features:**
- Hierarchical page structure
- Top menu / independence menu
- External link support
- Member zone pages

**Key Patterns:**
- Tree-based navigation
- Conditional rendering based on type
- Parent-child relationships

---

## 9. Troubleshooting Production Issues

### 9.1 Module Not Loading

**Check:**
1. Is `global_module: true` in `dist.php`?
2. Are dependencies listed in `require` array?
3. Is module in correct path?
4. Check `sites.inc.php` domain mapping

### 9.2 API Not Available

**Check:**
1. Is dependency loaded via `await()`?
2. Is API name correct in `package.php`?
3. Use `handshake()` before accessing API

### 9.3 Permission Denied

**Check:**
1. Are permissions setup in `__onReady()`?
2. Is authorization check using correct module code?
3. Has user been assigned the permission?

---

## 10. Appendix

### 10.1 Production Module List (razy-sample)

- active_manager
- announcement
- application_form
- banner
- company ✓ (analyzed)
- contacts
- document_uploader
- etrade_course
- expenses
- gtlink
- gtlink_payment
- individual
- membership_share
- membership_share_transfer
- member_preview
- minter
- mpel
- openoutcry_bank
- openoutcry_license
- operation_status
- pageblock
- payment
- recursive_payment
- refiner_products
- registration_license
- sync
- task ✓ (analyzed)
- vcard
- webpage ✓ (analyzed)
- zone_account

### 10.2 Shared Module List (rayfungpi)

- razit ✓ (core)
- razit-department
- razit-group ✓ (authz)
- razit-logging
- razit-multilang ✓ (i18n)
- razit-user ✓ (auth)

---

**End of Production Usage Analysis**
