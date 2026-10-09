<?php

namespace Tey\Mod\Install;

use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Support\Stack;

/** @internal Reads known installer shapes; planning never changes the application. */
final readonly class InertiaEdits
{
    public function __construct(private string $basePath, private CompiledLayout $layout, private Stack $stack) {}

    public function plan(): Plan
    {
        $plan = new Plan('mod:install', name: 'inertia');
        if ($this->stack->inertia() === null) {
            $plan->wouldWrite = false;

            return $plan;
        }
        $paths = $this->layout->frontend($this->stack);
        if ($paths['pages'] === null || $paths['views'] === null) {
            $plan->warning('mod:install inertia needs frontend paths. Declare ->frontend(pages:, views:) in your layout.');

            return $plan;
        }
        $root = $this->groupRoot();
        $entry = $this->entry();
        if ($entry === null) {
            $plan->warning('mod:install inertia could not find resources/js/app.ts, .js, .tsx or .jsx. Add the resolver to your Inertia entry manually.');

            return $plan;
        }
        $extension = $this->extension();
        $pageGlob = $this->glob($paths['pages'], '*').'/**/*.'.$extension;
        $jsGlob = $this->glob(dirname($paths['pages']), '**').'/**/*';
        $viewsGlob = $this->glob($paths['views'], '**').'/**';
        $resourceGlob = $this->resourcesGlob($paths['pages']);
        $source = '../../'.$resourceGlob.'/**/*.{'.$extension.','.($this->stack->typescript() ? 'ts' : 'js').',blade.php}';
        if (! $this->mirrored()) {
            $before = $this->read($entry);
            $after = $this->entryEdit($before, $pageGlob);
            if ($after === null) {
                $plan->warning('mod:install inertia found a custom resolve or entry it does not recognize in ['.$entry.']. Keep it and add the manual lines below.', file: $entry);
                $this->edit($plan, $entry, $before, $this->manualEntry($pageGlob), $this->text('entry_description'));
            } else {
                $this->edit($plan, $entry, $before, $after, $this->text('entry_description'));
            }
        }
        $vite = $this->first(['vite.config.ts', 'vite.config.js']);
        if ($vite === null) {
            $plan->warning($this->text('vite_missing'));
        } else {
            $before = $this->read($vite);
            $after = $this->viteEdit($before, $root, $viewsGlob);
            if ($after === null) {
                $plan->warning(sprintf($this->text('vite_custom'), $vite, $root, $viewsGlob), file: $vite);
            } else {
                $this->edit($plan, $vite, $before, $after, $this->text('vite_description'));
            }
        }
        if ($this->stack->typescript()) {
            $before = $this->read('tsconfig.json');
            $after = $this->typescriptEdit($before, $root, $jsGlob);
            if ($after === null) {
                $plan->warning(sprintf($this->text('typescript_custom'), $root, $jsGlob), file: 'tsconfig.json');
            } else {
                $this->edit($plan, 'tsconfig.json', $before, $after, sprintf($this->text('typescript_description'), substr($jsGlob, 0, -5)));
            }
        }
        $css = 'resources/css/app.css';
        $tailwind = $this->first(['tailwind.config.js', 'tailwind.config.ts', 'tailwind.config.cjs']);
        if (str_contains($this->read($css), 'tailwindcss') && ! str_contains($this->read($css), '@tailwind')) {
            $before = $this->read($css);
            $after = preg_match('~^\s*@source\s+[\'"]'.preg_quote($source, '~').'[\'"];~m', $before) === 1 ? $before : $this->newlines("@source '".$source."';\n".str_replace("\r\n", "\n", $before), $before);
            $this->edit($plan, $css, $before, $after, '@source "'.$source.'"');
        } elseif ($tailwind !== null) {
            $before = $this->read($tailwind);
            $content = './'.substr($source, 6);
            $after = str_contains($before, $content) ? $before : preg_replace('/(\bcontent:\s*\[)/', '$1'."'".$content."', ", $before, 1, $count);
            if ($after === null || (! str_contains($before, $content) && $count !== 1)) {
                $plan->warning('mod:install inertia does not recognize ['.$tailwind.']. Add ['.$content.'] to content.', file: $tailwind);
            } else {
                $this->edit($plan, $tailwind, $before, $after, $this->text('content_description'));
            }
        } else {
            $plan->warning(sprintf($this->text('tailwind_missing'), $source));
        }
        $plan->wouldWrite = $plan->warnings === [] && $plan->files !== [];

        return $plan;
    }

    /** @return array{inertia: bool, vite_alias: bool, tailwind: bool} */
    public function wiring(): array
    {
        $paths = $this->layout->frontend($this->stack);
        $entry = $this->read($this->entry() ?? 'resources/js/app.ts');
        $vite = $this->read($this->first(['vite.config.ts', 'vite.config.js']) ?? 'vite.config.ts');
        $pages = $paths['pages'];
        $source = $pages === null ? null : $this->resourcesGlob($pages).'/**/*.{'.$this->extension().','.($this->stack->typescript() ? 'ts' : 'js').',blade.php}';
        $tailwind = $this->read('resources/css/app.css').$this->read($this->first(['tailwind.config.js', 'tailwind.config.ts', 'tailwind.config.cjs']) ?? 'tailwind.config.js');

        return [
            'inertia' => $this->stack->inertia() !== null && ($this->mirrored() || ($pages !== null && $this->entryWired($entry, $this->glob($pages, '*').'/**/*.'.$this->extension()))),
            'vite_alias' => $this->aliasWired($vite, $this->groupRoot()),
            'tailwind' => $source !== null && (preg_match('~^\s*@source\s+[\'"]'.preg_quote('../../'.$source, '~').'[\'"];~m', $tailwind) === 1 || preg_match('~\bcontent:\s*\[[^\]]*[\'"]'.preg_quote('./'.$source, '~').'[\'"]~s', $tailwind) === 1),
        ];
    }

    private function groupRoot(): string
    {
        $paths = $this->layout->frontend();
        foreach (['views', 'css', 'pages', 'components'] as $key) {
            if ($paths[$key] !== null && str_contains($paths[$key], '{')) {
                return rtrim(explode('{', $paths[$key], 2)[0], '/');
            }
        }
        foreach ($this->layout->roots() as $root) {
            if ($root->namespace !== null) {
                return rtrim(explode('{', $root->path, 2)[0], '/');
            }
        }

        return 'app';
    }

    private function mirrored(): bool
    {
        $paths = $this->layout->frontend($this->stack);

        return $paths['pages'] !== null && ($paths['pages'] === $this->stack->pagesPath() || str_starts_with($paths['pages'], $this->stack->pagesPath().'/')) && ! str_contains($paths['page_name'] ?? '', '::');
    }

    private function resourcesGlob(string $pages): string
    {
        $directories = [];
        foreach ($this->layout->frontend($this->stack) as $key => $path) {
            if ($key !== 'page_name' && $path !== null && str_contains($path, '{') && ! str_starts_with($path, 'resources/')) {
                $directories[] = explode('/', dirname($path));
            }
        }
        $common = array_shift($directories) ?? explode('/', dirname(dirname($pages)));
        foreach ($directories as $parts) {
            foreach ($common as $index => $part) {
                if (($parts[$index] ?? null) !== $part) {
                    while (count($common) > $index) {
                        array_pop($common);
                    }
                    break;
                }
            }
        }

        return $this->glob(implode('/', $common), '**');
    }

    private function glob(string $path, string $wildcard): string
    {
        return (string) preg_replace('/\{[^}]+\}/', $wildcard, $path);
    }

    private function extension(): string
    {
        return $this->stack->inertia() === 'vue' ? 'vue' : ($this->stack->typescript() ? 'tsx' : 'jsx');
    }

    private function entry(): ?string
    {
        return $this->first($this->stack->inertia() === 'react' ? ['resources/js/app.tsx', 'resources/js/app.jsx', 'resources/js/app.ts', 'resources/js/app.js'] : ['resources/js/app.ts', 'resources/js/app.js']);
    }

    /** @param list<string> $paths */
    private function first(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($this->basePath.'/'.$path)) {
                return $path;
            }
        }

        return null;
    }

    private function read(string $path): string
    {
        return is_file($this->basePath.'/'.$path) ? (string) file_get_contents($this->basePath.'/'.$path) : '';
    }

    private function manualEntry(string $pageGlob): string
    {
        $type = $this->stack->typescript() ? "<import('".($this->stack->inertia() === 'vue' ? 'vue' : 'react')."').".($this->stack->inertia() === 'vue' ? 'DefineComponent' : 'ComponentType').'>' : '';
        $app = 'import.meta.glob'.$type."('./".basename($this->stack->pagesPath()).'/**/*.'.$this->extension()."')";
        $groups = $this->groupMap($pageGlob, 'import.meta.glob'.$type."('../../".$pageGlob."')");

        return $this->text('import')."\n".sprintf($this->text('entry_line'), $app, $groups)."\n";
    }

    private function entryWired(string $contents, string $pageGlob): bool
    {
        return preg_match('~^'.preg_quote($this->text('import'), '~').'~m', $contents) === 1
            && preg_match('~^\\s*resolve:\\s*\\(name\\)\\s*=>\\s*'.$this->text('resolver').'\\(name,.*'.preg_quote('../../'.$pageGlob, '~').'~m', $contents) === 1;
    }

    private function groupMap(string $pageGlob, string $expression): string
    {
        if (preg_match('~/\*/resources/js/(pages|Pages)/~', $pageGlob) === 1) {
            return $expression;
        }
        $pages = $this->layout->frontend($this->stack)['pages'];
        if ($pages === null) {
            return $expression;
        }
        $segments = preg_split('/(\{[^}]+\})/', '../../'.$pages.'/', flags: PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $pattern = implode('', array_map(static fn (string $part): string => str_starts_with($part, '{') ? '([^/]+)' : preg_quote($part, '/'), $segments));

        return 'Object.fromEntries(Object.entries('.$expression.').map(([key, value]) => [key.replace(/^'.$pattern."/, '$1::'), value]))";
    }

    private function entryEdit(string $contents, string $pageGlob): ?string
    {
        $s = str_replace("\r\n", "\n", $contents);
        if ($this->entryWired($s, $pageGlob)) {
            return $contents;
        }
        $pattern = '~    resolve:\s*\(name\)\s*=>\s*resolvePageComponent\(\s*`\./(pages|Pages)/\$\{name\}\.(vue|tsx|jsx)`\s*,\s*(import\.meta\.glob(?:<[^\n]+?>)?\([\'\"]\./\1/\*\*/\*\.\2[\'\"]\))\s*,?\s*\),~';
        if (preg_match($pattern, $s, $match) === 1) {
            $groupGlob = str_replace('./'.$match[1].'/', '../../'.explode('/**/', $pageGlob, 2)[0].'/', $match[3]);
            $line = sprintf($this->text('entry_line'), $match[3], $this->groupMap($pageGlob, $groupGlob));
            $s = str_replace($match[0], $line, $s);
            $s = str_replace("import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';\n", '', $s);
        } elseif (preg_match('/\bresolve\s*[:(]/', $s) === 1 || substr_count($s, 'createInertiaApp({') !== 1) {
            return null;
        } else {
            $line = explode("\n", $this->manualEntry($pageGlob))[1];
            $s = str_replace("createInertiaApp({\n", "createInertiaApp({\n".$line."\n", $s);
        }
        $s = $this->text('import')."\n".$s;

        return $this->newlines($s, $contents);
    }

    private function aliasWired(string $contents, string $root): bool
    {
        $path = preg_quote($root, '~');
        $active = (string) preg_replace('/^\s*\/\/[^\r\n]*$/m', '', $contents);

        return preg_match(sprintf($this->text('alias_target_pattern'), $path, $path, $path), $active) === 1;
    }

    private function viteEdit(string $contents, string $root, string $views): ?string
    {
        $s = str_replace("\r\n", "\n", $contents);
        if (preg_match($this->text('alias_pattern'), $s) !== 1) {
            $alias = sprintf($this->text('alias_line'), $root);
            if (preg_match('/\balias:\s*\{/', $s) === 1) {
                $s = (string) preg_replace('/(\balias:\s*\{)/', '$1'."\n            ".$alias, $s, 1);
            } elseif (preg_match('/\bresolve\s*:/', $s) === 1) {
                return null;
            } elseif (substr_count($s, "defineConfig({\n") === 1) {
                $s = str_replace("defineConfig({\n", "defineConfig({\n    resolve: {\n        alias: {\n            ".$alias."\n        },\n    },\n", $s);
            } else {
                return null;
            }
            $s = "import { fileURLToPath as modFileURLToPath } from 'node:url';\n".$s;
        } elseif (! $this->aliasWired($s, $root)) {
            return null;
        }
        if (! str_contains($s, $views)) {
            if (preg_match('/\brefresh:\s*true\b/', $s) === 1) {
                $s = (string) preg_replace('/\brefresh:\s*true\b/', "refresh: ['app/Livewire/**', 'app/View/Components/**', 'lang/**', 'resources/lang/**', 'resources/views/**', 'routes/**', '".$views."']", $s, 1);
            } elseif (preg_match('/\brefresh:\s*\[/', $s) === 1) {
                $s = (string) preg_replace('/(\brefresh:\s*\[)/', '$1'."'".$views."', ", $s, 1);
            } else {
                return null;
            }
        }

        return $this->newlines($s, $contents);
    }

    private function typescriptEdit(string $contents, string $root, string $include): ?string
    {
        $s = str_replace("\r\n", "\n", $contents);
        if (! str_contains($s, $this->text('typescript_alias'))) {
            // Anchor only on active JSONC properties, never commented examples.
            $s = (string) preg_replace('/(^\s*"paths":\s*\{)/m', '$1'.sprintf($this->text('typescript_line'), $root), $s, 1, $count);
            if ($count !== 1) {
                return null;
            }
        } elseif (! str_contains($s, './'.$root.'/*')) {
            return null;
        }
        if (! str_contains($s, $include)) {
            $s = (string) preg_replace('/(^\s*"include":\s*\[)/m', '$1'.'"'.$include.'", ', $s, 1, $count);
            if ($count !== 1) {
                return null;
            }
        }

        return $this->newlines($s, $contents);
    }

    private function newlines(string $after, string $before): string
    {
        return str_contains($before, "\r\n") ? str_replace("\n", "\r\n", $after) : $after;
    }

    private function text(string $key): string
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../resources/install/inertia.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_string($data[$key] ?? null)) {
            throw new \LogicException('Missing Inertia installer content ['.$key.'].');
        }

        return $data[$key];
    }

    private function edit(Plan $plan, string $path, string $before, string $after, string $description): void
    {
        if ($before !== $after) {
            $plan->file($description, 'frontend-wiring', $path, ['before' => $before, 'after' => $after], true);
        }
    }
}
