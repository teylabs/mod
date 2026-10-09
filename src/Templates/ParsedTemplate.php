<?php

namespace Tey\Mod\Templates;

/** @internal a template path resolved against one layout. */
final readonly class ParsedTemplate
{
    /**
     * @param  list<string>  $slots
     * @param  list<string>  $groups
     */
    public function __construct(
        public string $id,
        public string $root,
        public string $in,
        public array $slots,
        public array $groups,
        public ?string $notice = null,
    ) {}
}
