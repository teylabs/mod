<?php

namespace Tey\Mod\Layout;

use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Preset\PresetIssue;

/**
 * A layout that cannot compile; lists every problem at once, each naming the call that caused it.
 */
final class InvalidLayout extends ModException
{
    /**
     * @param  list<PresetIssue>  $issues
     */
    public function __construct(public readonly string $layout, public readonly array $issues)
    {
        parent::__construct("Layout [{$layout}] is invalid:\n".implode("\n", array_map(
            static fn (PresetIssue $issue): string => " - {$issue->subject}: {$issue->message} [{$issue->code->value}]",
            $issues,
        )));
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
