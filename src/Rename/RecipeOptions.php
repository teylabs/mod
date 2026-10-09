<?php

namespace Tey\Mod\Rename;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\ScaffoldRegistry;

/** @internal Register the union before input binding, including optional recovery arguments. */
final class RecipeOptions
{
    /** @var list<string> */
    private array $names = [];

    public function add(InputDefinition $definition, ScaffoldRegistry $registry, CompiledLayout $layout): void
    {
        $recipes = [...array_values($registry->all()), ...array_values($registry->resolved())];
        foreach ($registry->groups() as $group) {
            array_push($recipes, ...array_values($registry->forGroup($group)->all()));
        }
        $seen = [];
        for ($index = 0; $index < count($recipes); $index++) {
            $recipe = $recipes[$index];
            if (isset($seen[spl_object_id($recipe)])) {
                continue;
            }
            $seen[spl_object_id($recipe)] = true;
            foreach ($recipe->questions() as $question) {
                $mode = match ($question->type) {
                    'list' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                    'confirm' => InputOption::VALUE_NEGATABLE,
                    default => InputOption::VALUE_REQUIRED,
                };
                $this->option($definition, $question->name, $mode);
            }
            foreach ($recipe->parts() as $name => $part) {
                if (! in_array($name, $recipe->repetitions(), true)) {
                    $this->option($definition, $name, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
                }
                $recipes[] = $part;
            }
            if ($recipe instanceof Part && $recipe->scaffold() !== null && ($used = $registry->get($recipe->scaffold())) !== null) {
                $recipes[] = $used;
            }
            foreach ($recipe->repetitions() as $name => $part) {
                $this->option($definition, $name, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
            }
            foreach ($recipe->members() as $member) {
                if ($layout->hasFileType($member->fileType)) {
                    foreach (array_diff($layout->rule($member->fileType)->dimensions(), $layout->dimensionNames()) as $slot) {
                        $this->option($definition, $slot, InputOption::VALUE_REQUIRED);
                    }
                }
            }
        }
    }

    /** @param int<0, 31> $mode */
    private function option(InputDefinition $definition, string $name, int $mode): void
    {
        if (! $definition->hasOption($name)) {
            $definition->addOption(new InputOption($name, null, $mode, 'Explicit rename recipe answer; nested answers use --answer'));
            $this->names[] = $name;
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }
}
