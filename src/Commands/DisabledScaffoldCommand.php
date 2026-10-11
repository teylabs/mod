<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Plans\PlanWriter;

/** @internal hidden diagnostic placeholder, like OtherLayoutCommand; generates nothing */
final class DisabledScaffoldCommand extends Command
{
    public function __construct(string $name, private readonly string $problem, ?string $command = null)
    {
        $this->name = $command ?? 'mod:'.$name;
        parent::__construct();
        $this->setHidden();
        $this->addOption('dry-run');
        $this->addOption('json');
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            (new PlanWriter)->preview($this, $this->input, fn (Plan $plan) => $plan->warning($this->problem, code: str_contains($this->problem, '[vendor_required]') ? 'vendor_required' : 'generation_refused', data: ['command' => $this->getName()]));

            return self::FAILURE;
        }
        $this->components->warn($this->problem);
        $this->components->error('Command "'.$this->getName().'" is not defined.');

        return self::FAILURE;
    }
}
