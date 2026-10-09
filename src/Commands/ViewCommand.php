<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ViewMakeCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Commands\Concerns\GeneratesViews;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GeneratorAdapter;

/** Laravel's view generator, placed in the layout's views folder. */
class ViewCommand extends ViewMakeCommand implements GeneratorAdapter
{
    use GeneratesViews;

    public static function supports(ArtifactKind $kind): bool
    {
        return ! $kind->isClass();
    }

    protected function plan(): GenerationPlan
    {
        // Placement resolves the group through the declared file type; the native view name uses dots.
        $anchor = $this->resolveArtifact($this->kind()->id, 'view', $this->placementContext());

        return new GenerationPlan($this->viewArtifact($anchor, $this->getNameInput(), is_string($extension = $this->option('extension')) ? $extension : 'blade.php'));
    }

    protected function qualifyClass($name)
    {
        return $this->getNameInput();
    }

    protected function getPath($name)
    {
        return $this->existingArtifacts()->absolute($this->primary()->path());
    }

    public function handle()
    {
        $verbosity = $this->output->getVerbosity();
        $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        try {
            $result = parent::handle();
        } finally {
            $this->output->setVerbosity($verbosity);
        }
        if ($result !== false) {
            $identity = $this->viewIdentity();
            $this->components->info("View [{$identity->path()}] created successfully. Use it with view('{$identity->name()}').");
        }

        return $result;
    }
}
