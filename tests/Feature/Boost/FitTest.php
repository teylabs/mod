<?php

use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Assert;
use Tey\Mod\Boost\FitTool;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Survey\Scope;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Boost\SurveyFixture;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;

it('checks an uninstalled layout without using or changing app registrations', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'missing');
        Mod::layout('laravel')->generates('model', in: 'Wrong');
        $w->write('app/Areas/Billing/Models/Invoice.php', '<?php namespace App\Areas\Billing\Models; class Invoice {}');
        $w->write('app/Areas/Billing/Http/InvoiceController.php', '<?php namespace Other; class InvoiceController {}');
        $w->write('src/Tasks/SendInvoiceTask.php', '<?php namespace Work\Tasks; class SendInvoiceTask {}');
        $w->write('notes.txt', 'NEVER_RETURN_THIS_BODY');
        $before = Scenario::bytes($w);
        $layouts = app(LayoutRegistry::class)->names();
        $tool = app(FitTool::class);
        $request = new Request(['layout' => [
            'base' => 'modules', 'path' => 'app/Areas/{area}',
            'mounts' => [['id' => 'work', 'namespace' => 'Work\\', 'path' => 'src']],
            'types' => [
                ['id' => 'controller', 'in' => '@area/Http', 'suffix' => 'Controller'],
                ['id' => 'task', 'in' => 'work:Tasks', 'suffix' => 'Task'],
            ],
        ]]);
        $data = Scenario::data($tool->handle($request), $tool);
        expect($data['owned'])->toBe([
            ['file_type' => 'controller', 'path' => 'app/Areas/Billing/Http/InvoiceController.php'],
            ['file_type' => 'model', 'path' => 'app/Areas/Billing/Models/Invoice.php'],
            ['file_type' => 'task', 'path' => 'src/Tasks/SendInvoiceTask.php'],
        ])->and($data['conflicts'])->toBe([[
            'expected' => 'App\Areas\Billing\Http\InvoiceController', 'found' => ['Other\InvoiceController'],
            'path' => 'app/Areas/Billing/Http/InvoiceController.php', 'reason' => 'class-mismatch',
        ]])->and($data['unowned'])->toContain('notes.txt', 'composer.json')
            ->and((string) $tool->handle($request)->content())->toBe((string) $tool->handle($request)->content())
            ->and(Scenario::bytes($w))->toBe($before)
            ->and(app(LayoutRegistry::class)->names())->toBe($layouts)
            ->and(app()->resolved(CompiledLayout::class))->toBeFalse()
            ->and(json_encode($data))->not->toContain('NEVER_RETURN_THIS_BODY');
    });
});

it('reports recipe roles at other paths outside touches and code configuration without executing PHP', function () {
    Workspace::run(null, function (Workspace $w) {
        Http::preventStrayRequests();
        SurveyFixture::using($w, function () use ($w) {
            $w->write('database/migrations/2026_02_02_000000_alter_posts.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Schema as Tables;
// Tables::table('comments', fn () => null);
Tables::table('posts', function ($table) {});
Tables::table('comments', function ($table) {});
Tables::table('posts' . '_archive', function ($table) {});
PHP);
            $w->write('app/Providers/PostServiceProvider.php', <<<'PHP'
<?php
namespace App\Providers;
use Acme\Text\Parser as Package;
use App\Models\Post;
class PostServiceProvider {
    public function boot() {
        Package::resolveUsing(fn () => new Post);
        Package::unrelated(function () { return 'DO_NOT_RETURN_CALLBACK_BODY'; });
    }
}
throw new \RuntimeException('Source must not execute or call the network');
\Illuminate\Support\Facades\Http::get('https://example.test');
PHP);
            $before = Scenario::bytes($w);
            $tool = app(FitTool::class);
            $request = new Request(['recipe' => ['name' => 'post-feature', 'tables' => ['posts'], 'members' => [
                ['id' => 'model', 'path' => 'app/Models/Post.php', 'class' => 'App\Models\Post'],
                ['id' => 'controller', 'path' => 'app/Modules/Blog/Controllers/PostController.php', 'class' => 'App\Http\Controllers\PostController'],
                ['id' => 'migration', 'path' => 'app/Modules/Blog/Migrations/2026_09_09_000000_create_posts_table.php'],
                ['id' => 'index', 'path' => 'app/Modules/Blog/pages/Posts/Index.vue', 'page' => 'Posts/Index'],
                ['id' => 'accept', 'path' => 'app/Http/Controllers/AcceptPostController.php'],
            ]]]);
            $response = $tool->handle($request);
            $data = Scenario::data($response, $tool);
            expect($data['owned'])->toBe([['member' => 'model', 'path' => 'app/Models/Post.php']])
                ->and(array_column($data['variances'], 'path', 'member'))->toEqual([
                    'controller' => 'app/Http/Controllers/PostController.php',
                    'index' => 'resources/js/pages/Posts/Index.vue',
                    'migration' => 'database/migrations/2026_01_01_000000_create_posts_table.php',
                ])->and($data['missing'])->toBe([['can_mark_variance' => true, 'member' => 'accept', 'path' => 'app/Http/Controllers/AcceptPostController.php']])
                ->and($data['conflicts'])->toBe([])
                ->and($data['unowned'])->not->toContain('resources/js/pages/Posts/Index.vue', 'app/Http/Controllers/PostController.php')
                ->and($data['outside'])->toContain(
                    ['kind' => 'import', 'member' => 'controller', 'path' => 'routes/web.php', 'recipe' => 'post-feature', 'target' => 'App\Http\Controllers\PostController'],
                    ['kind' => 'table', 'line' => 4, 'operation' => 'table', 'path' => 'database/migrations/2026_02_02_000000_alter_posts.php', 'recipe' => 'post-feature', 'target' => 'posts'],
                )->and($data['configuration'])->toBe([
                    ['line' => 7, 'members' => ['model'], 'ownership' => 'unowned', 'path' => 'app/Providers/PostServiceProvider.php', 'recipe' => 'post-feature', 'target' => 'Acme\Text\Parser::resolveUsing'],
                    ['line' => 8, 'members' => [], 'ownership' => 'unowned', 'path' => 'app/Providers/PostServiceProvider.php', 'recipe' => null, 'target' => 'Acme\Text\Parser::unrelated'],
                ])->and($data['vendor'])->toContain(['class' => 'Acme\Text\Parser', 'path' => 'vendor/acme/text/src/Parser.php'])
                ->and((string) $tool->handle($request)->content())->toBe((string) $response->content())
                ->and(Scenario::bytes($w))->toBe($before)
                ->and(class_exists('App\Providers\PostServiceProvider', false))->toBeFalse()
                ->and(json_encode($data))->not->toContain($w->root->path, 'DO_NOT_RETURN_CALLBACK_BODY', 'posts_archive', ...SurveyFixture::SECRETS);
        });
    });
});

it('lets an agent resolve renamed roles and alternative mechanisms without claiming semantic inference', function () {
    Workspace::run(null, function (Workspace $w) {
        $w->write('resources/js/pages/Settings/Members.vue', '<template>Settings</template>');
        $w->write('app/Http/Controllers/RegisterController.php', '<?php namespace App\Http\Controllers; class RegisterController {}');
        $w->write('routes/web.php', '<?php use App\Http\Controllers\RegisterController;');
        $tool = app(FitTool::class);
        $data = Scenario::data($tool->handle(new Request([
            'layout' => ['base' => 'laravel'],
            'recipe' => ['name' => 'invite', 'members' => [
                ['id' => 'page', 'path' => 'resources/js/pages/Invitations/Index.vue'],
                ['id' => 'accept', 'path' => 'app/Http/Controllers/AcceptInvitationController.php'],
                ['id' => 'register', 'path' => 'app/Http/Controllers/RegisterController.php'],
            ]],
            'variances' => [
                ['member' => 'page', 'path' => 'resources/js/pages/Settings/Members.vue'],
                ['member' => 'accept', 'path' => 'app/Http/Controllers/RegisterController.php', 'mechanism' => 'registration-flow'],
            ],
        ])), $tool);
        expect($data['missing'])->toBe([])->and($data['conflicts'])->toBe([])
            ->and(array_column($data['variances'], 'reason', 'member'))->toBe(['accept' => 'different-mechanism', 'page' => 'different-path'])
            ->and(array_column($data['variances'], 'evidence'))->toBe(['agent', 'agent'])
            ->and(array_column($data['outside'], 'member'))->toBe(['accept', 'register'])
            ->and($data['owned'])->toContain(['file_type' => 'controller', 'member' => 'register', 'path' => 'app/Http/Controllers/RegisterController.php'])
            ->and($data['unowned'])->not->toContain('resources/js/pages/Settings/Members.vue', 'app/Http/Controllers/RegisterController.php');
    });
});

it('reports ambiguous roles identity collisions and overlapping recipe claims rather than guessing', function () {
    Workspace::run(null, function (Workspace $w) {
        $w->write('app/Models/Post.php', '<?php namespace App\Models; class SomethingElse {}');
        $w->write('resources/js/pages/A/Show.vue', 'A');
        $w->write('resources/js/pages/B/Show.vue', 'B');
        $w->write('routes/web.php', '<?php // shared');
        $tool = app(FitTool::class);
        $data = Scenario::data($tool->handle(new Request(['recipe' => ['name' => 'posts', 'members' => [
            ['id' => 'model', 'path' => 'app/Models/Post.php', 'class' => 'App\Models\Post'],
            ['id' => 'page', 'path' => 'resources/js/pages/Post/Show.vue'],
            ['id' => 'routes', 'path' => 'routes/web.php'],
            ['id' => 'other-routes', 'path' => 'routes/web.php'],
        ]]])), $tool);
        expect(array_column($data['conflicts'], 'reason'))->toBe(['ambiguous-member', 'class-mismatch', 'member-overlap'])
            ->and($data['conflicts'][0]['candidates'])->toBe(['resources/js/pages/A/Show.vue', 'resources/js/pages/B/Show.vue'])
            ->and($data['missing'])->toBe([])
            ->and($data['variances'])->toBe([]);
    });
});

it('refuses malformed input and caller-controlled scan paths', function (array $input) {
    Workspace::run(null, function (Workspace $w) use ($input) {
        $before = Scenario::bytes($w);
        $tool = app(FitTool::class);
        expect($tool->handle(new Request($input))->isError())->toBeTrue()
            ->and(Scenario::bytes($w))->toBe($before);
    });
})->with([
    [[]], [['path' => '/outside']], [['layout' => ['base' => 'not-installed']]],
    [['layout' => ['base' => 'laravel', 'callback' => 'file_get_contents']]],
    [['recipe' => ['name' => 'bad', 'members' => [['id' => 'a', 'path' => 'a'], ['id' => 'a', 'path' => 'b']]]]],
    [['recipe' => ['name' => 'bad', 'members' => 'not-an-array']]],
    [['layout' => ['base' => 'laravel'], 'variances' => [['member' => 'absent', 'path' => 'composer.json']]]],
]);

it('refuses protected absolute traversal and network paths in proposals', function (string $path) {
    Workspace::run(null, function () use ($path) {
        $tool = app(FitTool::class);
        foreach ([
            ['recipe' => ['name' => 'unsafe', 'members' => [['id' => 'a', 'path' => $path]]]],
            ['layout' => ['base' => 'modules', 'path' => $path]],
            ['layout' => ['base' => 'laravel', 'mounts' => [['id' => 'unsafe', 'namespace' => 'Unsafe\\', 'path' => $path]]]],
            ['layout' => ['base' => 'laravel', 'types' => [['id' => 'model', 'in' => $path]]]],
        ] as $input) {
            expect($tool->handle(new Request($input))->isError())->toBeTrue();
        }
    });
})->with(['../outside', 'app/../../sibling', '/etc/passwd', 'C:\private', '\\host\private', 'https://example.test/source', '.env.local.php', 'storage/app/Hidden.php', 'keys/private.pem', 'database/data.sqlite', 'bootstrap/cache/config.php', 'public/build/Page.vue', 'vendor/acme/Model.php']);

it('skips oversized and linked sources and refuses them as agent evidence', function () {
    Workspace::run(null, function (Workspace $w) {
        $w->write('app/Oversized.php', '<?php class TooLarge {} //'.str_repeat('x', Scope::MAX_SOURCE_BYTES));
        OwnedAppRoot::using(function (OwnedAppRoot $outside) use ($w) {
            file_put_contents($outside->path('Outside.php'), '<?php class OutsideSecret {}');
            if (! @symlink($outside->path('Outside.php'), $w->root->path('app/Linked.php'))) {
                Assert::markTestSkipped('This filesystem cannot create symlinks.');
            }
            try {
                $tool = app(FitTool::class);
                $recipe = ['name' => 'safe', 'members' => [['id' => 'model', 'path' => 'app/Missing.php']]];
                $data = Scenario::data($tool->handle(new Request(['recipe' => $recipe])), $tool);
                expect($data['skipped'])->toBe([['path' => 'app/Linked.php', 'reason' => 'symlink'], ['path' => 'app/Oversized.php', 'reason' => 'size-limit']])
                    ->and(json_encode($data))->not->toContain('OutsideSecret', 'TooLarge', $outside->path);
                foreach (['app/Linked.php', 'app/Oversized.php'] as $path) {
                    expect($tool->handle(new Request(['recipe' => $recipe, 'variances' => [['member' => 'model', 'path' => $path]]]))->isError())->toBeTrue();
                }
            } finally {
                unlink($w->root->path('app/Linked.php'));
            }
        });
    });
});
