<?php

use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

function optionalRecipe(): void
{
    Mod::scaffold('optional', fn (Scaffold $s) => $s
        ->asks('modal', type: 'confirm', default: false)
        ->asks('authorize', type: 'confirm', default: true)
        ->makes('model', stub: 'optional')
        ->makes('job', name: '{name}Modal', as: 'dialog', when: 'modal')
        ->makes('policy', when: fn (array $answers): bool => $answers['authorize'] ?? true));
}

function optionalWorkspace(Workspace $w): void
{
    config()->set('mod.layout', 'modules');
    $w->write('app/Modules/Inventory/.keep', '');
    $w->write('stubs/mod.model.optional.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    {{ #modal }}\n    public const MODAL = {{ dialog }}::class;\n    {{ /modal }}\n    {{ ^modal }}\n    public const MODAL = null;\n    {{ /modal }}\n    // {{ dialog.fqcn }}|{{ policy }}|\n}\n");
    optionalRecipe();
}

it('generates optional members by default flags and leaves the others out', function (array $flags, array $present, array $absent) {
    Workspace::run(null, function (Workspace $w) use ($flags, $present, $absent) {
        optionalWorkspace($w);
        $w->artisan('mod:optional', ['name' => 'Inventory:Widget', ...$flags])->assertSuccessful();
        foreach ($present as $path) {
            expect($w->exists('app/Modules/Inventory/'.$path))->toBeTrue($path);
        }
        foreach ($absent as $path) {
            expect($w->exists('app/Modules/Inventory/'.$path))->toBeFalse($path);
        }
    });
})->with([
    'defaults' => [[], ['Models/Widget.php', 'Policies/WidgetPolicy.php'], ['Jobs/WidgetModal.php']],
    '--modal' => [['--modal' => true], ['Models/Widget.php', 'Policies/WidgetPolicy.php', 'Jobs/WidgetModal.php'], []],
    '--no-authorize' => [['--no-authorize' => true], ['Models/Widget.php'], ['Jobs/WidgetModal.php', 'Policies/WidgetPolicy.php']],
    'both' => [['--modal' => true, '--no-authorize' => true], ['Models/Widget.php', 'Jobs/WidgetModal.php'], ['Policies/WidgetPolicy.php']],
]);

it('lists skipped members with their reasons in the dry run and leaves them out of the files', function () {
    Workspace::run(null, function (Workspace $w) {
        optionalWorkspace($w);
        $plan = json_decode($w->artisan('mod:optional', ['name' => 'Inventory:Widget', '--no-authorize' => true, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);

        expect(array_column($plan['files'], 'alias'))->toBe(['model'])
            ->and($plan['skipped'])->toBe([
                ['alias' => 'dialog', 'type' => 'job', 'reason' => 'modal is false'],
                ['alias' => 'policy', 'type' => 'policy', 'reason' => 'when() returned false'],
            ])
            ->and($plan['warnings'])->toBe([])
            ->and($w->exists('app/Modules/Inventory/Models/Widget.php'))->toBeFalse();
    });
});

it('reports an empty skipped list when every conditional member is generated, and none for other recipes', function () {
    Workspace::run(null, function (Workspace $w) {
        optionalWorkspace($w);
        Mod::scaffold('plain', fn (Scaffold $s) => $s->makes('model'));
        $plan = json_decode($w->artisan('mod:optional', ['name' => 'Inventory:Widget', '--modal' => true, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);
        $plain = json_decode($w->artisan('mod:plain', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);

        expect($plan['skipped'])->toBe([])->and(array_column($plan['files'], 'alias'))->toBe(['model', 'dialog', 'policy'])
            ->and($plain)->not->toHaveKey('skipped');
    });
});

it('names skipped members in the human plan', function () {
    Workspace::run(null, function (Workspace $w) {
        optionalWorkspace($w);
        $text = $w->artisan('mod:optional', ['name' => 'Inventory:Widget', '--dry-run' => true])->assertSuccessful()->normalisedOutput();
        expect($text)->toContain('dialog ')->toContain('skipped: modal is false');
    });
});

it('turns a skipped member alias into an empty placeholder and a false block', function () {
    Workspace::run(null, function (Workspace $w) {
        optionalWorkspace($w);
        $w->artisan('mod:optional', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Models/Widget.php'))->toBe("<?php\n\nnamespace App\\Modules\\Inventory\\Models;\n\nclass Widget\n{\n    public const MODAL = null;\n    // |WidgetPolicy|\n}\n");
        $w->remove(['app/Modules/Inventory/Models/Widget.php', 'app/Modules/Inventory/Policies/WidgetPolicy.php']);
        $w->artisan('mod:optional', ['name' => 'Inventory:Widget', '--modal' => true, '--no-authorize' => true])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Models/Widget.php'))->toBe("<?php\n\nnamespace App\\Modules\\Inventory\\Models;\n\nclass Widget\n{\n    public const MODAL = WidgetModal::class;\n    // App\\Modules\\Inventory\\Jobs\\WidgetModal||\n}\n");
    });
});

it('skips inserts into a skipped member and keeps the others', function (bool $modal) {
    Workspace::run(null, function (Workspace $w) use ($modal) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/.keep', '');
        $w->write('stubs/mod.model.anchored.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {\n// mod:entries\n}\n");
        $w->write('stubs/mod.job.anchored.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {\n// mod:entries\n}\n");
        $w->write('stubs/mod.insert.entry.stub', "const {{ entry }} = '{{ entry }}';");
        Mod::scaffold('entries', fn (Scaffold $s) => $s
            ->asks('modal', type: 'confirm', default: false)
            ->asks('items', type: 'list', default: ['History'])
            ->makes('model', stub: 'anchored')
            ->makes('job', name: '{name}Modal', as: 'modal', stub: 'anchored', when: 'modal')
            ->each('items', 'entry')
            ->part('entry', fn (Part $p) => $p->inserts(into: 'model', at: 'entries', stub: 'entry')->inserts(into: 'modal', at: 'entries', stub: 'entry')));
        $plan = json_decode($w->artisan('mod:entries', ['name' => 'Inventory:Widget', $modal ? '--modal' : '--no-modal' => true, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);
        expect(array_column($plan['inserts'], 'into'))->toBe($modal ? ['app/Modules/Inventory/Models/Widget.php', 'app/Modules/Inventory/Jobs/WidgetModal.php'] : ['app/Modules/Inventory/Models/Widget.php'])
            ->and(array_column($plan['skipped'], 'type'))->toBe($modal ? [] : ['job', 'insert'])
            ->and($plan['warnings'])->toBe([]);
        $w->artisan('mod:entries', ['name' => 'Inventory:Widget', $modal ? '--modal' : '--no-modal' => true])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Models/Widget.php'))->toContain("const History = 'History';")
            ->and($w->exists('app/Modules/Inventory/Jobs/WidgetModal.php'))->toBe($modal);
    });
})->with([true, false]);

it('treats a skipped member as absent when renaming, and a generated one as missing when it is gone', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('optional-rename', fn (Scaffold $s) => $s->asks('modal', type: 'confirm')->makes('model')->makes('job', name: '{name}Modal', as: 'modal', when: 'modal'));
        S::commit($w);
        $off = S::preview($w, ['--scaffold' => 'optional-rename', '--no-modal' => true]);
        expect($off['warnings'])->toBe([])->and(array_column($off['moves'], 'alias'))->toBe(['model']);

        $w->write('app/Modules/Inventory/Jobs/WidgetModal.php', "<?php\nnamespace App\\Modules\\Inventory\\Jobs;\nclass WidgetModal {}\n");
        S::commit($w);
        $on = S::preview($w, ['--scaffold' => 'optional-rename', '--modal' => true]);
        $aliases = array_column($on['moves'], 'alias');
        sort($aliases);
        expect($on['warnings'])->toBe([])->and($aliases)->toBe(['modal', 'model']);

        $w->remove(['app/Modules/Inventory/Jobs/WidgetModal.php']);
        S::commit($w);
        $missing = S::preview($w, ['--scaffold' => 'optional-rename', '--modal' => true]);
        expect($missing['warnings'][0]['message'])->toContain('WidgetModal.php is missing');
    });
});

it('refuses rename without the answer that decides an optional member', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('optional-rename', fn (Scaffold $s) => $s->asks('modal', type: 'confirm')->makes('model')->makes('job', name: '{name}Modal', as: 'modal', when: 'modal'));
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'optional-rename']);
        expect($data['warnings'][0]['message'])->toContain('Pass --modal explicitly');
    });
});

it('disables a recipe whose when names no confirm question or earlier member', function (Closure $recipe, string $message) {
    Workspace::run(null, function (Workspace $w) use ($recipe, $message) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('broken', $recipe);
        $registry = app(ScaffoldRegistry::class);
        $registry->resolve(app(CompiledLayout::class), 'modules', []);
        expect($registry->problems()['broken'])->toContain($message);
    });
})->with([
    'unknown' => [fn (Scaffold $s) => $s->makes('model')->makes('policy', when: 'nope'), "has when: 'nope'"],
    'a text question' => [fn (Scaffold $s) => $s->asks('label')->makes('policy', when: 'label'), "has when: 'label'"],
    'a later member' => [fn (Scaffold $s) => $s->makes('policy', when: 'model')->makes('model'), "has when: 'model'"],
]);

it('accepts an earlier member alias as the condition', function () {
    Workspace::run(null, function (Workspace $w) {
        optionalWorkspace($w);
        Mod::scaffold('chained', fn (Scaffold $s) => $s->asks('authorize', type: 'confirm', default: true)->makes('policy', when: 'authorize')->makes('job', when: 'policy'));
        $w->artisan('mod:chained', ['name' => 'Inventory:Widget', '--no-authorize' => true])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Jobs/Widget.php'))->toBeFalse();
        $w->artisan('mod:chained', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Jobs/Widget.php'))->toBeTrue();
    });
});

it('refuses an empty when and a part when that names no confirm question', function () {
    expect(fn () => (new Scaffold)->makes('model', when: ''))->toThrow(GenerationRefused::class, 'needs a confirm question name or a closure');
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/.keep', '');
        Mod::scaffold('parted', fn (Scaffold $s) => $s->asks('items', type: 'list', default: ['A'])->each('items', 'item')->part('item', fn (Part $p) => $p->makes('job', name: '{name}{item}', when: 'ghost')));
        $result = $w->artisan('mod:parted', ['name' => 'Inventory:Widget'])->assertFailed();
        expect($result->normalisedOutput())->toContain("when: 'ghost', which is not a confirm question or an earlier member");
    });
});
