<?php

namespace Tey\Mod\Reverse;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Placement\OpaquePlacementRule;
use Tey\Mod\Placement\Root;
use Tey\Mod\Preset\Preset;

/**
 * Maps an existing class or file back to the artifact a preset would own.
 *
 * Only declared rules are consulted. Nothing is inferred from a namespace
 * segment that no rule names, and excluded roots are never owned.
 */
final readonly class ReverseMapper
{
    public function __construct(private Preset $preset) {}

    public function fromClass(string $fqcn): ReverseMatch
    {
        return $this->map(ltrim($fqcn, '\\'), false);
    }

    public function fromPath(string $path): ReverseMatch
    {
        return $this->map(Root::normalisePath($path), true);
    }

    private function map(string $subject, bool $isPath): ReverseMatch
    {
        foreach ($this->preset->excludedRoots() as $excluded) {
            $under = $isPath ? $excluded->pathRemainder($subject) : $excluded->namespaceRemainder($subject);

            if ($under !== null) {
                return ReverseMatch::notOwned(sprintf('inside excluded root [%s]', $excluded->namespace ?? $excluded->path));
            }
        }

        /** @var list<ResolvedArtifact> $candidates */
        $candidates = [];
        $opaque = [];

        foreach ($this->preset->rules() as $rule) {
            if ($rule instanceof OpaquePlacementRule) {
                if ($rule->covers($subject, $isPath)) {
                    $opaque[] = $rule->kindId();
                }

                continue;
            }

            $candidates = [...$candidates, ...$rule->recognise($this->preset->kind($rule->kindId()), $subject, $isPath)];
        }

        if ($opaque !== []) {
            return ReverseMatch::unsupported(sprintf(
                'kind [%s] is placed by a callback that cannot be inverted',
                implode(', ', $opaque),
            ));
        }

        if ($candidates === []) {
            return ReverseMatch::notOwned('no declared rule recognises it');
        }

        if (count($candidates) === 1) {
            return ReverseMatch::matched($candidates[0]);
        }

        $ranked = [];

        foreach ($candidates as $candidate) {
            $ranked[$this->preset->rule($candidate->kind->id)->priority()][] = $candidate;
        }

        $top = $ranked[max(array_keys($ranked))];

        return count($top) === 1
            ? ReverseMatch::matched($top[0])
            : ReverseMatch::ambiguous($candidates);
    }
}
