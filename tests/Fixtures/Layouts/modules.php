<?php

/*
 * Layout 5: app/Modules/<Module>. Flat familiar folders
 * (Controllers/ beside Models/, no Http/), Database/{Factories,Seeders,Migrations},
 * a routes file per module. app/UI and app/Support are siblings, not modules.
 */
return [
    'roots' => [
        'app' => ['namespace' => 'App\\', 'path' => 'app'],
    ],
    'dimensions' => ['module'],
    'excluded' => [
        ['namespace' => 'App\\UI\\', 'path' => 'app/UI'],
        ['namespace' => 'App\\Support\\', 'path' => 'app/Support'],
    ],
    'kinds' => [
        'model' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:model', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Models']],
        'controller' => ['shape' => 'class', 'name' => ['suffix' => 'Controller'], 'command' => 'mod:controller', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Controllers']],
        'request' => ['shape' => 'class', 'name' => ['suffix' => 'Request'], 'command' => 'mod:request', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Requests']],
        'policy' => ['shape' => 'class', 'name' => ['suffix' => 'Policy'], 'command' => 'mod:policy', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Policies']],
        'provider' => ['shape' => 'class', 'name' => ['suffix' => 'ServiceProvider'], 'command' => 'mod:provider', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Providers']],
        'event' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:event', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Events']],
        'action' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:action', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Actions']],
        'data' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:data', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Data']],
        'query' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:query', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Queries']],
        'factory' => ['shape' => 'class', 'name' => ['suffix' => 'Factory'], 'command' => 'mod:factory', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Database', 'Factories']],
        'seeder' => ['shape' => 'class', 'name' => ['suffix' => 'Seeder'], 'command' => 'mod:seeder', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Database', 'Seeders']],
        'migration' => ['shape' => 'file', 'name' => 'timestamped', 'command' => 'mod:migration', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Database', 'Migrations']],
        'routes' => ['shape' => 'file', 'name' => 'as-given', 'command' => 'mod:routes', 'root' => 'app', 'segments' => ['Modules', '{module}', 'routes']],
    ],
    'relations' => [
        'factory' => ['from' => 'model', 'to' => 'factory', 'scope' => 'same', 'policy' => 'generate'],
        'migration' => ['from' => 'model', 'to' => 'migration', 'scope' => 'same', 'name' => 'explicit', 'policy' => 'generate'],
        'policy' => ['from' => 'model', 'to' => 'policy', 'scope' => 'same', 'policy' => 'reference'],
        'store-request' => ['from' => 'controller', 'to' => 'request', 'scope' => 'same', 'name' => ['prefix' => 'Store'], 'policy' => 'generate'],
        'update-request' => ['from' => 'controller', 'to' => 'request', 'scope' => 'same', 'name' => ['prefix' => 'Update'], 'policy' => 'generate'],
    ],
];
