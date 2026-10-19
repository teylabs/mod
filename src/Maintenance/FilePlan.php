<?php

namespace Tey\Mod\Maintenance;

use InvalidArgumentException;

/** Read-only three-way ownership decision; never authorises effects by itself. @api */
final readonly class FilePlan
{
    /**
     * @param  array<string, array{state: string, original: ?string, current: ?string, target: string}>  $files
     * @param  array<string, string>  $inputs
     * @param  array<string, string>  $compatibility
     */
    private function __construct(
        /** @api */ public string $appId,
        /** @api */ public string $root,
        /** @api */ public Artifact $baseline,
        /** @api */ public Artifact $target,
        /** @api */ public array $files,
        /** @api @var array<string, string> */ public array $inputs,
        /** @api @var array<string, string> */ public array $compatibility,
    ) {}

    /**
     * Current is a trusted inspector's observed bytes for the union of baseline and target paths.
     * Missing keys represent absent files; unrelated host paths must not be supplied.
     * Root must be the inspector's canonical checkout path. This pure planner does no IO.
     *
     * @param  array<string, string>  $current
     * @param  array<string, string>  $inputs  Host input identities (lock, bindings, policy).
     * @param  array<string, string>  $compatibility  Trusted checks: passed, failed or unavailable.
     *
     * @api
     */
    public static function between(string $appId, string $root, Artifact $baseline, Artifact $target, array $current, array $inputs, array $compatibility): self
    {
        if ($appId === '' || ! str_starts_with($root, '/') || str_ends_with($root, '/') || str_contains($root, '/./') || str_contains($root, '/../') || str_contains($root, "\0")) {
            throw new InvalidArgumentException('App identity and canonical absolute root are required.');
        }
        if ($baseline->name !== $target->name || $baseline->groups !== $target->groups || $baseline->bindings !== $target->bindings || array_diff_key($baseline->files, $target->files) !== []) {
            throw new InvalidArgumentException('Artifact lineage, selection and ownership must be preserved. Removals require explicit policy.');
        }
        Artifact::validateFiles($target->files + $baseline->files);
        Artifact::validateFiles($current);
        if (array_diff_key($current, $target->files + $baseline->files) !== []) {
            throw new InvalidArgumentException('Current observations must only cover explicit owned or proposed paths.');
        }
        Artifact::validateSelection($inputs, false);
        Artifact::validateSelection($compatibility, false);
        if (array_diff($compatibility, ['passed', 'failed', 'unavailable']) !== []) {
            throw new InvalidArgumentException('Invalid compatibility outcome.');
        }
        $files = [];
        foreach ($target->files as $path => $bytes) {
            $original = $baseline->files[$path] ?? null;
            $observed = $current[$path] ?? null;
            $state = match (true) {
                $original === null && $observed !== null => 'unowned',
                $original === null => 'create',
                $observed === null => 'restore',
                $observed !== $original => 'conflict',
                $observed === $bytes => 'unchanged',
                default => 'replace',
            };
            $files[$path] = ['state' => $state, 'original' => $original === null ? null : hash('sha256', $original), 'current' => $observed === null ? null : hash('sha256', $observed), 'target' => hash('sha256', $bytes)];
        }

        return new self($appId, $root, $baseline, $target, $files, $inputs, $compatibility);
    }

    /** @api */
    public function executable(): bool
    {
        return $this->compatibility !== [] && array_unique(array_values($this->compatibility)) === ['passed'] && ! array_intersect(array_column($this->files, 'state'), ['conflict', 'unowned']);
    }

    /** @api */
    public function identity(): string
    {
        return Fingerprint::of([$this->appId, $this->root, $this->baseline->identity(), $this->target->identity(), $this->files, $this->inputs, $this->compatibility]);
    }
}
