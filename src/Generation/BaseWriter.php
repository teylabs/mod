<?php

namespace Tey\Mod\Generation;

use Illuminate\Filesystem\Filesystem;
use Tey\Mod\Exceptions\InvalidGeneratorSetup;
use Tey\Mod\Placement\Root;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Support\Path;

/**
 * Where a generated base lives, and writing it when it is missing.
 *
 * A base goes in the bases folder (`mod.bases_path`, app/Support by default),
 * under its `in` folder: the same class name in every layout, so a module
 * copied to another application finds it there. A base marked inKindRoot()
 * goes below the generated kind's own root instead.
 *
 * @internal used by the mod:* generators and mod:bases
 */
final readonly class BaseWriter
{
    /**
     * @param  string  $basesPath  the bases folder, relative to the base path
     * @param  string  $appNamespace  the namespace of app/, for a bases folder no layout root covers
     */
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private string $basesPath = 'app/Support',
        private string $appNamespace = 'App\\',
    ) {}

    /**
     * The base's class name and its file, relative to the base path.
     *
     * @return array{fqcn: string, path: string}
     */
    public function locate(GeneratedBase $base, Preset $preset, string $kindId): array
    {
        [$namespace, $path] = $base->inKindRoot
            ? $this->kindRoot($preset, $kindId)
            : $this->basesFolder($preset);

        $folder = str_replace('/', '\\', $base->in);

        return [
            'fqcn' => rtrim($namespace, '\\').'\\'.($folder === '' ? '' : $folder.'\\').$base->name,
            'path' => Path::join($path, $base->in, $base->name.'.php'),
        ];
    }

    /**
     * The folder bases go in when they are not kept in a kind's root, relative to the base path.
     */
    public function basesPath(): string
    {
        return $this->basesPath;
    }

    /**
     * Write the base unless its class or file already exists; never overwrite.
     *
     * @param  array{fqcn: string, path: string}  $location
     * @return bool whether it was written
     */
    public function ensure(GeneratedBase $base, array $location): bool
    {
        $absolute = Path::join($this->basePath, $location['path']);

        if (class_exists($location['fqcn']) || is_file($absolute)) {
            return false;
        }

        $published = Path::join($this->basePath, 'stubs/mod.base.'.$base->stubName().'.stub');
        $body = (string) file_get_contents(is_file($published) ? $published : $base->stub);
        $namespace = substr($location['fqcn'], 0, (int) strrpos($location['fqcn'], '\\'));

        $this->files->ensureDirectoryExists(dirname($absolute));
        $this->files->put($absolute, str_replace(
            ['{{ namespace }}', '{{namespace}}', '{{ class }}', '{{class}}'],
            [$namespace, $namespace, $base->name, $base->name],
            $body,
        ));

        return true;
    }

    /**
     * @return array{string, string}
     */
    private function kindRoot(Preset $preset, string $kindId): array
    {
        $root = $preset->rule($kindId)->root();

        return [(string) $root->namespace, $root->path];
    }

    /**
     * The bases folder's namespace: from the deepest layout root that holds
     * it, else from app/.
     *
     * @return array{string, string}
     */
    private function basesFolder(Preset $preset): array
    {
        $path = Root::normalisePath($this->basesPath);
        $best = null;

        foreach ($preset->roots() as $root) {
            if ($root->namespace !== null && $root->pathRemainder($path) !== null
                && ($best === null || strlen($root->path) > strlen($best->path))) {
                $best = $root;
            }
        }

        if ($best !== null) {
            $remainder = (string) $best->pathRemainder($path);

            return [rtrim((string) $best->namespace, '\\').($remainder === '' ? '' : '\\'.str_replace('/', '\\', $remainder)), $path];
        }

        if ($path === 'app' || str_starts_with($path, 'app/')) {
            $remainder = substr($path, strlen('app'));

            return [rtrim($this->appNamespace, '\\').str_replace('/', '\\', $remainder), $path];
        }

        throw new InvalidGeneratorSetup("Config [mod.bases_path] is [{$this->basesPath}], which is outside app/ and every root of the layout, so its classes have no namespace. Set it to a folder inside app/, such as app/Support.");
    }
}
