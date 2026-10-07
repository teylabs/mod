<?php

namespace Tey\Mod\Relation;

/**
 * How the target's requested name derives from the source's, or that it cannot.
 *
 * Derivation works on requested names (stems), never on basenames: kind
 * suffixes such as Controller or Factory are added and removed by each kind's
 * name policy, so a controller "Invoice" relates to the request "StoreInvoice".
 */
final readonly class NameDerivation
{
    private function __construct(
        public bool $explicit,
        public ?string $stripSuffix,
        public ?string $prefix,
        public ?string $suffix,
    ) {}

    public static function fromSource(?string $stripSuffix = null, ?string $prefix = null, ?string $suffix = null): self
    {
        return new self(false, $stripSuffix, $prefix, $suffix);
    }

    /** The caller must supply the name; nothing about the source implies it. */
    public static function explicit(): self
    {
        return new self(true, null, null, null);
    }

    /**
     * The target's requested name, or null when it cannot be derived.
     */
    public function derive(string $sourceName, ?string $explicitName): ?string
    {
        if ($explicitName !== null && $explicitName !== '') {
            return $explicitName;
        }

        if ($this->explicit) {
            return null;
        }

        $stem = $sourceName;

        if ($this->stripSuffix !== null) {
            if (! str_ends_with($stem, $this->stripSuffix) || $stem === $this->stripSuffix) {
                return null;
            }

            $stem = substr($stem, 0, -strlen($this->stripSuffix));
        }

        return ($this->prefix ?? '').$stem.($this->suffix ?? '');
    }
}
