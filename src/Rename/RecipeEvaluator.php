<?php

namespace Tey\Mod\Rename;

use Closure;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Generation\PlainFile\Identity;
use Tey\Mod\Layout\BuiltIn\BuiltInLayouts;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Scaffolds\Member;
use Tey\Mod\Scaffolds\Placeholders;
use Tey\Mod\Scaffolds\QuestionAnswers;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;

/** @internal Identity evaluation only: never invokes generators, inserts or prompts. */
final readonly class RecipeEvaluator
{
    /** @param Closure(?string): CompiledLayout $layouts */
    public function __construct(private string $basePath, private ScaffoldRegistry $registry, private string $recipeName, private Closure $layouts) {}

    /** @param array<string, mixed> $answers
     * @return array<string, array{artifact: ResolvedArtifact, member: Member, identity: array<string, string>, layout: CompiledLayout}>
     */
    public function evaluate(Scaffold $recipe, CompiledLayout $layout, string $name, PlacementContext $context, array $answers): array
    {
        $outputs = [];
        $consumed = [];
        $this->node($recipe, $layout, $name, $context, $answers, [], '', $outputs, 0, $consumed);
        foreach (array_keys($answers) as $key) {
            if (! isset($consumed[$key])) {
                throw GenerationRefused::because("mod:rename answer {$key} is not used by the selected recipe. Correct the flag or qualified --answer key. Nothing was written.");
            }
        }

        return $outputs;
    }

    /** @param array<string, mixed> $answers
     * @param  array<string, mixed>  $given
     * @param  array<string, true>  $consumed
     * @param  array<string, array{artifact: ResolvedArtifact, member: Member, identity: array<string, string>, layout: CompiledLayout}>  $outputs
     */
    private function node(Scaffold $recipe, CompiledLayout $layout, string $name, PlacementContext $context, array $answers, array $given, string $prefix, array &$outputs, int $depth, array &$consumed): void
    {
        if ($depth > 10) {
            throw GenerationRefused::because('mod:rename recipe exceeds the depth limit. Describe a finite tree. Nothing was written.');
        }
        $values = ['name' => $name, ...$given];
        $resolver = new QuestionAnswers($layout, $this->basePath);
        foreach ($recipe->questions() as $key => $question) {
            if (array_key_exists($key, $given)) {
                continue;
            }
            $qualified = $prefix.$key;
            $option = $answers[$qualified] ?? ($prefix === '' ? ($answers[$key] ?? null) : null);
            // Defaults describe creation, never evidence of a historical tree.
            if ($option === null) {
                throw GenerationRefused::because("mod:rename cannot reconstruct {$this->recipeName} parts or answers. Pass --{$qualified} explicitly (nested answers may use --answer={$qualified}=<JSON>). Nothing was written.");
            }
            $consumed[$qualified] = true;
            $values[$key] = $question->type === 'list' && $option === [] ? [] : $resolver->answer($question, $option, false, 'mod:rename', $name);
        }
        foreach ($recipe->members() as $alias => $member) {
            $placement = $context;
            if ($member->ungrouped) {
                $placement = PlacementContext::none();
            } elseif ($member->group !== null) {
                $placement = PlacementContext::fromOption((new Placeholders($values))->render($member->group), $layout);
            }
            $group = implode('/', $placement->only($layout->dimensionNames())->toArray()) ?: null;
            $memberLayout = ($this->layouts)($group);
            foreach ($answers as $key => $value) {
                if (is_string($value) && in_array($key, array_diff($memberLayout->rule($member->fileType)->dimensions(), $layout->dimensionNames()), true)) {
                    $placement = $placement->with($key, $value);
                    $consumed[$key] = true;
                }
            }
            $stem = $member->name === null ? $name : (new Placeholders($values))->name($member->name);
            if (preg_match('/\{[^}]+\}/', $stem) === 1) {
                throw GenerationRefused::because("mod:rename cannot resolve member {$prefix}{$alias}. Provide every recipe answer. Nothing was written.");
            }
            $rule = $memberLayout->rule($member->fileType);
            if ($rule instanceof TemplateRule) {
                if ($member->ungrouped) {
                    $rule = (new BuiltInLayouts)->ungrouped($member->fileType) ?? $rule->withoutGroup($layout->dimensionNames());
                }
                if ($rule instanceof TemplateRule) {
                    $rule = $rule->withNestedNames();
                }
            }
            // A fixed timestamp makes migration identity evaluation deterministic;
            // historical lookup replaces it without ever producing a new timestamp.
            $artifact = $rule->place($memberLayout->kind($member->fileType), $stem, $placement, ['timestamp' => '0000_00_00_000000']);
            $key = $prefix.$alias;
            if (isset($outputs[$key])) {
                throw GenerationRefused::because("mod:rename duplicate member alias {$key}. Correct the recipe. Nothing was written.");
            }
            $identity = $artifact->fqcn() === null ? Identity::forms($artifact, $memberLayout) : ['class' => $artifact->fqcn(), 'path' => $artifact->path()];
            $outputs[$key] = ['artifact' => $artifact, 'member' => $member, 'identity' => $identity, 'layout' => $memberLayout];
            $values[$alias] = $artifact;
            if ($member->options !== []) {
                // Native option-driven generation may add members. Until it can be
                // evaluated exactly without generator effects, refuse rather than omit them.
                foreach ($member->options as $option => $value) {
                    $flag = is_int($option) ? (is_string($value) ? explode('=', $value, 2)[0] : '') : $option;
                    if (in_array(ltrim($flag, '-'), ['all', 'migration', 'factory', 'seed', 'policy', 'controller', 'requests', 'test', 'pest'], true) && $value !== false) {
                        throw GenerationRefused::because("mod:rename member {$key} has generating option {$flag}. Declare its generated members explicitly in the recipe. Nothing was written.");
                    }
                }
            }
        }
        foreach ($recipe->parts() as $partName => $part) {
            $list = array_search($partName, $recipe->repetitions(), true);
            $key = is_string($list) ? $list : $partName;
            $items = $values[$key] ?? $answers[$prefix.$key] ?? null;
            if ($items === null) {
                throw GenerationRefused::because("mod:rename cannot reconstruct {$this->recipeName} parts. Pass --{$prefix}{$key} for every existing part, including grown parts. Nothing was written.");
            }
            if (! is_array($items) && ! is_string($items)) {
                throw GenerationRefused::because("mod:rename --{$prefix}{$key} needs a list of existing part names. Nothing was written.");
            }
            $consumed[$prefix.$key] = true;
            $items = is_array($items) ? $items : explode(',', $items);
            if (count($items) !== count(array_unique($items, SORT_REGULAR))) {
                throw GenerationRefused::because("mod:rename --{$prefix}{$key} contains duplicate parts. Pass each part once. Nothing was written.");
            }
            foreach ($items as $item) {
                if (! is_string($item) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $item) !== 1) {
                    throw GenerationRefused::because("mod:rename --{$prefix}{$key} needs explicit part names. Nothing was written.");
                }
                $child = new Scaffold;
                if ($part->scaffold() !== null) {
                    $child = clone ($this->registry->get($part->scaffold()) ?? throw GenerationRefused::because('mod:rename referenced recipe is missing. Correct the recipe. Nothing was written.'));
                }
                $child->overlay($part);
                $given = $values;
                foreach ($part->values() as $answer => $value) {
                    $given[$answer] = is_string($value) ? (new Placeholders($values))->render($value) : $value;
                }
                $given[$partName] = $item;
                // Child questions must have their own explicit answers, except with: forwarding.
                foreach ($child->questions() as $question) {
                    if ($question->name !== $partName && ! array_key_exists($question->name, $part->values())) {
                        unset($given[$question->name]);
                    }
                }
                $this->node($child, $layout, $name, $context, $answers, $given, $prefix.$partName.'.'.$item.'.', $outputs, $depth + 1, $consumed);
            }
        }
    }
}
