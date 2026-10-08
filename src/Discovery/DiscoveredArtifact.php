<?php

namespace Tey\Mod\Discovery;

use UnexpectedValueException;

/**
 * One class that registers, with its provenance: which kind owns it, where it
 * was placed (context) and the file it came from. A relation type (factory,
 * policy) pairs the class with its target: the related class it resolves to.
 *
 * @internal
 */
final readonly class DiscoveredArtifact
{
    /**
     * @param  array<string, string>  $context  placement dimension values
     * @param  list<array{event: string, method: string}>  $events  listeners only
     * @param  string|null  $target  relation types only: the related class
     */
    public function __construct(
        public string $kindId,
        public DiscoveryType $type,
        public string $class,
        public string $path,
        public array $context = [],
        public array $events = [],
        public ?string $target = null,
    ) {}

    /**
     * @return array{kind: string, type: string, class: string, path: string, context: array<string, string>, events: list<array{event: string, method: string}>, target?: string}
     */
    public function toArray(): array
    {
        $data = [
            'kind' => $this->kindId,
            'type' => $this->type->value,
            'class' => $this->class,
            'path' => $this->path,
            'context' => $this->context,
            'events' => $this->events,
        ];

        if ($this->target !== null) {
            $data['target'] = $this->target;
        }

        return $data;
    }

    /**
     * @throws UnexpectedValueException
     */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)
            || ! is_string($data['kind'] ?? null)
            || ! is_string($data['type'] ?? null)
            || ! is_string($data['class'] ?? null)
            || ! is_string($data['path'] ?? null)
            || ! is_array($data['context'] ?? null)
            || ! is_array($data['events'] ?? null) || ! array_is_list($data['events'])
            || (array_key_exists('target', $data) && ! is_string($data['target']))
        ) {
            throw new UnexpectedValueException('malformed inventory entry');
        }

        $type = DiscoveryType::tryFrom($data['type']) ?? throw new UnexpectedValueException("unknown discovery type [{$data['type']}]");

        $context = [];

        foreach ($data['context'] as $dimension => $value) {
            if (! is_string($dimension) || ! is_string($value)) {
                throw new UnexpectedValueException('malformed entry context');
            }

            $context[$dimension] = $value;
        }

        $events = [];

        foreach ($data['events'] as $event) {
            if (! is_array($event) || ! is_string($event['event'] ?? null) || ! is_string($event['method'] ?? null)) {
                throw new UnexpectedValueException('malformed listener event');
            }

            $events[] = ['event' => $event['event'], 'method' => $event['method']];
        }

        return new self($data['kind'], $type, $data['class'], $data['path'], $context, $events, $data['target'] ?? null);
    }
}
