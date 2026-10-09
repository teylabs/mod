<?php

namespace Tey\Mod\Layout\BuiltIn;

use Tey\Mod\Generation\Starters;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\Root;

/**
 * The layouts mod ships, as data written with the public builder.
 *
 * This is the one place in src that names concrete layouts, folders and
 * placeholders; the engine itself stays layout-neutral (see the audit in
 * tests/Unit/Audit/LayoutNeutralityTest.php, which exempts only this folder).
 *
 * @internal
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

        if (! in_array($name, $this->names(), true)) {
            return false;
        }
        $base = match ($name) {
            'modules' => '@module',
            'features' => '@feature',
            'slices' => '@slice',
            'ddd' => 'app/Modules/{domain}',
            default => '',
        };
        $token = match ($name) {
            'modules' => '{module}',
            'features', 'slices', 'type-first' => '{feature}',
            'ddd' => '{domain}',
            default => '',
        };
        $resources = $base === '' ? 'resources' : $base.'/resources';
        $layout->frontend(pages: $resources.'/js/pages', components: $resources.'/js/components', css: $resources.'/css', views: $name === 'type-first' ? 'resources/views/{feature?}' : $resources.'/views', pageName: $token === '' ? '{path}' : $token.'::{path}')
            ->mounts('routes', null, $base === '' ? 'routes' : $base.'/routes');
        $layout->generates('view', in: 'resources-views:', using: fn (FileType $type) => $type->file());
        $layout->generates('component', in: $name === 'ddd' ? '{domain+}/View/Components' : ($name === 'type-first' ? '@feature/View/Components' : ($base === '' ? 'View/Components' : $base.'/View/Components')), using: fn (FileType $type) => $type->withinRoot($name === 'ddd' ? 'application' : 'app')->nested());
        $layout->mirrorsPages();

        return true;
    }

    private function laravel(Layout $layout): Layout
    {
        $layout->path('app');
        $layout
            ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
                ->generates('model', in: 'Models')
                ->generates('controller', in: 'Http/Controllers', suffix: 'Controller')
                ->generates('request', in: 'Http/Requests', suffix: 'Request')
                ->generates('policy', in: 'Policies', suffix: 'Policy')
                ->generates('provider', in: 'Providers', suffix: 'ServiceProvider')
                ->generates('command', in: 'Console/Commands')
                ->generates('event', in: 'Events')
                ->generates('listener', in: 'Listeners')
                ->generates('job', in: 'Jobs')
                ->generates('job-middleware', in: 'Jobs/Middleware')
                ->generates('mail', in: 'Mail')
                ->generates('notification', in: 'Notifications')
                ->generates('resource', in: 'Http/Resources')
                ->generates('middleware', in: 'Http/Middleware')
                ->generates('rule', in: 'Rules')
                ->generates('observer', in: 'Observers')
                ->generates('cast', in: 'Casts')
                ->generates('scope', in: 'Models/Scopes')
                ->generates('enum', in: 'Enums')
                ->generates('exception', in: 'Exceptions')
                ->generates('channel', in: 'Broadcasting')
                ->generates('class', in: '', priority: -10)
                ->generates('interface', in: '', priority: -11)
                ->generates('trait', in: '', priority: -12))
            ->mounts('factories', 'Database\\Factories\\', 'database/factories', fn (Root $root) => $root
                ->generates('factory', in: '', suffix: 'Factory'))
            ->mounts('seeders', 'Database\\Seeders\\', 'database/seeders', fn (Root $root) => $root
                ->generates('seeder', in: '', suffix: 'Seeder'))
            ->mounts('migrations', null, 'database/migrations', fn (Root $root) => $root
                ->generates('migration', in: '', timestamped: true))
            ->mounts('config', null, 'config', fn (Root $root) => $root
                ->generates('config', in: '', using: fn (FileType $kind) => $kind->file()))
            ->mounts('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->generates('test', in: 'Feature'));

        return $this->companions($layout);
    }

    private function features(Layout $layout): Layout
    {
        $layout->defaultPath('app/Features/{feature}');
        $layout
            ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
                ->generates('model', in: '@feature/Models')
                ->generates('controller', in: '@feature/Http/Controllers', suffix: 'Controller')
                ->generates('request', in: '@feature/Http/Requests', suffix: 'Request')
                ->generates('policy', in: '@feature/Policies', suffix: 'Policy')
                ->generates('provider', in: '@feature/Providers', suffix: 'ServiceProvider')
                ->generates('command', in: '@feature/Console/Commands', ungrouped: 'Console/Commands')
                ->generates('event', in: '@feature/Events')
                ->generates('listener', in: '@feature/Listeners')
                ->generates('job', in: '@feature/Jobs')
                ->generates('job-middleware', in: '@feature/Jobs/Middleware')
                ->generates('mail', in: '@feature/Mail')
                ->generates('notification', in: '@feature/Notifications')
                ->generates('resource', in: '@feature/Http/Resources')
                ->generates('middleware', in: '@feature/Http/Middleware')
                ->generates('rule', in: '@feature/Rules')
                ->generates('observer', in: '@feature/Observers')
                ->generates('cast', in: '@feature/Casts')
                ->generates('scope', in: '@feature/Scopes')
                ->generates('enum', in: '@feature/Enums')
                ->generates('exception', in: '@feature/Exceptions')
                ->generates('channel', in: '@feature/Broadcasting')
                ->generates('class', in: '@feature', priority: -10)
                ->generates('interface', in: '@feature', priority: -11)
                ->generates('trait', in: '@feature', priority: -12)
                ->generates('factory', in: '@feature/Database/Factories', suffix: 'Factory')
                ->generates('seeder', in: '@feature/Database/Seeders', suffix: 'Seeder')
                ->generates('migration', in: '@feature/Database/Migrations', timestamped: true)
                ->generates('query', in: '@feature/Queries')
                ->generates('validator', in: '@feature/Validation', suffix: 'Validator'))
            ->mounts('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->generates('test', in: 'Feature/{feature}'));

        return $this->companions($layout)
            ->excludes('App\\Support\\');
    }

    private function slices(Layout $layout): Layout
    {
        $layout->path('app/{feature}/{slice}');
        $layout
            ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
                ->generates('message', in: '@slice', fixed: 'Command')
                ->generates('handler', in: '@slice', fixed: 'Handler')
                ->generates('request', in: '@slice/Http/Requests', fixed: 'Request')
                ->generates('validator', in: '@slice', fixed: 'Validator')
                ->generates('query', in: '@slice', fixed: 'Query')
                ->generates('model', in: '@feature/Models')
                ->generates('controller', in: '@feature/Http/Controllers', suffix: 'Controller')
                ->generates('policy', in: '@feature/Policies', suffix: 'Policy')
                ->generates('provider', in: '@feature/Providers', suffix: 'ServiceProvider')
                ->generates('command', in: '@feature/Console/Commands', ungrouped: 'Console/Commands')
                ->generates('event', in: '@feature/Events')
                ->generates('listener', in: '@feature/Listeners')
                ->generates('job', in: '@feature/Jobs')
                ->generates('job-middleware', in: '@feature/Jobs/Middleware')
                ->generates('mail', in: '@feature/Mail')
                ->generates('notification', in: '@feature/Notifications')
                ->generates('resource', in: '@feature/Http/Resources')
                ->generates('middleware', in: '@feature/Http/Middleware')
                ->generates('rule', in: '@feature/Rules')
                ->generates('observer', in: '@feature/Observers')
                ->generates('cast', in: '@feature/Casts')
                ->generates('scope', in: '@feature/Scopes')
                ->generates('enum', in: '@feature/Enums')
                ->generates('exception', in: '@feature/Exceptions')
                ->generates('channel', in: '@feature/Broadcasting')
                ->generates('class', in: '{feature}', priority: -10)
                ->generates('interface', in: '{feature}', priority: -11)
                ->generates('trait', in: '{feature}', priority: -12)
                ->generates('factory', in: '@feature/Database/Factories', suffix: 'Factory')
                ->generates('seeder', in: '@feature/Database/Seeders', suffix: 'Seeder')
                ->generates('migration', in: '@feature/Database/Migrations', timestamped: true))
            ->mounts('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->generates('test', in: 'Feature/{feature}/{slice?}'));

        $this->companions($layout, singleRequest: true)
            ->relates('controller', to: 'request', as: 'controller-store-request', scope: ['keep' => ['feature'], 'name' => 'slice'])
            ->relates('model', to: 'request', as: 'model-store-request', scope: ['keep' => ['feature'], 'name' => 'slice'])
            ->relates('handler', to: 'request', as: 'handler-request')
            ->relates('request', to: 'model', as: 'request-model', scope: ['feature'], name: 'explicit', mode: 'reference');

        return $layout
            ->excludes('App\\Http\\', 'App\\Providers\\', 'App\\Support\\');
    }

    private function typeFirst(Layout $layout): Layout
    {
        $layout->path('app/*/{feature}');
        $layout
            ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
                ->generates('model', in: '@feature/Models')
                ->generates('controller', in: '@feature/Http/Controllers', suffix: 'Controller')
                ->generates('request', in: '@feature/Http/Requests', suffix: 'Request')
                ->generates('policy', in: '@feature/Policies', suffix: 'Policy')
                ->generates('provider', in: '@feature/Providers', suffix: 'ServiceProvider')
                ->generates('command', in: '@feature/Console/Commands')
                ->generates('event', in: '@feature/Events')
                ->generates('listener', in: '@feature/Listeners')
                ->generates('job', in: '@feature/Jobs')
                ->generates('job-middleware', in: '@feature/Jobs/Middleware')
                ->generates('mail', in: '@feature/Mail')
                ->generates('notification', in: '@feature/Notifications')
                ->generates('resource', in: '@feature/Http/Resources')
                ->generates('middleware', in: '@feature/Http/Middleware')
                ->generates('rule', in: '@feature/Rules')
                ->generates('observer', in: '@feature/Observers')
                ->generates('cast', in: '@feature/Casts')
                ->generates('scope', in: '@feature/Models/Scopes')
                ->generates('enum', in: '@feature/Enums')
                ->generates('exception', in: '@feature/Exceptions')
                ->generates('channel', in: '@feature/Broadcasting')
                ->generates('class', in: '@feature', priority: -10)
                ->generates('interface', in: '@feature', priority: -11)
                ->generates('trait', in: '@feature', priority: -12)
                ->generates('query', in: '@feature/Queries'))
            ->mounts('factories', 'Database\\Factories\\', 'database/factories', fn (Root $root) => $root
                ->generates('factory', in: '{feature?}', suffix: 'Factory'))
            ->mounts('seeders', 'Database\\Seeders\\', 'database/seeders', fn (Root $root) => $root
                ->generates('seeder', in: '{feature?}', suffix: 'Seeder'))
            ->mounts('migrations', null, 'database/migrations', fn (Root $root) => $root
                ->generates('migration', in: '{feature?}', timestamped: true))
            ->mounts('config', null, 'config', fn (Root $root) => $root
                ->generates('config', in: '', using: fn (FileType $kind) => $kind->file()))
            ->mounts('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->generates('test', in: 'Feature/{feature?}'));

        return $this->companions($layout)
            ->excludes('App\\Models\\Concerns\\');
    }

    private function modules(Layout $layout): Layout
    {
        $layout->defaultPath('app/Modules/{module}');
        $layout
            ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
                ->generates('model', in: '@module/Models')
                ->generates('controller', in: '@module/Http/Controllers', suffix: 'Controller')
                ->generates('request', in: '@module/Http/Requests', suffix: 'Request')
                ->generates('policy', in: '@module/Policies', suffix: 'Policy')
                ->generates('provider', in: '@module/Providers', suffix: 'ServiceProvider')
                ->generates('command', in: '@module/Console')
                ->generates('event', in: '@module/Events')
                ->generates('listener', in: '@module/Listeners')
                ->generates('job', in: '@module/Jobs')
                ->generates('job-middleware', in: '@module/Jobs/Middleware')
                ->generates('mail', in: '@module/Mail')
                ->generates('notification', in: '@module/Notifications')
                ->generates('resource', in: '@module/Http/Resources')
                ->generates('middleware', in: '@module/Http/Middleware')
                ->generates('rule', in: '@module/Rules')
                ->generates('observer', in: '@module/Observers')
                ->generates('cast', in: '@module/Casts')
                ->generates('scope', in: '@module/Scopes')
                ->generates('enum', in: '@module/Enums')
                ->generates('exception', in: '@module/Exceptions')
                ->generates('channel', in: '@module/Channels')
                ->generates('class', in: '@module', priority: -10)
                ->generates('interface', in: '@module', priority: -11)
                ->generates('trait', in: '@module', priority: -12)
                ->generates('factory', in: '@module/Database/Factories', suffix: 'Factory')
                ->generates('seeder', in: '@module/Database/Seeders', suffix: 'Seeder')
                ->generates('migration', in: '@module/Database/Migrations', timestamped: true)
                ->generates('action', in: '@module/Actions')
                ->generates('dto', in: '@module/Data', label: 'DTO', aliases: ['mod:data'])
                ->generates('value-object', in: '@module/ValueObjects', label: 'Value object', aliases: ['mod:value'])
                ->generates('view-model', in: '@module/ViewModels', label: 'View model')
                ->generates('query', in: '@module/Queries'))
            ->mounts('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->generates('test', in: 'Feature/Modules/{module}'));

        return $this->companions($layout)
            ->excludes('App\\UI\\', 'App\\Support\\');
    }

    /**
     * laravel-ddd's defaults: domain objects in src/Domain/<Domain>, application
     * objects (controllers, requests, middleware) in app/Modules/<Domain>.
     * Subdomains nest: --domain=Reporting.Internal. Bases stay in
     * src/Domain/Shared, where laravel-ddd puts them.
     */
    private function ddd(Layout $layout): Layout
    {
        $layout->path('src/Domain/{domain}')->allowsNesting();
        $layout
            ->mounts('domain', 'Domain\\', 'src/Domain', fn (Root $root) => $root
                ->generates('model', in: '@domain/Models')
                ->generates('dto', in: '@domain/Data', label: 'DTO', aliases: ['mod:data-transfer-object', 'mod:data'], stub: Starters::dto(baseIn: 'Shared/Data'))
                ->generates('value-object', in: '@domain/ValueObjects', label: 'Value object', aliases: ['mod:value'])
                ->generates('view-model', in: '@domain/ViewModels', label: 'View model', stub: Starters::viewModel(baseIn: 'Shared/ViewModels'))
                ->generates('action', in: '@domain/Actions', label: 'Action')
                ->generates('cast', in: '@domain/Casts')
                ->generates('channel', in: '@domain/Channels')
                ->generates('command', in: '@domain/Commands')
                ->generates('enum', in: '@domain/Enums')
                ->generates('event', in: '@domain/Events')
                ->generates('exception', in: '@domain/Exceptions')
                ->generates('factory', in: '@domain/Database/Factories', suffix: 'Factory')
                ->generates('job', in: '@domain/Jobs')
                ->generates('job-middleware', in: '@domain/Jobs/Middleware')
                ->generates('listener', in: '@domain/Listeners')
                ->generates('mail', in: '@domain/Mail')
                ->generates('migration', in: '@domain/Database/Migrations', timestamped: true)
                ->generates('notification', in: '@domain/Notifications')
                ->generates('observer', in: '@domain/Observers')
                ->generates('policy', in: '@domain/Policies', suffix: 'Policy')
                ->generates('provider', in: '@domain/Providers')
                ->generates('resource', in: '@domain/Resources')
                ->generates('rule', in: '@domain/Rules')
                ->generates('scope', in: '@domain/Scopes')
                ->generates('seeder', in: '@domain/Database/Seeders', suffix: 'Seeder')
                ->generates('class', in: '@domain', priority: -10)
                ->generates('interface', in: '@domain', priority: -11)
                ->generates('trait', in: '@domain', priority: -12))
            ->mounts('application', 'App\\Modules\\', 'app/Modules', fn (Root $root) => $root
                ->generates('controller', in: '{domain+}/Http/Controllers', suffix: 'Controller')
                ->generates('request', in: '{domain+}/Http/Requests', suffix: 'Request')
                ->generates('middleware', in: '{domain+}/Http/Middleware'))
            ->mounts('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->generates('test', in: 'Feature/{domain+}'));

        return $this->companions($layout);
    }

    private function companions(Layout $layout, bool $singleRequest = false): Layout
    {
        $layout
            ->relates('model', to: 'factory', as: 'model-factory')
            ->relates('model', to: 'seeder', as: 'model-seeder')
            ->relates('model', to: 'policy', as: 'model-policy')
            ->relates('model', to: 'controller', as: 'model-controller')
            ->relates('model', to: 'migration', as: 'model-migration', name: 'explicit')
            ->relates('factory', to: 'model', as: 'factory-model', mode: 'reference')
            ->relates('listener', to: 'event', as: 'listener-event', name: 'explicit', mode: 'reference')
            ->relates('controller', to: 'request', as: 'controller-store-request', name: ['prefix' => 'Store'])
            ->relates('model', to: 'request', as: 'model-store-request', name: ['prefix' => 'Store']);

        if (! $singleRequest) {
            $layout
                ->relates('controller', to: 'request', as: 'controller-update-request', name: ['prefix' => 'Update'])
                ->relates('model', to: 'request', as: 'model-update-request', name: ['prefix' => 'Update']);
        }

        return $layout;
    }
}
