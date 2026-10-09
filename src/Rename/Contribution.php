<?php

namespace Tey\Mod\Rename;

/** @internal Contributors return immutable candidates, never filesystem effects. */
final readonly class Contribution
{
    /** @param list<Edit> $edits
     * @param  list<Checklist>  $checklist
     * @param  list<GeneratedFile>  $files
     * @param  array<string, string>  $dependencies  Absolute read dependency => original SHA-256.
     * @param  list<string>  $blockers
     */
    public function __construct(public array $edits = [], public array $checklist = [], public array $files = [], public array $blockers = [], public array $dependencies = []) {}
}
