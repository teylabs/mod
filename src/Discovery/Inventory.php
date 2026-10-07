<?php

namespace Tey\Mod\Discovery;

use UnexpectedValueException;

/**
 * The result of discovery: what registers (with provenance) and what was
 * deliberately left out. Immutable; owned by the Discovery instance of one
 * application, never shared through static state.
 */
final readonly class Inventory
{
    /**
     * @param  list<DiscoveredArtifact>  $entries
     * @param  list<Rejection>  $rejections
     */
    public function __construct(
        public array $entries = [],
        public array $rejections = [],
    ) {}

    /**
     * @return list<DiscoveredArtifact>
     */
    public function ofType(DiscoveryType $type): array
    {
        return array_values(array_filter($this->entries, static fn (DiscoveredArtifact $entry): bool => $entry->type === $type));
    }

    /**
     * @return list<DiscoveredArtifact>
     */
    public function ofKind(string $kindId): array
    {
        return array_values(array_filter($this->entries, static fn (DiscoveredArtifact $entry): bool => $entry->kindId === $kindId));
    }

    /**
     * @return list<string>
     */
    public function classes(DiscoveryType $type): array
    {
        return array_map(static fn (DiscoveredArtifact $entry): string => $entry->class, $this->ofType($type));
    }

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
     */
    public function rejectedFor(RejectionReason $reason): array
    {
        return array_values(array_filter($this->rejections, static fn (Rejection $rejection): bool => $rejection->reason === $reason));
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /**
     * @return array{entries: list<array<string, mixed>>, rejections: list<array<string, mixed>>}
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
