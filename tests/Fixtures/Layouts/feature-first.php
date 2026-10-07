<?php

/*
 * Layout 2: feature-first. Everything of a feature under app/Features/<Feature>;
 * shared infrastructure outside it has its own rules or is excluded.
 */
return [
    'roots' => [
        'app' => ['namespace' => 'App\\', 'path' => 'app'],
    ],
    'dimensions' => ['feature'],
    'excluded' => [
        ['namespace' => 'App\\Support\\', 'path' => 'app/Support'],
    ],
    'kinds' => [
        'model' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:model', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Models']],
        'controller' => ['shape' => 'class', 'name' => ['suffix' => 'Controller'], 'command' => 'mod:controller', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Http', 'Controllers']],
        'request' => ['shape' => 'class', 'name' => ['suffix' => 'Request'], 'command' => 'mod:request', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Http', 'Requests']],
        'policy' => ['shape' => 'class', 'name' => ['suffix' => 'Policy'], 'command' => 'mod:policy', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Policies']],
        'factory' => ['shape' => 'class', 'name' => ['suffix' => 'Factory'], 'command' => 'mod:factory', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Database', 'Factories']],
        'query' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:query', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Queries']],
        'validator' => ['shape' => 'class', 'name' => ['suffix' => 'Validator'], 'command' => 'mod:validator', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Validation']],
        'provider' => ['shape' => 'class', 'name' => ['suffix' => 'ServiceProvider'], 'command' => 'mod:provider', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Providers']],
        'migration' => ['shape' => 'file', 'name' => 'timestamped', 'command' => 'mod:migration', 'root' => 'app', 'segments' => ['Features', '{feature}', 'Database', 'Migrations']],
        'command' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:command', 'root' => 'app', 'segments' => ['Console', 'Commands']],
    ],
    'relations' => [
        'factory' => ['from' => 'model', 'to' => 'factory', 'scope' => 'same', 'policy' => 'generate'],
        'migration' => ['from' => 'model', 'to' => 'migration', 'scope' => 'same', 'name' => 'explicit', 'policy' => 'generate'],
        'policy' => ['from' => 'model', 'to' => 'policy', 'scope' => 'same', 'policy' => 'reference'],
        'store-request' => ['from' => 'controller', 'to' => 'request', 'scope' => 'same', 'name' => ['prefix' => 'Store'], 'policy' => 'generate'],
    ],
];
