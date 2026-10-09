<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S13 exposes model and choice answers and names the missing option without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Mod::scaffold('notifier', fn (Scaffold $s) => $s->asks('subject', type: 'model', label: 'Which model is it about?')
            ->asks('channel', type: 'choice', options: ['mail', 'database'], default: 'mail')->makes('notification', stub: 'about'));
        $w->write('stubs/mod.notification.about.stub', "<?php\nnamespace {{ namespace }};\nuse {{ subject.fqcn }};\nclass {{ class }} { public function __construct(public {{ subject }} \${{ subject.camel }}) {} public function via(): array { return ['{{ channel }}']; } }\n");
        $failed = $w->artisan('mod:notifier', ['name' => 'Inventory:WidgetShared'])->assertFailed();
        expect($failed->normalisedOutput())->toBe("\n   ERROR  mod:notifier needs a subject. Pass --subject=<model>.  \n\n");
        $w->artisan('mod:notifier', ['name' => 'Inventory:WidgetShared', '--subject' => 'Widget', '--channel' => 'database'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Notifications/WidgetShared.php'))->toContain('use App\\Modules\\Inventory\\Models\\Widget;', "return ['database'];");
    });
});

it('S13 asks model and channel in a terminal and can cancel the write', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Mod::scaffold('notifier', fn (Scaffold $s) => $s->asks('subject', type: 'model', label: 'Which model is it about?')
            ->asks('channel', type: 'choice', options: ['mail', 'database'], default: 'mail')->makes('notification'));
        Examples::testCase()->artisan('mod:notifier', ['name' => 'Inventory:WidgetShared'])
            ->expectsQuestion('Which model is it about?', 'App\\Modules\\Inventory\\Models\\Widget')
            ->expectsChoice('Which model is it about?', 'App\\Modules\\Inventory\\Models\\Widget', ['App\\Modules\\Inventory\\Models\\Widget' => 'App\\Modules\\Inventory\\Models\\Widget'])
            ->expectsChoice('Channel', 'mail', ['mail', 'database'])->expectsConfirmation('Write these 1 files?', 'no')->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Notifications/WidgetShared.php'))->toBeFalse();
    });
});
