<?php

namespace Tey\Mod\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\Identifier;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Support\Path;
use Tey\Mod\Templates\PlaceholderFiller;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

/** A compiled generator template, using the ordinary generation plan and writer. */
class TemplateCommand extends GenericClassCommand
{
    /** @var array<string, string> */
    private array $answers = [];

    private bool $templateBound = false;

    public function forKind(CompiledLayout $preset, ArtifactKind $kind): static
    {
        $this->templateBound = true;
        parent::forKind($preset, $kind);
        foreach ($this->template()['slots'] as $slot) {
            $this->getDefinition()->addOption(new InputOption($slot, null, InputOption::VALUE_REQUIRED, 'The '.$slot.' folder and template value'));
        }

        return $this;
    }

    protected function placementOptions(): array
    {
        $options = parent::placementOptions();
        if ($this->templateBound) {
            foreach ($this->template()['slots'] as $slot) {
                unset($options[$slot]);
            }
        }

        return $options;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->answers = [];

        return parent::execute($input, $output);
    }

    /** @return array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool} */
    private function template(): array
    {
        return $this->layout()->templates()[$this->kind()->id];
    }

    private function interactive(): bool
    {
        return $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)));
    }

    protected function shorthand(): array
    {
        $raw = $this->rawNameInput();
        $colon = strpos($raw, ':');
        if ($colon !== false) {
            $prefix = substr($raw, 0, $colon);
            $name = substr($raw, $colon + 1);
            $groups = $this->template()['groups'];
            $slots = $this->template()['slots'];
            $parts = explode('/', $prefix);
            $nested = false;
            foreach ($this->layout()->dimensions() as $dimension) {
                if (in_array($dimension->name, $groups, true) && $dimension->multi) {
                    $nested = true;
                }
            }
            if (! $nested && $slots !== [] && count($parts) === count($groups) + 1) {
                $slot = $slots[0];
                $value = (string) array_pop($parts);
                $where = implode('/', $parts);
                $fix = "{$this->getName()} {$where}:{$name} --{$slot}={$value}";
                if (! $this->interactive()) {
                    throw GenerationRefused::because("[{$value}] looks like a {$slot}. Pass it with its option: {$fix}.");
                }
                if (! confirm("[{$value}] looks like a {$slot}. Use --{$slot}={$value}?")) {
                    throw GenerationRefused::because('Cancelled; nothing was written.');
                }
                $this->input->setOption($slot, $value);
                $this->input->setArgument('name', $where.':'.$name);
            }
            if ($groups === []) {
                $path = $this->resolveArtifact($this->kind()->id, $name, PlacementContext::none())->path();
                $message = "Layout [{$this->layoutName()}] takes no placement; drop the [{$prefix}:] prefix.";
                if (! $this->interactive()) {
                    throw GenerationRefused::because($message);
                }
                if (! confirm("Layout [{$this->layoutName()}] takes no placement. Write {$path} without [{$prefix}:]?")) {
                    throw GenerationRefused::because('Cancelled; nothing was written.');
                }
                $this->input->setArgument('name', $name);
            }
        }

        return parent::shorthand();
    }

    protected function placementContext(): PlacementContext
    {
        $context = parent::placementContext();
        foreach ($this->template()['groups'] as $group) {
            if (! $context->has($group) && ! isset($this->answers[$group]) && $this->interactive()) {
                $this->answers[$group] = (string) suggest('Which '.$group.'?', $this->existingGroups($group), hint: 'Type a new name to create a '.$group.'.', required: true);
            }
            if (isset($this->answers[$group])) {
                $context = $context->with($group, $this->answers[$group]);
            }
        }
        foreach ($this->template()['slots'] as $slot) {
            $value = $this->input->getOption($slot);
            if (! is_string($value) || trim($value) === '') {
                if (! isset($this->answers[$slot])) {
                    if (! $this->interactive()) {
                        throw GenerationRefused::because("{$this->getName()} needs a {$slot}. Pass --{$slot}=<{$slot}>.");
                    }
                    $this->answers[$slot] = (string) text('Which '.$slot.'?', hint: 'E.g. Drive. Used as the folder and as {{ '.$slot.' }}.', required: true);
                }
                $value = $this->answers[$slot];
            }
            if (! Identifier::isClassSegment($value)) {
                throw GenerationRefused::because("--{$slot} needs a single folder name. Pass --{$slot}=<{$slot}>.");
            }
            if ($context->has($slot)) {
                throw GenerationRefused::because("Slots are given only through their options. Pass --{$slot}={$value}.");
            }
            $context = $context->with($slot, $value);
        }

        return $context;
    }

    /** @return list<string> */
    private function existingGroups(string $group): array
    {
        $rule = $this->layout()->rule($this->kind()->id);
        if (! $rule instanceof TemplateRule) {
            return [];
        }
        $path = $rule->root()->path;
        foreach ($rule->segments() as $segment) {
            if ($segment->dimension === $group) {
                $directory = Path::resolve($this->laravel->basePath(), $path);
                if (! is_dir($directory)) {
                    return [];
                }

                return array_values(array_filter(scandir($directory) ?: [], static fn (string $name): bool => $name !== '.' && $name !== '..' && is_dir(Path::join($directory, $name))));
            }
            if ($segment->literal !== null) {
                $path = Path::join($path, $segment->literal);
            } elseif ($segment->dimension !== null && isset($this->answers[$segment->dimension])) {
                $path = Path::join($path, $this->answers[$segment->dimension]);
            }
        }

        return [];
    }

    protected function beforeGeneration(GenerationPlan $plan): void
    {
        $rule = $this->layout()->rule($this->kind()->id);
        if (! $rule instanceof TemplateRule) {
            return;
        }
        $path = $rule->root()->path;
        foreach ($rule->segments() as $segment) {
            $value = $segment->literal ?? ($segment->dimension !== null ? $plan->primary->context->get($segment->dimension) : null);
            if ($value === null) {
                continue;
            }
            $path = Path::join($path, $value);
            if (in_array($segment->dimension, $this->template()['slots'], true) && ! is_dir(Path::resolve($this->laravel->basePath(), $path))) {
                $this->components->info('Created new '.$segment->dimension.' '.$value.'.');
            }
        }
    }

    protected function replaceClass($stub, $name)
    {
        $stub = (new PlaceholderFiller)->fill($stub, class_basename($name), $this->primary()->context->toArray(), $this->rootNamespace());

        return parent::replaceClass($stub, $name);
    }
}
