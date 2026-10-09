<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Generation\BaseWriter;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Plans\PlanWriter;

/**
 * mod:bases: write every base class the layout's file types can extend that
 * is missing, whether or not a class extends it yet, for example after
 * copying files from another application. An existing base is never
 * overwritten; a second run writes nothing.
 */
class BasesCommand extends Command
{
    protected $signature = 'mod:bases {--dry-run : Describe missing bases without writing} {--json : Print the dry-run plan as JSON}';

    protected $description = 'Create every missing base class the layout can use, whether or not a class extends it yet';

    protected $help = 'Writes every base class the layout\'s file types can extend (DataTransferObject, ViewModel, ...) that does not exist yet, '
        .'not only the ones your classes already use. Run it after copying files from another application. '
        .'An existing base is never overwritten; a file type that extends a configured base (mod.bases) or an installed package needs none.';

    private ?Plan $dryPlan = null;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dryPlan = null;
        if ($input->getOption('dry-run')) {
            return (new PlanWriter)->preview($this, $input, function (Plan $preview) use ($input, $output): void {
                $this->dryPlan = $preview;
                parent::execute($input, $output);
            });
        }

        return parent::execute($input, $output);
    }

    public function handle(CompiledLayout $preset, StubRegistry $stubs, PackageDetector $detector, BaseWriter $writer): int
    {
        $config = $this->laravel->make('config');
        $bases = [];
        $created = 0;

        foreach ($preset->kinds() as $kind) {
            $stub = $stubs->resolve($kind->id, $preset->stub($kind->id));

            if ($stub === null) {
                continue;
            }

            $configured = $config->get('mod.bases.'.$kind->id);
            $choice = $stub->choose($detector, static fn (string $key): mixed => $config->get($key), is_string($configured) ? $configured : null);

            if ($choice->generatedBase === null) {
                continue;
            }

            $location = $writer->locate($choice->generatedBase, $preset, $kind->id);

            if (isset($bases[$location['fqcn']])) {
                continue;
            }

            $bases[$location['fqcn']] = true;

            if ($this->dryPlan !== null) {
                $exists = is_file($this->laravel->basePath($location['path'])) || class_exists($location['fqcn']);
                if (! $exists) {
                    $this->dryPlan->file($kind->id.' (base)', $kind->id, $location['path'], [], false, $location['fqcn']);
                }

                continue;
            }
            if ($writer->ensure($choice->generatedBase, $location)) {
                $this->components->info("Created base class {$location['fqcn']} [{$location['path']}].");
                $created++;
            }
        }

        if ($this->dryPlan !== null) {
            $this->dryPlan->wouldWrite = $this->dryPlan->files !== [];

            return self::SUCCESS;
        }
        if ($bases === []) {
            $this->components->info('No file type in this layout extends a generated base class.');
        } elseif ($created === 0) {
            $this->components->info('Every base class already exists.');
        }

        return self::SUCCESS;
    }
}
