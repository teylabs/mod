<?php

namespace Tey\Mod\Relation;

/**
 * A named edge from one artifact kind to another, with explicit scope mapping,
 * name derivation and generation mode.
 *
 *
 * @api
 */
final readonly class Relation
{
    /** @api */
    public string $fromFileType;

    /** @api */
    public string $toFileType;

    /** @internal */
    public function __construct(
        /** @api */
        public string $id,
        /** @internal */
        public string $fromKind,
        /** @internal */
        public string $toKind,
        /** @internal */
        public ScopeMap $scope,
        /** @internal */
        public NameDerivation $name,
        /** @api */
        public RelationMode $mode,
    ) {
        $this->fromFileType = $fromKind;
        $this->toFileType = $toKind;
    }
}
