<?php

/*
 * Built-in layout `laravel` (the default): ordinary Laravel, mod:* places
 * files exactly like make:*. The M2 default configuration, kept as the
 * reference the built-in is checked against.
 */
return [
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
];
