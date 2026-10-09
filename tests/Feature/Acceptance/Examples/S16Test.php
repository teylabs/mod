<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S16 copies the complete tree and swaps just the base or entry', function (string $variant) {
    Workspace::run(null, function (Workspace $w) use ($variant) {
        Tree::setup($w);
        $w->write('stubs/mod.view-model.index.stub', <<<'PHP'
<?php
namespace {{ namespace }};
use Illuminate\Http\Request;
abstract class {{ class }}
{
    public function __construct(protected Request $request) {}
    public function layout(): array
    {
        return ['sections' => [
            // mod:tabs
        ]];
    }
    abstract public function title(): string;
}
PHP);
        $w->write('stubs/mod.view-model.method.stub', <<<'PHP'
<?php
namespace {{ namespace }};
use {{ model.fqcn }};
abstract class {{ class }}
{
    public function __construct(protected {{ model }} ${{ model.camel }}) {}
    public function tabs(): array
    {
        return [
            // mod:tabs
        ];
    }
    abstract public function title(): string;
}
PHP);
        $w->write('stubs/mod.insert.href.stub', <<<'PHP'
            ['key' => '{{ tab.kebab }}', 'label' => '{{ tab }}', 'href' => route('{{ name.kebab }}.{{ tab.kebab }}', $this->{{ model.camel }})],
PHP);
        Mod::scaffold('variant', function (Scaffold $s) use ($variant) {
            $s->include('resource-tabs');
            if ($variant !== 'A') {
                $s->makes('view-model', name: 'Manage{name}ViewModel', as: 'base', stub: $variant === 'B' ? 'index' : 'method');
            }
            if ($variant === 'C') {
                $s->part('tab', uses: 'tab-page', with: ['base' => '{{ base.fqcn }}'], configure: fn (Part $p) => $p->inserts(into: 'base', at: 'tabs', stub: 'href')->inserts(into: 'controller', at: 'actions', stub: 'tabs-action'));
            }
        });
        $w->artisan('mod:variant', ['name' => 'Inventory:Widget', '--tabs' => ['Current', 'Period']])->assertSuccessful();
        Tree::assertClassesLoad($w);
        expect($w->read(Tree::page('Period')))->toContain('extends ManageWidgetViewModel')
            ->and($w->read(Tree::base()))->toContain($variant === 'C' ? "'href' => route('widget.period', \$this->widget)" : "['label' => 'Period'");
    });
})->with(['A', 'B', 'C']);
