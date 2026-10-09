<?php

namespace Tey\Mod\Discovery;

/**
 * Discovery for one preset kind: which kind's files are scanned and what the
 * framework does with the eligible classes (or, for a file kind, which
 * directories are collected).
 *
 * The kind id is preset data, so any kind can be discovered as any type; the
 * type's semantic eligibility still decides what registers.
 *
 * @api
 */
final readonly class DiscoveryDefinition
{
    /** @api */
    public string $fileType;

    /** @api */
    public static function forFileType(string $fileType, DiscoveryType $type, bool $enabled = true): self
    {
        return new self($fileType, $type, $enabled);
    }

    /** @internal */
    public function __construct(
        /** @internal */
        public string $kindId,
        /** @api */
        public DiscoveryType $type,
        /** @api */
        public bool $enabled = true,
    ) {
        $this->fileType = $kindId;
    }

    /** @internal */
    public static function providers(string $kindId = 'provider'): self
    {
        return new self($kindId, DiscoveryType::Provider);
    }

    /** @internal */
    public static function commands(string $kindId = 'command'): self
    {
        return new self($kindId, DiscoveryType::Command);
    }

    /** @internal */
    public static function listeners(string $kindId = 'listener'): self
    {
        return new self($kindId, DiscoveryType::Listener);
    }

    /** @internal */
    public static function subscribers(string $kindId = 'subscriber'): self
    {
        return new self($kindId, DiscoveryType::Subscriber);
    }

    /**
     * The directories a file kind's template binds that hold at least one file.
     *
     * @internal
     */
    public static function directories(string $kindId): self
    {
        return new self($kindId, DiscoveryType::Directory);
    }

    /**
     * Models paired with the class their relation to a target kind names
     * (DiscoveryType::Factory or ::Policy); the kind scanned is the model kind.
     *
     * @internal
     */
    public static function related(DiscoveryType $type, string $modelKindId = 'model'): self
    {
        return new self($modelKindId, $type);
    }

    /**
     * The key a definition is listed under: the kind id, or kind id and type for a relation type.
     *
     * @api
     */
    public function key(): string
    {
        return $this->type->isRelationType() ? $this->kindId.'|'.$this->type->value : $this->kindId;
    }

    /** @api */
    public function disabled(): self
    {
        return new self($this->kindId, $this->type, false);
    }

    /**
     * A stable description, part of the cache key.
     *
     * @api
     */
    public function identity(): string
    {
        return sprintf('%s:%s:%s', $this->kindId, $this->type->value, $this->enabled ? 'on' : 'off');
    }
}
