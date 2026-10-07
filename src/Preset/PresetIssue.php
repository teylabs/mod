<?php

namespace Tey\Mod\Preset;

/**
 * @internal a problem the validator found; surfaces through InvalidLayout / InvalidPreset messages.
 */
final readonly class PresetIssue
{
    public function __construct(
        public PresetIssueCode $code,
        public string $subject,
        public string $message,
    ) {}

    public function describe(): string
    {
        return sprintf('[%s] %s: %s', $this->code->value, $this->subject, $this->message);
    }
}
