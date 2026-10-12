<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

function literalWorkspace(Workspace $w): void
{
    config()->set('mod.layout', 'modules');
    $w->write('app/Modules/Inventory/.keep', '');
    $w->write('stubs/mod.job.cols.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    public const COLS = {{ cols.php }};\n    // {{ cols.ts }}|{{ cols.js }}|{{ cols.json }}\n    // {{ rows.php }}|{{ rows.ts }}\n}\n");
    Mod::scaffold('cols', fn (Scaffold $s) => $s
        ->asks('cols', type: 'list', default: ['name', 'company'])
        ->asks('rows', type: 'records', shape: ['key' => 'string', 'sortable' => 'bool'], default: [['key' => 'a', 'sortable' => true]])
        ->makes('job', stub: 'cols'));
}

it('prints list and records answers as PHP and script literals', function (?array $config, string $quote) {
    Workspace::run(null, function (Workspace $w) use ($config, $quote) {
        literalWorkspace($w);
        foreach ($config ?? [] as $file => $contents) {
            $w->write($file, $contents);
        }
        $w->artisan('mod:cols', ['name' => 'Inventory:Report'])->assertSuccessful();

        $q = $quote;
        expect($w->read('app/Modules/Inventory/Jobs/Report.php'))->toBe("<?php\n\nnamespace App\\Modules\\Inventory\\Jobs;\n\nclass Report\n{\n    public const COLS = ['name', 'company'];\n    // [{$q}name{$q}, {$q}company{$q}]|[{$q}name{$q}, {$q}company{$q}]|[\"name\",\"company\"]\n    // [['key' => 'a', 'sortable' => true]]|[{ key: {$q}a{$q}, sortable: true }]\n}\n");
    });
})->with([
    'no config means double quotes' => [null, '"'],
    '.prettierrc json' => [['.prettierrc' => '{"singleQuote": true}'], "'"],
    '.prettierrc.json false' => [['.prettierrc.json' => '{"singleQuote": false}'], '"'],
    '.prettierrc yaml' => [['.prettierrc' => "semi: false\nsingleQuote: true\n"], "'"],
    '.prettierrc.yaml' => [['.prettierrc.yaml' => "singleQuote: true\noverrides:\n  - files: '*.ts'\n    options:\n      singleQuote: false\n"], "'"],
    '.prettierrc.toml' => [['.prettierrc.toml' => "singleQuote = true\n"], "'"],
    'prettier.config.js' => [['prettier.config.js' => "export default {\n  singleQuote: true,\n};\n"], "'"],
    'prettier.config.mjs false' => [['prettier.config.mjs' => "export default { singleQuote: false };\n"], '"'],
    'package.json key' => [['package.json' => '{"prettier":{"singleQuote":true}}'], "'"],
    'a package.json without the key' => [['package.json' => '{"name":"app"}', '.prettierrc.json' => '{"singleQuote":true}'], "'"],
    'package.json wins over a file' => [['package.json' => '{"prettier":{"singleQuote":false}}', '.prettierrc.json' => '{"singleQuote":true}'], '"'],
]);
