<?php

namespace Tey\Mod\Rename;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;

/** @internal Collect project-owned original inputs without following links or dependencies. */
final class Snapshot
{
    public function __construct(private readonly ExcludedPaths $excluded = new ExcludedPaths) {}

    /** @return list<string> */
    public function roots(CompiledLayout $layout, ?string $basePath = null): array
    {
        $roots = ['app', 'bootstrap', 'config', 'resources', 'routes', 'tests'];
        if ($basePath !== null && is_dir($basePath.'/lang')) {
            $roots[] = 'lang';
        }
        foreach ($layout->roots() as $root) {
            $literal = rtrim(substr($root->path, 0, strcspn($root->path, '{')), '/');
            if ($literal !== '' && Path::relative('', $literal) !== null) {
                $roots[] = $literal;
            }
        }
        foreach ($layout->frontend() as $key => $path) {
            if ($key !== 'page_name' && $path !== null) {
                $roots[] = rtrim(substr($path, 0, strcspn($path, '{')), '/');
            }
        }
        $roots = array_values(array_unique(array_filter($roots, fn (string $root): bool => $root !== '' && Path::relative('', $root) !== null && ! $this->excluded($root))));
        sort($roots);
        $minimal = [];
        foreach ($roots as $root) {
            if (array_filter($minimal, static fn (string $parent): bool => Path::relative($parent, $root) !== null) === []) {
                $minimal[] = $root;
            }
        }

        return $minimal;
    }

    public function excluded(string $path): bool
    {
        return $this->excluded->contains($path);
    }

    public static function migration(string $path): bool
    {
        return preg_match('~(?:^|/)(?:migrations)(?:/|$)~i', $path) === 1 || preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_/', basename($path)) === 1;
    }

    /** @param list<string> $roots
     * @return list<string>
     */
    public function membership(string $basePath, array $roots, GitState $git): array
    {
        $paths = $git->paths;
        if ($git->root === null) {
            foreach ($roots as $root) {
                $absolute = Path::resolve($basePath, $root);
                if (! is_dir($absolute) || is_link($absolute)) {
                    continue;
                }
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
                /** @var SplFileInfo $file */
                foreach ($iterator as $file) {
                    $paths[] = Path::relative($basePath, $file->getPathname()) ?? '';
                }
            }
        }
        $paths = array_values(array_unique(array_filter($paths, fn (string $path): bool => ! $this->excluded($path) && array_filter($roots, fn (string $root): bool => Path::relative($root, $path) !== null) !== [])));
        sort($paths);

        return $paths;
    }

    /** @param list<string> $paths
     * @return array<string, InputFile>
     */
    public function files(string $basePath, array $paths): array
    {
        $files = [];
        $guard = new PathGuard($basePath);
        foreach ($paths as $path) {
            $absolute = Path::resolve($basePath, $path);
            if ($guard->problem($path) === null && is_file($absolute)) {
                $bytes = file_get_contents($absolute);
                if ($bytes !== false) {
                    $files[$path] = new InputFile($path, $bytes, (int) fileperms($absolute) & 0777, self::migration($path));
                }
            }
        }

        return $files;
    }

    /** Lane 4 calls under its lock immediately before applying the plan. */
    public function unchanged(Inputs $inputs, string $definitionHash): bool
    {
        $git = (new GitProbe)->inspect($inputs->basePath);
        if (! $git->clean() || $git->status !== $inputs->git->status || $git->indexHash !== $inputs->git->indexHash || $definitionHash !== $inputs->definitionHash || $this->membership($inputs->basePath, $inputs->roots, $git) !== $inputs->membership) {
            return false;
        }
        foreach ($inputs->files as $file) {
            $path = Path::resolve($inputs->basePath, $file->path);
            if ((new PathGuard($inputs->basePath))->problem($file->path) !== null || ! is_file($path) || hash_file('sha256', $path) !== $file->hash || ((int) fileperms($path) & 0777) !== $file->mode) {
                return false;
            }
        }
        foreach ($inputs->dependencies as $path => $hash) {
            if (! is_file($path) || hash_file('sha256', $path) !== $hash) {
                return false;
            }
        }

        return true;
    }
}
