<?php

namespace Tey\Mod\Generation;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Placement\Collision;
use Tey\Mod\Placement\CollisionDiagnoser;
use Tey\Mod\Placement\CollisionKind;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Relation\RelationResolution;

/**
 * Everything one mod:* invocation is about to write: the primary artifact and
 * the related artifacts its options ask for, resolved before anything is
 * written so collisions refuse the whole plan.
 *
 * @api
 */
final readonly class GenerationPlan
{
    /**
     * @param  list<RelationResolution>  $relations  resolved relations the invocation follows
     *
     * @api
     */
    public function __construct(
        /** @api */
        public ResolvedArtifact $primary,
        /**
         * @api
         *
         * @var list<RelationResolution>
         */
        public array $relations = [],
    ) {}

    /**
     * Related artifacts this plan generates (policy Generate).
     *
     * @return list<ResolvedArtifact>
     *
     * @api
     */
    public function generated(): array
    {
        $targets = [];

        foreach ($this->relations as $resolution) {
            if ($resolution->target !== null && $resolution->mode() === RelationMode::Generate) {
                $targets[] = $resolution->target;
            }
        }

        return $targets;
    }

    /**
     * Collisions for every artifact the plan writes, against what exists and against each other.
     *
     * @return list<Collision>
     *
     * @internal
     */
    public function collisions(CollisionDiagnoser $diagnoser, ExistingArtifacts $existing, bool $overwritePrimary = false): array
    {
        $collisions = [];

        $primary = $diagnoser->check($this->primary, $existing->for($this->primary));

        foreach ($primary as $collision) {
            if (! ($overwritePrimary && $collision->kind === CollisionKind::Path)) {
                $collisions[] = $collision;
            }
        }

        $written = [$this->primary];

        foreach ($this->generated() as $target) {
            if (! $target->kind->isClass()) {
                // Timestamped files are named at write time by their own command.
                continue;
            }

            array_push($collisions, ...$diagnoser->check($target, $existing->for($target)));

            foreach ($written as $other) {
                array_push($collisions, ...$diagnoser->between($target, $other));
            }

            $written[] = $target;
        }

        return $collisions;
    }
}
