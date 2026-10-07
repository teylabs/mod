<?php

namespace Tey\Mod\Generation;

use Tey\Mod\Preset\Preset;

/**
 * Supplies the application's preset when `mod.preset` names a class.
 */
interface PresetSource
{
    public function preset(): Preset;
}
