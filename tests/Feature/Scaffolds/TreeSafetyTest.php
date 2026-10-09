<?php

use Illuminate\Contracts\Console\Kernel;
use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Discovery\Console\DiscoveryCacheCommand;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldExecution;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('cancels the full tree after displaying every file and insert', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        $before = $w->files();
        Examples::testCase()->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget', '--model' => 'Widget', '--tabs' => ['Overview', 'Details', 'Notes']])
            ->expectsConfirmation('Write these 5 files and 6 inserts?', 'no')->assertSuccessful();
        expect($w->files())->toBe($before);
    });
});

it('refuses a repeated inline insert even when the part generates no file', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/.keep', '');
        $w->write('stubs/mod.model.anchored.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {\n// mod:entries\n}\n");
        $w->write('stubs/mod.insert.entry.stub', "const {{ entry }} = '{{ entry }}';");
        Mod::scaffold('entries', fn (Scaffold $s) => $s->makes('model', stub: 'anchored')->part('entry', configure: fn (Part $p) => $p->asks('entry')->inserts(into: 'model', at: 'entries', stub: 'entry')));
        $w->artisan('mod:entries', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $w->artisan('mod:entries.entry', ['name' => 'Inventory:Widget', 'value' => 'History'])->assertSuccessful();
        $before = $w->read('app/Modules/Inventory/Models/Widget.php');
        $w->artisan('mod:entries.entry', ['name' => 'Inventory:Widget', 'value' => 'History'])->assertFailed()->expectsOutputToContain('already exists');
        expect($w->read('app/Modules/Inventory/Models/Widget.php'))->toBe($before);
    });
});

it('answers text, repeated or comma lists and negatable confirms', function (array $options, string $expected) {
    Workspace::run(null, function (Workspace $w) use ($options, $expected) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/.keep', '');
        $w->write('stubs/mod.model.answers.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { const LABEL = '{{ label }}'; const TABS = {{ tabs.array }}; const ENABLED = {{ enabled }}; }\n");
        Mod::scaffold('answers', fn (Scaffold $s) => $s->asks('label', default: '{name}')->asks('tabs', type: 'list', default: ['Default'])->asks('enabled', type: 'confirm', default: true)->makes('model', stub: 'answers'));
        $w->artisan('mod:answers', ['name' => 'Inventory:Widget', ...$options])->assertSuccessful();
        $source = $w->read('app/Modules/Inventory/Models/Widget.php');
        expect($source)->toContain("const LABEL = 'Widget'", 'const ENABLED = '.$expected);
        expect($source)->toContain("'A'", "'B'");
    });
})->with([
    [['--tabs' => ['A,B'], '--no-enabled' => true], 'false'],
    [['--tabs' => ['A', 'B'], '--enabled' => true], 'true'],
]);

it('offers routes starts but stages them until the final confirmation', function (bool $write) {
    Workspace::run(null, function (Workspace $w) use ($write) {
        Tree::setup($w, routes: true);
        $w->write('stubs/mod.insert.tab-route.stub', '// route {{ tab }}');
        Examples::testCase()->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget', '--model' => 'Widget', '--tabs' => ['History']])
            ->expectsConfirmation('app/Modules/Inventory/routes/web.php has no mod:routes anchor. Create it with the anchor?', 'yes')
            ->expectsConfirmation('Write these 3 files and 3 inserts?', $write ? 'yes' : 'no')->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/routes/web.php'))->toBe($write)->and($w->exists(Tree::page('History')))->toBe($write);
        if ($write) {
            expect($w->read('app/Modules/Inventory/routes/web.php'))->toContain('// route History', '// mod:routes');
        }
    });
})->with([false, true]);

it('publishes finite registry metadata in mod:cache', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        $preset = app(CompiledLayout::class);
        DiscoveryRegistrar::register(app(), $preset);
        app(Kernel::class)->registerCommand(app(DiscoveryCacheCommand::class));
        $w->artisan('mod:cache')->assertSuccessful();
        $payload = require app(Discovery::class)->cache()->path;
        expect($payload['scaffolds']['resource-tabs']['children'])->toBe(['resource-tabs.tab'])
            ->and($payload['scaffolds']['resource-tabs.tab']['uses'])->toBe('tab-page');
    });
});

it('gives L6 package provenance and the overridden child members', function () {
    $registry = new ScaffoldRegistry;
    $registry->register('leaf', fn (Scaffold $s) => $s->makes('model'), 'acme/tabs-kit');
    $registry->register('root', fn (Scaffold $s) => $s->makes('controller')->part('tab', uses: 'leaf'), 'acme/tabs-kit');
    $registry->register('root.tab', fn (Part $p) => $p->uses('leaf'));
    $nodes = $registry->nodes();
    expect($nodes['root']['source'])->toBe('acme/tabs-kit')->and($nodes['root.tab']['source'])->toBe('app')
        ->and($nodes['root.tab']['from'])->toBe('app (overrides acme/tabs-kit)')->and($nodes['root.tab']['members']['model']['fileType'])->toBe('model');
    $nodes['root']['children'] = [];
    expect($registry->nodes()['root']['children'])->toBe(['root.tab']);
});

it('plans inline template members and forwards their slot options', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Agents/.keep', '');
        $w->write('stubs/mod/@module/Tools/[source]/tool.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { public string \$source = '{{ source }}'; }\n");
        Mod::scaffold('capability', fn (Scaffold $s) => $s->asks('tools', type: 'list', default: ['Search', 'Write'])
            ->part('tool', fn (Part $p) => $p->asks('tool')->makes('tool', name: '{name}{tool}'))
            ->each('tools', part: 'tool'));
        $w->artisan('mod:capability', ['name' => 'Agents:Documents', '--source' => 'Notion'])->assertSuccessful();
        expect($w->read('app/Modules/Agents/Tools/Notion/DocumentsSearch.php'))->toContain("public string \$source = 'Notion'");
    });
});

it('overrides a deeper node during creation and grows it inside its own parent', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/.keep', '');
        $w->write('stubs/mod.model.group.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {\n// mod:items\n}\n");
        $w->write('stubs/mod.insert.item.stub', "const {{ item }} = '{{ item }}';");
        Mod::scaffold('nested', fn (Scaffold $s) => $s->asks('groups', type: 'list', default: ['First'])
            ->part('group', fn (Part $p) => $p->asks('group')->asks('items', type: 'list', default: ['Child'])->makes('model', name: '{name}{group}', stub: 'group')
                ->part('item', fn (Part $p) => $p->asks('item')->makes('job', name: '{name}{group}{item}')->inserts(into: 'model', at: 'items', stub: 'item'))
                ->each('items', part: 'item'))
            ->each('groups', part: 'group'));
        Mod::scaffold('nested.group.item', fn (Part $p) => $p->asks('item')->makes('job', name: '{name}{group}{item}Audited')->inserts(into: 'model', at: 'items', stub: 'item'));
        $w->artisan('mod:nested', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Jobs/WidgetFirstChildAudited.php'))->toBeTrue();
        $w->artisan('mod:nested.group.item', ['name' => 'Inventory:Widget', 'value' => 'First/History'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Jobs/WidgetFirstHistoryAudited.php'))->toBeTrue()
            ->and($w->read('app/Modules/Inventory/Models/WidgetFirst.php'))->toContain("const History = 'History';");
    });
});

it('refuses a descendant insert into a grandparent alias', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/.keep', '');
        $w->write('stubs/mod.model.owner.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {\n// mod:entries\n}\n");
        $w->write('stubs/mod.insert.entry.stub', '// entry {{ entry }}');
        Mod::scaffold('owners', fn (Scaffold $s) => $s->makes('model', as: 'grandparent', stub: 'owner')->asks('groups', type: 'list', default: ['A'])
            ->part('group', fn (Part $p) => $p->asks('entries', type: 'list', default: ['B'])
                ->part('entry', fn (Part $p) => $p->inserts(into: 'grandparent', at: 'entries', stub: 'entry'))->each('entries', part: 'entry'))
            ->each('groups', part: 'group'));
        $w->artisan('mod:owners', ['name' => 'Inventory:Widget'])->assertFailed()->expectsOutputToContain('not owned by this node');
        expect($w->exists('app/Modules/Inventory/Models/Widget.php'))->toBeFalse();
    });
});

it('creates a missing cluster with the requested tab in one confirmed plan', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Examples::testCase()->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History', '--model' => 'Widget'])
            ->expectsConfirmation('There is no ManageWidgetViewModel in Inventory. Create the Widget resource with mod:resource-tabs first?', 'yes')
            ->expectsConfirmation('Write these 3 files and 2 inserts?', 'yes')->assertSuccessful();
        expect($w->read(Tree::base()))->toContain("['label' => 'History'")->and($w->exists(Tree::page('History')))->toBeTrue();
    });
});

it('restores existing bytes and removes generated files if applying an insert fails', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Tree::create($w);
        $before = $w->read(Tree::base());
        $controller = $w->read('app/Modules/Inventory/Controllers/WidgetController.php');
        $files = $w->files();
        $command = new class(app('files')) extends GenericClassCommand
        {
            public string $target;

            public function handle()
            {
                $result = parent::handle();
                if (! app(ScaffoldExecution::class)->planning) {
                    file_put_contents($this->target, str_replace('// mod:tabs', '', (string) file_get_contents($this->target)));
                }

                return $result;
            }
        };
        $command->target = $w->root->path(Tree::base());
        $preset = app(CompiledLayout::class);
        app(Kernel::class)->registerCommand($command->forKind($preset, $preset->kind('view-model')));
        $w->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History'])->assertFailed();
        expect($w->read(Tree::base()))->toBe($before)->and($w->read('app/Modules/Inventory/Controllers/WidgetController.php'))->toBe($controller)
            ->and($w->files())->toBe($files);
    });
});
