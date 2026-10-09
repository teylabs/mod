<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ComponentMakeCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Commands\Concerns\GeneratesViews;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Views\ViewIdentity;

/** Laravel's Blade component generator, including anonymous views. */
class ComponentCommand extends ComponentMakeCommand implements GeneratorAdapter
{
    use GeneratesViews { plan as private componentPlan; }

    protected function companionViewName(): ?string
    {
        if ($this->option('inline') && ! $this->option('view')) {
            return null;
        }
        if ($this->layout()->frontend()['views'] === null) {
            throw GenerationRefused::because($this->getName().' needs a frontend views folder. Declare ->frontend(views: ...) on the layout in a service provider. Nothing was written.');
        }
        $name = str_replace(['\\', '.'], '/', $this->getNameInput());
        $parts = array_map(Str::kebab(...), explode('/', $name));
        $last = array_pop($parts);
        $path = $this->option('path');

        return (is_string($path) ? implode('.', array_map(Str::kebab(...), explode('/', trim($path, '/')))) : 'components'.($parts !== [] ? '.'.implode('.', $parts) : '')).'.'.$last;
    }

    protected function plan(): GenerationPlan
    {
        $plan = $this->componentPlan();

        return $this->option('view') ? new GenerationPlan($plan->generated()[0]) : $plan;
    }

    public function handle()
    {
        parent::handle();
        if ($this->option('inline') && ! $this->option('view')) {
            $this->components->info('Use it as <'.$this->componentTag().' />.');
        }
    }

    private function componentTag(): string
    {
        if ($this->option('view')) {
            return $this->viewIdentity()->tag();
        }
        $group = implode('/', $this->primary()->context->only($this->layout()->dimensionNames())->toArray()) ?: null;
        $name = implode('.', array_map(Str::kebab(...), explode('/', str_replace(['\\', '.'], '/', $this->getNameInput()))));

        return (new ViewIdentity($group, 'components.'.$name, ''))->tag();
    }

    protected function getView()
    {
        return $this->viewIdentity()->name();
    }

    protected function writeView()
    {
        $verbosity = $this->output->getVerbosity();
        $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        try {
            parent::writeView();
        } finally {
            $this->output->setVerbosity($verbosity);
        }
        $identity = $this->viewIdentity();
        $label = $this->option('view') ? 'Component' : 'View';
        $this->components->info("{$label} [{$identity->path()}] created successfully. Use it as <{$this->componentTag()} />.");
    }
}
