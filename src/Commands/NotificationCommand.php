<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\NotificationMakeCommand;
use Illuminate\Support\Str;
use Tey\Mod\Commands\Concerns\GeneratesViews;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:notification generator, placed by the preset.
 */
class NotificationCommand extends NotificationMakeCommand implements GeneratorAdapter
{
    use GeneratesViews;

    protected function companionViewName(): ?string
    {
        if ($this->layout()->frontend()['views'] === null || ($this->option('markdown') === false)) {
            return null;
        }
        $name = $this->option('markdown');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return 'mail.'.implode('.', array_map(Str::kebab(...), explode('/', str_replace('\\', '/', $this->getNameInput()))));
    }

    protected function getView()
    {
        return $this->layout()->frontend()['views'] === null ? parent::getView() : $this->viewIdentity()->name();
    }

    protected function writeMarkdownTemplate()
    {
        $path = $this->viewPath();
        if ($this->option('force') && $this->files->exists($path)) {
            $this->files->put($path, $this->files->get($this->resolveStubPath('/stubs/markdown.stub')));
            $this->components->info(sprintf('%s [%s] created successfully.', 'Markdown', $path));

            return;
        }
        parent::writeMarkdownTemplate();
    }
}
