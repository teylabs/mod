<?php

namespace Tey\Mod\Artifact;

/** A layout's immutable file type metadata, without naming-policy internals.  @api */
final readonly class CompiledFileType
{
    /** @api */
    public string $id;

    /** @api */
    public ?string $command;

    /**
     * @api
     *
     * @var list<string>
     */
    public array $aliases;

    /** @api */
    public ?string $label;

    /** @api */
    public ?string $extension;

    /** @api */
    public ?string $case;

    private bool $classType;

    private bool $timestamped;

    /** @internal */
    public function __construct(ArtifactKind $type)
    {
        $this->id = $type->id;
        $this->command = $type->command;
        $this->aliases = $type->aliases;
        $this->label = $type->label;
        $this->extension = $type->extension;
        $this->case = $type->case;
        $this->classType = $type->isClass();
        $this->timestamped = $type->namePolicy->kind === NamePolicyKind::Timestamped;
    }

    /** @api */
    public function isClass(): bool
    {
        return $this->classType;
    }

    /** @api */
    public function isTimestamped(): bool
    {
        return $this->timestamped;
    }
}
