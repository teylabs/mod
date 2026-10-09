<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Console\ConfigMakeCommand;
use Illuminate\Support\Str;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;

/** @return array<string, string> */
function builtInFolders(string $layout, string $group, string $slice): array
{
    if ($layout === 'ddd') {
        $domain = [
            'model' => 'Models', 'dto' => 'Data', 'value-object' => 'ValueObjects', 'view-model' => 'ViewModels', 'action' => 'Actions',
            'cast' => 'Casts', 'channel' => 'Channels', 'command' => 'Commands', 'enum' => 'Enums', 'event' => 'Events',
            'exception' => 'Exceptions', 'factory' => 'Database/Factories', 'job' => 'Jobs', 'job-middleware' => 'Jobs/Middleware',
            'listener' => 'Listeners', 'mail' => 'Mail', 'migration' => 'Database/Migrations', 'notification' => 'Notifications',
            'observer' => 'Observers', 'policy' => 'Policies', 'provider' => 'Providers', 'resource' => 'Resources', 'rule' => 'Rules',
            'scope' => 'Scopes', 'seeder' => 'Database/Seeders', 'class' => '', 'interface' => '', 'trait' => '',
        ];
        $folders = array_map(static fn (string $folder): string => rtrim("src/Domain/{$group}/{$folder}", '/'), $domain);

        foreach (['controller' => 'Http/Controllers', 'request' => 'Http/Requests', 'middleware' => 'Http/Middleware'] as $kind => $folder) {
            $folders[$kind] = "app/Modules/{$group}/{$folder}";
        }

        $folders['test'] = "tests/Feature/{$group}";

        return $folders;
    }

    $folders = [
        'model' => 'Models', 'controller' => 'Http/Controllers', 'request' => 'Http/Requests',
        'policy' => 'Policies', 'provider' => 'Providers', 'command' => 'Console/Commands',
        'event' => 'Events', 'listener' => 'Listeners', 'job' => 'Jobs', 'job-middleware' => 'Jobs/Middleware',
        'mail' => 'Mail', 'notification' => 'Notifications', 'resource' => 'Http/Resources',
        'middleware' => 'Http/Middleware', 'rule' => 'Rules', 'observer' => 'Observers',
        'cast' => 'Casts', 'scope' => 'Models/Scopes', 'enum' => 'Enums', 'exception' => 'Exceptions',
        'channel' => 'Broadcasting', 'class' => '', 'interface' => '', 'trait' => '',
    ];
    if (in_array($layout, ['features', 'slices', 'modules'], true)) {
        $folders['scope'] = 'Scopes';
        $folders += ['factory' => 'Database/Factories', 'seeder' => 'Database/Seeders', 'migration' => 'Database/Migrations'];
    }
    if ($layout === 'modules') {
        $folders = array_replace($folders, ['channel' => 'Channels', 'command' => 'Console']);
        $folders += ['action' => 'Actions', 'dto' => 'Data', 'value-object' => 'ValueObjects', 'view-model' => 'ViewModels', 'query' => 'Queries'];
    }
    if ($layout === 'features') {
        $folders += ['query' => 'Queries', 'validator' => 'Validation'];
    }
    if ($layout === 'type-first') {
        $folders += ['query' => 'Queries'];
    }
    foreach ($folders as &$folder) {
        $folder = match ($layout) {
            'features' => "app/Features/{$group}/{$folder}",
            'slices' => "app/{$group}/{$folder}",
            'modules' => "app/Modules/{$group}/{$folder}",
            'type-first' => "app/{$folder}/{$group}",
            default => "app/{$folder}",
        };
        $folder = str_replace('//', '/', rtrim($folder, '/'));
    }
    unset($folder);
    if ($layout === 'slices') {
        foreach (['message', 'handler', 'validator', 'query'] as $kind) {
            $folders[$kind] = "app/{$group}/{$slice}";
        }
        $folders['request'] = "app/{$group}/{$slice}/Http/Requests";
    }
    if (in_array($layout, ['laravel', 'type-first'], true)) {
        foreach (['factory' => 'factories', 'seeder' => 'seeders', 'migration' => 'migrations'] as $kind => $folder) {
            $folders[$kind] = "database/{$folder}".($layout === 'type-first' ? "/{$group}" : '');
        }
        $folders['config'] = 'config';
    }
    $folders['test'] = match ($layout) {
        'laravel' => 'tests/Feature',
        'slices' => "tests/Feature/{$group}/{$slice}",
        'modules' => "tests/Feature/Modules/{$group}",
        default => "tests/Feature/{$group}",
    };

    return $folders;
}

it('generates and autoloads every declared built-in kind at its table location', function (string $layout) {
    AcceptanceApp::run($layout, function (AcceptanceApp $app) use ($layout) {
        $group = 'Billing'.$app->tag;
        $slice = 'Create'.$app->tag;
        $folders = builtInFolders($layout, $group, $slice);
        expect(array_keys($app->preset->kinds()))->toEqualCanonicalizing(array_keys($folders));
        $app->handWrite('tests/TestCase.php', 'Tests', 'abstract class TestCase extends \\PHPUnit\\Framework\\TestCase {}');
        foreach ($folders as $kind => $folder) {
            if ($kind === 'config' && ! class_exists(ConfigMakeCommand::class)) {
                expect($app->modCommands())->not->toContain('mod:config');

                continue;
            }
            $name = str_replace('-', '', ucwords($kind, '-')).$app->tag;
            $name = $kind === 'config' ? strtolower($name) : $name;
            $options = ['name' => $name];
            $placement = $layout === 'laravel' || $kind === 'config' ? '' : $group;
            if ($layout === 'slices' && in_array($kind, ['message', 'handler', 'request', 'validator', 'query', 'test'], true)) {
                $placement .= '/'.$slice;
            }
            if ($placement !== '') {
                $options['--in'] = $placement;
            }
            if ($kind === 'test') {
                $options['--phpunit'] = true;
            }
            $before = glob($app->root->path($folder).'/*.php') ?: [];
            $result = $app->artisan((string) $app->preset->kind($kind)->command, $options)->assertSuccessful();
            $files = array_values(array_diff(glob($app->root->path($folder).'/*.php') ?: [], $before));
            expect($files)->toHaveCount(1, "{$layout}: {$kind} in {$folder}");
            $file = $files[0];
            $contents = (string) file_get_contents($file);
            preg_match('/namespace ([^;]+);/', $contents, $declaredNamespace);
            expect($result)->toHaveGenerated((string) Path::relative($app->root->path, $file), namespace: $declaredNamespace[1] ?? null);
            if ($kind === 'migration') {
                expect(require $file)->toBeInstanceOf(Migration::class);
            } elseif ($kind === 'config') {
                expect(require $file)->toBeArray();
            } else {
                preg_match('/namespace ([^;]+);/', $contents, $namespace);
                preg_match('/^(?:final |abstract |readonly )?(?:class|interface|trait|enum) (\w+)/m', $contents, $symbol);
                $class = ($namespace[1] ?? '').'\\'.($symbol[1] ?? '');
                $exists = match ($kind) {
                    'interface' => interface_exists($class),
                    'trait' => trait_exists($class),
                    'enum' => enum_exists($class),
                    default => class_exists($class),
                };
                expect($exists)->toBeTrue("{$layout}: {$class} should autoload");
            }
        }
        $unit = 'Unit'.$app->tag;
        $options = ['name' => $unit, '--unit' => true, '--phpunit' => true];
        if ($layout !== 'laravel') {
            $options['--in'] = $group;
        }
        $unitResult = $app->artisan('mod:test', $options)->assertSuccessful();
        $unitFolder = str_replace('/Feature', '/Unit', builtInFolders($layout, $group, '')['test']);
        expect($unitResult)->toHaveGenerated(rtrim($unitFolder, '/').'/'.$unit.'.php');
    });
})->with(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd']);

it('generates model companions including policy alone in every built-in', function (string $layout) {
    AcceptanceApp::run($layout, function (AcceptanceApp $app) use ($layout) {
        $group = 'Billing'.$app->tag;
        $folders = builtInFolders($layout, $group, '');
        $placement = $layout === 'laravel' ? [] : ['--in' => $group];
        $name = 'Invoice'.$app->tag;
        $app->artisan('mod:model', ['name' => $name, '--all' => true, ...$placement])->assertSuccessful();
        foreach (['model' => '', 'factory' => 'Factory', 'seeder' => 'Seeder', 'policy' => 'Policy', 'controller' => 'Controller'] as $kind => $suffix) {
            expect(is_file($app->root->path($folders[$kind].'/'.$name.$suffix.'.php')))->toBeTrue("{$layout}: {$kind}");
        }
        $requestFolder = $layout === 'slices' ? "app/{$group}/{$name}/Http/Requests" : $folders['request'];
        $requestNames = $layout === 'slices' ? ['Request'] : ['Store'.$name.'Request', 'Update'.$name.'Request'];
        foreach ($requestNames as $request) {
            expect(is_file($app->root->path($requestFolder.'/'.$request.'.php')))->toBeTrue("{$layout}: {$request}");
        }
        expect($app->migration($folders['migration'], 'create_'.Str::snake(Str::pluralStudly($name)).'_table'))->toBeString();
        foreach ($app->files() as $file) {
            expect($app->root->path($file))->toBeValidPhp();
        }
        $alone = 'PolicyOnly'.$app->tag;
        $before = $app->files();
        $policyResult = $app->artisan('mod:model', ['name' => $alone, '--policy' => true, ...$placement])->assertSuccessful();
        expect($policyResult)->toHaveGenerated($folders['model'].'/'.$alone.'.php');
        expect($app->root->path($folders['policy'].'/'.$alone.'Policy.php'))->toBeValidPhp();
        expect(array_values(array_diff($app->files(), $before)))->toEqualCanonicalizing([
            $folders['model'].'/'.$alone.'.php', $folders['policy'].'/'.$alone.'Policy.php',
        ]);
    });
})->with(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd']);

it('generates standalone model request companions in every built-in', function (string $layout) {
    AcceptanceApp::run($layout, function (AcceptanceApp $app) use ($layout) {
        $group = 'Billing'.$app->tag;
        $name = 'Create'.$app->tag;
        $placement = $layout === 'laravel' ? [] : ['--in' => $group];
        $app->artisan('mod:model', ['name' => $name, '--requests' => true, ...$placement])->assertSuccessful();
        $folder = $layout === 'slices' ? "app/{$group}/{$name}/Http/Requests" : builtInFolders($layout, $group, '')['request'];
        $requests = $layout === 'slices' ? ['Request'] : ['Store'.$name.'Request', 'Update'.$name.'Request'];
        foreach ($requests as $request) {
            expect($app->root->path($folder.'/'.$request.'.php'))->toBeValidPhp();
        }
        if ($layout === 'slices') {
            expect(array_keys($app->preset->relations()))->not->toContain('controller-update-request', 'model-update-request');
        }
    });
})->with(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd']);
