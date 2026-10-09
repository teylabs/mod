<?php

namespace Tey\Mod\Scaffolds;

use Tey\Mod\Exceptions\GenerationRefused;

/** @internal */
final readonly class Question
{
    /** @param array<array-key, string> $options */
    public function __construct(public string $name, public string $type = 'text', public mixed $default = null, public ?string $label = null, public array $options = [])
    {
        if (! in_array($type, ['text', 'list', 'choice', 'confirm', 'model', 'class', 'file'], true)) {
            throw GenerationRefused::because("Unknown question type [{$type}]. Use text, list, choice, confirm, model, class or file.");
        }
    }
}
