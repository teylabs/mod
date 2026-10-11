<?php

namespace Tey\Mod\Survey;

use FilesystemIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Tey\Mod\Support\Path;

/**
 * The only door the survey has to the disk: it resolves a requested folder
 * inside the application, walks it without following links and reads PHP
 * sources. Everything outside the base path, vendor/, storage/, hidden
 * entries, keys, logs and databases is refused before it is opened.
 *
 * @internal
 */
final readonly class SurveyScope
{
    /** Folders below the base path that are never walked. */
    public const EXCLUDED_FOLDERS = ['bootstrap/cache', 'node_modules', 'public/build', 'public/hot', 'public/storage', 'storage', 'vendor'];

    /** Files that are never read or listed, wherever they are. */
    public const PRIVATE_FILES = ['*.crt', '*.db', '*.key', '*.log', '*.p12', '*.pem', '*.pfx', '*.sql', '*.sqlite', '*.sqlite3', '.*', 'auth.json', 'id_dsa*', 'id_ecdsa*', 'id_ed25519*', 'id_rsa*'];

    /** Larger sources are listed but not read. */
    private const MAX_SOURCE_BYTES = 1048576;

    private string $root;

    public function __construct(string $basePath)
    {
        $real = realpath($basePath);
        $this->root = Path::normalize($real === false ? $basePath : $real);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The project-relative folder to survey: '' for the whole application.
     *
     * @throws OutOfScope when the folder is outside the application or private
     */
    public function folder(?string $requested): string
    {
        if ($requested === null || trim($requested) === '' || trim($requested) === '.') {
            return '';
        }
        if (str_contains($requested, "\0")) {
            throw new OutOfScope('mod-survey reads only folders inside this application. Pass a path relative to the application root.');
        }
        $normalized = Path::normalize(trim($requested));
        if (in_array('..', explode('/', $normalized), true)) {
            throw new OutOfScope('mod-survey refuses ['.$requested.']: ".." would leave the application. It reads only folders inside this application.');
        }
        $candidate = (new Filesystem)->isAbsolutePath($normalized) ? $normalized : Path::join($this->root, $normalized);
        $real = realpath($candidate);
        $relative = $real === false ? null : Path::relative($this->root, Path::normalize($real));
        if ($relative === null) {
            throw new OutOfScope('mod-survey refuses ['.$requested.']: it is outside this application or does not exist. It reads only folders inside this application, never parent or sibling folders, other repositories or the home directory.');
        }
        if (! is_dir((string) $real)) {
            throw new OutOfScope('mod-survey refuses ['.$requested.']: pass a folder inside this application.');
        }
        if ($relative !== '' && ($this->isExcluded($relative) || $this->hasPrivateSegment($relative))) {
            throw new OutOfScope('mod-survey refuses ['.$requested.']: vendor/, storage/, caches, hidden folders and private files are never surveyed.');
        }

        return $relative;
    }

    /**
     * Every surveyable file below a folder, project-relative and sorted. Links
     * are never followed, and excluded folders and private files are skipped.
     *
     * @return list<string>
     */
    public function files(string $folder): array
    {
        $files = [];
        $this->walk($folder, $files);
        sort($files, SORT_STRING);

        return $files;
    }

    /** The source of a surveyable PHP file, or null when it may not be read. */
    public function source(string $relative): ?string
    {
        if (! str_ends_with($relative, '.php') || str_ends_with($relative, '.blade.php') || $this->isExcluded($relative) || $this->hasPrivateSegment($relative)) {
            return null;
        }
        $path = Path::join($this->root, $relative);
        $real = realpath($path);
        if (is_link($path) || $real === false || Path::relative($this->root, Path::normalize($real)) !== $relative || ! is_file($real) || filesize($real) > self::MAX_SOURCE_BYTES) {
            return null;
        }
        $source = file_get_contents($real);

        return $source === false ? null : $source;
    }

    /** @param list<string> $files */
    private function walk(string $folder, array &$files): void
    {
        $absolute = $folder === '' ? $this->root : Path::join($this->root, $folder);
        if (is_link($absolute) || ! is_dir($absolute)) {
            return;
        }
        /** @var SplFileInfo $item */
        foreach (new FilesystemIterator($absolute, FilesystemIterator::SKIP_DOTS) as $item) {
            $relative = $folder === '' ? $item->getFilename() : $folder.'/'.$item->getFilename();
            if ($item->isLink() || $this->isPrivate($item->getFilename())) {
                continue;
            }
            if ($item->isDir()) {
                if (! $this->isExcluded($relative)) {
                    $this->walk($relative, $files);
                }
            } elseif ($item->isFile()) {
                $files[] = $relative;
            }
        }
    }

    private function isExcluded(string $relative): bool
    {
        foreach (self::EXCLUDED_FOLDERS as $excluded) {
            if (Path::relative($excluded, $relative) !== null) {
                return true;
            }
        }

        return in_array('node_modules', explode('/', $relative), true);
    }

    private function hasPrivateSegment(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if ($this->isPrivate($segment)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivate(string $name): bool
    {
        foreach (self::PRIVATE_FILES as $pattern) {
            if (fnmatch($pattern, strtolower($name))) {
                return true;
            }
        }

        return false;
    }
}
