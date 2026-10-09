<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\JsonSchema;

it('keeps laravel-ddd application folders and native markdown views', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        foreach (['controller' => 'Controllers/StockController', 'request' => 'Requests/StockRequest', 'middleware' => 'Middleware/Stock'] as $type => $file) {
            $w->artisan('mod:'.$type, ['name' => 'Inventory/Reports:Stock'])->assertSuccessful();
            expect($w->exists('app/Modules/Inventory/Reports/'.$file.'.php'))->toBeTrue();
        }
        config()->set('view.paths', [$w->root->path('resources/views')]);
        foreach (['mail' => 'Mail', 'notification' => 'Notifications'] as $type => $folder) {
            $options = ['name' => 'Inventory:StockChanged', '--markdown' => 'mail.'.$type];
            $before = $w->files();
            $plan = json_decode($w->artisan('mod:'.$type, [...$options, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
            expect($plan['files'])->toHaveCount(2)
                ->and($plan['files'][1]['identity']['name'])->toBe('mail.'.$type)
                ->and($plan['files'][1]['path'])->toBe('resources/views/mail/'.$type.'.blade.php')
                ->and($w->files())->toBe($before);
            $w->artisan('mod:'.$type, $options)->assertSuccessful();
            expect($w->exists('resources/views/mail/'.$type.'.blade.php'))->toBeTrue()
                ->and(str_replace("\r\n", "\n", $w->read('src/Domain/Inventory/'.$folder.'/StockChanged.php')))->toContain("'mail.".$type."'");
        }
        expect($w->exists('app/Modules/Inventory/resources'))->toBeFalse();
    });
});

it('reports every frontend field as null for plain ddd', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend'])->toBe(['pages' => null, 'components' => null, 'css' => null, 'views' => null, 'page_name' => null, 'view_namespace' => null, 'import_alias' => null])
            ->and(JsonSchema::errors($data, (new InventorySectionRegistry)->schema()))->toBe([]);
    });
});

it('refuses ddd pages with frontend guidance in either interaction mode', function (bool $interactive) {
    Workspace::run(null, function (Workspace $w) use ($interactive) {
        config()->set('mod.layout', 'ddd');
        $w->write('package.json', '{"dependencies":{"@inertiajs/vue3":"*"}}');
        $before = $w->files();
        $options = ['name' => 'Inventory:Stock/Index', '--no-interaction' => ! $interactive];
        expect(Artisan::call('mod:page', $options))->toBe(1)
            ->and(str_replace("\r\n", "\n", Artisan::output()))->toContain('->frontend(')
            ->and($w->files())->toBe($before);
    });
})->with([true, false]);

it('allows frontend generation when a child opts into ddd frontend paths', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'web-ddd');
        Mod::layout('web-ddd')->extends('ddd')->frontend(pages: 'app/Modules/{domain}/ui/js/pages', views: 'app/Modules/{domain}/ui/views', pageName: '{domain}::{path}')
            ->mounts('routes', null, 'app/Modules/{domain}/routes');
        $w->write('package.json', '{"dependencies":{"@inertiajs/vue3":"*"}}');
        $w->artisan('mod:page', ['name' => 'Inventory:Stock/Index'])->assertSuccessful();
        $w->artisan('mod:mail', ['name' => 'Inventory:StockChanged', '--markdown' => 'mail.stock'])->assertSuccessful();
        $w->artisan('mod:routes', ['module' => 'Inventory'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/ui/js/pages/Stock/Index.vue'))->toBeTrue()
            ->and($w->read('src/Domain/Inventory/Mail/StockChanged.php'))->toContain("'inventory::mail.stock'")
            ->and($w->exists('app/Modules/Inventory/ui/views/mail/stock.blade.php'))->toBeTrue()
            ->and($w->exists('app/Modules/Inventory/routes/web.php'))->toBeTrue();
    });
});

it('has no ddd routes mount and refuses route files before writing', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        expect(app(LayoutRegistry::class)->compile('ddd')->roots())->not->toHaveKey('routes');
        $before = $w->files();
        $w->artisan('mod:routes', ['module' => 'Inventory'])->assertFailed()->expectsOutputToContain("->mounts('routes', null");
        expect($w->files())->toBe($before);
    });
});

it('still checks case collisions for opted-in ddd frontend paths', function () {
    $registry = new LayoutRegistry;
    $registry->layout('web-ddd')->extends('ddd')->frontend(views: 'src/Domain/{domain}/resources/views');
    expect(fn () => $registry->compile('web-ddd'))->toThrow(InvalidLayout::class, 'differ only by case');
});

it('refuses frontend templates without declared paths before writing', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        $w->write('stubs/mod/@domain/ui/components/card.vue.stub', '<b>{{ name }}</b>');
        $before = $w->files();
        $w->artisan('mod:card', ['name' => 'Inventory:Stock'])->assertFailed()->expectsOutputToContain('->frontend(');
        expect($w->files())->toBe($before);
    });
});

it('requires frontend views before generating a ddd Blade component', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        config()->set('view.paths', [$w->root->path('resources/views')]);
        $before = $w->files();
        $w->artisan('mod:component', ['name' => 'Inventory:StockBadge'])->assertFailed()->expectsOutputToContain('->frontend(views: ...)');
        expect($w->files())->toBe($before);
    });
});

it('retains frontend and route roots in every other built-in', function (string $name) {
    $layout = (new LayoutRegistry)->compile($name);
    expect($layout->frontend()['pages'])->not->toBeNull()
        ->and($layout->frontend()['views'])->not->toBeNull()
        ->and($layout->roots())->toHaveKey('routes');
})->with(['laravel', 'modules', 'features', 'slices', 'type-first']);
