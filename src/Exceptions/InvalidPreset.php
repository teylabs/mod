<?php

namespace Tey\Mod\Exceptions;

use Tey\Mod\Preset\PresetIssue;

final class InvalidPreset extends ModException
{
    /**
     * @param  list<PresetIssue>  $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct("Invalid preset:\n".implode("\n", array_map(
            static fn (PresetIssue $issue): string => ' - '.$issue->describe(),
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
