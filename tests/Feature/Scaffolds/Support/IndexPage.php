<?php

namespace Tey\Mod\Tests\Feature\Scaffolds\Support;

use RuntimeException;
use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/** A server-driven index page: columns that may sort, and an optional status filter. */
final class IndexPage
{
    public const REQUEST = 'app/Http/Requests/InvoiceIndexRequest.php';

    public const PAGE = 'resources/js/pages/Invoice/Index.vue';

    public const COLUMNS = '[{"key":"number","label":"Number","sortable":true},{"key":"customer_name","label":"Customer","sortable":true},{"key":"notes","label":"Notes","sortable":false}]';

    public static function recipe(Scaffold $s): void
    {
        $s->asks('columns', type: 'records', shape: ['key' => 'string', 'label' => 'string', 'sortable' => 'bool'], default: [
            ['key' => 'number', 'label' => 'Number', 'sortable' => true],
        ])
            ->asks('status_filter', type: 'confirm', default: false)
            ->makes('request', name: '{name}IndexRequest', stub: 'index')
            ->makes('page', name: '{name}/Index', stub: 'index');
    }

    public static function setup(Workspace $w, ?callable $recipe = null): void
    {
        $w->write('package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^2']]));
        $w->write('stubs/mod.request.index.stub', (string) file_get_contents(__DIR__.'/index/request.stub'));
        $w->write('stubs/mod.page.index.vue.stub', (string) file_get_contents(__DIR__.'/index/page.vue.stub'));
        Mod::vendor('acme')->scaffold('index', $recipe ?? self::recipe(...));
    }

    /** Run Laravel Pint over the files, as the app would. */
    public static function pint(Workspace $w, string ...$paths): void
    {
        $binary = dirname(__DIR__, 4).'/vendor/bin/pint';
        $process = new Process([PHP_BINARY, $binary, '--test', ...array_map(fn (string $path): string => $w->root->path($path), $paths)], $w->root->path, timeout: 120);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
    }

    /**
     * Run Prettier over the files with the given options, as the app would.
     *
     * @param  array<string, mixed>  $options
     */
    public static function prettier(Workspace $w, array $options, string ...$paths): void
    {
        $binary = dirname(__DIR__, 3).'/Fixtures/frontend-build/node_modules/prettier/bin/prettier.cjs';
        if (! is_file($binary)) {
            throw new RuntimeException('Run npm ci in tests/Fixtures/frontend-build first.');
        }
        $w->write('.prettierrc.json', json_encode($options));
        $process = new Process(['node', $binary, '--check', ...$paths], $w->root->path, timeout: 120);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
    }
}
