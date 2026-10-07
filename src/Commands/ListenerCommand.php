<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\ListenerMakeCommand;
use Tey\Mod\Commands\Concerns\PlacesGeneratedClass;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:listener, placed by the preset. A bare --event is placed as
 * the event kind; a namespaced one (Illuminate\Auth\Events\Login) is kept.
 */
class ListenerCommand extends ListenerMakeCommand implements GeneratorAdapter
{
    use PlacesGeneratedClass;

    /**
     * @param  string  $name
     * @return string
     */
    protected function buildClass($name)
    {
        $event = $this->option('event');

        if (is_string($event) && $event !== '') {
            $this->input->setOption('event', '\\'.$this->placeSibling('event', $event));
        }

        return parent::buildClass($name);
    }
}
