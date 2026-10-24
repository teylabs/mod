<?php

namespace Tey\Mod\Maintenance;

use InvalidArgumentException;

/** Read-only three-way ownership decision; never authorises effects by itself. @api */
final readonly class FilePlan
{
    /**
     * @param  array<string, array{state: string, original: ?string, current: ?string, target: ?string, reason?: string}>  $files
     * @param  array<string, string>  $inputs
     * @param  array<string, string>  $compatibility
     * @param  list<string>  $claim
     * @param  array<string, string>  $removals
     */
    private function __construct(
        /** @api */ public string $appId,
        /** @api */ public string $root,
        /** @api */ public Artifact $baseline,
        /** @api */ public Artifact $target,
        /** @api */ public array $files,
        /** @api @var array<string, string> */ public array $inputs,
        /** @api @var array<string, string> */ public array $compatibility,
        /** @api @var list<string> */ public array $claim = [],
        /** @api @var array<string, string> */ public array $removals = [],
    ) {}

    /**
     * Current is a trusted inspector's observed bytes for the union of baseline and target paths.
     * Missing keys represent absent files; unrelated host paths must not be supplied.
     * Root must be the inspector's canonical POSIX checkout path. This pure planner does no IO or canonicalisation.
     *
     * @param  array<string, string>  $current
     * @param  array<string, string>  $inputs  Host input identities (lock, bindings, policy).
     * @param  array<string, string>  $compatibility  Trusted checks: passed, failed or unavailable.
     * @param  array<string, string>  $refusals  Target paths this lineage may never own, mapped to why (owned_by:<name> or host_owned:<name>).
     * @param  list<string>  $claim  Reviewed new target paths whose existing identical bytes become owned without a write.
     * @param  array<string, string>  $removals  Every baseline path absent from the target, mapped to delete (remove an untouched file) or release (keep it, now host-owned).
     *
     * @api
     */
    public static function between(string $appId, string $root, Artifact $baseline, Artifact $target, array $current, array $inputs, array $compatibility, array $refusals = [], array $claim = [], array $removals = []): self
    {
        if ($appId === '' || ! str_starts_with($root, '/') || str_ends_with($root, '/') || array_intersect(explode('/', substr($root, 1)), ['', '.', '..']) !== [] || preg_match('/[\\\\:\x00-\x1F\x7F]/', $root)) {
            throw new InvalidArgumentException('App identity and a canonical POSIX checkout root are required. Drive and network roots are unsupported.');
        }
        if ($baseline->name !== $target->name || $baseline->groups !== $target->groups || $baseline->bindings !== $target->bindings) {
            throw new InvalidArgumentException('Artifact lineage, selection and ownership must be preserved.');
        }
        Artifact::validateSelection($removals, false);
        $dropped = array_keys(array_diff_key($baseline->files, $target->files));
        $declared = array_keys($removals);
        sort($dropped);
        sort($declared);
        if ($dropped !== $declared) {
            throw new InvalidArgumentException('Removals require explicit policy: exactly every baseline path absent from the target.');
        }
        if (array_diff($removals, ['delete', 'release']) !== []) {
            throw new InvalidArgumentException('Removal policy must be delete or release.');
        }
        // A reviewed policy is a map: its order must not change plan identity.
        ksort($removals);
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
        Artifact::validateSelection($refusals, false);
        if (array_diff_key($refusals, $target->files) !== []) {
            throw new InvalidArgumentException('Refusals must only cover proposed paths.');
        }
        Artifact::validateSelection($claim, true);
        foreach ($claim as $path) {
            if (! isset($target->files[$path]) || isset($baseline->files[$path])) {
                throw new InvalidArgumentException('Claims must only name new target paths.');
            }
        }
        // A reviewed list is a set: its order must not change plan identity.
        sort($claim);
        $files = [];
        foreach ($target->files as $path => $bytes) {
            $original = $baseline->files[$path] ?? null;
            $observed = $current[$path] ?? null;
            $state = match (true) {
                isset($refusals[$path]) => 'conflict',
                $original === null && $observed !== null && in_array($path, $claim, true) => $observed === $bytes ? 'claim' : 'conflict',
                $original === null && $observed !== null => 'unowned',
                $original === null => 'create',
                $observed === null => 'restore',
                $observed !== $original => 'conflict',
                $observed === $bytes => 'unchanged',
                default => 'replace',
            };
            $files[$path] = ['state' => $state, 'original' => $original === null ? null : hash('sha256', $original), 'current' => $observed === null ? null : hash('sha256', $observed), 'target' => hash('sha256', $bytes)];
            if (isset($refusals[$path])) {
                $files[$path]['reason'] = $refusals[$path];
            }
        }
        foreach ($removals as $path => $policy) {
            $original = $baseline->files[$path];
            $observed = $current[$path] ?? null;
            $state = match (true) {
                $observed === null => 'gone',
                $policy === 'release' => 'release',
                $observed !== $original => 'conflict',
                default => 'remove',
            };
            $files[$path] = ['state' => $state, 'original' => hash('sha256', $original), 'current' => $observed === null ? null : hash('sha256', $observed), 'target' => null];
            if ($state === 'conflict') {
                $files[$path]['reason'] = 'modified';
            }
        }

        return new self($appId, $root, $baseline, $target, $files, $inputs, $compatibility, $claim, $removals);
    }

    /** @api */
    public function executable(): bool
    {
        return $this->compatibility !== [] && array_unique(array_values($this->compatibility)) === ['passed'] && ! array_intersect(array_column($this->files, 'state'), ['conflict', 'unowned']);
    }

    /** @api */
    public function identity(): string
    {
        $identity = [$this->appId, $this->root, $this->baseline->identity(), $this->target->identity(), $this->files, $this->inputs, $this->compatibility];
        if ($this->claim !== []) {
            $identity[] = ['claim' => $this->claim];
        }
        if ($this->removals !== []) {
            $identity[] = ['removals' => $this->removals];
        }

        return Fingerprint::of($identity);
    }
}
