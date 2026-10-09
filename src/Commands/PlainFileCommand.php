<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Commands\Concerns\InteractsWithLayout;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Generation\PlainFile\Identity;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Plans\PlanWriter;
use Tey\Mod\Scaffolds\Placeholders;
use Tey\Mod\Support\Path;
use Tey\Mod\Support\Stack;

/** A namespace-free template using ordinary placement and scaffold planning. */
class PlainFileCommand extends Command implements GeneratorAdapter
{
    use InteractsWithLayout { forKind as bindKind; }

    protected $signature = 'mod:plain {name} {--force} {--dry-run} {--json}';

    protected $description = 'Create a plain file from a generator template';

    private ?Plan $preview = null;

    public static function supports(ArtifactKind $kind): bool
    {
        return ! $kind->isClass();
    }

    public function forKind(CompiledLayout $preset, ArtifactKind $kind): static
    {
        $this->bindKind($preset, $kind);
        foreach ($preset->templates()[$kind->id]['slots'] ?? [] as $slot) {
            if (! $this->getDefinition()->hasOption($slot)) {
                $this->getDefinition()->addOption(new InputOption($slot, null, InputOption::VALUE_REQUIRED, 'The '.$slot.' folder and template value'));
            }
        }

        return $this;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->preview = null;
        if ($input->getOption('dry-run')) {
            return (new PlanWriter)->preview($this, $input, function (Plan $plan) use ($input, $output): void {
                $this->preview = $plan;
                parent::execute($input, $output);
            });
        }

        return parent::execute($input, $output);
    }

    public function handle(): int
    {
        try {
            if ($this->kind()->id === 'page' && $this->laravel->make(Stack::class)->inertia() === null) {
                throw GenerationRefused::because('mod:page: No Inertia app found in package.json. Declare @inertiajs/vue3 or @inertiajs/react before creating a page.');
            }
            $context = $this->placementContext();
            foreach ($this->layout()->templates()[$this->kind()->id]['slots'] ?? [] as $slot) {
                $value = $this->option($slot);
                if (! is_string($value) || $value === '') {
                    if (! $this->interactive()) {
                        throw GenerationRefused::because($this->getName()." needs a {$slot}. Pass --{$slot}=<{$slot}>.");
                    }
                    $value = (string) \Laravel\Prompts\text('Which '.$slot.'?', required: true);
                }
                $context = $context->with($slot, $value);
            }
            $artifact = $this->resolveArtifact($this->kind()->id, $this->shorthand()[1], $context);
            $scope = $this->scaffoldExecution();
            $file = $this->templateFile();
            if ($scope?->planning) {
                $scope->collect(new GenerationPlan($artifact), $file);

                return self::SUCCESS;
            }
            $forms = Identity::forms($artifact, $this->layout());
            $values = ['name' => $artifact->name, ...$context->toArray()];
            foreach ($this->layout()->templates()[$this->kind()->id]['body_aliases'] ?? [] as $alias => $token) {
                $values[$alias] = $context->get($token);
            }
            $values = [...$values, ...($scope?->values[$artifact->path()] ?? [])];
            $file = $scope?->variants[$artifact->path()] ?? $file;
            $source = (string) file_get_contents($file);
            if ($this->kind()->id === 'page' && ! $this->laravel->make(Stack::class)->typescript() && $file === __DIR__.'/../Generation/PlainFile/stubs/page.vue.stub') {
                $source = str_replace(' lang="ts"', '', $source);
            }
            $warnings = [];
            $contents = (new Placeholders($values))->renderPlain($source, $this->kind()->extension ?? '', Path::relative($this->laravel->basePath(), $file) ?? $file, $warnings);
            $absolute = $this->laravel->basePath($artifact->path());
            if ($this->preview !== null) {
                $this->preview->artifact($this->kind()->id, $artifact, $this->laravel->basePath());
                foreach ($warnings as $warning) {
                    $this->preview->warning($warning['message'], blocking: false, file: $warning['file'], line: $warning['line']);
                }
                $this->preview->collisions((bool) $this->option('force'));

                return self::SUCCESS;
            }
            if ($scope?->keeps($artifact)) {
                return self::SUCCESS;
            }
            if (is_file($absolute) && ! $this->option('force') && ! ($scope !== null && $scope->force)) {
                if (! $this->interactive()) {
                    throw GenerationRefused::because($this->getName().' ['.$artifact->path().'] already exists. Pass --force to overwrite it. Nothing was written.');
                }
                if (! \Laravel\Prompts\confirm($artifact->path().' already exists. Overwrite it?', default: false)) {
                    return self::SUCCESS;
                }
            }
            $this->laravel->make('files')->ensureDirectoryExists(dirname($absolute));
            $this->laravel->make('files')->put($absolute, $contents);
            foreach ($warnings as $warning) {
                $this->components->warn($warning['message']);
            }
            $label = $this->kind()->label ?? Str::headline($this->kind()->id);
            $suffix = isset($forms['tag']) ? ' Use it as <'.$forms['tag'].' />.' : '';
            if ($this->kind()->id === 'page') {
                $suffix = " Render it with Inertia::render('".$forms['name']."').";
            }
            $this->components->info($label.' ['.$artifact->path().'] created successfully.'.$suffix);

            return self::SUCCESS;
        } catch (ModException $error) {
            if ($this->preview !== null || $this->scaffoldExecution()?->planning) {
                throw $error;
            }

            return $this->reportRefusal($error);
        }
    }

    private function interactive(): bool
    {
        return $this->input->isInteractive() && ($this->laravel->runningUnitTests() || (stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)));
    }

    private function templateFile(): string
    {
        $template = $this->layout()->templates()[$this->kind()->id] ?? null;
        if ($template !== null) {
            return $template['file'];
        }
        $extension = $this->kind()->extension ?? '.vue';
        $app = $this->laravel->basePath('stubs/mod.'.$this->kind()->id.$extension.'.stub');

        return is_file($app) ? $app : __DIR__.'/../Generation/PlainFile/stubs/page'.$extension.'.stub';
    }
}
