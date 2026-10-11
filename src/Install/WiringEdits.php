<?php

namespace Tey\Mod\Install;

use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Support\Stack;

/** @internal Combine provider wiring with the existing installer recognisers. */
final readonly class WiringEdits
{
    public function __construct(private string $basePath, private CompiledLayout $layout, private Stack $stack, private Wiring $wiring) {}

    public function plan(?Plan $plan = null): Plan
    {
        $plan ??= new Plan('mod:install', name: 'inertia');
        $before = [];
        $after = [];
        $labels = [];
        foreach ($plan->files as $file) {
            if (is_string($file['identity']['before'] ?? null) && is_string($file['identity']['after'] ?? null)) {
                $labels[$file['path']] = $file['alias'];
                $before[$file['path']] = $file['identity']['before'];
                $after[$file['path']] = $file['identity']['after'];
            }
        }
        $vite = is_file($this->basePath.'/vite.config.ts') ? 'vite.config.ts' : 'vite.config.js';
        foreach ($this->wiring->aliases() as $alias => $directory) {
            $edits = new InertiaEdits($this->basePath, $this->layout, $this->stack, $alias);
            $before[$vite] ??= $this->read($vite);
            $after[$vite] ??= $before[$vite];
            $edited = $edits->viteEdit($after[$vite], $directory, '');
            if ($edited === null) {
                $plan->warning('Could not confirm ['.$vite.']; add '.json_encode($alias).": fileURLToPath(new URL('./".$directory."', import.meta.url)) to resolve.alias; keep the more specific alias first.", file: $vite);
            } else {
                $after[$vite] = $edited;
            }
            if ($this->stack->typescript()) {
                $before['tsconfig.json'] ??= $this->read('tsconfig.json');
                $after['tsconfig.json'] ??= $before['tsconfig.json'];
                $edited = $edits->typescriptEdit($after['tsconfig.json'], $directory, $directory.'/**/*');
                if ($edited === null) {
                    $plan->warning('Could not confirm [tsconfig.json]; add '.json_encode($alias.'/*').': ["./'.$directory.'/*"] to compilerOptions.paths and "'.$directory.'/**/*" to include.', file: 'tsconfig.json');
                } else {
                    $after['tsconfig.json'] = $edited;
                }
            }
        }
        $css = 'resources/css/app.css';
        foreach (['source' => $this->wiring->sources(), 'import' => $this->wiring->styles()] as $directive => $paths) {
            foreach ($paths as $path) {
                $before[$css] ??= $this->read($css);
                $after[$css] ??= $before[$css];
                $relative = $this->fromCss($path);
                $line = '@'.$directive." '".$relative."';";
                $active = $this->active($after[$css]);
                if (preg_match('~^\s*@'.$directive.'\s+[\'"]'.preg_quote($relative, '~').'[\'"];~m', $active) === 1) {
                    continue;
                }
                if ($directive === 'source' && (! str_contains($active, 'tailwindcss') || str_contains($active, '@tailwind'))) {
                    $plan->warning('Could not confirm ['.$css.']; add '.$line.' to the Tailwind v4 entry, or "'.$path.'/**/*" to Tailwind v3 content.', file: $css);
                } elseif ($active === '') {
                    $plan->warning('Could not confirm ['.$css.']; add '.$line.' to your CSS entry.', file: $css);
                } else {
                    $newline = str_contains($after[$css], "\r\n") ? "\r\n" : "\n";
                    $after[$css] = $line.$newline.$after[$css];
                }
            }
        }
        $plan->files = array_values(array_filter($plan->files, static fn (array $file): bool => ! isset($after[$file['path']])));
        foreach ($after as $path => $contents) {
            if ($contents !== $before[$path]) {
                $plan->file($labels[$path] ?? 'frontend wiring', 'frontend-wiring', $path, ['before' => $before[$path], 'after' => $contents], is_file($this->basePath.'/'.$path));
            }
        }

        return $plan;
    }

    /** @return list<array{alias: string, root: string, vite_alias: bool, typescript: bool}> */
    public function status(): array
    {
        $vite = $this->read(is_file($this->basePath.'/vite.config.ts') ? 'vite.config.ts' : 'vite.config.js');
        $rows = [];
        foreach ($this->wiring->aliases() as $alias => $root) {
            $edits = new InertiaEdits($this->basePath, $this->layout, $this->stack, $alias);
            $rows[] = ['alias' => $alias, 'root' => $root, 'vite_alias' => $edits->aliasWired($vite, $root), 'typescript' => ! $this->stack->typescript() || $edits->typescriptEdit($this->read('tsconfig.json'), $root, $root.'/**/*') === $this->read('tsconfig.json')];
        }

        return $rows;
    }

    private function read(string $path): string
    {
        return is_file($this->basePath.'/'.$path) ? (string) file_get_contents($this->basePath.'/'.$path) : '';
    }

    private function active(string $contents): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $contents);
    }

    private function fromCss(string $path): string
    {
        return '../../'.$path;
    }
}
