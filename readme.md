# **Razy v0.5 - The PHP framework for manageable development environment**

## What is the roadmap for v0.5 to become v1.0?

1. ✏️Enable downloading and installing modules from GitHub repositories via CLI
2. ✅Enhance the application workflow to reduce coding requirements
3. ✏️Cache system
4. ✏️Thread system
5. ✏️Revamp `Table` and `Column` classes to streamline coding
6. ✅️Revamp `Action` and `Validate` classes to streamline coding
7. ✏️Cross-distributor communication internally
8. ✏️More build-in classes and functions

## **What is new in v0.5?**

### **Simplified Flow**

In Razy v0.5, the module loading and routing stages have been revamped. The operation of each stage in module loading is now clearer and reduces the number of handshakes needed to ensure required modules are loaded successfully. Additionally, it will unload a module if one of its required modules fails to load.

***New module load and route flow***

__onInit(Agent  $agent)

> Trigger when the module has been scanned and loaded in
>

Return false to mark the module as unloaded. This is used to set up the module's route, API, script, async callback, and event listener.

---

__onLoad(Agent  $agent)

> Trigger when all modules have loaded and put into the queue
>

Based on loading order to preload module,  return false to remove from the loading queue.

---

__onRequire()

> Trigger before any action,  routing or script execute
>

Used to ensure the module has completed initialization, or return false to prevent routing or execution.

---

__onReady()

> Trigger after async callbacks is executed
>

Await callbacks (`Agent::await()`) are very useful for accessing the API before any routing or execution occurs. They ensure that required modules have already loaded into the system. All await callbacks execute between the __onRequire and __onReady stages, guaranteeing that API access in __onReady is completely safe.

---

__onScriptReady()  /  __onRouted

> Trigger when the URL query has matched
>

Typically used to perform pre-checks on the module before other modules are routed or executed.

---

__onEntry()

> Trigger when it has been routed successfully
>

At this stage, developers can preload parameters before any routed method is executed. This is typically used in conjunction with __onRouted() to handle redirecting.

---

Based on real-world development experience, Razy v0.5 has reduced the amount of code needed for handling module load status confirmation and preloading by 30%.

### Guaranteed Module Loading

Module loading order is now based on dependencies, preventing modules from being unable to access required modules during the loading stage. In previous versions, the module loading stage was confusing; developers often spent time and effort identifying whether a module was already loaded and determining the loading priority. To address this, I've added a handshake logic that allows modules to signal each other, ensuring they're ready for API access.

In Razy v0.5, I've introduced a new stage after `__onLoad` that allows modules to determine their status. This addition has reduced handshake times and minimized the code needed to preload certain functions, resulting in fewer conflicts.

### **Module config file required, new namespace rule**

Now developers need to create a `module.php` file in the module folder, or the module won't load. This file contains `module_code`, `author`, and `description` parameters, which are used for module loading and previewing. Additionally, the module code supports deeper levels for namespace paths and automatically requires the parent namespace's module.

For example, when the module code is named `rayfungpi/razit/user`, the `rayfungpi/razit` module is automatically required. This parent module needs to be loaded and is added to the require list when the system scans the module. This feature is particularly useful for bundle packages.

### Revamp Scope

Applications now run as instances, providing a more reliable workflow. Creating an Application object no longer automatically matches the domain, allowing for more flexible operations outside of running the application. When Razy is launched, an Application object must be created to start URL query matching or configure the application.

Normally, when routing starts and scripts run, the Application is locked to prevent developers from accessing the Application's core configuration functions.

### Distributor code is now standardized

It now supports using `-` in the code, and the distributor folder name must match the distributor code. Previous versions often caused confusion between the folder name and the distributor code, frequently leading to failed distributor setups.

### Feature

**Packed in phar**

The Razy framework is now packaged into a single phar file, making source code maintenance easier and enabling self-update functionality. For instance, you can build the Razy environment in any location using "php Razy.phar build", or add new sites with the following command:

`php Razy.phar set yourdomain.com/path/to/ dest_code`

**Routing**

Razy routes requests using URL queries. First, it matches the URL to a specified distributor. Once matched, it loads the modules under the distributor's folder and prepares for routing. Razy offers two types of routes: lazy routes and regex routes. Lazy routes start with the module code and combine with a simple path. Regex routes match URL queries using regular expressions.

```php
/**
 * The module code is `hello`
 *
 * The route `domain.com/hello/first/second` will link to ./controller/first/second.php
 * The route `domain.com/hello/root will link to ./controller/Route.root.php
 */
$agent->addLazyRoute([
    'first' => [
        'second' => 'third',
    ],
    'root' => 'root'
]);

/**
 * The route `domain.com/regex/get-abc/page-1/tester` will link to ./controller/Route.regex.php,
 * and it will pass the parameters `abc`, `1` and `tester` to the controller
 */
$agent->addRoute('/regex/get-(:a)/page-(:d)/(:[a-z0-9_-]{3,})', 'regex');
```

**Web Asset**

You can create a `webassets` folder under the module folder. Clients will access these web assets through an `.htaccess` rewrite rule automatically generated by Razy.

```
|- (distributor)
    |- (vendor)
        |- (module)
            |- defualt
                |- webasset
                    |- (your js, css or else)
            |- 0.1.0 # When a version has committed, the `webassets` will be cloned into new version folder
                |- app.phar
                |- webassets
                    |- (your js, css or else)
```

**Module Structure**

Modules now require a `module.php` file for the module's code, author, and description, and a `package.php` file inside the package folder. The `package.php` file contains `api` code, required modules, and other runtime settings.

```
| RazyApplication
    |- shared
        |- module
            |- (vendor)
                |- (module)
                    |- defualt
                        |- package.php
                    |- 0.1.0
                    |- 0.1.1
                    |- 0.1.3.phar
                    |- module.php
    |- (distributor)
        |- (vendor)
            |- (module)
                |- defualt
                    |- package.php
                |- 0.1.0
                |- 0.1.1
                |- 0.1.3.phar
                |- module.php
```

**Distributor Versioning**

You can assign different domains with different paths to the same module. To achieve this, provide the distributor code identifier in `sites.inc.php`. This identifier will match with `dist.php` for versioning purposes.

```php
return [
    'domains' => [
        'localhost' => [
            '/demo' => 'distCode',
            '/demo-dev' => 'distCode@dev',
        ],
    ],
];
```

In the `dist.php` file, you can set up the version of each module using the `enable_module` parameter.

```php
return [
    'dist' => 'distCode',
    'global_module' => true,

    // A list to load the modules in shared folder or in distribution folder
    'enable_module' => [
        '*' => [], // * means the default version, override by specified identifier as below
        'dev' => [
            'vendor/package' => '0.0.1',
        ]
    ],
];
```

**Event Emitter**

Razy has a new Event & Listen logic to allow modules to interactive with others. In  `Module`  initialize stage, you can set up a list of events to listen for, such as:

```
$agent->listen('vendor/modulecode:onload', 'pathOfMethod');
```

In other side, you can create an EventEmitter by  `$this->trigger`  by the given event name, or pass a  `Closure`  as a  `handler`  additionally. After that, you can execute  `EventEmitter`  method named  `reslove(...$args)`  to pass any number of arguments to other modules that which are listening the event and pass the response to the  `handler`  if set. Such as:

```
$this->trigger('onload')->resolve(function($paramA, $paramB) {
    // blah blah blah
});
```

**Template Engine**

Razy features a high-performance template engine that efficiently parses parameter tags and string values. The engine has removed parameter closing tags, which were previously confusing and difficult to identify in template files, despite providing a hashtag identifier. With modifications, array-path values, various functional blocks, and function tags, the template engine offers front-end designers a highly flexible and user-friendly experience.

```
{$parameter.path.of.the.value->mod:"param":"here"->othermod}
```

Or consider features like enclosed parameter tags and the `if` function tag.

```
{@if $text|$parameter.path.of.the.value->gettype="array"}
// blah blah blah
{/if}
```

The parameter tag supports modifier syntax for function tag arguments. The argument is parsed as a value from the `Entity` parameter and passed to the processor. This eliminates the need to parse the argument in the processor itself.

```
// Shorten, ordered by source, kvp
{@each $arraydata}
Key: {$kvp.key}
Value: {$key.value}
{/each}

// Parameter Set
{@each source=$arraydata kvp="nameofkvp"}
Key: {$nameofkvp.key}
Value: {$nameofkey.value}
{/each}

// Bypass
{@if $data->gettype="array",($data.value="hello"|$data.value="world")}
// The content after `if` will pass to the plugin as the first parameter
{/if}
```

Or you can define the parameter with in the template file

```
{@def "name" "Define a new variable"}

// Or you can copy the value from other variable
{@def "newvalue" $data.path.of.value}
```

The template system features four distinct types of template blocks: `WRAPPER`, `INCLUDE`, `TEMPLATE`, and `USE`. These blocks are incredibly versatile, allowing you to load external template files, control wrappers, or reuse template blocks within any child block.

```
<!-- START BLOCK: blockA -->
    <!-- TEMPLATE BLOCK: template -->
    Here is the template content
    <!-- END BLOCK: template -->

    <!-- START BLOCK: sample -->
        Below is the content generated from the TEMPLATE block
        <!-- USE template BLOCK: subblock -->
    <!-- END BLOCK: sample -->
<!-- END BLOCK: blockA -->

<!-- START BLOCK: blockB -->
    Include the external template file from the current file location
    <!-- INCLUDE BLOCK: folder/external.tpl -->
    You cannot use the template block from other block!
    <!-- USE template BLOCK: subblock -->
<!-- END BLOCK: blockB -->

<!-- WRAPPER BLOCK: blockName -->
<div class="wrapper">
    <ul>
        <!-- START BLOCK: blockName -->
        <li>Block</li>
        <!-- END BLOCK: blockName -->
    </ul>
</div>
<!-- END BLOCK: blockName -->
```

Developers can also load template files from inline template blocks or those defined in the template engine. Note that loaded templates cannot access parameter values from the caller. You must pass parameters using the `template` function tag.

```
$tpl = $this->getTemplate();

$tpl->loadTemplate([
    'SampleA' => $this->getTemplateFilePath('include/SampleA'),
    'SampleB' => $this->getTemplateFilePath('include/SampleB'),
]);
```

You can load the template in your template file using the `template` function tag:

```jsx
{@template:SampleA paramA="test" paramB=$parameter.value.in.array}
```

Furthermore, developers can also nest templates within templates. However, they must be careful to pass parameters correctly when doing so.

**Database Statement Simple Syntax**

Razy's WhereSyntax and TableJoinSyntax provide clear and shortened syntax to generate MySQL statements. This is particularly helpful for maintaining complex MySQL queries. These features can generate multiple MySQL JSON_* function combinations using simple operators such as `~=` and `:=`. Moreover, Razy's TableJoinSyntax and WhereSyntax offer more accurate syntax parsing, preventing users from providing invalid syntax formats that could generate incomplete or invalid statements. Additionally, WhereSyntax has been enhanced to parse operands more accurately, detecting the operand type to generate appropriate statements.

```
$statement = $database->prepare()->from('u.user-g.group[group_id]')->where('u.user_id=?,!g.auths~=?')->assign([
    'auths' => 'view',
    'user_id' => 1,
]);
echo $statement->getSyntax();

/**
 * Result:
 * SELECT * FROM `user` AS `u` JOIN `group` AS `g` ON u.group_id = g.group_id WHERE `u`.`user_id` = 1 AND !(JSON_CONTAINS(JSON_EXTRACT(`g`.`auths`, '$.*'), '"view"') = 1)
 */
```

| Operator | Description |
|----------| --- |
| =        | Equal |
| \|=      | Search in list |
| *=       | Contain string |
| ^=       | Start with string |
| $=       | End with string |
| !=       | Not equal |
| <        | Less than |
| \>       | Greater than |
| <=       | Less than and equal to |
| \>=      | Greater than and equal to |
| :=       | Extract the node in specified column with JSON datatype by given path |
| ~=       | Search the value or a list of value in specified column with JSON datatype |
| &=       | Search the string in specified column with JSON datatype |
| @=       | Match multiple keys in specified column with JSON datatype |

The Razy simple syntax for Table Join and Where statements offers significant advantages when writing complex and lengthy statements. However, it doesn't cover all possible scenarios. In fact, while developing several systems using Razy v0.3, I encountered a critical limitation: the `Database\Statement::update` function is too basic to handle useful simple operations such as incrementing, decrementing, concatenation, or mathematical operations.

```
echo $database->update('table_name', ['name', 'count++', 'document_code="doc_"&id', 'path&=?', 'another_count+=4'])->where('id=1')->assign([
    'name' => 'Razy',
    'path' => '/node',
])->getSyntax();

/**
 * Result:
 * UPDATE table_name SET `name` = 'Razy', `count` = `count` + 1, `document_code` = CONCAT("doc_", id), `path` = CONCAT(`path`, '/node'), `another_count` = `another_count` + 4 WHERE `id` = 1;
 */
```

**Autoloader**

Razy employs a two-layered autoloading system: the root of the Razy structure and the `Razy.phar` file. The system first searches for class files in the Razy structure's root, then in `Razy.phar`. This approach allows developers to override Razy core classes or create new project-specific classes without modifying the original files.

But wait, there's more! Razy also supports package installation and updates from [packagist.org](http://packagist.org). By integrating with the `composer` repository, Razy simplifies package management for developers, eliminating the hassle of manually loading project classes. Furthermore, classes from [packagist.org](http://packagist.org) are neatly organized by distributor within the `autoload` folder, preventing version conflicts.

To update or install a Module and composer package, use this command:

```
php Razy.phar validate distCode
```

Razy supports PSR-0 autoloading and isolates `composer packages`, `custom classes`, and `controller classes` in separate packages.

## Any documentation for Razy?

I dislike writing documentation! However, it's essential. Before releasing any formal documentation, I plan to record some development cases to demonstrate how Razy works. I hope someone will be willing to help with documentation after watching my videos.

## Why Am I Dedicating Time to Developing Razy?

When I started my freelance career in system development, managing several projects simultaneously wasn't a problem—perhaps because I didn't have many jobs at the time ;). Following MVC made my development easier as a full-stack developer. However, after accumulating experience and revamping systems, I found myself struggling to update functions or overhaul subsystems. I needed to backport updates to older systems and access different servers to update them, which became painful. Any changes posed risks and took time, and I had to be cautious about making too many changes that I wanted to reuse in other projects.

Midway through my career, I realized I needed to develop my own framework instead of using open-source options. This decision stemmed from my desire to continuously improve my development skills and create a fast development environment for future projects. Razy is my biggest project (along with Void0.js, which I use to replace jQuery) and it's really helpful for managing different sites in a single place.

Additionally, Razy offers excellent code management and an improved development lifecycle. I can revert to older committed packages when something goes wrong or maintain a stable committed package for projects not yet ready for upgrades. If a client has custom functions in a module, I can easily clone the current version as a new package and begin development without concerns.

In 2024, despite the abundance of open-source options, I continue working on Razy because it embodies my values and showcases my experience. In today's business climate, developers using open-source frameworks are often seen as interchangeable—employers may choose based on cost rather than skill. Sadly, many developers in the current generation lack a deep understanding of logic and performance optimization, which I view as critical weaknesses.

My message to all developers is this: focus on enhancing your logical thinking, understanding diverse industry workflows, and mastering native programming language skills throughout your career. These fundamentals will enable you to seamlessly transition between programming languages, debug effectively in any framework, and streamline your work process to the point where it feels effortless.
