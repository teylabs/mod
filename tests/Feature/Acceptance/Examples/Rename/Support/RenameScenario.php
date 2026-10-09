<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support;

use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
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

    public static function commit(Workspace $w): void
    {
        self::git($w, ['add', '--all']);
        self::git($w, ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'Fixture', '--allow-empty']);
    }

    /** @param list<string> $arguments */
    public static function git(Workspace $w, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $w->root->path);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function preview(Workspace $w, array $options = []): array
    {
        $before = self::git($w, ['status', '--porcelain=v1']);
        $index = hash_file('sha256', $w->root->path('.git/index'));
        $paths = $w->files();
        $bytes = array_combine($paths, array_map($w->read(...), $paths));
        $result = $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--dry-run' => true, '--json' => true, ...$options])->assertSuccessful();
        $afterPaths = $w->files();
        expect(array_combine($afterPaths, array_map($w->read(...), $afterPaths)))->toBe($bytes);
        expect(self::git($w, ['status', '--porcelain=v1']))->toBe($before)
            ->and(hash_file('sha256', $w->root->path('.git/index')))->toBe($index);

        return json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
    }
}
