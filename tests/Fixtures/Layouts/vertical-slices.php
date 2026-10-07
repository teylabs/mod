<?php

/*
 * Layout 3: vertical slices by use case. Slice kinds have fixed basenames
 * (Command, Handler, Request, ...); feature-scoped kinds (model, factory,
 * policy, migrations) take no slice. A genuine Artisan command lives in a
 * distinct declared root.
 */
return [
    'roots' => [
        'app' => ['namespace' => 'App\\', 'path' => 'app'],
        'console' => ['namespace' => 'App\\Console\\Commands\\', 'path' => 'app/Console/Commands'],
    ],
    'dimensions' => ['feature', 'slice'],
    'excluded' => [
        ['namespace' => 'App\\Http\\', 'path' => 'app/Http'],
        ['namespace' => 'App\\Providers\\', 'path' => 'app/Providers'],
        ['namespace' => 'App\\Support\\', 'path' => 'app/Support'],
    ],
    'kinds' => [
        'message' => ['shape' => 'class', 'name' => ['fixed' => 'Command'], 'command' => 'mod:message', 'root' => 'app', 'segments' => ['{feature}', '{slice}']],
        'handler' => ['shape' => 'class', 'name' => ['fixed' => 'Handler'], 'command' => 'mod:handler', 'root' => 'app', 'segments' => ['{feature}', '{slice}']],
        'request' => ['shape' => 'class', 'name' => ['fixed' => 'Request'], 'command' => 'mod:request', 'root' => 'app', 'segments' => ['{feature}', '{slice}']],
        'validator' => ['shape' => 'class', 'name' => ['fixed' => 'Validator'], 'command' => 'mod:validator', 'root' => 'app', 'segments' => ['{feature}', '{slice}']],
        'query' => ['shape' => 'class', 'name' => ['fixed' => 'Query'], 'command' => 'mod:query', 'root' => 'app', 'segments' => ['{feature}', '{slice}']],
        'model' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:model', 'root' => 'app', 'segments' => ['{feature}', 'Models']],
        'factory' => ['shape' => 'class', 'name' => ['suffix' => 'Factory'], 'command' => 'mod:factory', 'root' => 'app', 'segments' => ['{feature}', 'Database', 'Factories']],
        'policy' => ['shape' => 'class', 'name' => ['suffix' => 'Policy'], 'command' => 'mod:policy', 'root' => 'app', 'segments' => ['{feature}', 'Policies']],
        'migration' => ['shape' => 'file', 'name' => 'timestamped', 'command' => 'mod:migration', 'root' => 'app', 'segments' => ['{feature}', 'Database', 'Migrations']],
        'command' => ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:command', 'root' => 'console', 'segments' => []],
    ],
    'relations' => [
        'request' => ['from' => 'handler', 'to' => 'request', 'scope' => 'same', 'policy' => 'generate'],
        'model' => ['from' => 'request', 'to' => 'model', 'scope' => ['keep' => ['feature']], 'name' => 'explicit', 'policy' => 'reference'],
        'factory' => ['from' => 'model', 'to' => 'factory', 'scope' => 'same', 'policy' => 'generate'],
        'migration' => ['from' => 'model', 'to' => 'migration', 'scope' => 'same', 'name' => 'explicit', 'policy' => 'generate'],
    ],
];
