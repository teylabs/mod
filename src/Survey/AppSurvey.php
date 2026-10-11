<?php

namespace Tey\Mod\Survey;

use Composer\Autoload\ClassLoader;
use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Listing\LayoutInventory;
use Tey\Mod\Pages\PageDirectories;
use Tey\Mod\Pages\PageEntry;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseOutcome;
use Tey\Mod\Support\Path;

/**
 * How the application is actually organized: where each file type's files
 * live, the files no file type places, a reference graph between app classes,
 * the file sets and names that recur, the vendor classes the app uses and
 * the git state of what was scanned.
 *
 * Placement comes from the compiled layout and its reverse mapper, the same
 * services mod:list and discovery use. Every read goes through SurveyScope.
 * The output is sorted throughout, so the same tree gives the same bytes.
 *
 * @internal
 *
 * @phpstan-import-type Node from FileSets
 * @phpstan-import-type Edge from FileSets
 */
final readonly class AppSurvey
{
    private const PAGE_EXTENSIONS = ['vue', 'tsx', 'jsx', 'svelte', 'ts', 'js'];

    public function __construct(private Application $app) {}

    /**
     * @return array<string, mixed>
     *
     * @throws OutOfScope when the requested folder may not be read
     * @throws ModException when the layout does not compile
     */
    public function read(?string $path = null): array
    {
        $scope = new SurveyScope($this->app->basePath());
        $folder = $scope->folder($path);
        $layout = $this->app->make(CompiledLayout::class);
        $mapper = new ReverseMapper($layout);
        $files = $scope->files($folder);
        $known = array_fill_keys($files, true);

        /** @var array<string, Node> $nodes */
        $nodes = [];
        /** @var array<string, string> $classes fqcn => node id */
        $classes = [];
        /** @var array<string, PhpSource> $sources node id => source */
        $sources = [];
        /** @var array<string, ResolvedArtifact> $artifacts node id => placed artifact */
        $artifacts = [];
        $groups = [];
        $ambiguous = [];
        $unplaced = [];
        foreach ($files as $file) {
            $source = $scope->source($file);
            $parsed = $source === null ? null : PhpSource::parse($source);
            $class = $parsed->classes[0] ?? null;
            // A class declared twice keeps its first file; the copy is known by its path.
            $id = $class !== null && ! isset($nodes[$class]) ? $class : $file;
            $match = $mapper->fromPath($file);
            $artifact = $match->outcome === ReverseOutcome::Matched ? $match->artifact : null;
            $context = $artifact?->context->toArray() ?? [];
            ksort($context, SORT_STRING);
            $nodes[$id] = ['type' => $artifact?->kind->id, 'name' => $class === null ? self::fileName($file) : class_basename($class), 'path' => $file, 'context' => $context];
            foreach ($parsed->classes ?? [] as $declared) {
                $classes[$declared] ??= $id;
            }
            if ($parsed !== null) {
                $sources[$id] = $parsed;
            }
            if ($artifact !== null) {
                $artifacts[$id] = $artifact;
                $directory = str_contains($file, '/') ? substr($file, 0, (int) strrpos($file, '/')) : '';
                $namespace = $class !== null && str_contains($class, '\\') ? substr($class, 0, (int) strrpos($class, '\\')) : null;
                $key = $directory."\0".$namespace."\0".json_encode($context);
                $groups[$artifact->kind->id][$key] ??= ['context' => $context === [] ? null : $context, 'files' => [], 'folder' => $directory, 'namespace' => $namespace];
                $groups[$artifact->kind->id][$key]['files'][] = ['class' => $class, 'path' => $file];
            } elseif ($match->outcome === ReverseOutcome::Ambiguous) {
                $types = array_values(array_unique(array_map(static fn (ResolvedArtifact $candidate): string => $candidate->kind->id, $match->candidates)));
                sort($types, SORT_STRING);
                $ambiguous[] = ['path' => $file, 'types' => $types];
            } else {
                $unplaced[] = $file;
            }
        }

        /** @var array<string, Edge> $edges */
        $edges = [];
        /** @var array<string, array<string, true>> $vendorUses class => node ids */
        $vendorUses = [];
        $vendor = $this->vendorLoader($scope->root());
        $link = static function (string $from, string $kind, string $to) use (&$edges): void {
            if ($from !== $to) {
                $edges[$from."\0".$kind."\0".$to] = ['from' => $from, 'kind' => $kind, 'to' => $to];
            }
        };
        $refer = static function (string $from, string $class) use ($classes, $vendor, &$vendorUses, $link, $scope): void {
            $class = ltrim($class, '\\');
            if (isset($classes[$class])) {
                $link($from, 'import', $classes[$class]);
            } elseif (self::vendorPackage($vendor, $scope->root(), $class) !== null) {
                $vendorUses[$class][$from] = true;
            }
        };

        $pages = new PageEntry(new PageDirectories($layout, $scope->root(), new Filesystem), $scope->root());
        foreach ($sources as $id => $parsed) {
            foreach ($parsed->imports as $import) {
                $refer($id, $import);
            }
            foreach ($parsed->pages as $page) {
                $target = 'inertia:'.$page;
                foreach (self::PAGE_EXTENSIONS as $extension) {
                    try {
                        $candidate = $pages->path($page, $extension);
                    } catch (InvalidArgumentException) {
                        break;
                    }
                    if (isset($known[$candidate])) {
                        $target = $candidate;
                        break;
                    }
                }
                if (! isset($nodes[$target])) {
                    $nodes[$target] = ['type' => 'page', 'name' => $page, 'path' => null, 'context' => []];
                }
                $link($id, 'inertia', $target);
            }
        }

        foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
            $controller = $route->getControllerClass();
            if (! is_string($controller) || $controller === '') {
                continue;
            }
            $controller = ltrim($controller, '\\');
            $methods = $route->methods();
            sort($methods, SORT_STRING);
            $id = 'route:'.implode('|', $methods).' '.$route->uri();
            if (isset($classes[$controller])) {
                $nodes[$id] ??= ['type' => 'route', 'name' => $route->uri(), 'path' => null, 'context' => []];
                $link($id, 'route', $classes[$controller]);
            } elseif ($folder === '' && self::vendorPackage($vendor, $scope->root(), $controller) !== null) {
                $vendorUses[$controller][$id] = true;
            }
        }

        $relations = new RelationResolver($layout, new PlacementResolver($layout));
        foreach ($artifacts as $id => $artifact) {
            foreach ($layout->relationsFrom($artifact->kind->id) as $relation) {
                try {
                    $target = $relations->resolve($artifact, $relation->id)->target?->fqcn();
                } catch (ModException) {
                    continue;
                }
                if ($target !== null && isset($classes[$target])) {
                    $kind = $relation->fromKind === FileSets::ANCHOR_TYPE && $relation->toKind === 'policy' ? 'policy' : 'relation';
                    $link($id, $kind, $classes[$target]);
                }
            }
        }
        $gate = $this->app->bound(GateContract::class) ? $this->app->make(GateContract::class) : null;
        if ($gate instanceof Gate) {
            foreach ((array) $gate->policies() as $model => $policy) {
                if (is_string($model) && is_string($policy) && isset($classes[ltrim($model, '\\')], $classes[ltrim($policy, '\\')])) {
                    $link($classes[ltrim($model, '\\')], 'policy', $classes[ltrim($policy, '\\')]);
                }
            }
        }

        ksort($edges, SORT_STRING);
        $edges = array_values($edges);
        $clusters = FileSets::build($nodes, $edges);

        $types = [];
        foreach (array_keys($layout->kinds()) as $type) {
            $rows = array_values($groups[$type] ?? []);
            usort($rows, static fn (array $a, array $b): int => [$a['folder'], (string) $a['namespace'], json_encode($a['context'])] <=> [$b['folder'], (string) $b['namespace'], json_encode($b['context'])]);
            $types[] = ['count' => array_sum(array_map(static fn (array $row): int => count($row['files']), $rows)), 'declared' => LayoutInventory::folder($layout, $type), 'groups' => $rows, 'id' => $type];
        }
        usort($types, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        $vendorRows = [];
        foreach ($vendorUses as $class => $users) {
            $users = array_keys($users);
            sort($users, SORT_STRING);
            $vendorRows[] = ['class' => $class, 'package' => self::vendorPackage($vendor, $scope->root(), $class), 'used_by' => $users];
        }
        usort($vendorRows, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        $layoutName = $this->app->make('config')->get('mod.layout');

        return self::sortKeys([
            'ambiguous' => $ambiguous,
            'graph' => ['edges' => $edges],
            'patterns' => $clusters['patterns'],
            'scan' => [
                'excluded' => [...SurveyScope::EXCLUDED_FOLDERS, ...SurveyScope::PRIVATE_FILES],
                'files' => count($files),
                'git' => GitState::read($scope->root()),
                'layout' => is_string($layoutName) && $layoutName !== '' ? $layoutName : 'laravel',
                'path' => $folder === '' ? '.' : $folder,
            ],
            'sets' => $clusters['sets'],
            'types' => $types,
            'unplaced' => ['count' => count($unplaced), 'paths' => $unplaced],
            'vendor' => $vendorRows,
        ]);
    }

    /** The name a file is known by: its folder and file name, without the extension. */
    private static function fileName(string $file): string
    {
        $parts = explode('/', $file);
        $last = (string) array_pop($parts);
        $base = str_contains($last, '.') ? substr($last, 0, (int) strpos($last, '.')) : $last;
        $parent = array_pop($parts);

        return $parent === null ? $base : $parent.'/'.$base;
    }

    /** The Composer loader that autoloads the application's own vendor folder. */
    private function vendorLoader(string $root): ?ClassLoader
    {
        $vendor = realpath($root.'/vendor');
        if ($vendor === false) {
            return null;
        }
        foreach (ClassLoader::getRegisteredLoaders() as $directory => $loader) {
            $real = realpath($directory);
            if ($real !== false && Path::same(Path::normalize($real), Path::normalize($vendor))) {
                return $loader;
            }
        }

        return null;
    }

    /** The vendor/name package whose files define a class, located without reading them. */
    private static function vendorPackage(?ClassLoader $loader, string $root, string $class): ?string
    {
        $file = $loader?->findFile($class);
        if (! is_string($file)) {
            return null;
        }
        $real = realpath($file);
        $relative = $real === false ? null : Path::relative($root.'/vendor', Path::normalize($real));
        $parts = $relative === null ? [] : explode('/', $relative);

        return count($parts) > 2 ? $parts[0].'/'.$parts[1] : null;
    }

    /**
     * Sort object keys at every level; lists keep their own order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
