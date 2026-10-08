<?php

namespace Tey\Mod\Generation;

/**
 * A base class a kind's classes extend, written into the application the
 * first time one of them is generated. The application owns it from then on:
 * it is never overwritten, not even with --force.
 *
 *     GeneratedBase::named('Record', in: 'Shared/Records', stub: __DIR__.'/stubs/record.stub')
 *
 * places `Record` in the kind's root, under `in`. The application may
 * publish its own body as `stubs/mod.base.<name>.stub` (the name in
 * kebab-case, e.g. `stubs/mod.base.record.stub`).
 */
final readonly class GeneratedBase
{
    private function __construct(
        public string $name,
        public string $in,
        public string $stub,
    ) {}

    /**
     * @param  string  $name  the class name, e.g. "Record"
     * @param  string  $in  the folder below the kind's root, e.g. "Shared/Records"
     * @param  string  $stub  the stub file of its body
     */
    public static function named(string $name, string $in, string $stub): self
    {
        return new self($name, trim($in, '/'), $stub);
    }

    /**
     * The name of the base in published stub file names: "Record" → "record".
     */
    public function stubName(): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $this->name));
    }
}
