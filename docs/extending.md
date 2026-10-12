# Extending Mod

A package can build on mod to add file types and commands to a layout, ship generator templates and scaffold recipes, and use another package's base class when it is installed.

## Writing a mod plugin

A mod plugin is an ordinary Laravel package whose service provider calls the `Mod` facade in `boot()`. Require `tey/mod` in its `composer.json`:

```bash
composer require tey/mod
```

Everything below goes in that provider. Mod reads it when Artisan starts, so the order of providers doesn't matter.

<a id="adding-file-types-commands-and-aliases"></a>

### Adding file types and commands

Customize a built-in layout with `Mod::layout()`. A new file type gets a `mod:<type>` command; `command:` renames it, `aliases:` adds other names and `label:` sets the noun its output uses:

```php
// src/KnowledgeToolsServiceProvider.php
use Tey\Mod\Facades\Mod;

public function boot(): void
{
    Mod::layout('ddd')
        ->generates('builder', in: '{domain+}/Builders', suffix: 'Builder', aliases: ['mod:query-builder'], label: 'Query builder');
}
```

```bash
php artisan mod:builder Knowledge:Document
# ->  INFO  Query builder [src/Domain/Knowledge/Builders/DocumentBuilder.php] created successfully.
php artisan mod:query-builder Knowledge:Chunk
# -> src/Domain/Knowledge/Builders/ChunkBuilder.php
```

- Repeating an existing file type changes only the arguments you pass. Aliases add up: `->generates('dto', aliases: ['mod:payload'])` keeps `mod:data` and the DTO's other aliases.
- Without `label:`, the output names the type's id in title case (`Builder`). File types with a Laravel generator keep Laravel's wording.
- A hyphenated command or alias also gets a dash-free alias, so `mod:query-builder` works as `mod:querybuilder`. When that name is already a command or alias, the existing one keeps it.
- A command or alias that another file type already uses stops the layout from compiling, with an error naming both.
- `Mod::hasLayout('ddd')` checks that a layout exists before customizing it, and `Mod::layouts()` lists every layout name.
- The layout methods are listed in [Defining a layout](layouts.md#defining-a-layout).

### Registering stubs

A file type with no Laravel generator starts as an empty class. Register a stub for it:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;

Mod::stubs()->forFileType('builder', Stub::file(__DIR__.'/../stubs/builder.stub'));
```

```php
<?php
// stubs/builder.stub

namespace {{ namespace }};

use Illuminate\Database\Eloquent\Builder;

class {{ class }} extends Builder
{
    //
}
```

`Mod::stubs()->forFileType()` works for any file type, including those with a Laravel generator. The stub that is used is the first that exists:

1. the application's `stubs/mod.<type>.stub`;
2. the stub registered with `Mod::stubs()->forFileType()` (the last registration wins);
3. the stub the layout declares (`generates(..., stub: ...)`);
4. the starter for the file type's id: `dto`, `view-model`, `value-object` or `action` (see [Starter stubs](layouts.md#starter-stubs));
5. the Laravel generator's stub, or mod's empty class.

A file type with another id uses a starter through `stub:`, such as `->generates('payload', in: '{domain+}/Payloads', stub: Starters::dto())` with `use Tey\Mod\Generation\Starters;`.

| Placeholder | Filled with |
| --- | --- |
| `{{ namespace }}`, `{{ class }}` | the class's namespace and short name |
| `{{ base }}`, `{{ baseClass }}` | the base class's full and short name |
| `{{ baseImport }}` | its `use` line, or nothing when there is no base |
| `{{ extends }}` | ` extends <baseClass>`, or nothing when there is no base |

### Using another package when it is installed

A stub can name variants. The first whose package is installed (`whenInstalled`) or whose class exists (`whenClass`) supplies the base class, the stub, or both:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;

Mod::stubs()->forFileType('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
    ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
    ->whenClass('App\\Support\\BaseData', stub: __DIR__.'/../stubs/dto.app.stub'));
```

```bash
php artisan mod:dto Knowledge:DocumentData
# ->  INFO  Using spatie/laravel-data (installed).
```

`stubs/dto.stub` uses `{{ baseImport }}` and `{{ extends }}`, so one file serves every variant:

```php
<?php
// stubs/dto.stub

namespace {{ namespace }};
{{ baseImport }}
class {{ class }}{{ extends }}
{
    public function __construct(
        //
    ) {}
}
```

An explicit base wins over every variant. The application sets one in `config/mod.php` (`'bases' => ['dto' => ...]`); a plugin can read its own config key and give a default with `->base(config: 'knowledge.base_dto', class: 'App\\Support\\BaseData')`.

### Generating a base class

When no variant applies, a stub can write a base class into the application the first time it is used. The base goes in the application's bases folder, `app/Support` by default:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\Stub;

Mod::stubs()->forFileType('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
    ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
    ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Data', stub: __DIR__.'/../stubs/bases/data-transfer-object.stub')));
```

```bash
php artisan mod:dto Knowledge:DocumentData
# ->  INFO  Created base class App\Support\Data\DataTransferObject [app/Support/Data/DataTransferObject.php].
# ->  INFO  DTO [app/Modules/Knowledge/Data/DocumentData.php] created successfully.
```

- `in:` is a folder below the bases folder, which the application sets with `bases_path` in `config/mod.php`.
- To place the base below the file type's own root instead, add `->inFileTypeRoot()`. In the `ddd` layout, `GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: ...)->inFileTypeRoot()` writes `src/Domain/Shared/Data/DataTransferObject.php`.
- The base stub fills `{{ namespace }}` and `{{ class }}`. The application can replace it by publishing `stubs/mod.base.data-transfer-object.stub` (the base's name in kebab-case).
- Once the file exists, the application owns it: mod never overwrites it, even with `--force`. `mod:bases` writes it when it is missing.
- Stubs must not use mod's own classes, so the generated code runs without mod installed.

### Swapping a generator

Replace the command behind a file type with `Mod::generators()->useFileType()`. Extend the adapter it replaces: `GenericClassCommand` for file types with no Laravel generator, or the matching command such as `ModelCommand`:

```php
<?php
// src/Commands/BuilderCommand.php

namespace Knowledge\Tools\Commands;

use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Generation\GenerationPlan;

class BuilderCommand extends GenericClassCommand
{
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void
    {
        $this->components->info('Add a newEloquentBuilder() method to the model to use it.');
    }
}
```

```php
use Knowledge\Tools\Commands\BuilderCommand;
use Tey\Mod\Facades\Mod;

Mod::generators()->useFileType('builder', BuilderCommand::class);
```

The protected hooks an adapter offers are listed in [Building your own generators](#building-your-own-generators).

## Example: a DDD plugin

The provider below is the shape of [laravel-ddd](https://github.com/teylabs/laravel-ddd) on mod. It keeps laravel-ddd's own config keys for base classes, adds a file type the built-in layout doesn't have, and ships its own stubs:

```php
<?php
// src/DddServiceProvider.php

namespace Vendor\Ddd;

use Illuminate\Support\ServiceProvider;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\Stub;

class DddServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mod::layout('ddd')
            ->generates('builder', in: '{domain+}/Builders', suffix: 'Builder');

        Mod::stubs()
            ->for('builder', Stub::file(__DIR__.'/../stubs/builder.stub'))
            ->for('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
                ->base(config: 'ddd.base_dto')
                ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
                ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: __DIR__.'/../stubs/bases/data-transfer-object.stub')->inFileTypeRoot()))
            ->for('view-model', Stub::file(__DIR__.'/../stubs/view-model.stub')
                ->base(config: 'ddd.base_view_model')
                ->whenInstalled('spatie/laravel-view-models', base: 'Spatie\\ViewModels\\ViewModel'));
    }
}
```

```bash
php artisan mod:builder Knowledge:Document
# -> src/Domain/Knowledge/Builders/DocumentBuilder.php
php artisan mod:view-model Knowledge:ShowDocument
# with ddd.base_view_model set to Domain\Shared\ViewModels\ViewModel:
# ->  INFO  Using the configured base Domain\Shared\ViewModels\ViewModel.
```

## Building your own generators

Every `mod:*` command is a subclass of the matching Laravel command, such as `Tey\Mod\Commands\ModelCommand`, `ControllerCommand`, `RequestCommand`, `FactoryCommand` and `MigrationCommand`. A package with its own command catalog can extend these instead of the native commands and override a few protected hooks:

| Hook | Use |
| --- | --- |
| `placementInput()` | the placement in `--in` syntax, for example from your own option or prompt |
| `placementContext()` | the placement context the command resolves against |
| `placementOptions()` | which placement options the command adds: option name => the dimension it sets, with `null` for `--in`. Return `[]` to add none; related commands then receive the `Group:Name` form |
| `resolveLayout()`, `fileTypeId()` | the layout and file type, for commands not registered through the layout |
| `layout()`, `fileType()` | read the compiled layout and the command's file type from inside a hook |
| `stubDefinition()` | the `Stub` the class is generated from: by default the one registered with `Mod::stubs()`, else the layout's |
| `collisionPolicy()` | `CollisionPolicy::Refuse` (mod checks every file before writing) or `CollisionPolicy::Native` (the native generator's own check and `--force` decide) |
| `plansEagerly()`, `resolvePlan()` | plan from inside your own `handle()` |
| `beforeGeneration(GenerationPlan $plan)`, `afterGeneration(GenerationPlan $plan, int $exitCode)` | run code around generation |
| `nativePathAllowed()` | on `MigrationCommand`: let `--path` and `--realpath` through |
| `reportRefusal(ModException $e)`, `reportReference(ResolvedArtifact $target)` | the only places the commands print on their own |

These hooks and the additional hooks in [Building on mod](#building-on-mod) are the API a subclass can rely on. All unlisted protected methods are internal.

`Mod::current()` returns the active layout, compiled (`Tey\Mod\Layout\CompiledLayout`): `dimensionNames()` lists its dimensions in order, and `placementOptions()` maps each one to its option name.

Every exception mod throws extends `Tey\Mod\Exceptions\ModException`. `UnknownFileType` names a file type the layout doesn't have, `InvalidName` a class name the file type can't take, and `InvalidLayout` a layout that isn't defined, a `mod.layout` value that isn't a layout name, or a layout definition with errors.

## Turning commands off

A package or application with its own Artisan commands can keep mod's placement and discovery without the `mod:*` commands:

```php
// config/mod.php
'commands' => false,
```

Or turn them off for one layout with `Mod::layout('mine')->withoutCommands()`. Its file types keep their `command:` names for a host to dispatch by, and several file types may then share one. The discovery cache commands are registered only while the `mod:*` commands and discovery are both on.

## Shipping generator templates

Register a folder from the package's provider. Use the neutral `@group` anchor so the generated folder follows the application's layout:

```php
// src/ToolsServiceProvider.php: imports at file scope, registration in boot().
use Tey\Mod\Facades\Mod;

Mod::stubs()->folder(__DIR__.'/../stubs/mod');
```

For example, `stubs/mod/@group/Tools/tool.stub` gives the application a `mod:tool` command:

```php
<?php
// stubs/mod/@group/Tools/tool.stub

namespace {{ namespace }};

class {{ class }}
{
    //
}
```

With the `modules` layout:

```bash
php artisan mod:tool Knowledge:SearchDocuments --no-interaction
# -> app/Modules/Knowledge/Tools/SearchDocuments.php
```

The application's template takes precedence. Two packages claiming the same command disable only that command with a warning naming both; the other commands keep working.

## Shipping scaffolds

Packages declare their vendor once on a scoped registrar. It accepts lowercase letters, digits and hyphens, starting with a letter or digit. Multiple packages may share a vendor.

```php
Mod::vendor('acme')->scaffold('pages', fn (Scaffold $s) => $s
    ->makes('page', name: '{name}/Index', stub: 'list'));
Mod::vendor('acme')->scaffolds([Pages::class, Filters::class]);
Mod::vendor('acme')->scaffold('pages', fn (Scaffold $s) => $s->makes('page'), command: 'kit:pages');
```

The default command is `mod:acme/pages`; parts use `mod:acme/pages.form`. Dots mean declared parts. `command:` opts into one canonical Artisan name, with the same options, questions and messages; it adds no alias. An existing command is never replaced. Invokable classes declare `$name`, and may declare a string `$command`.

Test members can use Pest or PHPUnit stubs. Collision checks use the file path for the `test` file type and every member under a test root, without loading the test through Composer. Rerunning a scaffold reports existing files as usual: `--skip-existing` keeps them, `--force` overwrites them, and interactive runs offer keep, overwrite or cancel. Other PHP members check their paths and autoload their class identities, so a class declared elsewhere is still a collision even when it has not been loaded yet.

A root scaffold can declare a default name so its command runs bare:

```php
Mod::vendor('acme')->scaffold('pages', fn (Scaffold $s) => $s
    ->named('Acme')
    ->makes('page', name: '{name}/Index'), command: 'acme:pages');
```

`php artisan acme:pages` uses `Acme`; `acme:pages Billing` uses `Billing`. The `name` argument becomes optional, `--help` shows the default, and `--dry-run --json` reports the resolved name. Recipes without `named()` still require the name. Default names belong to root scaffolds. A part takes its root scaffold's name; calling `named()` on a part throws `GenerationRefused` and asks you to call it on the root scaffold. The default applies to the root recipe that declares it, not to recipes that `include()` it.

Use full ids in `include('acme/pages')`, layout scaffold overrides and `mod:rename --scaffold=acme/pages`. App scaffolds may contain slashes. An app definition with the same id wins and emits `vendor_scaffold_overridden`. An app name under a registered vendor prefix that the vendor does not supply emits `vendor_prefix_shadowed`, suggests a hyphenated name and asks to continue (default Yes). Non-interactive runs emit the notice on stderr and continue. Ordinary unprefixed app names and prefixes without registered vendors have no notice.

Two packages supplying the same id emit `vendor_scaffold_conflict`. An interactive invocation chooses a provider for that run; non-interactive invocation refuses (exit 1, including JSON previews) unless you pin the provider or define the scaffold in your app:

```php
// config/mod.php
'scaffolds' => ['prefer' => ['acme/pages' => AcmeServiceProvider::class]],
```

A pin selects only a registered provider; the app still wins. Includes and rename require a deterministic definition (a pin or app override) when packages conflict. `mod:list --json` supplies `vendor`, `name` (after the vendor slash), `id`, `command`, provider `source`, Composer `package` when known, `overrides` and structured `notices`. `mod:list --vendor=acme` filters scaffolds in both formats, including notices. Unknown command names use Artisan's suggestions; an undeclared dot part is disabled with a diagnostic.

### Owning a frontend folder

A recipe can own a subtree, including files added by hand after generation:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;

Mod::vendor('acme')->scaffold('resource-pages', fn (Scaffold $s) => $s
    ->folder('pages', '{name}', as: 'pageFolder')
    ->makes('page', name: '{name}/Index/Page', as: 'indexPage'));
```

The first argument is a configured frontend path key: `pages`, `components` or `views`. The name is a non-empty relative subtree below that path, resolved with the recipe's name and answers in its group. Only plain-file roots are supported; folders cannot own PHP class roots or paths outside the project. Includes and parts retain folder members and their aliases.

Generation writes no directory for a folder member and does not change the placement of file members inside it. `--dry-run --json` lists folder declarations under optional `folders`, with `alias`, `type` (the path key) and resolved `path`; they are not generated `files`. `mod:list` shows them in recipe trees and JSON as `type: "folder"`, with `path_key` and the folder pattern.

[`mod:rename`](renaming.md#owned-frontend-folders) moves the whole subtree once, including layouts, partials and undeclared pages. A recipe without `folder()` keeps its existing member-only behaviour. Declare page-entry stems on the layout when only files such as `Page.vue` and `Modal.vue` are render targets; folder ownership does not make helpers into page entries.

### Supplying member stub variants

A member's `stub: 'list'` uses a key such as `page.list`. Register extension-specific variants for each supported frontend stack:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;

Mod::stubs()->forFileType('page.list.vue', Stub::file(__DIR__.'/../stubs/list.vue.stub'));
Mod::stubs()->forFileType('page.list.tsx', Stub::file(__DIR__.'/../stubs/list.tsx.stub'));
Mod::stubs()->forFileType('page.list.jsx', Stub::file(__DIR__.'/../stubs/list.jsx.stub'));
```

For a planned `.tsx` page, lookup is the app's `stubs/mod.page.list.tsx.stub`, then the registered `page.list.tsx`, then the registered `page.list` fallback. The app's stub wins. Use the extension-free key only for content that works across stacks. Another stack's key is never used: without a matching variant or fallback the plan names the stub to supply and writes nothing. The same precedence applies to flat recipes, tree members and parts, and to any file type with an extension (for example `view.card.blade.php`).

### Scaffold placeholders

Member stubs and insert stubs share these values, including inside template blocks:

| Placeholder | Filled with |
| --- | --- |
| `{{ name }}` | the scaffold's name, such as `TypedPage`; an ungrouped member uses its own name |
| `{{ group }}` | the target group's name as placed, such as `Showroom`, or `Knowledge/Search` for a nested group |
| `{{ module }}`, `{{ domain }}` | the layout token's value: `module` on `modules`, `domain` on `ddd`, or your custom token on a custom layout |
| `{{ group.kebab }}`, `{{ group.snake }}` | `show-room`, `show_room` for `ShowRoom` |
| `{{ group.studly }}`, `{{ group.camel }}`, `{{ group.lower }}` | `ShowRoom`, `showRoom`, `showroom` for `ShowRoom` |
| `{{ model }}`, `{{ model.fqcn }}` | a member alias's short or fully qualified class name |
| `{{ answer }}` | a question's answer, using the question's name |

The token alias supports the same transforms as `group`; transforms chain as they do for `name`. For example, `{{ module.kebab }}.{{ name.kebab }}.index` produces `showroom.typed-page.index` for `Showroom:TypedPage`. Nested groups keep their `/` separators. In a layout with several dimensions, each token has its own value and `group` names the innermost dimension.

A member uses its own resolved group, including a `group:` override. An insert into a member uses that target member's group; route and anchored-path inserts use the node's group. `group` and its token alias render empty for `ungrouped: true` members and inserts into them. In a layout without groups, `group` renders empty and there is no token alias.

A question or member named `group` takes precedence over the built-in value, as names do today. The same applies to a name matching a token alias. A record field with the same name hides the outer value inside its template block.

### Asking for lists of records

A `records` question collects a list of objects with declared fields: a table's columns, a filter's options.

```php
$s->asks('columns', type: 'records', label: 'Column', shape: [
    'key' => 'string', 'label' => 'string', 'sortable' => 'bool',
], default: [
    ['key' => 'name', 'label' => 'Name', 'sortable' => true],
]);
```

- `shape` maps each field to `string`, `bool` or `int`. It is required, and field names are letters, digits and underscores.
- With a terminal, mod asks for each field of each record, then "Add another?". The default's records are offered first. A default of `[]` asks "Add a Column record?" with No.
- Without a terminal, pass JSON: `--columns='[{"key":"name","label":"Name","sortable":true}]'`. Without the flag the recipe default is used; with neither the command refuses and names the flag. `--columns='[]'` is an empty list.
- Every record must carry exactly the declared fields, with the declared types. Strings cannot be empty, booleans are JSON `true` or `false`, and integers are whole numbers. A bad value refuses before anything is planned (`--dry-run --json` reports it in `warnings`). A bad `default` throws when the recipe is declared.
- Records are normalized to the shape's field order, so the same answers always produce the same output.
- `{{ columns.json }}` prints the list as JSON. To repeat template lines for each record, see "Template blocks" below.

`mod:rename --scaffold=` takes the same JSON flag, or `--answer=columns=<JSON>`.

### Printing a list as a literal

A `list` or `records` answer prints as source code, on one line:

| Placeholder | Output for `['name', 'company']` |
| --- | --- |
| `{{ cols.php }}` | `['name', 'company']` |
| `{{ cols.ts }}` or `{{ cols.js }}` | `['name', 'company']`, or `["name", "company"]` |
| `{{ cols.json }}` | `["name","company"]` |

Records print as keyed arrays in PHP (`['key' => 'name', 'sortable' => true]`) and as objects in TypeScript and JavaScript (`{ key: 'name', sortable: true }`).

The script forms follow the app's Prettier `singleQuote`. mod reads it as text from `package.json` (`"prettier"`) or the first Prettier config file found, in Prettier's own order: `.prettierrc`, `.prettierrc.json`, `.yaml`, `.yml`, `.json5`, `.js`, `.ts`, `.mjs`, `.cjs`, `prettier.config.*`, then `.prettierrc.toml`. It never runs a config file, so a computed or `overrides` setting is not seen. Without a setting the default is double quotes, as in Prettier. A string takes the other quote when it holds more of the preferred one, as Prettier does.

The literal is always one line, which Prettier keeps only while it fits the print width. For long lists, repeat a template block per item instead.

### Optional members

`when:` on `makes()` leaves a member out of the plan. It takes the name of a `confirm` question, or a closure that receives the answers and returns a boolean:

```php
$s->asks('modal', type: 'confirm', default: false)
    ->makes('page', name: '{name}/Modal', as: 'modal', when: 'modal')
    ->makes('policy', when: fn (array $answers) => $answers['authorize'] ?? true);
```

- A skipped member is not planned or written. `--dry-run --json` lists it under `skipped` as `{alias, type, reason}`, for example `{"alias":"modal","type":"page","reason":"modal is false"}`. A closure's reason is `when() returned false`. `skipped` appears for any recipe with a `when:`, as an empty list when nothing was left out. The text plan shows `skipped: <reason>` lines.
- Inserts into a skipped member are skipped too, and listed with type `insert`.
- A placeholder naming a skipped member, such as `{{ modal }}` or `{{ modal.fqcn }}`, renders as nothing. `{{ #modal }}…{{ /modal }}` renders only when the member is generated (see "Template blocks"). A `with:` value built from it is empty.
- `when:` can also name an earlier member's alias, so one optional member can depend on another. A name that is neither a confirm question nor an earlier member disables a root recipe with a message; in a part it refuses when the part runs.
- A confirm question gives `--modal` and `--no-modal`. Without a terminal and without a default, the command refuses and names both flags. The answers a closure receives are the recipe's question answers, plus the parent's answers inside a part. Aliases are not included.
- A question and a member may share a name, as `modal` does above. `{{ #modal }}` then follows the boolean, which agrees with whether the member is generated. Give the member a different `as:` to use `{{ modal.fqcn }}`, because a question's answer wins over a member of the same name in placeholders.
- `mod:rename --scaffold=` evaluates the same condition against the answers you pass, so a skipped member is absent from the cluster, not missing. Pass `--modal` or `--no-modal` like any other historical answer.

### Template blocks

Stubs and insert stubs can repeat lines for each record, or keep a section only when a boolean is true. A tag is `{{ #name }}` to open, `{{ ^name }}` to open an inverted block, and `{{ /name }}` to close.

```vue
<tr>
    {{ #columns }}
    <th>{{ label }}</th>
    {{ /columns }}
</tr>
```

- `{{ #name }}` repeats over a `records` answer. Inside, each record's fields are placeholders (`{{ key }}`, `{{ key.studly }}`), and every outer placeholder stays reachable. A field with the same name as an outer value, such as `name`, hides it inside the block.
- `{{ #name }}` over a boolean renders once when it is true. A `confirm` question is a boolean, and so is a record's `bool` field. Over a member alias it renders when the member is generated.
- `{{ ^name }}` renders when a boolean is false or a list is empty, so `{{ ^columns }}No columns{{ /columns }}` is the empty state.
- An empty list renders nothing. Blocks nest, and an inner block sees the outer block's fields.
- A tag alone on its line leaves nothing behind, not even a blank line.
- A block's lines take the indentation of the line the opening tag is on, whatever indentation the stub gave them. The body's own common indentation is removed first, so relative indentation is kept. Write the body flush with the tag, or indented inside it, and the output is the same. Blank lines carry no whitespace.
- Tags that share a line with other text render inline, with no whitespace changes: `<ul>{{ #columns }}<li>{{ key }}</li>{{ /columns }}</ul>`. Both tags of a block must be alone on their lines, or neither.
- An inline repeat takes `sep="…"` to put text between its items, never after the last: `'in:{{ #sorts sep="," }}{{ value }}{{ /sorts }}'` renders `'in:name,-name,company'`, and `type Sort = {{ #sorts sep=" | " }}'{{ value }}'{{ /sorts }};` renders `type Sort = 'name' | '-name' | 'company';`. A single item gets no separator and an empty list renders nothing. The separator is literal text in double quotes, so it cannot hold a `"`, and it applies to inline blocks only: a block with its tags alone on their lines, a boolean, an inverted block or a closing tag refuses `sep`, as does an unclosed quote or any other option.
- Line endings follow the stub. The same answers always produce the same bytes, so an app can regenerate a file and diff it.
- A misspelt block name, an unclosed block, malformed options, or a block over text or a plain `list` refuses before anything is written. Use `@{{ #name }}` to print the tag literally in a `.vue` or Blade file.

Stub text is written to be formatter-clean after substitution. Keep each line of a block valid for Pint or Prettier on its own, and choose a layout where the formatter's wrapping does not depend on the data (for example, one element per line in PHP arrays). A `.vue` or `.php` fixture in mod's own tests runs both formatters over the output.

### Upgrading package scaffolds from 0.5

Unprefixed package registration is refused with `vendor_required`. Replace `Mod::scaffold('pages', $recipe)` with `Mod::vendor('acme')->scaffold('pages', $recipe)`, and `Mod::scaffolds($recipes)` with `Mod::vendor('acme')->scaffolds($recipes)`. Update command calls from `mod:pages` to `mod:acme/pages`, and include, override and rename references from `pages` to `acme/pages`. App registrations keep their existing names.

## Building on mod

Packages can own their layout, discovery lifecycle and generator commands while using Mod's placement engine. Use only members marked `@api`. A public PHP method or property marked `@internal` is not a compatibility promise, even when its class is public. The published signatures, parameter names and nested value types are pinned in `tests/Fixtures/published-api.json`.

### Isolated layouts and public values

`Layout::fresh(string $name): Layout` starts with shipped defaults for a built-in name or an empty definition otherwise. It does not read or mutate app customisations. `extends()` resolves built-ins in its private registry; use `Mod::layout()` for application definitions. Call `compiled(): CompiledLayout` to compile without sealing the mutable definition. Repeated compilation produces independent values.

```php
use Tey\Mod\Layout\Layout;
use Tey\Mod\Placement\PlacementContext;

$definition = Layout::fresh('ddd');
$layout = $definition->compiled();
$invoice = $layout->place('model', 'Invoice', PlacementContext::of(['domain' => 'Billing/Internal']));
$owned = $layout->locate('Domain\\Billing\\Internal\\Models\\Invoice');
```

`CompiledLayout::place(string $fileType, string $name, PlacementContext $context, array $attributes = []): ResolvedArtifact` performs placement without writing or checking collisions. Attributes are `array<string,string|int|float|bool|null>`. `locate(string $fqcn): ?ResolvedArtifact` returns null for an unowned or ambiguous class; ownership does not prove existence.

Inspect `ResolvedArtifact::$fileType`, `$context`, `$name`, `$nested`, `fqcn(): ?string`, `namespace(): ?string`, `path(): string` and `nestedName(): string`. `namespace()` returns null for files and an empty string for global-namespace classes. For schema callbacks, `ResolvedArtifact::phpClass(string $fileType, string $namespace, string $basename, string $path, ?PlacementContext $context = null): self` preserves the chosen identity verbatim and normalises the path; it does not reapply naming policy or infer child commands.

`Artifact\CompiledFileType` is the immutable compiled metadata; `Layout\FileType` remains the mutable builder. The compiled value exposes `id`, `command`, `aliases`, `label`, `extension`, `case`, `isClass(): bool` and `isTimestamped(): bool`. Use `CompiledLayout::fileTypes(): array<string,CompiledFileType>`, `fileType(string $fileType): CompiledFileType` and `hasFileType(string $fileType): bool`. The old `ArtifactKind`, `kind()`, `kinds()`, `hasKind()` and artifact `$kind` remain internal.

Enumerate `array_keys($layout->fileTypes())` when disabling discovery. Resolve `artifact->path()` against the host base with its path utilities, retaining absolute paths and Windows drive paths. Do not inspect placement rules, identity objects, naming policies or `ExistingArtifacts`. A host with only a `domain` dimension formats its child option from `context->get('domain')`, replacing `/` with `.`, and retains its existing fallback when absent.

### Host discovery and cache policy

```php
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;

$discovery = new Discovery(layout: $layout, options: DiscoveryOptions::fromConfig([
    'cache' => 'bootstrap/cache/host-discovery.php',
    'file_types' => ['provider' => 'provider'],
]), basePath: $app->basePath());

try {
    $inventory = $discovery->readCache();
} catch (InvalidDiscoveryCache $exception) {
    $inventory = $discovery->scan(); // The host chooses its own fallback policy.
}
$providers = $inventory->ofType(DiscoveryType::Provider);
```

The constructor is `Discovery(CompiledLayout $layout, DiscoveryOptions $options, string $basePath)`. `scan(): Inventory` is a cold scan. `readCache(): Inventory` validates the existing cache and throws `InvalidDiscoveryCache` for missing, foreign, stale or malformed data. It never scans or writes, and leaves memoised application discovery state alone. `cacheInventory(): Inventory` explicitly cold-scans and writes the current cache format with no scaffold payload. Use a host-owned cache path; writing a shared app cache would replace its scaffold payload. Rebuild on deployment when a custom candidate callback changes: its fingerprint records presence, not closure contents.

`Inventory::ofType(DiscoveryType $type): array` returns `list<DiscoveredArtifact>` in inventory order; `directories(string $fileType): array` returns sorted directory paths. Entries expose `fileType`, `type`, `class`, `path`, `context: array<string,string>`, `events: list<array{event:string,method:string}>` and `target: ?string`. Preserve existing path representation; directory entries have an empty class. Rejections, payload serialisation, cache objects, fingerprint helpers and listener registration remain internal.

`DiscoveryType` is the string-backed enum `Provider`, `Command`, `Listener`, `Subscriber`, `Directory`, `Factory`, `Policy`. Use its standard `from()`, `tryFrom()` and `cases()`. `DiscoveryDefinition::forFileType(string $fileType, DiscoveryType $type, bool $enabled = true): self` constructs public candidate metadata; inspect `$fileType`, `$type` and `$enabled`. Existing definition factories with old parameter names are internal. `DiscoveryOptions::withCandidates()` accepts `Closure(CompiledRoot, string, DiscoveryDefinition): iterable<string>`; candidates do not override ownership or eligibility. Use `file_types` in configuration.

### Generator support without app feature boot

A host Laravel provider calls `ModServiceProvider::registerGenerationServices($app)` in `register()`. It supplies adapter defaults with `bindIf`, preserves host bindings and is safe to call repeatedly. It registers no Mod commands, active layout, routes, views or discovered providers/listeners. Laravel's application/files/config services are prerequisites; a bare container is insufficient for the application-dependent factories.

The host supplies its own compiled layout through `resolveLayout()` or `layout()`, and its file type id through `fileTypeId()`. Normal stubs, package variants, generated bases and model migration companions work without registering the full provider. Module-owned generator template rebinding requires the full provider and app layouts. Do not depend on the internal registry/template selection helpers for isolated layouts.

Register a package stub using `Mod::stubs()->forFileType(string $fileType, Stub $stub)` and a generator replacement using `Mod::generators()->useFileType(string $fileType, string $command)`. Old registration methods with internal vocabulary remain callable for compatibility but are not published API.

### Rendering into another root

Hosts render scaffolds through their commands. To write a comparison copy, give the scaffold and every member command a Laravel application whose `basePath()` is the destination, for example a separate copy of the host application passed through `setLaravel()` and a shared console application. Keep the running application's root unchanged. The destination needs the Composer manifest and any app-owned stubs used by the recipe.

When that destination differs from the running application's `basePath()`, an autoloadable class declared outside it is not a collision. A class declared elsewhere inside the destination still collides. Existing destination files still require `--skip-existing` or `--force`; force overwrites paths, never class-name collisions. Paths are compared with resolved filesystem roots, so another spelling or symlink to the application root does not bypass its protection. Normal in-app generation still refuses classes declared elsewhere, and test members remain path-only checks without autoloading test files.

### Supported planning and dispatch hooks

These are protected hooks on the public command subclasses. Concern traits are internal implementation locations, not host mixins.

| Hook | Supported purpose |
| --- | --- |
| `plan(): GenerationPlan` | Class adapters: build a candidate with a custom primary. |
| `currentPlan(): ?GenerationPlan` | Inspect an already-resolved invocation without causing planning. |
| `plannedRelations(ResolvedArtifact $primary): array` | Select native option relations or supply host-placed request targets; returns `list<RelationResolution>`. |
| `plannedRelation(string $relationId): ?RelationResolution` | Read the running plan by id; hosts may supply a prefixed-id fallback. |
| `relatedFileType(string $role): string` | Map a native model/factory/request/migration role to a canonical file type id; defaults to the input. |
| `followRelation(RelationResolution $resolution, array $arguments = []): void` | Generate a related target or report a reference; arguments are `array<string,mixed>`. |
| `argumentsFor(ResolvedArtifact $target): array` | Format child name and placement options; returns `array<string,mixed>`. |
| `generateOwnedClass(string $fqcn, string $fileType): void` | Override missing model/parent dispatch; the file type id is already canonical. |

`fileType(): CompiledFileType` and `fileTypeId(): string` replace the old internal metadata hooks. Existing documented layout, placement, stub, collision, timing and reporting hooks stay supported. `plannedRelationsTo()`, `relationsTo()`, `placeSibling()`, `inOption()` and collision/preview/scaffold helpers remain internal. Native adapters map roles once at their call boundaries, including migration preflight. Remove translator overrides of those internal helpers. Hosts inspect `currentPlan()?->relations ?? []` and filter by public `relation->toFileType` instead.

```php
protected function relatedFileType(string $role): string
{
    return 'domain-'.$role;
}

protected function plan(): GenerationPlan
{
    $primary = $this->blueprint->artifact();
    return new GenerationPlan($primary, $this->plannedRelations($primary));
}
```

`plan()` and `plannedRelations()` can run more than once while group answers settle; they must not write files. For input preparation in `handle()`, return false from `plansEagerly()` and call `resolvePlan()` after preparation. Resolution remains idempotent per invocation and performs Mod's collision checks. `currentPlan()` is null before resolution and after invocation cleanup. Do not substitute it with a call that would resolve input or prompt too early.

`GenerationPlan` exposes `primary` and `relations: list<RelationResolution>` and accepts those public values in its constructor. Relations expose `id`, `fromFileType`, `toFileType`, `mode`; use `CompiledLayout::relations()`, `relation(string $relationId)` or `relationsFrom(string $fileType)`. To change an inherited mode, call `relates($relation->fromFileType, $relation->toFileType, mode: 'none', as: $relation->id)`. Scope/name engines remain internal.

For controller store/update forwarding, place targets through the host's request file type, then return `RelationResolution::resolved($relation, $primary, $target)` from `plannedRelations()`. A resolution exposes `relation`, `source`, `target`, `isResolved()` and `mode()`. Override `plannedRelation()` for custom relation ids and `followRelation()`/`argumentsFor()` for host dispatch. Retain generation/reference semantics and non-zero child failure propagation. Calling the parent preserves Mod's related-generation scope; bypassing it makes the host responsible for that scope and recursion policy.

### Migration creators

`MigrationCommand::__construct(Illuminate\Database\Migrations\MigrationCreator $creator, Illuminate\Support\Composer $composer)` accepts the framework creator directly. Mod adapts an exact framework creator internally, preserving filesystem and custom stub path, and pins planned timestamps. Existing internal Mod creators still work. Native `--path`/`--realpath` require the supported `nativePathAllowed(): bool` opt-in.

An application creator subclass keeps its actual native dispatch and bypasses Mod planning independently of `--path`. It has no Mod plan and does not run plan-specific callbacks. A host can retain its domain directory override using only the public framework creator classification; do not reflect into or construct a Mod creator. Arbitrary creator output cannot be promised to match a Mod timestamp/path plan.

## Installed package generators

When `laravel/mcp` is installed, file types named `mcp-tool`, `mcp-server`, `mcp-resource` and `mcp-prompt` use explicit adapters for the package's own generators. They retain native stub selection (including published `stubs/mcp-*.stub`), placeholder replacements and options. Mod places the class and checks its plan before writing. App-owned generator registrations still take precedence.

The `modules` layout adds `@module/Mcp/Tools`, `Servers`, `Resources` and `Prompts`; `ddd` puts these application-facing classes in `app/Modules/<Domain>/Mcp/...`, including nested domains. For example, `php artisan mod:mcp-tool Inventory:Probe --dry-run --json` previews the tool without writing it. Extend the layout to choose other folders with `->generates('mcp-tool', in: '@module/Protocol/Tools')`.

Mod does not automatically wrap arbitrary installed `make:*` commands. Their constructors, typed overrides and companion writes need a reviewed adapter. Unsupported ids retain the generic declarative class generator; matching a native command name alone does not imply native output. In particular, `mcp-app-resource` is not adapted because its native command also writes a Blade view. Use that native command directly or register an application-owned adapter that plans and collision-checks both files. Mod ships no MCP stub copies or scaffold recipes.

### Structured plan warnings

Every `--dry-run --json` warning keeps `file`, `line` and `message`, and adds `code` and an object `data`. `missing_placement` provides `file_type`, `dimension`, accepted `options` and available group `choices`. `generation_refused` reports another blocking refusal; `advisory` is non-blocking; `routes_not_loaded` reminds you to wire routes. Codes and data are additive: consumers should tolerate new codes and fields, and display `message` for people. Missing placement messages name the running command's option, `--in` and the `Group:Name` short form.

### Declaring package frontend paths

Packages can call `Mod::pages()` and `Mod::wiring()` unconditionally from their
providers; see [package pages and wiring](frontend.md#package-pages-and-wiring).
Composer path repositories installed as symlinks or Windows directory junctions are supported. Use installed
`vendor/<vendor>/<package>/...` paths for aliases, sources and styles. Page roots
may use `__DIR__.'/../resources/js/pages'`: Mod maps the package's real location
back through its installed symlink and keeps lexical vendor paths in page entries
and installer edits.

Unusable declarations are skipped without interrupting boot. Inspect
`frontend_notices` in `mod:list --json`, or the notices printed by `mod:install`.
Each inventory notice contains a stable `code`, `path` and `message`; installer
JSON warnings carry the same code with `data.path`. Literal paths outside the
application and symlink escapes outside `vendor/` remain refused. TypeScript
aliases map both the bare import and `/*` subpaths, using normal directory index
resolution for the bare import.
