<?php

namespace Tey\Mod\Support;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path as SymfonyPath;

/** @internal Validate declarations without resolving installed paths in generated wiring. */
final class FrontendPaths
{
    /** @var list<array{code: string, path: string, message: string}> */
    private array $notices = [];

    public function __construct(private readonly string $basePath) {}

    public function relative(string $path, bool $directory = false): ?string
    {
        $original = Path::normalize($path);
        $absolute = (new Filesystem)->isAbsolutePath($original);
        // Providers commonly pass __DIR__.'/../resources': PHP resolves __DIR__
        // through Composer's link. Recover the installed, lexical package path.
        $path = $absolute ? Path::normalize(SymfonyPath::canonicalize($original)) : $original;
        $relative = $absolute ? Path::relative($this->basePath, $path) : $path;
        if ($absolute && $relative === null) {
            foreach (glob($this->basePath.'/vendor/*/*', GLOB_ONLYDIR) ?: [] as $package) {
                if (! Path::isLink($package) || ($real = realpath($package)) === false) {
                    continue;
                }
                $suffix = Path::relative($real, $path);
                if ($suffix !== null) {
                    $relative = Path::relative($this->basePath, $package).($suffix === '' ? '' : '/'.$suffix);
                    break;
                }
            }
        }
        if ($relative === null || $relative === '' || preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1f\x27"`]|^[A-Za-z]:|^/|[{}*?]~', $relative) === 1) {
            return $this->skip('frontend_path_invalid', $original, 'Use a literal path inside the application.');
        }
        $target = $this->basePath.'/'.$relative;
        $ancestor = $target;
        while (! file_exists($ancestor) && ! Path::isLink($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $real = realpath($ancestor);
        if (! file_exists($ancestor) || $real === false) {
            return $this->skip('frontend_path_unavailable', $original, 'The path cannot be resolved.');
        }
        if (! str_starts_with($relative, 'vendor/') && Path::relative((string) realpath($this->basePath), $real) === null) {
            return $this->skip('frontend_path_escape', $original, 'Only installed vendor paths may follow a symlink outside the application.');
        }
        if ($directory && ! is_dir($target)) {
            return $this->skip('frontend_path_unavailable', $original, 'The page directory does not exist.');
        }

        return rtrim($relative, '/');
    }

    public function skip(string $code, string $path, string $reason): null
    {
        $notice = ['code' => $code, 'path' => $path, 'message' => 'Skipped frontend declaration ['.$path.']. '.$reason];
        if (! in_array($notice, $this->notices, true)) {
            $this->notices[] = $notice;
        }

        return null;
    }

    /** @return list<array{code: string, path: string, message: string}> */
    public function notices(): array
    {
        return $this->notices;
    }
}
