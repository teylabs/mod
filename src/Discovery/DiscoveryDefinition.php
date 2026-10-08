<?php

namespace Tey\Mod\Discovery;

/**
 * Discovery for one preset kind: which kind's files are scanned and what the
 * framework does with the eligible classes (or, for a file kind, which
 * directories are collected).
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

    public static function subscribers(string $kindId = 'subscriber'): self
    {
        return new self($kindId, DiscoveryType::Subscriber);
    }

    /**
     * The directories a file kind's template binds that hold at least one file.
     */
    public static function directories(string $kindId): self
    {
        return new self($kindId, DiscoveryType::Directory);
    }

    /**
     * Models paired with the class their relation to a target kind names
     * (DiscoveryType::Factory or ::Policy); the kind scanned is the model kind.
     */
    public static function related(DiscoveryType $type, string $modelKindId = 'model'): self
    {
        return new self($modelKindId, $type);
    }

    /**
     * The key a definition is listed under: the kind id, or kind id and type for a relation type.
     */
    public function key(): string
    {
        return $this->type->isRelationType() ? $this->kindId.'|'.$this->type->value : $this->kindId;
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
