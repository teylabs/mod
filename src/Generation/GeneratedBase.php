<?php

namespace Tey\Mod\Generation;

/**
 * A base class a file type's classes extend, written into the application the
 * first time one of them is generated. The application owns it from then on:
 * it is never overwritten, not even with --force.
 *
 *     GeneratedBase::named('Record', in: 'Records', stub: __DIR__.'/stubs/record.stub')
 *
 * places `Record` in the application's bases folder (`mod.bases_path`,
 * app/Support by default), under `in`: app/Support/Records/Record.php. With
 * inFileTypeRoot(), `in` is below the generated file type's own root instead. The
 * application may publish its own body as `stubs/mod.base.<name>.stub` (the
 * name in kebab-case, e.g. `stubs/mod.base.record.stub`).
 */
final readonly class GeneratedBase
{
    private function __construct(
        public string $name,
        public string $in,
        public string $stub,
        public bool $inFileTypeRoot = false,
    ) {}

    /**
     * @param  string  $name  the class name, e.g. "Record"
     * @param  string  $in  the folder below the bases folder, e.g. "Records"
     * @param  string  $stub  the stub file of its body
     */
    public static function named(string $name, string $in, string $stub): self
    {
        return new self($name, trim($in, '/'), $stub);
    }

    /**
     * Place the base below the generated file type's own root instead of the bases folder.
     */
    public function inFileTypeRoot(): self
    {
        return new self($this->name, $this->in, $this->stub, true);
    }

    /**
     * The name of the base in published stub file names: "Record" → "record".
     */
    public function stubName(): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $this->name));
    }
}
