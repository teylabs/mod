# Module translations

With the `modules` layout, each existing `app/Modules/<Module>/lang/` folder loads automatically, independently of class discovery. Create language files using Laravel's package convention:

```php
// app/Modules/Inventory/lang/en/messages.php
return ['saved' => 'Inventory saved.'];
```

```php
__('inventory::messages.saved'); // Inventory saved.
```

Namespaces follow the same rule as module views: kebab case for each group segment, joined with dots. `AgentTools` uses `agent-tools`; a nested `Knowledge/Search` group uses `knowledge.search`. Groups without a `lang/` folder register nothing. Existing application or package namespaces keep ownership; a clash logs a warning and skips that group's PHP and JSON registration. If two group names produce the same namespace, the first folder in path order wins and the other warns.

## Declaring a different folder

Use `lang:` on `frontend()` to choose a project-relative path, using the same placeholders and anchors as views. A relocated module path follows automatically:

```php
use Tey\Mod\Facades\Mod;

Mod::layout('areas')->extends('modules')->path('src/Areas/{area}');
// src/Areas/Inventory/lang/en/messages.php -> inventory::messages.saved

Mod::layout('modules')->frontend(lang: '@module/translations');
```

The other built-in layouts, including `ddd`, do not declare a language folder. Opt in on the layout you use:

```php
Mod::layout('ddd')->frontend(lang: 'app/Modules/{domain}/lang');
// app/Modules/Knowledge/Search/lang/en/messages.php -> knowledge.search::messages.saved
```

Mod creates no language files or scaffolds. It probes the declared path segments, listing immediate children only for group placeholders, and memoizes the result for one application scope. Nested dimensions may expand nested group folders; translation files and locale folders are never scanned for registration.

## Application overrides and JSON

Laravel's file loader handles locales, fallback and package overrides. To override selected module keys, add `lang/vendor/inventory/en/messages.php` in the application's language path. Keys absent from that file still come from the module. This works without publishing anything.

A module can also ship `lang/en.json`. JSON keys use Laravel's global syntax, such as `__('Inventory saved.')`; they have no module namespace. Application JSON files take precedence. Between modules, a later folder in path order wins for a duplicate JSON key, matching Laravel's JSON path merging. Use distinct phrases when modules need distinct translations. PHP namespace collisions skip the corresponding JSON path too.

These behaviours follow [Laravel's package language-file conventions](https://laravel.com/docs/12.x/packages#language-files).

## Inventory and caching

`php artisan mod:list --json` includes `frontend.lang` and a `translations` list containing each folder's `group`, `namespace` and `path`. The list describes declared folders; an existing namespace owner can still prevent registration.

`php artisan mod:cache` (also run by `optimize`) stores the translation folder inventory in the existing discovery cache. `mod:clear` / `optimize:clear` remove it. These commands retain their existing requirement that Mod commands and class discovery are enabled. Translation registration itself also works with discovery disabled.

A warm translation folder cache avoids directory listing. Changing the layout or language path invalidates it. Translation contents are loaded by Laravel, rather than copied into Mod's cache; adding a module language folder requires rebuilding or clearing the folder cache. Restart long-lived workers after changing translations, as their translator may already hold loaded messages.
