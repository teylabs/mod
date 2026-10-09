<?php

namespace Tey\Mod\Tests\Feature\Boost;

use Laravel\Boost\BoostServiceProvider;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Tey\Mod\ModServiceProvider;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Install\Kit;

final class Scenario
{
    /** @return array<string, mixed> */
    public static function data(Response $response, Tool $tool): array
    {
        expect($response->isError())->toBeFalse((string) $response->content());

        return json_decode($response->content()->toTool($tool)['text'], true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    public static function bytes(Workspace $w): array
    {
        $paths = [...$w->files(), 'composer.json'];

        return array_combine($paths, array_map($w->read(...), $paths));
    }

    public static function inventory(Workspace $w): void
    {
        Kit::setup($w);
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        $w->write('app/Modules/Inventory/resources/views/mail/widget-restocked.blade.php', '<p>Restocked</p>');
        $w->write('app/Modules/Inventory/resources/views/components/widget-card.blade.php', '<div>Widget</div>');
        $w->write('app/Modules/Inventory/routes/web.php', '<?php // The inventory describes this entrypoint.');
        // A2's loader is on bootstrap/app.php:18, matching the catalogue provenance.
        $w->write('bootstrap/app.php', "<?php\n".str_repeat("\n", 16)."\\Tey\\Mod\\Facades\\Mod::routes();\n");
        require $w->root->path('bootstrap/app.php');
        $w->write('stubs/mod/@module/resources/js/components/card.vue.stub', '<template>{{ name.studly }}</template>');
    }

    public static function application(Workspace $w, bool $boostFirst = false): void
    {
        $providers = [ModServiceProvider::class, BoostServiceProvider::class];
        if ($boostFirst) {
            $providers = array_reverse($providers);
        }
        $w->write('bootstrap/providers.php', '<?php return '.var_export($providers, true).';');
        $w->write('bootstrap/app.php', <<<'APP'
<?php
return \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))->withExceptions()
    ->create();
APP);
        $w->write('config/app.php', "<?php return ['env' => 'local', 'debug' => true];");
        $w->write('config/boost.php', "<?php return ['browser_logs_watcher' => false];");
        $w->write('config/mod.php', "<?php return ['layout' => 'modules', 'discovery' => ['enabled' => false]];");
        $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
        $w->write('artisan', "<?php\nrequire {$autoload};\n\$app = require __DIR__.'/bootstrap/app.php';\nexit(\$app->handleCommand(new \\Symfony\\Component\\Console\\Input\\ArgvInput));\n");
    }
}
