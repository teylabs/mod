<?php

namespace Tey\Mod\Artifact;

/**
 * A semantic kind of artifact (model, request, query, migration, ...).
 *
 * Kinds are data declared by the preset; the engine has no built-in list.
 * The constants below are only shared spellings for well-known ids.
 *
 * @internal
 */
final readonly class ArtifactKind
{
    public const MODEL = 'model';

    public const CONTROLLER = 'controller';

    public const REQUEST = 'request';

    public const FACTORY = 'factory';

    public const MIGRATION = 'migration';

    public const POLICY = 'policy';

    public const PROVIDER = 'provider';

    public const COMMAND = 'command';

    public const EVENT = 'event';

    public const LISTENER = 'listener';

    /**
     * @param  list<string>  $aliases  other command names that run the same command
     * @param  ?string  $label  the noun the kind's command prints ("DTO [...] created successfully.")
     */
    public function __construct(
        public string $id,
        public IdentityShape $shape,
        public NamePolicy $namePolicy,
        public ?string $command = null,
        public array $aliases = [],
        public ?string $label = null,
        public ?string $extension = null,
        public ?string $case = null,
    ) {}

    public static function phpClass(string $id, ?NamePolicy $namePolicy = null, ?string $command = null): self
    {
        return new self($id, IdentityShape::PhpClass, $namePolicy ?? NamePolicy::asGiven(), $command);
    }

    public static function file(string $id, ?NamePolicy $namePolicy = null, ?string $command = null): self
    {
        return new self($id, IdentityShape::File, $namePolicy ?? NamePolicy::asGiven(), $command);
    }

    public function isClass(): bool
    {
        return $this->shape === IdentityShape::PhpClass;
    }
}
