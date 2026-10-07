<?php

namespace Tey\Mod\Discovery;

/**
 * Discovery for one preset kind: which kind's files are scanned and what the
 * framework does with the eligible classes.
 *
 * The kind id is preset data, so any kind can be discovered as any type; the
 * type's semantic eligibility still decides what registers.
 */
final readonly class DiscoveryDefinition
{
    public function __construct(
        public string $kindId,
        public DiscoveryType $type,
        public bool $enabled = true,
    ) {}

    public static function providers(string $kindId = 'provider'): self
    {
        return new self($kindId, DiscoveryType::Provider);
    }

    public static function commands(string $kindId = 'command'): self
    {
        return new self($kindId, DiscoveryType::Command);
    }

    public static function listeners(string $kindId = 'listener'): self
    {
        return new self($kindId, DiscoveryType::Listener);
    }

    public function disabled(): self
    {
        return new self($this->kindId, $this->type, false);
    }

    /**
     * A stable description, part of the cache key.
     */
    public function identity(): string
    {
        return sprintf('%s:%s:%s', $this->kindId, $this->type->value, $this->enabled ? 'on' : 'off');
    }
}
