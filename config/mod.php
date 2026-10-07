<?php

/*
 * Provisional configuration for tey/mod (M2). The public preset syntax is
 * decided after the M2 evidence; until then `preset` takes the internal
 * definition format documented in Tey\Mod\Preset\PresetValidator.
 */
return [

    /*
     * Register the built-in mod:* generator commands. A host that owns its
     * own artisan catalog turns this off and no mod:* command is registered.
     */
    'commands' => true,

    /*
     * The application's layout: an internal preset definition array, or the
     * class name of a Tey\Mod\Generation\PresetSource. Defaults to ordinary
     * Laravel, where mod:* places files exactly like make:*.
     */
    'preset' => [
        'roots' => [
            'app' => ['namespace' => 'App\\', 'path' => 'app'],
            'factories' => ['namespace' => 'Database\\Factories\\', 'path' => 'database/factories'],
            'seeders' => ['namespace' => 'Database\\Seeders\\', 'path' => 'database/seeders'],
            'migrations' => ['path' => 'database/migrations'],
        ],
        'dimensions' => [],
        'kinds' => [
            'model' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:model', 'root' => 'app', 'segments' => ['Models']],
            'controller' => ['shape' => 'class', 'name' => ['suffix' => 'Controller'], 'command' => 'mod:controller', 'root' => 'app', 'segments' => ['Http', 'Controllers']],
            'request' => ['shape' => 'class', 'name' => ['suffix' => 'Request'], 'command' => 'mod:request', 'root' => 'app', 'segments' => ['Http', 'Requests']],
            'factory' => ['shape' => 'class', 'name' => ['suffix' => 'Factory'], 'command' => 'mod:factory', 'root' => 'factories', 'segments' => []],
            'seeder' => ['shape' => 'class', 'name' => ['suffix' => 'Seeder'], 'command' => 'mod:seeder', 'root' => 'seeders', 'segments' => []],
            'policy' => ['shape' => 'class', 'name' => ['suffix' => 'Policy'], 'command' => 'mod:policy', 'root' => 'app', 'segments' => ['Policies']],
            'provider' => ['shape' => 'class', 'name' => ['suffix' => 'ServiceProvider'], 'command' => 'mod:provider', 'root' => 'app', 'segments' => ['Providers']],
            'command' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:command', 'root' => 'app', 'segments' => ['Console', 'Commands']],
            'event' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:event', 'root' => 'app', 'segments' => ['Events']],
            'listener' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:listener', 'root' => 'app', 'segments' => ['Listeners']],
            'migration' => ['shape' => 'file', 'name' => 'timestamped', 'command' => 'mod:migration', 'root' => 'migrations', 'segments' => []],
        ],
        'relations' => [
            'factory' => ['from' => 'model', 'to' => 'factory', 'scope' => 'same', 'policy' => 'generate'],
            'seeder' => ['from' => 'model', 'to' => 'seeder', 'scope' => 'same', 'policy' => 'generate'],
            'policy' => ['from' => 'model', 'to' => 'policy', 'scope' => 'same', 'policy' => 'generate'],
            'controller' => ['from' => 'model', 'to' => 'controller', 'scope' => 'same', 'policy' => 'generate'],
            'migration' => ['from' => 'model', 'to' => 'migration', 'scope' => 'same', 'name' => 'explicit', 'policy' => 'generate'],
            'model' => ['from' => 'factory', 'to' => 'model', 'scope' => 'same', 'policy' => 'reference'],
            'store-request' => ['from' => 'controller', 'to' => 'request', 'scope' => 'same', 'name' => ['prefix' => 'Store'], 'policy' => 'generate'],
            'update-request' => ['from' => 'controller', 'to' => 'request', 'scope' => 'same', 'name' => ['prefix' => 'Update'], 'policy' => 'generate'],
        ],
    ],

    /*
     * Generator adapters by kind id, replacing or adding to the built-in
     * ones. A class-shaped kind without an adapter gets the declarative
     * generic generator; its stub is stubs/mod.<kind>.stub when published.
     */
    'generators' => [],

    /*
     * Runtime discovery of the providers, Artisan commands and listeners the
     * preset places. Kinds with id provider/command/listener are discovered
     * by default; map other kind ids to a type, or false to skip one.
     * Production: `php artisan optimize` writes the cache (mod:discovery-cache).
     */
    'discovery' => [
        'enabled' => true,
        'kinds' => [],
        'cache' => 'bootstrap/cache/mod-discovery.php',
        // 'fail' refuses a stale or foreign cache; 'scan' scans instead (never rewrites the file).
        'on_stale_cache' => 'fail',
    ],

];
