<?php

namespace Tey\Mod\Scaffolds;

use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Exceptions\GenerationRefused;

/** @internal */
final readonly class Question
{
    public const SHAPE_TYPES = ['string', 'bool', 'int'];

    /**
     * @param  array<array-key, string>  $options
     * @param  array<string, string>  $shape  field name => string, bool or int, for records
     */
    public function __construct(public string $name, public string $type = 'text', public mixed $default = null, public ?string $label = null, public array $options = [], public array $shape = [])
    {
        if (! in_array($type, ['text', 'list', 'records', 'choice', 'confirm', 'model', 'class', 'file'], true)) {
            throw GenerationRefused::because("Unknown question type [{$type}]. Use text, list, records, choice, confirm, model, class or file.");
        }
        if ($type !== 'records' && $shape !== []) {
            throw GenerationRefused::because("Question [{$name}] declares a shape, but only records questions have one. Use type: 'records'.");
        }
        if ($type === 'records') {
            $this->checkShape();
            if ($default !== null) {
                (new Records($shape))->validate($default, 'default');
            }
        }
    }

    /** @return int<0, 31> */
    public function inputMode(): int
    {
        return match ($this->type) {
            'list' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'confirm' => InputOption::VALUE_NEGATABLE,
            default => InputOption::VALUE_REQUIRED,
        };
    }

    private function checkShape(): void
    {
        if ($this->shape === []) {
            throw GenerationRefused::because("Question [{$this->name}] is a records question, so it needs a shape. Pass shape: ['key' => 'string', 'sortable' => 'bool'].");
        }
        foreach ($this->shape as $field => $type) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $field) !== 1) {
                throw GenerationRefused::because("Question [{$this->name}] shape field [{$field}] must be a plain name: letters, digits and underscores.");
            }
            if (! in_array($type, self::SHAPE_TYPES, true)) {
                throw GenerationRefused::because("Question [{$this->name}] shape field [{$field}] has type [{$type}]. Use string, bool or int.");
            }
        }
    }
}
