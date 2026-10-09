<?php

namespace Tey\Mod\Tests\Feature\Scaffolds\Support;

use Pest\TestSuite;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\TestCase;

final class Examples
{
    public static function testCase(): TestCase
    {
        $test = TestSuite::getInstance()->test;
        if (! $test instanceof TestCase) {
            throw new \LogicException('Scaffold acceptance tests need Testbench.');
        }

        return $test;
    }

    public static function recipe(Scaffold $s): void
    {
        $s->makes('model', options: ['--migration', '--factory'])
            ->makes('request', name: 'Store{name}Request', as: 'storeRequest', stub: 'crud')
            ->makes('request', name: 'Update{name}Request', as: 'updateRequest', stub: 'crud')
            ->makes('resource', name: '{name}Resource')
            ->makes('policy', name: '{name}Policy')
            ->makes('controller', name: '{name}Controller', stub: 'crud');
    }

    public static function setup(Workspace $workspace, string $layout = 'modules', bool $controller = true): void
    {
        putenv('COLUMNS=72');
        config()->set('mod.layout', $layout);
        mkdir($workspace->root->path('app/Modules/Knowledge'), 0700, true);
        mkdir($workspace->root->path('app/Modules/Agents'), 0700, true);
        app()->bind(ModMigrationCreator::class, fn () => new class(app('files'), $workspace->root->path('stubs')) extends ModMigrationCreator
        {
            public function datePrefixFor(string $directory): string
            {
                return '2026_10_08_120000';
            }

            protected function getDatePrefix(): string
            {
                return '2026_10_08_120000';
            }
        });
        $workspace->write('app/Http/Controllers/Controller.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nabstract class Controller {}\n");
        Mod::scaffold('crud', self::recipe(...));
        $workspace->write('stubs/mod.request.crud.stub', (string) file_get_contents(__DIR__.'/request.stub'));
        if ($controller) {
            $workspace->write('stubs/mod.controller.crud.stub', (string) file_get_contents(__DIR__.'/controller.stub'));
        }
    }

    /** @return list<string> */
    public static function paths(string $domain = 'app/Modules/Knowledge', string $application = 'app/Modules/Knowledge', string $name = 'Document'): array
    {
        return [
            "$domain/Models/$name.php",
            "$domain/Database/Migrations/2026_10_08_120000_create_".strtolower($name).'s_table.php',
            "$domain/Database/Factories/{$name}Factory.php",
            "$application/".(str_starts_with($domain, 'src/') ? '' : 'Http/')."Requests/Store{$name}Request.php",
            "$application/".(str_starts_with($domain, 'src/') ? '' : 'Http/')."Requests/Update{$name}Request.php",
            "$domain/".(str_starts_with($domain, 'app/') ? 'Http/' : '')."Resources/{$name}Resource.php",
            "$domain/Policies/{$name}Policy.php",
            "$application/".(str_starts_with($domain, 'src/') ? '' : 'Http/')."Controllers/{$name}Controller.php",
        ];
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $exists
     */
    public static function plan(array $paths, string $name = 'crud', string $input = 'Knowledge:Document', array $exists = []): string
    {
        $aliases = ['model', 'model (migration)', 'model (factory)', 'storeRequest', 'updateRequest', 'resource', 'policy', 'controller'];
        $lines = [];
        foreach ($paths as $index => $path) {
            $label = $aliases[$index].(in_array($path, $exists, true) ? ' (exists)' : '');
            $lines[] = '  '.$path.' '.str_repeat('.', max(2, 72 - strlen($path) - strlen($label) - 4)).' '.$label;
        }

        return "\n   INFO  mod:$name will write ".count($paths)." files for $input.  \n\n".implode("\n", $lines)."\n\n";
    }

    public static function controller(string $domain = 'App\\Modules\\Knowledge', string $application = 'App\\Modules\\Knowledge', string $name = 'Document'): string
    {
        $source = (string) file_get_contents(__DIR__.'/controller.stub');
        $http = str_starts_with($domain, 'Domain\\') ? '' : 'Http\\';
        $replace = ['namespace' => "{$application}\\{$http}Controllers", 'class' => "{$name}Controller", 'model' => $name, 'model.camel' => lcfirst($name), 'resource' => "{$name}Resource", 'storeRequest' => "Store{$name}Request", 'updateRequest' => "Update{$name}Request", 'model.fqcn' => "$domain\\Models\\$name", 'resource.fqcn' => ($domain === $application ? "$domain\\Http\\Resources\\{$name}Resource" : "$domain\\Resources\\{$name}Resource"), 'storeRequest.fqcn' => "{$application}\\{$http}Requests\\Store{$name}Request", 'updateRequest.fqcn' => "{$application}\\{$http}Requests\\Update{$name}Request"];
        foreach ($replace as $key => $value) {
            $source = str_replace('{{ '.$key.' }}', $value, $source);
        }
        preg_match_all('/^use .+;$/m', $source, $imports);
        $sorted = $imports[0];
        sort($sorted);

        return str_replace(implode("\n", $imports[0]), implode("\n", $sorted), $source);
    }
}
