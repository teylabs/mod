<?php

namespace Tey\Mod\Relation;

/**
 * A named edge from one artifact kind to another, with explicit scope mapping,
 * name derivation and generation mode.
 *
 * @internal
 */
final readonly class Relation
{
    public function __construct(
        public string $id,
        public string $fromKind,
        public string $toKind,
        public ScopeMap $scope,
        public NameDerivation $name,
        public RelationMode $mode,
    ) {}
}
