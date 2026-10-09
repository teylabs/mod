<?php

namespace Tey\Mod\Exceptions;

use Tey\Mod\Preset\PresetIssue;

/**
 * A layout that cannot compile; lists every problem at once, each naming the call that caused it.
 *
 * @api
 */
final class InvalidLayout extends ModException
{
    /**
     * @param  list<PresetIssue>  $issues
     */
    public function __construct(public readonly ?string $layout, public readonly array $issues, ?string $message = null)
    {
        parent::__construct($message ?? ($layout === null ? 'The layout definition is invalid' : "Layout [{$layout}] is invalid").":\n".implode("\n", array_map(
            static fn (PresetIssue $issue): string => " - {$issue->subject}: {$issue->message} [{$issue->code->value}]",
            $issues,
        )));
    }

    /**
     * @param  list<string>  $builtIn
     */
    public static function notDefined(string $layout, array $builtIn): self
    {
        return new self($layout, [], sprintf(
            'Layout [%s] is not defined. Use a built-in layout (%s) or define it with Mod::layout(\'%s\') in a service provider.',
            $layout,
            implode(', ', $builtIn),
            $layout,
        ));
    }

    public static function notNamed(): self
    {
        return new self(null, [], 'Config [mod.layout] must be a layout name such as "laravel".');
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_map(
            static fn (PresetIssue $issue): string => $issue->code->value,
            $this->issues,
        )));
    }
}
