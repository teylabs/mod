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
        return ['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd'];
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
            'ddd' => $this->ddd($layout),
            default => null,
        };

        return in_array($name, $this->names(), true);
    }

    private function laravel(Layout $layout): Layout
    {
        $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('model', in: 'Models')
                ->kind('controller', in: 'Http/Controllers', suffix: 'Controller')
                ->kind('request', in: 'Http/Requests', suffix: 'Request')
                ->kind('policy', in: 'Policies', suffix: 'Policy')
                ->kind('provider', in: 'Providers', suffix: 'ServiceProvider')
                ->kind('command', in: 'Console/Commands')
                ->kind('event', in: 'Events')
                ->kind('listener', in: 'Listeners')
                ->kind('job', in: 'Jobs')
                ->kind('job-middleware', in: 'Jobs/Middleware')
                ->kind('mail', in: 'Mail')
                ->kind('notification', in: 'Notifications')
                ->kind('resource', in: 'Http/Resources')
                ->kind('middleware', in: 'Http/Middleware')
                ->kind('rule', in: 'Rules')
                ->kind('observer', in: 'Observers')
                ->kind('cast', in: 'Casts')
                ->kind('scope', in: 'Models/Scopes')
                ->kind('enum', in: 'Enums')
                ->kind('exception', in: 'Exceptions')
                ->kind('channel', in: 'Broadcasting')
                ->kind('class', in: '', priority: -10)
                ->kind('interface', in: '', priority: -11)
                ->kind('trait', in: '', priority: -12))
            ->root('factories', 'Database\\Factories\\', 'database/factories', fn (Root $root) => $root
                ->kind('factory', in: '', suffix: 'Factory'))
            ->root('seeders', 'Database\\Seeders\\', 'database/seeders', fn (Root $root) => $root
                ->kind('seeder', in: '', suffix: 'Seeder'))
            ->root('migrations', null, 'database/migrations', fn (Root $root) => $root
                ->kind('migration', in: '', timestamped: true))
            ->root('config', null, 'config', fn (Root $root) => $root
                ->kind('config', in: '', using: fn (Kind $kind) => $kind->file()))
            ->root('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->kind('test', in: 'Feature'));

        return $this->companions($layout);
    }

    private function features(Layout $layout): Layout
    {
        $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('model', in: 'Features/{feature}/Models')
                ->kind('controller', in: 'Features/{feature}/Http/Controllers', suffix: 'Controller')
                ->kind('request', in: 'Features/{feature}/Http/Requests', suffix: 'Request')
                ->kind('policy', in: 'Features/{feature}/Policies', suffix: 'Policy')
                ->kind('provider', in: 'Features/{feature}/Providers', suffix: 'ServiceProvider')
                ->kind('command', in: 'Features/{feature}/Console/Commands', ungrouped: 'Console/Commands')
                ->kind('event', in: 'Features/{feature}/Events')
                ->kind('listener', in: 'Features/{feature}/Listeners')
                ->kind('job', in: 'Features/{feature}/Jobs')
                ->kind('job-middleware', in: 'Features/{feature}/Jobs/Middleware')
                ->kind('mail', in: 'Features/{feature}/Mail')
                ->kind('notification', in: 'Features/{feature}/Notifications')
                ->kind('resource', in: 'Features/{feature}/Http/Resources')
                ->kind('middleware', in: 'Features/{feature}/Http/Middleware')
                ->kind('rule', in: 'Features/{feature}/Rules')
                ->kind('observer', in: 'Features/{feature}/Observers')
                ->kind('cast', in: 'Features/{feature}/Casts')
                ->kind('scope', in: 'Features/{feature}/Scopes')
                ->kind('enum', in: 'Features/{feature}/Enums')
                ->kind('exception', in: 'Features/{feature}/Exceptions')
                ->kind('channel', in: 'Features/{feature}/Broadcasting')
                ->kind('class', in: 'Features/{feature}', priority: -10)
                ->kind('interface', in: 'Features/{feature}', priority: -11)
                ->kind('trait', in: 'Features/{feature}', priority: -12)
                ->kind('factory', in: 'Features/{feature}/Database/Factories', suffix: 'Factory')
                ->kind('seeder', in: 'Features/{feature}/Database/Seeders', suffix: 'Seeder')
                ->kind('migration', in: 'Features/{feature}/Database/Migrations', timestamped: true)
                ->kind('query', in: 'Features/{feature}/Queries')
                ->kind('validator', in: 'Features/{feature}/Validation', suffix: 'Validator'))
            ->root('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->kind('test', in: 'Feature/{feature}'));

        return $this->companions($layout)
            ->exclude('App\\Support\\');
    }

    private function slices(Layout $layout): Layout
    {
        $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('message', in: '{feature}/{slice}', fixed: 'Command')
                ->kind('handler', in: '{feature}/{slice}', fixed: 'Handler')
                ->kind('request', in: '{feature}/{slice}', fixed: 'Request')
                ->kind('validator', in: '{feature}/{slice}', fixed: 'Validator')
                ->kind('query', in: '{feature}/{slice}', fixed: 'Query')
                ->kind('model', in: '{feature}/Models')
                ->kind('controller', in: '{feature}/Http/Controllers', suffix: 'Controller')
                ->kind('policy', in: '{feature}/Policies', suffix: 'Policy')
                ->kind('provider', in: '{feature}/Providers', suffix: 'ServiceProvider')
                ->kind('command', in: '{feature}/Console/Commands', ungrouped: 'Console/Commands')
                ->kind('event', in: '{feature}/Events')
                ->kind('listener', in: '{feature}/Listeners')
                ->kind('job', in: '{feature}/Jobs')
                ->kind('job-middleware', in: '{feature}/Jobs/Middleware')
                ->kind('mail', in: '{feature}/Mail')
                ->kind('notification', in: '{feature}/Notifications')
                ->kind('resource', in: '{feature}/Http/Resources')
                ->kind('middleware', in: '{feature}/Http/Middleware')
                ->kind('rule', in: '{feature}/Rules')
                ->kind('observer', in: '{feature}/Observers')
                ->kind('cast', in: '{feature}/Casts')
                ->kind('scope', in: '{feature}/Scopes')
                ->kind('enum', in: '{feature}/Enums')
                ->kind('exception', in: '{feature}/Exceptions')
                ->kind('channel', in: '{feature}/Broadcasting')
                ->kind('class', in: '{feature}', priority: -10)
                ->kind('interface', in: '{feature}', priority: -11)
                ->kind('trait', in: '{feature}', priority: -12)
                ->kind('factory', in: '{feature}/Database/Factories', suffix: 'Factory')
                ->kind('seeder', in: '{feature}/Database/Seeders', suffix: 'Seeder')
                ->kind('migration', in: '{feature}/Database/Migrations', timestamped: true))
            ->root('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->kind('test', in: 'Feature/{feature}/{slice?}'));

        $this->companions($layout, singleRequest: true)
            ->relation('controller-store-request', scope: ['keep' => ['feature'], 'name' => 'slice'])
            ->relation('model-store-request', scope: ['keep' => ['feature'], 'name' => 'slice'])
            ->relation('handler-request', from: 'handler', to: 'request')
            ->relation('request-model', from: 'request', to: 'model', scope: ['feature'], name: 'explicit', mode: 'reference');

        return $layout
            ->exclude('App\\Http\\', 'App\\Providers\\', 'App\\Support\\');
    }

    private function typeFirst(Layout $layout): Layout
    {
        $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('model', in: 'Models/{feature?}')
                ->kind('controller', in: 'Http/Controllers/{feature?}', suffix: 'Controller')
                ->kind('request', in: 'Http/Requests/{feature?}', suffix: 'Request')
                ->kind('policy', in: 'Policies/{feature?}', suffix: 'Policy')
                ->kind('provider', in: 'Providers/{feature?}', suffix: 'ServiceProvider')
                ->kind('command', in: 'Console/Commands/{feature?}')
                ->kind('event', in: 'Events/{feature?}')
                ->kind('listener', in: 'Listeners/{feature?}')
                ->kind('job', in: 'Jobs/{feature?}')
                ->kind('job-middleware', in: 'Jobs/Middleware/{feature?}')
                ->kind('mail', in: 'Mail/{feature?}')
                ->kind('notification', in: 'Notifications/{feature?}')
                ->kind('resource', in: 'Http/Resources/{feature?}')
                ->kind('middleware', in: 'Http/Middleware/{feature?}')
                ->kind('rule', in: 'Rules/{feature?}')
                ->kind('observer', in: 'Observers/{feature?}')
                ->kind('cast', in: 'Casts/{feature?}')
                ->kind('scope', in: 'Models/Scopes/{feature?}')
                ->kind('enum', in: 'Enums/{feature?}')
                ->kind('exception', in: 'Exceptions/{feature?}')
                ->kind('channel', in: 'Broadcasting/{feature?}')
                ->kind('class', in: '{feature?}', priority: -10)
                ->kind('interface', in: '{feature?}', priority: -11)
                ->kind('trait', in: '{feature?}', priority: -12)
                ->kind('query', in: 'Queries/{feature?}'))
            ->root('factories', 'Database\\Factories\\', 'database/factories', fn (Root $root) => $root
                ->kind('factory', in: '{feature?}', suffix: 'Factory'))
            ->root('seeders', 'Database\\Seeders\\', 'database/seeders', fn (Root $root) => $root
                ->kind('seeder', in: '{feature?}', suffix: 'Seeder'))
            ->root('migrations', null, 'database/migrations', fn (Root $root) => $root
                ->kind('migration', in: '{feature?}', timestamped: true))
            ->root('config', null, 'config', fn (Root $root) => $root
                ->kind('config', in: '', using: fn (Kind $kind) => $kind->file()))
            ->root('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->kind('test', in: 'Feature/{feature?}'));

        return $this->companions($layout)
            ->exclude('App\\Models\\Concerns\\');
    }

    private function modules(Layout $layout): Layout
    {
        $layout
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('model', in: 'Modules/{module}/Models')
                ->kind('controller', in: 'Modules/{module}/Controllers', suffix: 'Controller')
                ->kind('request', in: 'Modules/{module}/Requests', suffix: 'Request')
                ->kind('policy', in: 'Modules/{module}/Policies', suffix: 'Policy')
                ->kind('provider', in: 'Modules/{module}/Providers', suffix: 'ServiceProvider')
                ->kind('command', in: 'Modules/{module}/Console')
                ->kind('event', in: 'Modules/{module}/Events')
                ->kind('listener', in: 'Modules/{module}/Listeners')
                ->kind('job', in: 'Modules/{module}/Jobs')
                ->kind('job-middleware', in: 'Modules/{module}/Jobs/Middleware')
                ->kind('mail', in: 'Modules/{module}/Mail')
                ->kind('notification', in: 'Modules/{module}/Notifications')
                ->kind('resource', in: 'Modules/{module}/Resources')
                ->kind('middleware', in: 'Modules/{module}/Middleware')
                ->kind('rule', in: 'Modules/{module}/Rules')
                ->kind('observer', in: 'Modules/{module}/Observers')
                ->kind('cast', in: 'Modules/{module}/Casts')
                ->kind('scope', in: 'Modules/{module}/Scopes')
                ->kind('enum', in: 'Modules/{module}/Enums')
                ->kind('exception', in: 'Modules/{module}/Exceptions')
                ->kind('channel', in: 'Modules/{module}/Channels')
                ->kind('class', in: 'Modules/{module}', priority: -10)
                ->kind('interface', in: 'Modules/{module}', priority: -11)
                ->kind('trait', in: 'Modules/{module}', priority: -12)
                ->kind('factory', in: 'Modules/{module}/Database/Factories', suffix: 'Factory')
                ->kind('seeder', in: 'Modules/{module}/Database/Seeders', suffix: 'Seeder')
                ->kind('migration', in: 'Modules/{module}/Database/Migrations', timestamped: true)
                ->kind('action', in: 'Modules/{module}/Actions')
                ->kind('dto', in: 'Modules/{module}/Data', label: 'DTO', aliases: ['mod:data'])
                ->kind('value-object', in: 'Modules/{module}/ValueObjects', label: 'Value object', command: 'mod:value')
                ->kind('view-model', in: 'Modules/{module}/ViewModels', label: 'View model')
                ->kind('query', in: 'Modules/{module}/Queries'))
            ->root('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->kind('test', in: 'Feature/Modules/{module}'));

        return $this->companions($layout)
            ->exclude('App\\UI\\', 'App\\Support\\');
    }

    /**
     * laravel-ddd's defaults: domain objects in src/Domain/<Domain>, application
     * objects (controllers, requests, middleware) in app/Modules/<Domain>.
     * Subdomains nest: --domain=Reporting.Internal. Bases stay in
     * src/Domain/Shared, where laravel-ddd puts them.
     */
    private function ddd(Layout $layout): Layout
    {
        $layout
            ->root('domain', 'Domain\\', 'src/Domain', fn (Root $root) => $root
                ->kind('model', in: '{domain+}/Models')
                ->kind('dto', in: '{domain+}/Data', label: 'DTO', aliases: ['mod:data-transfer-object', 'mod:datatransferobject', 'mod:data'], stub: Starters::dto(inKindRoot: 'Shared/Data'))
                ->kind('value-object', in: '{domain+}/ValueObjects', label: 'Value object', command: 'mod:value', aliases: ['mod:value-object', 'mod:valueobject'])
                ->kind('view-model', in: '{domain+}/ViewModels', label: 'View model', aliases: ['mod:viewmodel'], stub: Starters::viewModel(inKindRoot: 'Shared/ViewModels'))
                ->kind('action', in: '{domain+}/Actions', label: 'Action')
                ->kind('cast', in: '{domain+}/Casts')
                ->kind('channel', in: '{domain+}/Channels')
                ->kind('command', in: '{domain+}/Commands')
                ->kind('enum', in: '{domain+}/Enums')
                ->kind('event', in: '{domain+}/Events')
                ->kind('exception', in: '{domain+}/Exceptions')
                ->kind('factory', in: '{domain+}/Database/Factories', suffix: 'Factory')
                ->kind('job', in: '{domain+}/Jobs')
                ->kind('job-middleware', in: '{domain+}/Jobs/Middleware')
                ->kind('listener', in: '{domain+}/Listeners')
                ->kind('mail', in: '{domain+}/Mail')
                ->kind('migration', in: '{domain+}/Database/Migrations', timestamped: true)
                ->kind('notification', in: '{domain+}/Notifications')
                ->kind('observer', in: '{domain+}/Observers')
                ->kind('policy', in: '{domain+}/Policies', suffix: 'Policy')
                ->kind('provider', in: '{domain+}/Providers')
                ->kind('resource', in: '{domain+}/Resources')
                ->kind('rule', in: '{domain+}/Rules')
                ->kind('scope', in: '{domain+}/Scopes')
                ->kind('seeder', in: '{domain+}/Database/Seeders', suffix: 'Seeder')
                ->kind('class', in: '{domain+}', priority: -10)
                ->kind('interface', in: '{domain+}', priority: -11)
                ->kind('trait', in: '{domain+}', priority: -12))
            ->root('application', 'App\\Modules\\', 'app/Modules', fn (Root $root) => $root
                ->kind('controller', in: '{domain+}/Controllers', suffix: 'Controller')
                ->kind('request', in: '{domain+}/Requests', suffix: 'Request')
                ->kind('middleware', in: '{domain+}/Middleware'))
            ->root('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->kind('test', in: 'Feature/{domain+}'));

        return $this->companions($layout);
    }

    private function companions(Layout $layout, bool $singleRequest = false): Layout
    {
        $layout
            ->relation('model-factory', from: 'model', to: 'factory')
            ->relation('model-seeder', from: 'model', to: 'seeder')
            ->relation('model-policy', from: 'model', to: 'policy')
            ->relation('model-controller', from: 'model', to: 'controller')
            ->relation('model-migration', from: 'model', to: 'migration', name: 'explicit')
            ->relation('factory-model', from: 'factory', to: 'model', mode: 'reference')
            ->relation('listener-event', from: 'listener', to: 'event', name: 'explicit', mode: 'reference')
            ->relation('controller-store-request', from: 'controller', to: 'request', name: ['prefix' => 'Store'])
            ->relation('model-store-request', from: 'model', to: 'request', name: ['prefix' => 'Store']);

        if (! $singleRequest) {
            $layout
                ->relation('controller-update-request', from: 'controller', to: 'request', name: ['prefix' => 'Update'])
                ->relation('model-update-request', from: 'model', to: 'request', name: ['prefix' => 'Update']);
        }

        return $layout;
    }
}
