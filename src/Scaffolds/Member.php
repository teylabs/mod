<?php

namespace Tey\Mod\Scaffolds;

use Closure;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;

/** @internal one file type in a scaffold recipe */
final readonly class Member
{
    /**
     * @param  array<array-key, mixed>  $options
     * @param  string|(Closure(array<string, mixed>): bool)|null  $when  a confirm question name, or a closure over the answers
     */
    public function __construct(
        public string $fileType,
        public ?string $name = null,
        public ?string $stub = null,
        public array $options = [],
        public bool $ungrouped = false,
        public ?string $group = null,
        public ?string $existing = null,
        public string|Closure|null $when = null,
        public bool $folder = false,
    ) {}

    /**
     * Why this member is left out of the plan, or null when it is generated.
     *
     * @param  array<string, mixed>  $values  the answers and the aliases planned so far
     *
     * @throws GenerationRefused
     */
    public function skipReason(array $values): ?string
    {
        if ($this->when === null) {
            return null;
        }
        if ($this->when instanceof Closure) {
            $answers = array_filter($values, static fn (mixed $value): bool => ! $value instanceof ResolvedArtifact && ! $value instanceof SkippedMember);

            return ($this->when)($answers) ? null : 'when() returned false';
        }
        $value = $values[$this->when] ?? null;
        if ($value instanceof ResolvedArtifact || $value instanceof SkippedMember || is_bool($value)) {
            return $value === false || $value instanceof SkippedMember ? "{$this->when} is false" : null;
        }

        throw GenerationRefused::because("A scaffold member has when: '{$this->when}', which is not a confirm question or an earlier member. Declare ->asks('{$this->when}', type: 'confirm'), or pass a closure. Nothing was written.");
    }
}
