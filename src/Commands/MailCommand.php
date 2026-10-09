<?php

namespace Tey\Mod\Commands;

use Illuminate\Foundation\Console\MailMakeCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Str;
use Tey\Mod\Commands\Concerns\GeneratesViews;
use Tey\Mod\Generation\GeneratorAdapter;

/**
 * Native make:mail generator, placed by the preset.
 *
 * @api
 */
class MailCommand extends MailMakeCommand implements GeneratorAdapter
{
    use GeneratesViews;

    protected function companionViewName(): ?string
    {
        if ($this->option('markdown') === false && $this->option('view') === false) {
            return null;
        }
        $name = $this->option('markdown') ?: $this->option('view');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        if ($this->layout()->frontend()['views'] === null) {
            return parent::getView();
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
            $this->components->info(sprintf('%s [%s] created successfully.', 'Markdown view', $path));

            return;
        }
        parent::writeMarkdownTemplate();
    }

    protected function writeView()
    {
        $path = $this->viewPath();
        if ($this->option('force') && $this->files->exists($path)) {
            $quote = Inspiring::quotes()->random();
            $contents = str_replace('{{ quote }}', is_string($quote) ? $quote : '', $this->files->get($this->resolveStubPath('/stubs/view.stub')));
            $this->files->put($path, $contents);
            $this->components->info(sprintf('View [%s] created successfully.', $path));

            return;
        }
        parent::writeView();
    }
}
