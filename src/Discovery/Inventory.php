<?php

namespace Tey\Mod\Discovery;

use UnexpectedValueException;

/**
 * The result of discovery: what registers (with provenance) and what was
 * deliberately left out. Immutable; owned by the Discovery instance of one
 * application, never shared through static state.
 *
 *
 * @api
 */
final readonly class Inventory
{
    /**
     * @param  list<DiscoveredArtifact>  $entries
     * @param  list<Rejection>  $rejections
     *
     * @internal
     */
    public function __construct(
        /** @internal */
        public array $entries = [],
        /** @internal */
        public array $rejections = [],
    ) {}

    /**
     * @return list<DiscoveredArtifact>
     *
     * @api
     */
    public function ofType(DiscoveryType $type): array
    {
        return array_values(array_filter($this->entries, static fn (DiscoveredArtifact $entry): bool => $entry->type === $type));
    }

    /**
     * @return list<DiscoveredArtifact>
     *
     * @internal
     */
    public function ofKind(string $kindId): array
    {
        return array_values(array_filter($this->entries, static fn (DiscoveredArtifact $entry): bool => $entry->kindId === $kindId));
    }

    /**
     * @return list<string>
     *
     * @internal
     */
    public function classes(DiscoveryType $type): array
    {
        return array_map(static fn (DiscoveredArtifact $entry): string => $entry->class, $this->ofType($type));
    }

    /**
     * A relation type's pairs: model class => related class.
     *
     * @return array<string, string>
     *
     * @internal
     */
    public function pairs(DiscoveryType $type): array
    {
        $pairs = [];

        foreach ($this->ofType($type) as $entry) {
            if ($entry->target !== null) {
                $pairs[$entry->class] = $entry->target;
            }
        }

        return $pairs;
    }

    /**
     * The directories a file kind's discovery collected (DiscoveryType::Directory), sorted.
     *
     * @return list<string>
     *
     * @api
     */
    public function directories(string $fileType): array
    {
        $paths = [];

        foreach ($this->entries as $entry) {
            if ($entry->type === DiscoveryType::Directory && $entry->kindId === $fileType) {
                $paths[] = $entry->path;
            }
        }

        sort($paths);

        return $paths;
    }

    /** @internal */
    public function rejection(string $path): ?Rejection
    {
        foreach ($this->rejections as $rejection) {
            if ($rejection->path === $path) {
                return $rejection;
            }
        }

        return null;
    }

    /**
     * @return list<Rejection>
     *
     * @internal
     */
    public function rejectedFor(RejectionReason $reason): array
    {
        return array_values(array_filter($this->rejections, static fn (Rejection $rejection): bool => $rejection->reason === $reason));
    }

    /** @internal */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @internal */
    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /**
     * @return array{entries: list<array<string, mixed>>, rejections: list<array<string, mixed>>}
     *
     * @internal
     */
    public function toArray(): array
    {
        return [
            'entries' => array_map(static fn (DiscoveredArtifact $entry): array => $entry->toArray(), $this->entries),
            'rejections' => array_map(static fn (Rejection $rejection): array => $rejection->toArray(), $this->rejections),
        ];
    }

    /**
     * @throws UnexpectedValueException
     *
     * @internal
     */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)
            || ! is_array($data['entries'] ?? null) || ! array_is_list($data['entries'])
            || ! is_array($data['rejections'] ?? null) || ! array_is_list($data['rejections'])
        ) {
            throw new UnexpectedValueException('malformed inventory');
        }

        return new self(
            array_map(DiscoveredArtifact::fromArray(...), $data['entries']),
            array_map(Rejection::fromArray(...), $data['rejections']),
        );
    }
}
