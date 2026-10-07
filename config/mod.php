<?php

/*
 * Configuration for tey/mod.
 */
return [

    /*
     * Register the built-in mod:* generator commands. A host that owns its
     * own artisan catalog turns this off and no mod:* command is registered.
     */
    'commands' => true,

    /*
     * The application's layout: a built-in one (laravel, features, slices,
     * type-first, modules) or any name defined with Mod::layout() in a
     * service provider. Built-in layouts can be extended the same way:
     *
     *     Mod::layout('modules')->kind('job', in: 'Modules/{module}/Jobs');
     *
     * The default, laravel, places files exactly like make:*.
     */
    'layout' => 'laravel',

    /*
     * Generator adapters by kind id, replacing or adding to the built-in
     * ones. A class-shaped kind without an adapter gets the declarative
     * generic generator; its stub is stubs/mod.<kind>.stub when published.
     */
    'generators' => [],

    /*
     * Runtime discovery of the providers, Artisan commands and listeners the
     * layout places. Kinds with id provider/command/listener are discovered
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
