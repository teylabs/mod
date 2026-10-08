<?php

namespace Tey\Mod\Reverse;

use Tey\Mod\Artifact\ResolvedArtifact;

/**
 * The explicit result of mapping a class or path back to the layout.
 *
 * @internal
 */
final readonly class ReverseMatch
{
    /**
     * @param  list<ResolvedArtifact>  $candidates
     */
    private function __construct(
        public ReverseOutcome $outcome,
        public ?ResolvedArtifact $artifact,
        public array $candidates,
        public ?string $reason,
    ) {}

    public static function matched(ResolvedArtifact $artifact): self
    {
        return new self(ReverseOutcome::Matched, $artifact, [$artifact], null);
    }

    public static function notOwned(string $reason): self
    {
        return new self(ReverseOutcome::NotOwned, null, [], $reason);
    }

    /**
     * @param  list<ResolvedArtifact>  $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(ReverseOutcome::Ambiguous, null, $candidates, 'more than one file type places this file and none has priority');
    }

    public static function unsupported(string $reason): self
    {
        return new self(ReverseOutcome::Unsupported, null, [], $reason);
    }

    public function isMatched(): bool
    {
        return $this->outcome === ReverseOutcome::Matched;
    }
}
