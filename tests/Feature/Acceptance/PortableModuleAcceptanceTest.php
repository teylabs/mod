<?php

use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;

/*
 * Acceptance, copying a module: a DTO generated in one application extends a
 * base in app/Support, outside the module. Copied alone into a second
 * application it cannot load until `php artisan mod:bases` writes the base.
 * The optional packages are not installed in mod's dev install, so the
 * generated base is used.
 */

it('restores the bases a copied module needs with mod:bases', function () {
    [$module, $basesPath, $files] = AcceptanceApp::run('modules', function (AcceptanceApp $a) {
        $module = 'Billing'.$a->tag;
        $a->boot();

        $a->artisan('mod:dto', ['name' => "{$module}:InvoiceData"])->assertSuccessful();
        $a->write("app/Modules/{$module}/Data/InvoiceData.php", <<<PHP
            <?php

            namespace App\\Modules\\{$module}\\Data;

            use App\\Support\\{$a->tag}\\Data\\DataTransferObject;

            class InvoiceData extends DataTransferObject
            {
                public function __construct(
                    public string \$number,
                    public int \$total,
                ) {}
            }

            PHP);

        $files = [];

        foreach ($a->files() as $path) {
            if (str_starts_with($path, "app/Modules/{$module}/")) {
                $files[$path] = $a->read($path);
            }
        }

        expect($a->files())->toContain("{$a->basesPath()}/Data/DataTransferObject.php");

        return [$module, $a->basesPath(), $files];
    });

    AcceptanceApp::run('modules', function (AcceptanceApp $b) use ($module, $basesPath, $files) {
        $b->boot();
        // Both applications keep their bases in the same folder, as two apps on the defaults do.
        $b->app()->make('config')->set('mod.bases_path', $basesPath);

        foreach ($files as $path => $contents) {
            $b->write($path, $contents);
        }

        $dto = "App\\Modules\\{$module}\\Data\\InvoiceData";
        $base = str_replace('/', '\\', ucfirst(substr($basesPath, 4)));

        expect(fn () => class_exists($dto))->toThrow(Error::class, "App\\{$base}\\Data\\DataTransferObject");

        $result = $b->artisan('mod:bases');

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain("Created base class App\\{$base}\\Data\\DataTransferObject [{$basesPath}/Data/DataTransferObject.php].")
            ->and($dto::fromArray(['number' => 'INV-1', 'total' => 1200])->toArray())->toBe(['number' => 'INV-1', 'total' => 1200])
            ->and($b->artisan('mod:bases')->output)->toContain('Every base class already exists.');
    });
});
