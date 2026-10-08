<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Preset\Preset;

/**
 * The declarative generator for class kinds Laravel has no make:* for
 * (queries, actions, data objects...). Declaring the kind in the preset is
 * enough. The class comes from the kind's Stub when a package or the layout
 * declares one, else a plain class; stubs/mod.<kind>.stub in the
 * application replaces either.
 */
class GenericClassCommand extends GeneratorCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass {
        forKind as bindKind;
    }

    protected $name = 'mod:class';

    protected $description = 'Create a new class of a layout-declared kind';

    protected $type = 'Class';

    public function forKind(Preset $preset, ArtifactKind $kind): static
    {
        $this->bindKind($preset, $kind);

        $this->type = $kind->label ?? Str::headline($kind->id);
        $this->setDescription('Create a new '.($kind->label === null ? $kind->id : self::noun($kind->label)).' class');

        return $this;
    }

    /**
     * The label as a noun inside a sentence: "Value object" becomes "value object"; "DTO" stays.
     */
    private static function noun(string $label): string
    {
        return preg_match('/^\p{Lu}{2}/u', $label) === 1 ? $label : lcfirst($label);
    }

    protected function getStub()
    {
        return $this->modStubFile() ?? __DIR__.'/stubs/class.stub';
    }

    /**
     * @return list<array{0: string, 1: string, 2: int, 3: string}>
     */
    protected function getOptions()
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if it already exists'],
        ];
    }
}
