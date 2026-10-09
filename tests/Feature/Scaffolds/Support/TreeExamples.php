<?php

namespace Tey\Mod\Tests\Feature\Scaffolds\Support;

use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

final class TreeExamples
{
    public static function setup(Workspace $w, bool $routes = false): void
    {
        putenv('COLUMNS=72');
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        $w->write('app/Http/Controllers/Controller.php', "<?php\nnamespace App\\Http\\Controllers;\nabstract class Controller {}\n");
        $w->write('app/Support/ViewModels/ViewModel.php', "<?php\nnamespace App\\Support\\ViewModels;\nabstract class ViewModel {}\n");
        $w->write('stubs/mod.view-model.tabs-layout.stub', <<<'STUB'
<?php

namespace {{ namespace }};

use {{ model.fqcn }};
{{ baseImport }}
abstract class {{ class }}{{ extends }}
{
    public function __construct(protected {{ model }} ${{ model.camel }}) {}

    public function layout(): array
    {
        return [
            'tabs' => [
                // mod:tabs
            ],
        ];
    }

    abstract public function title(): string;
}
STUB
        );
        $w->write('stubs/mod.view-model.tab-page.stub', <<<'STUB'
<?php

namespace {{ namespace }};

use {{ base.fqcn }};

class {{ class }} extends {{ base }}
{
    public function title(): string
    {
        return '{{ tab }}';
    }
}
STUB
        );
        $w->write('stubs/mod.controller.tabs.stub', <<<'STUB'
<?php

namespace {{ namespace }};

use App\Http\Controllers\Controller;
use {{ model.fqcn }};

class {{ class }} extends Controller
{
    // mod:actions
}
STUB
        );
        $w->write('stubs/mod.insert.tabs-entry.stub', <<<'STUB'
                ['label' => '{{ tab }}', 'route' => '{{ name.kebab }}.{{ tab.kebab }}'],
STUB
        );
        $w->write('stubs/mod.insert.tabs-action.stub', <<<'STUB'
    public function {{ tab.camel }}({{ model }} ${{ model.camel }})
    {
        return inertia('{{ name }}/{{ tab }}', new \{{ tab.page.fqcn }}(${{ model.camel }}));
    }

STUB
        );
        Mod::scaffold('tab-page', fn (Scaffold $s) => $s
            ->asks('base', type: 'class', label: 'Which base view model does the page extend?')
            ->asks('tab', type: 'text', label: 'Which tab is this?')
            ->makes('view-model', name: '{name}{tab}ViewModel', as: 'page', stub: 'tab-page'));
        Mod::scaffold('resource-tabs', fn (Scaffold $s) => $s
            ->asks('model', type: 'model', default: '{name}', label: 'Which model do the tabs manage?')
            ->asks('tabs', type: 'list', default: ['Overview', 'Details', 'Notes'], label: 'Which tabs?')
            ->makes('view-model', name: 'Manage{name}ViewModel', as: 'base', stub: 'tabs-layout')
            ->makes('controller', name: '{name}Controller', stub: 'tabs')
            ->part('tab', uses: 'tab-page', with: ['base' => '{{ base.fqcn }}'], configure: function (Part $p) use ($routes) {
                $p->inserts(into: 'base', at: 'tabs', stub: 'tabs-entry')
                    ->inserts(into: 'controller', at: 'actions', stub: 'tabs-action');
                if ($routes) {
                    $p->inserts(into: '@module/routes/web.php', at: 'routes', stub: 'tab-route');
                }
            })
            ->each('tabs', part: 'tab'));
    }

    /** @param list<string> $tabs */
    public static function create(Workspace $w, array $tabs = ['Overview', 'Details', 'Notes']): void
    {
        $w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget', '--tabs' => $tabs])->assertSuccessful();
    }

    public static function assertClassesLoad(Workspace $w): void
    {
        $files = [];
        foreach ($w->files() as $path) {
            if (! str_starts_with($path, 'app/') || ! str_ends_with($path, '.php')) {
                continue;
            }
            $lint = new Process([PHP_BINARY, '-l', $w->root->path($path)]);
            $lint->run();
            expect($lint->isSuccessful())->toBeTrue($lint->getErrorOutput().$lint->getOutput());
            $files[] = $w->root->path($path);
        }
        $bootstrap = 'require '.var_export(dirname(__DIR__, 4).'/vendor/autoload.php', true).';';
        $load = <<<'CODE'
$files = json_decode($argv[1], true);
spl_autoload_register(function ($class) use ($files) {
    foreach ($files as $file) {
        $source = file_get_contents($file);
        if (preg_match('/namespace\\s+([^;]+);/', $source, $ns) && preg_match('/\\b(?:class|interface|trait|enum)\\s+(\\w+)/', $source, $name) && trim($ns[1]).'\\'.$name[1] === $class) {
            require_once $file;
            return;
        }
    }
});
foreach ($files as $file) { require_once $file; }
CODE;
        $process = new Process([PHP_BINARY, '-r', $bootstrap.$load, json_encode($files, JSON_THROW_ON_ERROR)]);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    }

    public static function expectedBase(): string
    {
        return <<<'PHP'
<?php

namespace App\Modules\Inventory\ViewModels;

use App\Modules\Inventory\Models\Widget;

use App\Support\ViewModels\ViewModel;

abstract class ManageWidgetViewModel extends ViewModel
{
    public function __construct(protected Widget $widget) {}

    public function layout(): array
    {
        return [
            'tabs' => [
                ['label' => 'Overview', 'route' => 'widget.overview'],
                ['label' => 'Details', 'route' => 'widget.details'],
                ['label' => 'Notes', 'route' => 'widget.notes'],
                // mod:tabs
            ],
        ];
    }

    abstract public function title(): string;
}
PHP;
    }

    public static function base(): string
    {
        return 'app/Modules/Inventory/ViewModels/ManageWidgetViewModel.php';
    }

    public static function page(string $tab): string
    {
        return 'app/Modules/Inventory/ViewModels/Widget'.$tab.'ViewModel.php';
    }
}
