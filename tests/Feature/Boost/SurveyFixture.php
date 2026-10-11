<?php

namespace Tey\Mod\Tests\Feature\Boost;

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/**
 * A small Laravel app for mod-survey: two models with their controllers,
 * requests, policies, factories and Inertia pages, a service using a vendor
 * class, and secrets the survey must never read.
 */
final class SurveyFixture
{
    /** Text that appears only inside private files. */
    public const SECRETS = ['SECRET-ENV-VALUE', 'SECRET-LOG-LINE', 'SECRET-KEY-MATERIAL', 'SECRET-DATABASE-ROW', 'SECRET-CACHED-CONFIG', 'LeakedFromStorage', 'LeakedPage', 'SECRET-HIDDEN'];

    /**
     * Write the app, register its routes and policy, and run the callback
     * with a loader for its vendor folder.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function using(Workspace $w, callable $callback): mixed
    {
        foreach (['Post', 'Comment'] as $model) {
            $plural = $model.'s';
            $w->write("app/Models/{$model}.php", <<<PHP
<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;
use Illuminate\\Database\\Eloquent\\Model;

class {$model} extends Model
{
    use HasFactory;
}
PHP);
            $w->write("app/Http/Controllers/{$model}Controller.php", <<<PHP
<?php

namespace App\\Http\\Controllers;

use App\\Http\\Requests\\Store{$model}Request;
use App\\Models\\{$model};
use Inertia\\Inertia;

class {$model}Controller extends Controller
{
    public function index()
    {
        return Inertia::render('{$plural}/Index', ['items' => {$model}::all()]);
    }

    public function store(Store{$model}Request \$request)
    {
        return inertia('{$plural}/Show');
    }
}
PHP);
            $w->write("app/Http/Requests/Store{$model}Request.php", <<<PHP
<?php

namespace App\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;

class Store{$model}Request extends FormRequest {}
PHP);
            $w->write("app/Policies/{$model}Policy.php", <<<PHP
<?php

namespace App\\Policies;

use App\\Models\\{$model};
use App\\Models\\User;

class {$model}Policy {}
PHP);
            $w->write("database/factories/{$model}Factory.php", <<<PHP
<?php

namespace Database\\Factories;

use Illuminate\\Database\\Eloquent\\Factories\\Factory;

class {$model}Factory extends Factory {}
PHP);
            $w->write("resources/js/pages/{$plural}/Index.vue", '<template><div /></template>');
            $w->write("resources/js/pages/{$plural}/Show.vue", '<template><div /></template>');
        }
        $w->write('app/Models/User.php', "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\n\nclass User extends Authenticatable {}\n");
        $w->write('app/Http/Controllers/Controller.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nabstract class Controller {}\n");
        $w->write('app/Services/Markdown.php', <<<'PHP'
<?php

namespace App\Services;

use Acme\Text\{Parser, Renderer as Output};
use function Acme\Text\helper;

final class Markdown
{
    public function render(Parser $parser): string
    {
        return (function () use ($parser) { return ''; })();
    }
}
PHP);
        $w->write('routes/web.php', <<<'PHP'
<?php

use App\Http\Controllers\CommentController;
use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

Route::get('posts', [PostController::class, 'index']);
Route::post('comments', [CommentController::class, 'store']);
PHP);
        $w->write('database/migrations/2026_01_01_000000_create_posts_table.php', "<?php\n\nreturn new class {};\n");
        $w->write('vendor/acme/text/src/Parser.php', "<?php\n\nnamespace Acme\\Text;\n\nclass Parser {}\n");
        $w->write('vendor/acme/text/src/Renderer.php', "<?php\n\nnamespace Acme\\Text;\n\nclass Renderer {}\n");

        // Private files, each with a class or page name that must not surface either.
        $w->write('.env', "APP_KEY=SECRET-ENV-VALUE\n");
        $w->write('.env.production', "DB_PASSWORD=SECRET-ENV-VALUE\n");
        $w->write('storage/logs/laravel.log', "SECRET-LOG-LINE\n");
        $w->write('storage/app/LeakedFromStorage.php', "<?php\n\nnamespace App;\n\nuse App\\Models\\Post;\n\nclass LeakedFromStorage { function f() { return \\Inertia\\Inertia::render('LeakedPage'); } }\n");
        $w->write('storage/oauth-private.key', "SECRET-KEY-MATERIAL\n");
        $w->write('config/keys/app.pem', "SECRET-KEY-MATERIAL\n");
        $w->write('database/database.sqlite', 'SECRET-DATABASE-ROW');
        $w->write('bootstrap/cache/config.php', "<?php return ['key' => 'SECRET-CACHED-CONFIG'];\n");
        $w->write('.hidden/Leak.php', "<?php\n\nclass SecretHidden { const X = 'SECRET-HIDDEN'; }\n");

        Route::get('posts', ['App\\Http\\Controllers\\PostController', 'index']);
        Route::post('comments', ['App\\Http\\Controllers\\CommentController', 'store']);
        Route::get('vendor-page', ['Acme\\Text\\Parser', 'show']);
        Route::get('closure', fn () => 'ok');
        Gate::policy('App\\Models\\Comment', 'App\\Policies\\CommentPolicy');

        $loader = new ClassLoader($w->root->path('vendor'));
        $loader->addPsr4('Acme\\Text\\', $w->root->path('vendor/acme/text/src'));
        $loader->register();
        try {
            return $callback();
        } finally {
            $loader->unregister();
        }
    }
}
