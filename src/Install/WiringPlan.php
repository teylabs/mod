<?php

namespace Tey\Mod\Install;

use RuntimeException;
use Tey\Mod\Plans\Plan;

/** A preview that applies only the exact files it inspected. @api */
final readonly class WiringPlan
{
    /** @internal */
    public function __construct(private string $basePath, private Plan $plan) {}

    /**
     * @api
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->plan->toArray();
    }

    /** Refuse warnings or files changed since planning. @api */
    public function apply(): bool
    {
        if (! $this->plan->wouldWrite) {
            return false;
        }
        foreach ($this->plan->files as $file) {
            $path = $this->basePath.'/'.$file['path'];
            $before = is_file($path) ? (string) file_get_contents($path) : '';
            if ($before !== ($file['identity']['before'] ?? null)) {
                throw new RuntimeException('Frontend file ['.$file['path'].'] changed after planning; build a new plan.');
            }
        }
        foreach ($this->plan->files as $file) {
            $after = $file['identity']['after'] ?? null;
            if (is_string($after)) {
                $path = $this->basePath.'/'.$file['path'];
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                if (file_put_contents($path, $after) === false) {
                    throw new RuntimeException('Could not write frontend file ['.$file['path'].'].');
                }
            }
        }

        return true;
    }
}
