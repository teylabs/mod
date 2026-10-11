<?php

use Laravel\Mcp\Request;
use Symfony\Component\Process\Process;
use Tey\Mod\Boost\SurveyTool;
use Tey\Mod\Survey\GitState;
use Tey\Mod\Survey\PhpSource;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Boost\SurveyFixture;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function surveyData(array $arguments = []): array
{
    $tool = app(SurveyTool::class);

    return Scenario::data($tool->handle(new Request($arguments)), $tool);
}

/** @param array<string, mixed> $arguments */
function surveyText(array $arguments = []): string
{
    $tool = app(SurveyTool::class);
    $response = $tool->handle(new Request($arguments));
    expect($response->isError())->toBeFalse((string) $response->content());

    return $response->content()->toTool($tool)['text'];
}

/** @param array<string, mixed> $arguments */
function surveyError(array $arguments): string
{
    $tool = app(SurveyTool::class);
    $response = $tool->handle(new Request($arguments));
    expect($response->isError())->toBeTrue();

    return $response->content()->toTool($tool)['text'];
}

it('surveys a fixture app on the default laravel layout as the expected JSON', function () {
    Workspace::run(null, function (Workspace $w) {
        SurveyFixture::using($w, function () {
            $expected = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Boost/survey-laravel.json'), true, flags: JSON_THROW_ON_ERROR);
            expect(surveyData())->toBe($expected);
        });
    });
});

it('returns byte-identical JSON for the same tree', function () {
    Workspace::run(null, function (Workspace $w) {
        SurveyFixture::using($w, function () {
            $first = surveyText();
            expect(surveyText())->toBe($first)
                ->and(surveyText(['path' => '.']))->toBe($first);
        });
    });
});

it('never reads or reports private files, storage or caches', function () {
    Workspace::run(null, function (Workspace $w) {
        SurveyFixture::using($w, function () {
            $data = json_decode(surveyText(), true, flags: JSON_THROW_ON_ERROR);
            // The scan lists the excluded patterns themselves; nothing else may name them.
            expect($data['scan']['excluded'])->toContain('storage', 'bootstrap/cache', '.*', '*.key', '*.log', '*.sqlite');
            unset($data['scan']['excluded']);
            $text = json_encode($data, JSON_UNESCAPED_SLASHES);
            foreach ([...SurveyFixture::SECRETS, '.env', 'storage/', 'bootstrap/cache', 'oauth-private', 'app.pem', 'database.sqlite', '.hidden'] as $secret) {
                expect($text)->not->toContain($secret);
            }
        });
    });
});

it('refuses folders outside the application', function (string $path) {
    Workspace::run(null, function (Workspace $w) use ($path) {
        SurveyFixture::using($w, function () use ($path) {
            expect(surveyError(['path' => $path]))->toContain('mod-survey refuses')->toContain('inside this application');
        });
    });
})->with([
    'parent' => '..',
    'traversal back in' => 'app/../../',
    'sibling by traversal' => '../tey-mod-root-sibling',
    'absolute' => fn () => dirname(base_path()),
    'home' => fn () => (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '/root'),
    'missing' => 'no/such/folder',
]);

it('refuses private and vendor folders', function (string $path) {
    Workspace::run(null, function (Workspace $w) use ($path) {
        SurveyFixture::using($w, function () use ($path) {
            expect(surveyError(['path' => $path]))->toContain('mod-survey refuses');
        });
    });
})->with(['vendor', 'storage', 'storage/logs', '.hidden', 'bootstrap/cache', 'database/database.sqlite']);

it('refuses a link to a sibling folder and never follows links while walking', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $sibling) {
        file_put_contents($sibling->path('Sibling.php'), "<?php\n\nnamespace App;\n\nclass SiblingSecret {}\n");
        Workspace::run(null, function (Workspace $w) use ($sibling) {
            symlink($sibling->path, $w->root->path('app/linked'));
            symlink($sibling->path('Sibling.php'), $w->root->path('app/Linked.php'));
            SurveyFixture::using($w, function () {
                expect(surveyError(['path' => 'app/linked']))->toContain('outside this application');
                $text = surveyText();
                foreach (['SiblingSecret', 'app/linked', 'app/Linked.php'] as $linked) {
                    expect($text)->not->toContain($linked);
                }
            });
        });
    });
});

it('accepts a folder inside the application and limits the scan to it', function () {
    Workspace::run(null, function (Workspace $w) {
        SurveyFixture::using($w, function () use ($w) {
            $data = surveyData(['path' => 'app/Http']);
            $paths = array_merge(...array_map(static fn (array $type): array => array_merge([], ...array_map(static fn (array $group): array => array_column($group['files'], 'path'), $type['groups'])), $data['types']));
            expect($data['scan']['path'])->toBe('app/Http')
                ->and($paths)->not->toBeEmpty();
            foreach ([...$paths, ...$data['unplaced']['paths']] as $path) {
                expect($path)->toStartWith('app/Http/');
            }
            expect(surveyData(['path' => $w->root->path('app')])['scan']['path'])->toBe('app');
        });
    });
});

it('rejects a path that is not a string', function () {
    Workspace::run(null, function () {
        expect(surveyError(['path' => ['..']]))->toContain('mod-survey');
    });
});

it('reports null git fields outside a repository and never asks a parent repository', function () {
    Workspace::run(null, function (Workspace $w) {
        expect(surveyData()['scan']['git'])->toBe(['branch' => null, 'commit' => null, 'dirty' => null, 'remote' => null, 'root_commit' => null]);

        // A repository in the parent folder is not this application's.
        surveyGit($w->root->path, 'init', '-q', '-b', 'main');
        $w->write('nested/app/Thing.php', "<?php\n\nnamespace App;\n\nclass Thing {}\n");
        app()->setBasePath($w->root->path('nested'));
        expect(surveyData()['scan']['git'])->toBe(['branch' => null, 'commit' => null, 'dirty' => null, 'remote' => null, 'root_commit' => null]);
        app()->setBasePath($w->root->path);
    });
});

it('reports the branch, commit, dirty state and identity of the app repository', function () {
    Workspace::run(null, function (Workspace $w) {
        surveyGit($w->root->path, 'init', '-q', '-b', 'main');
        surveyGit($w->root->path, 'remote', 'add', 'origin', 'https://user:token@example.com/acme/shop.git');
        surveyGit($w->root->path, 'add', '-A');
        surveyGit($w->root->path, 'commit', '-q', '-m', 'Start');
        $commit = surveyGit($w->root->path, 'rev-parse', 'HEAD');
        expect(surveyData()['scan']['git'])->toBe(['branch' => 'main', 'commit' => $commit, 'dirty' => false, 'remote' => 'https://example.com/acme/shop.git', 'root_commit' => $commit]);

        $w->write('app/Models/Post.php', "<?php\n\nnamespace App\\Models;\n\nclass Post {}\n");
        surveyGit($w->root->path, 'add', '-A');
        surveyGit($w->root->path, 'commit', '-q', '-m', 'Add a model');
        surveyGit($w->root->path, 'checkout', '-q', '--detach');
        $w->write('app/Models/Draft.php', "<?php\n\nnamespace App\\Models;\n\nclass Draft {}\n");
        $git = surveyData()['scan']['git'];
        expect($git['branch'])->toBeNull()
            ->and($git['commit'])->toBe(surveyGit($w->root->path, 'rev-parse', 'HEAD'))
            ->and($git['dirty'])->toBeTrue()
            ->and($git['root_commit'])->toBe($commit);
    });
});

it('removes credentials from remote URLs', function (string $url, string $expected) {
    expect(GitState::withoutCredentials($url))->toBe($expected);
})->with([
    ['https://user:token@github.com/acme/shop.git', 'https://github.com/acme/shop.git'],
    ['ssh://git@github.com/acme/shop.git', 'ssh://github.com/acme/shop.git'],
    ['git@github.com:acme/shop.git', 'git@github.com:acme/shop.git'],
    ['https://github.com/acme/shop', 'https://github.com/acme/shop'],
]);

it('reads declarations, imports and literal Inertia pages with the tokenizer', function () {
    $source = PhpSource::parse(<<<'PHP'
<?php
namespace App\Http\Controllers;

use App\Models\{Post, Comment as Note};
use Inertia\Inertia as Pages, Illuminate\Http\Request;
use function App\helper;
use const App\LIMIT;

// use App\Commented\Out;
final class PostController extends Controller
{
    use App\Concerns\NotAnImport;

    public function index()
    {
        $label = 'use App\InString;';
        $closure = function () use ($label) {};
        $other = Other::render('NotInertia');
        Pages::render('Posts/Index');
        \Inertia\Inertia::render('Posts/Show', []);
        inertia('Posts/Edit');
        inertia($dynamic);
        Pages::render('Posts/'.$name);
        return PostController::class;
    }
}

enum Status: string {}
interface Contract {}
PHP);
    expect($source->classes)->toBe(['App\\Http\\Controllers\\Contract', 'App\\Http\\Controllers\\PostController', 'App\\Http\\Controllers\\Status'])
        ->and($source->imports)->toBe(['App\\Models\\Comment', 'App\\Models\\Post', 'Illuminate\\Http\\Request', 'Inertia\\Inertia'])
        ->and($source->pages)->toBe(['Posts/Edit', 'Posts/Index', 'Posts/Show']);
});

it('clusters a set spread across folders in a grouped layout', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Blog/Models/Post.php', "<?php\n\nnamespace App\\Modules\\Blog\\Models;\n\nclass Post {}\n");
        $w->write('app/Modules/Blog/Models/Tag.php', "<?php\n\nnamespace App\\Modules\\Blog\\Models;\n\nclass Tag {}\n");
        // Not linked to the model by any reference: it joins through its folder.
        $w->write('app/Modules/Blog/Http/Controllers/PostArchiveController.php', "<?php\n\nnamespace App\\Modules\\Blog\\Http\\Controllers;\n\nclass PostArchiveController {}\n");
        $data = surveyData();
        $controller = $data['types'][array_search('controller', array_column($data['types'], 'id'), true)];
        expect($controller['groups'])->toBe([['context' => ['module' => 'Blog'], 'files' => [['class' => 'App\\Modules\\Blog\\Http\\Controllers\\PostArchiveController', 'path' => 'app/Modules/Blog/Http/Controllers/PostArchiveController.php']], 'folder' => 'app/Modules/Blog/Http/Controllers', 'namespace' => 'App\\Modules\\Blog\\Http\\Controllers']])
            ->and($data['scan']['layout'])->toBe('modules')
            ->and($data['sets'])->toBe([['anchor' => 'App\\Modules\\Blog\\Models\\Post', 'members' => [['basis' => ['folder'], 'id' => 'App\\Modules\\Blog\\Http\\Controllers\\PostArchiveController', 'type' => 'controller']]]]);
    });
});

it('converts an invalid layout into a tool error', function () {
    Workspace::run(null, function () {
        config()->set('mod.layout', 'missing-layout');
        expect(surveyError([]))->toContain('mod-survey');
    });
});

function surveyGit(string $directory, string ...$arguments): string
{
    $process = new Process(['git', '-c', 'user.name=Survey', '-c', 'user.email=survey@localhost', '-c', 'commit.gpgsign=false', ...$arguments], $directory, ['GIT_CEILING_DIRECTORIES' => dirname($directory)]);
    $process->mustRun();

    return trim($process->getOutput());
}
