<?php

/*
 * Layout 4: type-first with optional feature identity AFTER the kind segments.
 * The same preset serves App\Models\Invoice and App\Models\Billing\Invoice.
 */
return [
    'roots' => [
        'app' => ['namespace' => 'App\\', 'path' => 'app'],
        'factories' => ['namespace' => 'Database\\Factories\\', 'path' => 'database/factories'],
        'migrations' => ['path' => 'database/migrations'],
    ],
    'dimensions' => ['feature'],
    'excluded' => [
        ['namespace' => 'App\\Models\\Concerns\\', 'path' => 'app/Models/Concerns'],
    ],
    'kinds' => [
        'model' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:model', 'root' => 'app', 'segments' => ['Models', '{feature?}']],
        'controller' => ['shape' => 'class', 'name' => ['suffix' => 'Controller'], 'command' => 'mod:controller', 'root' => 'app', 'segments' => ['Http', 'Controllers', '{feature?}']],
        'request' => ['shape' => 'class', 'name' => ['suffix' => 'Request'], 'command' => 'mod:request', 'root' => 'app', 'segments' => ['Http', 'Requests', '{feature?}']],
        'query' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:query', 'root' => 'app', 'segments' => ['Queries', '{feature?}']],
        'policy' => ['shape' => 'class', 'name' => ['suffix' => 'Policy'], 'command' => 'mod:policy', 'root' => 'app', 'segments' => ['Policies', '{feature?}']],
        'factory' => ['shape' => 'class', 'name' => ['suffix' => 'Factory'], 'command' => 'mod:factory', 'root' => 'factories', 'segments' => ['{feature?}']],
        'migration' => ['shape' => 'file', 'name' => 'timestamped', 'command' => 'mod:migration', 'root' => 'migrations', 'segments' => ['{feature?}']],
    ],
    'relations' => [
        'factory' => ['from' => 'model', 'to' => 'factory', 'scope' => 'same', 'policy' => 'generate'],
        'migration' => ['from' => 'model', 'to' => 'migration', 'scope' => 'same', 'name' => 'explicit', 'policy' => 'generate'],
        'policy' => ['from' => 'model', 'to' => 'policy', 'scope' => 'same', 'policy' => 'reference'],
        'store-request' => ['from' => 'controller', 'to' => 'request', 'scope' => 'same', 'name' => ['prefix' => 'Store'], 'policy' => 'generate'],
    ],
];
