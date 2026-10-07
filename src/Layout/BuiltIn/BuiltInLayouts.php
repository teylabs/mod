<?php

namespace Tey\Mod\Layout\BuiltIn;

use Tey\Mod\Layout\Kind;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\Root;

/**
 * The layouts mod ships, as data written with the public builder.
 *
 * This is the one place in src that names concrete layouts, folders and
 * placeholders; the engine itself stays layout-neutral (see the audit in
 * tests/Unit/Audit/LayoutNeutralityTest.php, which exempts only this folder).
 */
final readonly class BuiltInLayouts
{
    /**
     * @return list<string>
     */
    public function names(): array
    {
        return ['laravel', 'features', 'slices', 'type-first', 'modules'];
    }

    /**
     * Seed a fresh layout with the built-in definition of that name, if there is one.
     */
    public function define(string $name, Layout $layout): bool
    {
        match ($name) {
            'laravel' => $this->laravel($layout),
            'features' => $this->features($layout),
            'slices' => $this->slices($layout),
            'type-first' => $this->typeFirst($layout),
            'modules' => $this->modules($layout),
            default => null,
        };

        return in_array($name, $this->names(), true);
    }

    /**
     * Ordinary Laravel: mod:* places files exactly like make:*.
     */
    private function laravel(Layout $layout): Layout
    {
        return $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('model', in: 'Models')
                ->kind('controller', in: 'Http/Controllers', suffix: 'Controller')
                ->kind('request', in: 'Http/Requests', suffix: 'Request')
                ->kind('policy', in: 'Policies', suffix: 'Policy')
                ->kind('provider', in: 'Providers', suffix: 'ServiceProvider')
                ->kind('command', in: 'Console/Commands')
                ->kind('event', in: 'Events')
                ->kind('listener', in: 'Listeners'))
            ->root('factories', 'Database\\Factories\\', 'database/factories', fn (Root $root) => $root
                ->kind('factory', in: '', suffix: 'Factory'))
            ->root('seeders', 'Database\\Seeders\\', 'database/seeders', fn (Root $root) => $root
                ->kind('seeder', in: '', suffix: 'Seeder'))
            ->root('migrations', null, 'database/migrations', fn (Root $root) => $root
                ->kind('migration', in: '', timestamped: true))
            ->relation('factory', from: 'model', to: 'factory')
            ->relation('seeder', from: 'model', to: 'seeder')
            ->relation('policy', from: 'model', to: 'policy')
            ->relation('controller', from: 'model', to: 'controller')
            ->relation('migration', from: 'model', to: 'migration', name: 'explicit')
            ->relation('model', from: 'factory', to: 'model', policy: 'reference')
            ->relation('store-request', from: 'controller', to: 'request', name: ['prefix' => 'Store'])
            ->relation('update-request', from: 'controller', to: 'request', name: ['prefix' => 'Update']);
    }

    /**
     * Feature-first: everything of a feature under app/Features/<Feature>.
     */
    private function features(Layout $layout): Layout
    {
        return $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('model', in: 'Features/{feature}/Models')
                ->kind('controller', in: 'Features/{feature}/Http/Controllers', suffix: 'Controller')
                ->kind('request', in: 'Features/{feature}/Http/Requests', suffix: 'Request')
                ->kind('policy', in: 'Features/{feature}/Policies', suffix: 'Policy')
                ->kind('factory', in: 'Features/{feature}/Database/Factories', suffix: 'Factory')
                ->kind('query', in: 'Features/{feature}/Queries')
                ->kind('validator', in: 'Features/{feature}/Validation', suffix: 'Validator')
                ->kind('provider', in: 'Features/{feature}/Providers', suffix: 'ServiceProvider')
                ->kind('migration', in: 'Features/{feature}/Database/Migrations', timestamped: true)
                ->kind('command', in: 'Console/Commands'))
            ->relation('factory', from: 'model', to: 'factory')
            ->relation('policy', from: 'model', to: 'policy', policy: 'reference')
            ->relation('store-request', from: 'controller', to: 'request', name: ['prefix' => 'Store'])
            ->exclude('App\\Support\\');
    }

    /**
     * Vertical slices by use case: slice kinds have fixed basenames
     * (Command, Handler, ...); feature-wide kinds take no slice. Genuine
     * Artisan commands live in their own root.
     */
    private function slices(Layout $layout): Layout
    {
        return $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('message', in: '{feature}/{slice}', fixed: 'Command')
                ->kind('handler', in: '{feature}/{slice}', fixed: 'Handler')
                ->kind('request', in: '{feature}/{slice}', fixed: 'Request')
                ->kind('validator', in: '{feature}/{slice}', fixed: 'Validator')
                ->kind('query', in: '{feature}/{slice}', fixed: 'Query')
                ->kind('model', in: '{feature}/Models')
                ->kind('factory', in: '{feature}/Database/Factories', suffix: 'Factory')
                ->kind('policy', in: '{feature}/Policies', suffix: 'Policy')
                ->kind('migration', in: '{feature}/Database/Migrations', timestamped: true))
            ->root('console', 'App\\Console\\Commands\\', 'app/Console/Commands', fn (Root $root) => $root
                ->kind('command', in: ''))
            ->relation('request', from: 'handler', to: 'request')
            ->relation('model', from: 'request', to: 'model', scope: ['feature'], name: 'explicit', policy: 'reference')
            ->relation('factory', from: 'model', to: 'factory')
            ->exclude('App\\Http\\', 'App\\Providers\\', 'App\\Support\\');
    }

    /**
     * Type-first with an optional feature folder after the type folders:
     * App\Models\Invoice and App\Models\Billing\Invoice alike.
     */
    private function typeFirst(Layout $layout): Layout
    {
        return $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('model', in: 'Models/{feature?}')
                ->kind('controller', in: 'Http/Controllers/{feature?}', suffix: 'Controller')
                ->kind('request', in: 'Http/Requests/{feature?}', suffix: 'Request')
                ->kind('query', in: 'Queries/{feature?}')
                ->kind('policy', in: 'Policies/{feature?}', suffix: 'Policy'))
            ->root('factories', 'Database\\Factories\\', 'database/factories', fn (Root $root) => $root
                ->kind('factory', in: '{feature?}', suffix: 'Factory'))
            ->root('migrations', null, 'database/migrations', fn (Root $root) => $root
                ->kind('migration', in: '{feature?}', timestamped: true))
            ->relation('factory', from: 'model', to: 'factory')
            ->relation('policy', from: 'model', to: 'policy', policy: 'reference')
            ->relation('store-request', from: 'controller', to: 'request', name: ['prefix' => 'Store'])
            ->exclude('App\\Models\\Concerns\\');
    }

    /**
     * app/Modules/<Module>: flat familiar folders, a routes
     * file per module; app/UI and app/Support are siblings, not modules.
     */
    private function modules(Layout $layout): Layout
    {
        return $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('model', in: 'Modules/{module}/Models')
                ->kind('controller', in: 'Modules/{module}/Controllers', suffix: 'Controller')
                ->kind('request', in: 'Modules/{module}/Requests', suffix: 'Request')
                ->kind('policy', in: 'Modules/{module}/Policies', suffix: 'Policy')
                ->kind('provider', in: 'Modules/{module}/Providers', suffix: 'ServiceProvider')
                ->kind('event', in: 'Modules/{module}/Events')
                ->kind('action', in: 'Modules/{module}/Actions')
                ->kind('data', in: 'Modules/{module}/Data')
                ->kind('query', in: 'Modules/{module}/Queries')
                ->kind('factory', in: 'Modules/{module}/Database/Factories', suffix: 'Factory')
                ->kind('seeder', in: 'Modules/{module}/Database/Seeders', suffix: 'Seeder')
                ->kind('migration', in: 'Modules/{module}/Database/Migrations', timestamped: true)
                ->kind('routes', in: 'Modules/{module}/routes', using: fn (Kind $kind) => $kind->file()))
            ->relation('factory', from: 'model', to: 'factory')
            ->relation('policy', from: 'model', to: 'policy', policy: 'reference')
            ->relation('store-request', from: 'controller', to: 'request', name: ['prefix' => 'Store'])
            ->relation('update-request', from: 'controller', to: 'request', name: ['prefix' => 'Update'])
            ->exclude('App\\UI\\', 'App\\Support\\');
    }
}
