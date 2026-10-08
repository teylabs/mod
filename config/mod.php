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
     * type-first, modules, ddd) or any name defined with Mod::layout() in a
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
     * The class a kind's generated classes extend, by kind id. Null (the
     * default) lets mod decide: a supported package when it is installed
     * (spatie/laravel-data for DTOs, spatie/laravel-view-models for view
     * models, lorisleiva/laravel-actions for actions), else a base class it
     * writes into the app on first use.
     */
    'bases' => [
        'dto' => null,
        'view-model' => null,
        'value-object' => null,
        'action' => null,
    ],

    /*
     * Where those generated base classes go: app/Support/Data/DataTransferObject.php,
     * app/Support/ViewModels/ViewModel.php. The ddd layout keeps them in
     * src/Domain/Shared instead. `php artisan mod:bases` writes any that are
     * missing, for example after copying a module from another app.
     */
    'bases_path' => 'app/Support',

    /*
     * Runtime discovery of the providers, Artisan commands and listeners the
     * layout places. Kinds with id provider/command/listener are discovered
     * by default; map other kind ids to a type, or false to skip one.
     * Production: `php artisan optimize` writes the cache (mod:cache).
     */
    'discovery' => [
        'enabled' => true,
        'kinds' => [],
        'cache' => 'bootstrap/cache/mod-discovery.php',
        // 'scan' ignores a stale or foreign cache file, scans instead (never rewriting the file) and
        // warns; 'fail' refuses to boot until the cache is rebuilt (mod:cache) or removed.
        'on_stale_cache' => 'scan',
        // Models placed by the layout get their factory (Model::factory(), no newFactory() needed)
        // and their policy (Gate::policy) through the layout's factory and policy relations.
        'factories' => true,
        'policies' => true,
    ],

];
