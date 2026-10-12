<?php

namespace Tey\Mod\Rename;

use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Mod\Exceptions\GenerationRefused;
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
            if ($key !== 'page_name' && is_string($path)) {
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

    /** @return array{files: list<string>, directories: array<string, int>} */
    public static function tree(string $basePath, string $path): array
    {
        if ((new self)->excluded($path)) {
            throw GenerationRefused::because("mod:rename folder content {$path} is excluded from project-owned paths. Nothing was written.");
        }
        if (($problem = (new PathGuard($basePath))->problem($path)) !== null) {
            throw GenerationRefused::because($problem);
        }
        $absolute = Path::resolve($basePath, $path);
        if (! is_dir($absolute)) {
            throw GenerationRefused::because("mod:rename source folder {$path} is missing. Nothing was written.");
        }
        $tree = ['files' => [], 'directories' => [$path => (int) fileperms($absolute) & 0777]];
        foreach (new DirectoryIterator($absolute) as $file) {
            if ($file->isDot()) {
                continue;
            }
            $child = Path::join($path, $file->getFilename());
            if (($problem = (new PathGuard($basePath))->problem($child)) !== null || Path::isLink($file->getPathname())) {
                throw GenerationRefused::because($problem ?? "mod:rename path {$child} traverses a symlink. Nothing was written.");
            }
            if ($file->isDir()) {
                $subtree = self::tree($basePath, $child);
                array_push($tree['files'], ...$subtree['files']);
                $tree['directories'] += $subtree['directories'];
            } elseif ($file->isFile()) {
                $tree['files'][] = $child;
            } else {
                throw GenerationRefused::because("mod:rename folder content {$child} is not a regular file. Nothing was written.");
            }
        }
        sort($tree['files']);
        ksort($tree['directories']);

        return $tree;
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

    /** Lane 4 calls under its lock immediately before applying the plan.
     * @phpstan-impure Reads current filesystem and Git state on every invocation.
     */
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
