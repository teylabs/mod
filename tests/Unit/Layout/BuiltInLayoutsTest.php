<?php

use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Layout\BuiltIn\BuiltInLayouts;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Listing\LayoutInventory;
use Tey\Mod\Relation\RelationMode;

it('ships the common native kinds in every built-in', function (string $name) {
    $preset = (new LayoutRegistry)->compile($name);
    $common = ['model', 'factory', 'seeder', 'migration', 'controller', 'request', 'policy', 'provider', 'command', 'event', 'listener', 'job', 'job-middleware', 'mail', 'notification', 'resource', 'middleware', 'rule', 'observer', 'cast', 'scope', 'enum', 'exception', 'channel', 'class', 'interface', 'trait', 'test'];
    foreach ($common as $kind) {
        expect($preset->hasKind($kind))->toBeTrue("{$name}: {$kind}");
    }
    expect($preset->hasKind('config'))->toBe(in_array($name, ['laravel', 'type-first'], true))
        ->and($preset->hasKind('routes'))->toBeFalse()
        ->and($preset->hasKind('view'))->toBeTrue()->and($preset->hasKind('component'))->toBeTrue()
        ->and($preset->roots()['tests']->namespace)->toBe('Tests\\')
        ->and($preset->roots()['tests']->path)->toBe('tests');
    foreach (['model-factory', 'model-seeder', 'model-policy', 'model-controller', 'model-migration'] as $relation) {
        expect($preset->relation($relation)->mode)->toBe(RelationMode::Generate);
    }
})->with(['laravel', 'features', 'slices', 'type-first', 'modules']);

it('gives every built-in layout its own registry copy', function () {
    $first = new LayoutRegistry;
    $first->layout('modules')->generates('report', in: 'Modules/{module}/Reports');
    expect((new LayoutRegistry)->compile('modules')->hasKind('report'))->toBeFalse()
        ->and($first->compile('modules')->hasKind('report'))->toBeTrue();
});

it('names every built-in relation <from>-<to>[-qualifier], meaning the same in every layout', function (string $layout, array $extra) {
    $companions = [
        'model-factory' => ['model', 'factory'],
        'model-seeder' => ['model', 'seeder'],
        'model-policy' => ['model', 'policy'],
        'model-controller' => ['model', 'controller'],
        'model-migration' => ['model', 'migration'],
        'factory-model' => ['factory', 'model'],
        'listener-event' => ['listener', 'event'],
        'controller-store-request' => ['controller', 'request'],
        'model-store-request' => ['model', 'request'],
    ];
    $relations = [];

    foreach ((new LayoutRegistry)->compile($layout)->relations() as $relation) {
        $relations[$relation->id] = [$relation->fromKind, $relation->toKind];
    }

    expect($relations)->toEqualCanonicalizing([...$companions, ...$extra]);
})->with([
    'laravel' => ['laravel', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'features' => ['features', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'type-first' => ['type-first', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'modules' => ['modules', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'ddd' => ['ddd', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'slices' => ['slices', ['handler-request' => ['handler', 'request'], 'request-model' => ['request', 'model']]],
]);

it('pins every built-in file type folder for 0.3', function (string $name) {
    $snapshot = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/layout-folders-03.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = mcpLayoutPackages(false);
    $layout = (new LayoutRegistry(new BuiltInLayouts($packages)))->compile($name);
    $folders = [];
    foreach ($layout->kinds() as $id => $kind) {
        $folders[$id] = LayoutInventory::folder($layout, $id);
    }
    expect($folders)->toBe($snapshot[$name]);
})->with(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd']);

it('adds MCP placements only to module and DDD layouts when the package is installed', function (bool $installed, string $name) {
    $packages = mcpLayoutPackages($installed);
    $layout = (new LayoutRegistry(new BuiltInLayouts($packages)))->compile($name);
    foreach (['server' => 'Servers', 'tool' => 'Tools', 'resource' => 'Resources', 'prompt' => 'Prompts'] as $id => $folder) {
        $expected = $installed && in_array($name, ['modules', 'ddd'], true);
        expect($layout->hasKind('mcp-'.$id))->toBe($expected);
        if ($expected) {
            expect(LayoutInventory::folder($layout, 'mcp-'.$id))
                ->toBe('app/Modules/'.($name === 'modules' ? '{module}' : '{domain+}').'/Mcp/'.$folder);
        }
    }
    expect($layout->hasKind('mcp-app-resource'))->toBeFalse();
})->with([true, false])->with(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd']);

function mcpLayoutPackages(bool $installed): PackageDetector
{
    return new class($installed) implements PackageDetector
    {
        public function __construct(private bool $installed) {}

        public function isInstalled(string $package): bool
        {
            return $package === 'laravel/mcp' && $this->installed;
        }

        public function classExists(string $class): bool
        {
            return class_exists($class);
        }
    };
}
