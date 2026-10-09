<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Foundation\Console\ModelMakeCommand;
use Illuminate\Support\Str;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\ClassMembers;
use Tey\Mod\Generation\FactoryConvention;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Relation\RelationResolution;

/**
 * Native make:model, placed by the preset.
 *
 * Every companion option (--factory, --seed, --migration, --controller,
 * --requests, --policy, --all) follows a declared relation from the model
 * and runs the target kind's mod:* command; an option without a declared
 * relation is refused before anything is written.
 *
 * @api
 */
class ModelCommand extends ModelMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;

    /**
     * @return list<RelationResolution>
     *
     * @api
     */
    protected function plannedRelations(ResolvedArtifact $primary): array
    {
        $all = (bool) $this->option('all');
        $relations = [];

        if ($all || $this->option('factory')) {
            array_push($relations, ...$this->relationsTo($primary, $this->relatedFileType('factory')));
        }

        if ($all || $this->option('seed')) {
            array_push($relations, ...$this->relationsTo($primary, $this->relatedFileType('seeder')));
        }

        if ($all || $this->option('migration')) {
            // Placement only: mod:migration reads the real timestamp from the native clock.
            array_push($relations, ...$this->relationsTo($primary, $this->relatedFileType('migration'), $this->migrationName(), ['timestamp' => $this->laravel->make(ModMigrationCreator::class)->datePrefixFor($this->existingArtifacts()->absolute(dirname($this->resolveArtifact($this->relatedFileType('migration'), $this->migrationName(), $primary->context, ['timestamp' => '0000_00_00_000000'])->path())))]));
        }

        if ($all || $this->option('controller') || $this->option('resource') || $this->option('api')) {
            array_push($relations, ...$this->relationsTo($primary, $this->relatedFileType('controller')));
        } elseif ($this->option('requests')) {
            array_push($relations, ...$this->relationsTo($primary, $this->relatedFileType('request')));
        }

        if ($all || $this->option('policy')) {
            array_push($relations, ...$this->relationsTo($primary, $this->relatedFileType('policy')));
        }

        return $relations;
    }

    /** @internal */
    protected function generateScaffoldRelations(): void
    {
        $this->createFactory();
        $this->createMigration();
        $this->createSeeder();
        $this->createController();
        $this->createFormRequests();
        $this->createPolicy();
    }

    /** @internal */
    protected function createFactory()
    {
        foreach ($this->plannedRelationsTo($this->relatedFileType('factory')) as $relation) {
            $this->followRelation($relation, ['--model' => $this->primary()->fqcn()]);
        }
    }

    /** @internal */
    protected function createMigration()
    {
        foreach ($this->plannedRelationsTo($this->relatedFileType('migration')) as $relation) {
            $this->followRelation($relation, ['--create' => $this->tableName()]);
        }
    }

    /** @internal */
    protected function createSeeder()
    {
        foreach ($this->plannedRelationsTo($this->relatedFileType('seeder')) as $relation) {
            $this->followRelation($relation);
        }
    }

    /** @internal */
    protected function createController()
    {
        $resourceful = $this->option('resource') || $this->option('api');

        foreach ($this->plannedRelationsTo($this->relatedFileType('controller')) as $relation) {
            $this->followRelation($relation, [
                '--model' => $resourceful ? $this->primary()->fqcn() : null,
                '--api' => $this->option('api'),
                '--requests' => $this->option('requests') || $this->option('all'),
                '--test' => $this->option('test'),
                '--pest' => $this->option('pest'),
            ]);
        }
    }

    /** @internal */
    protected function createFormRequests()
    {
        foreach ($this->plannedRelationsTo($this->relatedFileType('request')) as $relation) {
            $this->followRelation($relation);
        }
    }

    /** @internal */
    protected function createPolicy()
    {
        foreach ($this->plannedRelationsTo($this->relatedFileType('policy')) as $relation) {
            $this->followRelation($relation, ['--model' => $this->primary()->fqcn()]);
        }
    }

    /**
     * HasFactory names the resolved factory; when Laravel's convention would
     * not find it (a factory outside Database\Factories) the model links it
     * explicitly with newFactory().
     *
     * @return array<string, string>
     *
     * @internal
     */
    protected function buildFactoryReplacements()
    {
        $factory = ($this->plannedRelationsTo($this->relatedFileType('factory'))[0] ?? null)?->target?->fqcn();

        if ($factory === null) {
            return parent::buildFactoryReplacements();
        }

        $source = GeneratorCommand::buildClass((string) $this->primary()->fqcn());
        $source = str_replace(['{{ factory }}', '{{ factoryImport }}'], '', $source);
        $members = new ClassMembers($source);
        $class = (string) $this->primary()->class()?->basename;
        $code = $members->hasTrait($class, 'HasFactory') || $members->hasTrait($class, 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory') ? '' : "/** @use HasFactory<\\{$factory}> */\n    use HasFactory;";

        if (! (new FactoryConvention($this->laravel->getNamespace()))->links((string) $this->primary()->fqcn(), $factory)
            && ! $members->hasMethod($class, 'newFactory')) {
            $code .= "\n\n    protected static function newFactory(): \\{$factory}\n    {\n        return \\{$factory}::new();\n    }";
        }

        return [
            '{{ factory }}' => $code,
            '{{ factoryImport }}' => $members->hasImport('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') ? '' : 'use Illuminate\Database\Eloquent\Factories\HasFactory;',
        ];
    }

    private function tableName(): string
    {
        $table = Str::snake(Str::pluralStudly($this->primary()->name));

        return $this->option('pivot') ? Str::singular($table) : $table;
    }

    private function migrationName(): string
    {
        return "create_{$this->tableName()}_table";
    }
}
