<?php

namespace Tey\Mod\Discovery;

use UnexpectedValueException;

/**
 * A scanned file that was deliberately not registered, and why.
 */
final readonly class Rejection
{
    /**
     * @param  list<string>  $candidates  descriptions of competing artifacts (ambiguity)
     */
    public function __construct(
        public string $path,
        public RejectionReason $reason,
        public string $detail,
        public ?string $kindId = null,
        public ?string $class = null,
        public array $candidates = [],
    ) {}

    /**
     * @return array{path: string, reason: string, detail: string, kind: ?string, class: ?string, candidates: list<string>}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'reason' => $this->reason->value,
            'detail' => $this->detail,
            'kind' => $this->kindId,
            'class' => $this->class,
            'candidates' => $this->candidates,
        ];
    }

    /**
     * @throws UnexpectedValueException
     */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)
            || ! is_string($data['path'] ?? null)
            || ! is_string($data['reason'] ?? null)
            || ! is_string($data['detail'] ?? null)
            || ! array_key_exists('kind', $data) || ! (is_string($data['kind']) || $data['kind'] === null)
            || ! array_key_exists('class', $data) || ! (is_string($data['class']) || $data['class'] === null)
            || ! is_array($data['candidates'] ?? null) || ! array_is_list($data['candidates'])
        ) {
            throw new UnexpectedValueException('malformed rejection');
        }

        $candidates = [];

        foreach ($data['candidates'] as $candidate) {
            if (! is_string($candidate)) {
                throw new UnexpectedValueException('malformed rejection candidate');
            }

            $candidates[] = $candidate;
        }

        $reason = RejectionReason::tryFrom($data['reason']) ?? throw new UnexpectedValueException("unknown rejection reason [{$data['reason']}]");

        return new self($data['path'], $reason, $data['detail'], $data['kind'], $data['class'], $candidates);
    }
}
