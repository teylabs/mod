<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support;

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Process;
use Tey\Mod\Rename\Request;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

final class RenameScenario
{
    public static function setup(Workspace $w): void
    {
        putenv('COLUMNS=72');
        config()->set('mod.layout', 'modules');
        Mod::scaffold('model-only', fn (Scaffold $s) => $s->makes('model'));
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget {}\n");
        $w->write('app/Modules/Catalog/Models/.gitkeep', '');
        $w->write('.gitignore', "/.tey-mod-owned\n/vendor/\n/node_modules/\n/build/\n");
        self::git($w, ['init']);
        self::commit($w);
    }

    /** @param array<string, mixed> $options */
    public static function apply(Workspace $w, array $options = [], bool $success = true): void
    {
        $arguments = ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true, ...$options];
        if (! $success) {
            $index = self::git($w, ['ls-files', '--stage', '-z']);
            $status = self::git($w, ['status', '--porcelain=v1', '-z']);
            $w->artisan('mod:rename', $arguments)->assertFailed();
            expect(self::git($w, ['ls-files', '--stage', '-z']))->toBe($index)->and(self::git($w, ['status', '--porcelain=v1', '-z']))->toBe($status);

            return;
        }
        $request = new Request($arguments['old'], $arguments['new'], $arguments['--scaffold'], isset($options['--tabs']) ? ['tabs' => $options['--tabs']] : [], isset($options['--table-migration']));
        $result = app(Planner::class)->build($request);
        expect($result->inputs)->not->toBeNull();
        $w->artisan('mod:rename', $arguments)->assertSuccessful();
        foreach ($result->bodies as $path => $body) {
            expect($w->read($result->inputs->afterPath($path)))->toBe($body);
        }
        foreach ($result->plan->rename['moves'] as $move) {
            expect($w->exists($move['from']))->toBeFalse()->and($w->exists($move['to']))->toBeTrue();
        }
        expect(self::git($w, ['diff', '--name-only']))->toBe('');
    }

    public static function commit(Workspace $w): void
    {
        self::git($w, ['add', '--all']);
        self::git($w, ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'Fixture', '--allow-empty']);
    }

    /** @param list<string> $arguments */
    public static function git(Workspace $w, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $w->root->path, ['GIT_OPTIONAL_LOCKS' => '0'], timeout: 60);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function preview(Workspace $w, array $options = []): array
    {
        $before = self::state($w);
        $metadata = [$w->exists('.git/mod-rename/lock'), $w->exists('.git/mod-rename/journal.json')];
        $paths = self::contentPaths($w);
        $bytes = array_combine($paths, array_map($w->read(...), $paths));
        $result = $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--dry-run' => true, '--json' => true, ...$options])->assertSuccessful();
        $afterPaths = self::contentPaths($w);
        expect(array_combine($afterPaths, array_map($w->read(...), $afterPaths)))->toBe($bytes);
        expect(self::state($w))->toBe($before);
        expect([$w->exists('.git/mod-rename/lock'), $w->exists('.git/mod-rename/journal.json')])->toBe($metadata);

        return json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function contentPaths(Workspace $w): array
    {
        return array_values(array_filter($w->files(), static fn (string $path): bool => $path !== '.git' && ! str_starts_with($path, '.git/')));
    }

    /** @return array{status: string, index: string, staged: string, head: string} */
    private static function state(Workspace $w): array
    {
        return [
            'status' => self::git($w, ['status', '--porcelain=v1', '-z', '--untracked-files=all']),
            'index' => self::git($w, ['ls-files', '--stage', '-z']),
            'staged' => self::git($w, ['diff', '--cached', '--binary']),
            'head' => self::git($w, ['rev-parse', 'HEAD']),
        ];
    }
}
