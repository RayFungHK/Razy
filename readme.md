# Razy v0.4 - The next framework for fast development

## What is new in v0.4?

### Structure Changed

Changed the module structure from `Manager->Package->Controller` to `App->Domain->Distributor->Module->Controller`. New
structure provides site-to-site internal API access and have clear picture and role of `Distributor`

### Packed in phar
Razy framework has packed into a single phar file, it can let the source code easier maintain and provide self-update function. For example build the Razy environment in any location by using `php Razy.phar build`, or add a new sites by the following command:
```
php Razy.phar set yourdomain.com/path/to/ dest_code
```

### Web Asset
In v0.3, the module web assets such as `css`, `js` or `image` are located in their own `view` directory, so the URL of the web assets will contain the module path. In other words, the web asset file URL length depends on how deep of the module directory. Indeed, some developer would not like to disclose the module or distributor structure from the URL. To fulfill the developer secure requirement, in  v0.4, there is a parameter called `assets` in `package.php`, used to unpack the specified web asset into a folder named by the distributor code, and update the `.htaccess` rewrite rules to hide the actual asset location.

In package.php, the `assets` parameters should be like the following:
```php
<?php
return [
	'module_code' => 'yourname.module',
	'api' => 'yourapi',
	'version' => '1.0.0',
	'author' => 'Your Name',
	'assets' => [
		'the/file/under/module/folder' => 'target/folder',
		'specified/file/name.txt' => 'newname.txt',
	],
];
```

Above list of asset will be cloned into under `view\{$dist_code}` directory by `set`, `remove`, `link`, `unlink`, `unpackasset` and `fix` command from Razy.phar via CLI.

### New rule for naming module class

In order to prevent redeclaration error between accessing module in the different distributor via internal API, Razy has
new naming rule of each `Module`. All modules must have the namespace beginning
with `Razy\Module\{Distributor_Code}\{Module_Class}`.

Assume the `Distributor Code` is `Main` and the `Module Code` is `root`：

Before v0.4

```php
namespace \Razy\Module\root;
```

After v0.4

```php
namespace \Razy\Module\Main\root;
/**
 * Now you can also use fqdn format as the module code like `this.is.root`,
 * and the namespace of the module class should be:
 */
namespace \Razy\Module\Main\this\is\root;
```

Beware that the `Distributor` code format should be alphabet and numeric only.

Razy v0.4 has a new feature that you can load the shared module in different `Distributor`, to prevent redeclare class
error cause, you should use the namespace as:

```php
namespace \Razy\Shared\this\is\root;
```

### URL Query Route

Changed the game of URL query route, now it has `Lazy Route` and `Regex Route`. You can set up
by `Controller->addLazyRoute()`, it will auto combine the nest of array with the module code as a route, and map the
closure file path by key and value. Or you can use `Controller->addRoute()` to create a regular expression to match the
url query, and pass the matched string as the parameters. Also, you can use the `Lazy Route` and `Regex Route` at the
same time, but the Distributor will match the `Regex Route` route first then `Lazy Route`.

```php
/**
 * The module code is `Sample.Route` and the alias is `hello`
 *
 * The route `domain.com/hello/first/second` will link to ./controller/first/second.php
 * The route `domain.com/hello/root will link to ./controller/Route.root.php
 */
$this->addLazyRoute([
    'first' => [
        'second' => 'third',
    ],
    'root' => 'root'
]);

/**
 * The route `domain.com/regex/get-abc/page-1/tester` will link to ./controller/Route.regex.php,
 * and it will pass the parameters `abc`, `1` and `tester` to the controller
 */
$this->addRoute('/regex/get-(:a)/page-(:d)/(:[a-z0-9_-]{3,})', 'regex');
```

### Internal cross-site API

Previously, when you have created multiple `Distributor` under a Razy structure, the API is not allowed to access between the `Distributor` directly, yet you can access another `Distributor` API via CURL, but it may increase the execution time. Definitely, copying the same function to all `Distributor` module would be implemented is a dumb solution, but it is the only way to implement the function in each `Distributor`. Back to the original intention, Razy is designed for better coding management and prevent merge conflict in development. The responsible developer or team of the `Module` should maintain the API to allow other `Module` access for, to prevent let other developer try to modify your code to fulfill their requirements. 

In v0.4, Razy `Controller` provides a `connect()` method which can let developer access other `Distributor` API directly. Also, you can configure the `Distributor` whitelist that allow to connect, or restrict access in `Controller::__onAPICall()`.

```php
$connection = $this->connect('domain.name.in.razy.com');
$connection->api('api.function', 'Developer', 'Friendly');
```

### Namespace module code

You can use namespace to name your module code that it could prevent name conflict with other modules, such
as `Author.Package.ClassName`. Your class file should contain a class under the namespace `Razy\Module\Author\Package\ClassName`, and the `Lazy Route` will start from `ClassName` or an alias you
provided.

```php
/**
 * If the module code is named `Author\Sample\Route`, the class should be declared as below
 */
namespace Razy\Module\Author\Sample;

class Route
{
    // bla bla bla...
}
```

### Force to Enable/Disable Module

You can enable or disable the module in `dist.php`, so that you don't need to force to disable the module in onInit().

### Shared Module

You don't need to clone the module from other project when you want to reuse it, now you can update the module
in `shared` folder for all Distributors which are not in their module folder.

### Event Emitter

Now Razy has a new Event & Listen logic to allow modules to interactive with others. In `Module` initialize stage, you
can set up a list of events to listen for, such as:

```php
$this->listen('test.onload', 'pathOfMethod');
```

In other side, you can create an EventEmitter by `$this->trigger` by the given event name, or pass a `Closure` as
a `handler` additionally. After that, you can execute `EventEmitter` method named `reslove(...$args)` to pass any number
of arguments to other modules that which are listening the event and pass the response to the `handler` if set. Such as:

```php
$this->trigger('test.onload', function($response, $moduleCode) {
    echo $moduleCode . ' response: ' . $response;
})->resolve('hello world!');
```

### From Iterator to Collection

In v0.3, it was called as `Iternator\Manager`, it is an array-like data factory to process its elements, such as `trim`
, `uppercase` or `int`. In v0.4, it is completely different now, even more powerful.

Why the `Iterator` is discontinued? It is because PHP7.4 native array functions are not supported in an object. For example, `array_key_exists` or `array_keys` will prompt a warning message and not functional when you have passed the `ArrayObject` or `ArrayAccess` object into. So, Razy has a new class called `Collection` to replace `Iterator`, used to process the elements in the array.

```php
$sample = [
    'name' => 'Hello World',
    'path' => [
        'of' => [
            'the' => 'Road',
            'number' => 20,
            'text_a' => '    Bad Boy!',
            'text_b' => 'Good Boy!   ',
        ],    
    ],
];

$collection = collect($sample);
$result = $collection('name,path:istype("array").of.*:istype("string")')->trim()->getArray();
var_dump($result);

/**
 * The selected strings have trimmed:
 * 
 * array(4) {
 *  ["$.name"]=>
 *  string(11) "Hello World"
 *  ["$.path.of.the"]=>
 *  string(4) "Road"
 *  ["$.path.of.text_a"]=>
 *  string(8) "Bad Boy!"
 *  ["$.path.of.text_b"]=>
 *  string(9) "Good Boy!"
 * }
 */
```

As above sample, it showed that you can use the selector syntax `name,path:istype("array").of.*:istype("string")` to match the elements collected by `Collection`. The syntax is similar as the CSS selector. Also, you can use the pattern start with colon like `:plugin(paramA, paramB)` to filter the matched elements that pass the test implemented by the plugin function.

After the selector has parsed, the matched elements will be passed to the `Processor` to have further processing, such as `trim`, `upper` or `lower` that implemented by the plugin function, or call `get()` to return a new `Collection` object with the matched values.

### Optimized Template Engine

Razy has enhanced the Template engine that will well-parsing the parameter tag and string value. Also, the parameter's closing tag is removed that it was very confusing and hard to identify in the template file, although it provides a hashtag identical.

The Template Engine was working smooth and matures in v0.3, so there is no big difference on the structure or format. Regarding the plugin of modifier and function, it has quite different. First, the modifier format has changed to fulfill shorten conditional syntax.

In v0.3

```html
{$parameter.path.of.the.value|mod:"param":"here"|othermod}
```

Now in v0.4

```html
{$parameter.path.of.the.value->mod:"param":"here"->othermod}
```

So we can use the parameter with the modifier syntax in `if` function tag!

```html
{@if $text|$parameter.path.of.the.value->gettype="array"}
// blah blah blah
{/if}
```

As above change you can find that the modifier separator has changed from `|` to `->`, it is similar with the PHP method
call. Second, the function tag also can be configured as it is a block statement enclosures or standalone tag, thus have
an easier plugin coding.

Finally, the parameter tag final support modifier syntax as the function tag arguments, and the argument will be parsed
as a value from the `Entity` parameter and pass to the processor afterwards so that we don't need to parse the argument
in the processor.

Notice that, some function plugins has updated to fulfill above changes, and the function tag supports 3 arguments
format, `Shorten`, `Parameter Set` and `Bypass` if configured.

In v0.3

```html
{@each source=$arraydata key="key" value="value"}
Key: {$key}
Value: {$value}
{/each}
```

In v0.4

```html
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

The traditional way of parameter declaration has deprecated, it has replaced by the function tag called `def`.

In v0.3

```html
{$name: "Define a new variable"}
```

In v0.4

```html
{@def "name" "Define a new variable"}

// Or you can copy the value from other variable
{@def "newvalue" $data.path.of.value}
```

Therefore, v0.4 also added 3 different types of the template block, `INCLUDE`, `TEMPLATE` and `USE`. It is very useful
for load the external template file or re-use the template block in any child block.

```html
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
```

The last thing to say, the Template Engine rewritten the code to use the Generator to fetch the content of the template file, as it will save a lot of memory due to Razy will load all the file content in memory previously.

### Database Statement Simple Syntax
In v0.3 Razy WhereSyntax and TableJoinSyntax provides clear and shorten syntax to generate the MySQL Statement. It is very helpful for maintaining complex MySQL statement, also it can generate the multiple MySQL JSON_* function combination just using a simple operator such as `~=` and `:=`. In v0.4 Razy enhanced the TableJoinSyntax and WhereSyntax to make it more accurate to parse the syntax, to prevent user provides an invalid syntax format to generate the incomplete or invalid statement. Besides, WhereSyntax also enhanced for parsing the operand more accurate, it will detect the type of the operand to generate different statements.

```php
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

|Operator|Description|
|---|---|
|=|Equal|
|&#124;=|Search in list|
|*=|Contain string
|^=|Start with string
|$=|End with string
|!=|Not equal
|<|Less than
|&#62;|Greater than
|<=|Less than and equal to
|>=|Greater than and equal to
|:=|Extract the node in specified column with JSON datatype by given path
|~=|Search the value or a list of value in specified column with JSON datatype
|&=|Search the string in specified column with JSON datatype
|@=|Match multiple keys in specified column with JSON datatype

The Razy simple syntax of the Table Join and Where statement provides big advantage for writing complex and long statement, but it is not enough to cover most of the statement. In fact, I have developed several systems using Razy v0.3 and faced a critical problem, the `Database\Statement::update` function is too dumb that cannot cover some useful simple statements such as incrementing or decrementing, concatenate or maths operators. So, Razy v0.4 provides a simple syntax for `Update Statement`, and there is no function usage changes between v0.3 and v0.4.

v0.3
```php
echo $database->update('table_name', ['comment', 'name'])->where('id=1')->assign([
    'comment' => 'Hello World',
    'name' => 'Razy',
])->getSyntax();

/**
 * Result:
 * UPDATE table_name SET `comment` = 'Hello World', `name` = 'Razy' WHERE `id` = 1;
 */
```
v0.4
```php
echo $database->update('table_name', ['name', 'count++', 'document_code="doc_"&id', 'path&=?', 'another_count+=4'])->where('id=1')->assign([
    'name' => 'Razy',
    'path' => '/node',
])->getSyntax();

/**
 * Result:
 * UPDATE table_name SET `name` = 'Razy', `count` = `count` + 1, `document_code` = CONCAT("doc_", id), `path` = CONCAT(`path`, '/node'), `another_count` = `another_count` + 4 WHERE `id` = 1;
 */
```

### Database Table and Column
In v0.3, developer can create table and column by using `Database::Table` and `Database::Column` class to generate the SQL statement, but it is hard to modify or add a column or table when upgrading the module. So in v0.4, `Database::Table` and `Database::Column` has enhanced to support alter table and column, it will generate the SQL statement from each `Database::Table->commit()`.

Besides, `Database::Table` and `Database::Column` also support passing the configuration syntax as the parameter used to import all the table settings and its columns settings. It is useful to commit previous version table setting and generate the SQL statement to update the table.

In v0.3
```php
// Create a Table
$table = new Database\Table('test_table');

// Create a new column, and set the type as an auto increment id.
$columnA = $table->addColumn('column_a');
$columnA->type('auto');

// Create a new column, and set the type as int, length 11 and default value to 1
$columnB = $table->addColumn('column_b');
$columnB->type('int')->length('11')->default('1');

// Generate the `CREATE` table syntax
echo $table->getSyntax();
/**
 * Result:
 * CREATE TABLE test_table (`column_a` INT(8) NOT NULL AUTO_INCREMENT, `column_b` INT(11) NOT NULL DEFAULT '0', `column_c` TINYINT(1) NOT NULL DEFAULT '0', PRIMARY KEY(`column_a`)) ENGINE InnoDB CHARSET=utf8 COLLATE utf8mb4_general_ci;
 */
```

In v0.4
```php
// Create a Table
$table = new Database\Table('test_table');
$columnA = $table->addColumn('column_a=type(auto)');
$columnB = $table->addColumn('column_b=type(int),length(11),default(1)');
$columnC = $table->addColumn('column_c')->setType('bool');

// Generate Create Table SQL Statement in first commit.
echo $table->commit();
/**
 * Result:
 * CREATE TABLE test_table (`column_a` INT(8) NOT NULL AUTO_INCREMENT, `column_b` INT(11) NOT NULL DEFAULT '0', `column_c` TINYINT(1) NOT NULL DEFAULT '0', PRIMARY KEY(`column_a`)) ENGINE InnoDB CHARSET=utf8 COLLATE utf8mb4_general_ci;
 */

// Reorder the columnC and modify the columnB type
$table->moveColumnAfter('column_c', 'column_a');
$columnB->setType('text');

// Generate Alter Table and Alter Column Statement
echo $table->commit();

/**
 * Result:
 * ALTER TABLE `test_table`, MODIFY COLUMN column_c TINYINT(1) NOT NULL AFTER column_a, MODIFY COLUMN column_b VARCHAR(255) NOT NULL DEFAULT '';
 */
```

Import and Export the config
```php
$table = Database\Table::Import('`table_name`=charset(utf8),collation(utf8_general_ci)[`column_b`=type(int),length(11),default("0"):`column_a`=type(auto),length(8),default("0")]');

// Add extra column
$table->addColumn('text_column');
echo $table->exportConfig();
/**
 * `table_name`=charset(utf8),collation(utf8_general_ci)[`column_b`=type(int),length(11),default("0"):`column_a`=type(auto),length(8),default("0"):`text_column`=type(text),length(255)]
 */
```

### Enhanced exception handling
In v0.3, the Exception Handler is not accurate enough to find out the exception location, so Razy v0.4 has rewritten all library exception handling, and throw the exception with the correct file and line. Previously, it may stack an extra backtrace thus harder to track the error, so the new change will help developer debugging faster and easier.

### Autoloader
In v0.3 Razy supports autoloading classes under the `library` folder, but the namespace is not naming in Psr standard, so Razy v0.4 makes some big changes. First all Razy core classes has moved to the `Razy` folder under `library`

Second, Razy has 2 layers of autoloading phases, the root of the Razy structure and the `Razy.phar`. Razy will search the class file under the root of the Razy structure first, then the `Razy.phar`. It is very useful to override the Razy core classes without overwriting the original classes, or creating new classes for your project.

Sound good? Even more, Razy v0.4 supports install or update package from packagist.org. Yes! Integrated with `composer` repository could help developers manage packages easier, no pain to load the classes for your project. Also, the classes from packagist.org will be separated by the distributor under `autoload` folder, to prevent version conflict.

Update or install Module and composer package by below command:
```
php Razy.phar validate distCode
```

Finally, Razy v0.4 also changed the autoloader logic of the `Module` library, now under the `Module` library classes should be under the `Module` namespace:

```php
/**
 * The ABC Module namespace
 */
namespace Razy\Module\distCode\ABC;
namespace Razy\Shared\ABC;

/**
 * The Sample classes under the ABC module
 */
namespace Razy\Module\distCode\ABC\Sample;
namespace Razy\Shared\ABC\Sample;
```

Above changes are completely isolate the `composer package`, `custom classes`, `distributor classes` and `module classes` to prevent any mismatch version classes conflict, class name conflict, confusing and coding management confusion.

Additionally, Razy v0.4 support Psr-0 autoload now.

## Open/Close Principle, SOLID design

The following table is the injection and the path between each class.

### Core

| |Application|Domain|Distributor|Module|Controller *
|---|:---:|:---:|:---:|:---:|:---:|
|connect()|⁕|←|←|←|←|
|trigger()| | |⁕|←|←|
|api()| | |⁕|←|←|
|handshake()| | |⁕|←|←|
|addRoute()| | |⁕|←|←*|
|addLazyRoute()| | |⁕|←|←*|
|addAPI()| | |⁕|←|←*|
|listen()| | | |⁕|←*|
|getAPI()|→|→|⁕| | |
|query()|→|⁕| | | |

* There is a `Pilot` object used to configure route, API or event on `__onInit()` stage. When the `Controller` method is called, `Module` will bind the closure to `Controller`'s inherited object instead of the abstract class of `Controller`. It can prevent the inherited `Controller` object includes its closure to access private method or property in abstract class.

## Controller Event & Priority
| Event                |Description
|----------------------|---|
| __onValidate(): bool |Trigger after the module is loaded, return false to put the module into the preload stage.
| __onPreload(): bool  |Trigger when the module has returned false in `__onValidate()`. Return false to refuse to enter routing stage.
| __onInit(): bool     |Trigger when all modules are validated, return false to mark the module as unloaded
| __onReady(): void    |When all modules are loaded, API and Event will enable and all the modules will be triggered once.
| __onRoute(): bool    |Only trigger when the module route is matched with the URL query, return false to refuse the matching.
| __onAPICall(): bool  |Only trigger when the module's API is called, return false to refuse the API.
| __onTrigger(): bool  |Only trigger when the module's listening event is called, return false to refuse the event trigger.
| __onError(): void    |Only trigger when the module's throw any error.
