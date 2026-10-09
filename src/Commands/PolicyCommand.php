<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\PolicyMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:policy, placed by the preset. A bare --model is placed as the
 * model kind; the native stub does the rest.
 *
 * @api
 */
class PolicyCommand extends PolicyMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;

    /**
     * @param  string  $name
     * @return string
     */
    protected function buildClass($name)
    {
        $model = $this->option('model');

        if (is_string($model) && $model !== '') {
            $this->input->setOption('model', '\\'.$this->placeSibling($this->relatedFileType('model'), $model));
        }

        return parent::buildClass($name);
    }
}
