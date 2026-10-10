<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Rename\Executor;
use Tey\Mod\Rename\RecoveryInspector;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\ScaffoldRegistry;

/** @internal Rename capability is additive; generator inventory retains its meaning.
 * @phpstan-import-type SchemaFragment from InventorySection
 *
 * @phpstan-type Recipe array{name: string, source: string, required_answers: list<string>, parts: list<string>}
 * @phpstan-type Capability array{command: string, preview: bool, execution: bool, requires_git: bool, requires_clean_tree: bool, requires_scaffold: bool, flags: list<string>, nested_answer: string, recipes: list<Recipe>, recovery: array{inspection: bool, execution: bool, cluster_arguments: bool, clean_tree: bool, storage: string, compare_before_restore: bool}}
 */
final class RenameSection implements InventorySection
{
    /** @return array{rename: Capability} */
    public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
    {
        $registry = $app->make(ScaffoldRegistry::class);
        $recipes = [];
        foreach ($registry->resolved() as $name => $recipe) {
            $required = array_values(array_unique([...array_keys($recipe->questions()), ...array_keys($recipe->repetitions())]));
            if ($recipe instanceof Part && $recipe->scaffold() !== null) {
                $required = array_values(array_unique([...$required, ...array_keys($registry->get($recipe->scaffold())?->questions() ?? [])]));
            }
            $recipes[] = ['name' => $name, 'source' => $registry->sources()[$name] ?? 'app', 'required_answers' => $required, 'parts' => array_keys($recipe->parts())];
        }
        usort($recipes, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return ['rename' => ['command' => 'mod:rename', 'preview' => true, 'execution' => $app->bound(Executor::class), 'requires_git' => true, 'requires_clean_tree' => true, 'requires_scaffold' => true, 'flags' => ['--scaffold', '--answer', '--yes', '--no-interaction', '--dry-run', '--json', '--table-migration', '--recover'], 'nested_answer' => '--answer=part.Item.question=<JSON>', 'recipes' => $recipes, 'recovery' => ['inspection' => $app->bound(RecoveryInspector::class), 'execution' => $app->bound(Executor::class), 'cluster_arguments' => false, 'clean_tree' => false, 'storage' => 'worktree-git-directory', 'compare_before_restore' => true]]];
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        return InventorySectionRegistry::fragment('rename');
    }
}
