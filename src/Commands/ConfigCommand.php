<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ConfigMakeCommand;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:config generator, placed as a class-less file by the preset.
 */
class ConfigCommand extends ConfigMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;

    public static function supports(ArtifactKind $kind): bool
    {
        return ! $kind->isClass();
    }

    protected function qualifyClass($name)
    {
        return $this->getNameInput();
    }

    protected function getPath($name): string
    {
        return $this->existingArtifacts()->absolute($this->primary()->path());
    }

    /**
     * Typed, as ConfigMakeCommand declares it (unlike the other generators).
     */
    protected function getStub(): string
    {
        return $this->modStubFile() ?? parent::getStub();
    }
}
