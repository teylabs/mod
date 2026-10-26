<?php

namespace Tey\Mod\Routing;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use ReflectionClass;
use Tey\Mod\Discovery\DisabledGroups;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Layout\BuiltIn\RouteContent;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Support\Path;
use Tey\Mod\Templates\TokenExtractor;

/** @internal Application-scoped route catalogue, call provenance and explicit loader. */
final class ModRoutes
{
    /** @var array<string, string> */
    private array $calls = [];

    /** @var array<string, int> */
    private array $orders = [];

    /** @var array<string, string> */
    private array $loaded = [];

    /** @var list<array{only: ?list<string>, except: list<string>, apiPrefix: string}> */
    private array $invocations = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, array{directory: ?string, class: ?class-string<RegistersRoutes>, apiPrefix: ?string}> */
    private array $packages = [];

    public function files(string $group, string $directory, ?string $apiPrefix = null): void
    {
        $this->addPackage($group, ['directory' => $directory, 'class' => null, 'apiPrefix' => $apiPrefix]);
    }

    /** @param class-string<RegistersRoutes> $class */
    public function registrar(string $group, string $class, ?string $apiPrefix = null): void
    {
        $this->addPackage($group, ['directory' => null, 'class' => $class, 'apiPrefix' => $apiPrefix]);
    }

    /** @param array{directory: ?string, class: ?class-string<RegistersRoutes>, apiPrefix: ?string} $entry */
    private function addPackage(string $group, array $entry): void
    {
        if (trim($group) === '' || isset($this->packages[$group]) || $this->calledFor($group)) {
            throw new \LogicException("Package route group [{$group}] must be unique and registered before loading routes.");
        }
        $this->packages[$group] = $entry;
    }

    /** @param list<string> $baseline */
    public function __construct(private readonly Application $app, private readonly array $baseline) {}

    /** @param list<string>|null $only
     * @param  list<string>  $except
     */
    public function load(?array $only, array $except, string $call, string $apiPrefix = 'api'): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }
        $this->invocations[] = ['only' => $only, 'except' => $except, 'apiPrefix' => $apiPrefix];
        $entries = $this->entries();
        $groups = array_values(array_unique(array_column($entries, 'group')));
        $disabled = DisabledGroups::of($this->app);
        $groups = array_values(array_filter($groups, static fn (string $group): bool => ($only === null || in_array($group, $only, true)) && ! in_array($group, $except, true) && ! $disabled->contains($group)));
        foreach ($groups as $group) {
            if (isset($this->calls[$group])) {
                throw new \LogicException(sprintf(RouteContent::DUPLICATE, $group, $this->calls[$group], $call));
            }
        }
        foreach ($this->warnings as $warning) {
            $this->app->make('log')->warning($warning);
        }
        $router = $this->app->make(Router::class);
        foreach ($groups as $position => $group) {
            $this->calls[$group] = $call;
            $this->orders[$group] = $position + 1;
            foreach ($entries as $entry) {
                if ($entry['group'] !== $group || $entry['loaded_by'] !== null) {
                    continue;
                }
                $method = $entry['middleware_group'];
                if ($method === 'console') {
                    if ($this->app->runningInConsole()) {
                        $this->includeFile($entry['entrypoint']);
                        $this->loaded[$entry['entrypoint']] = $call;
                    }

                    continue;
                }
                $attributes = ['middleware' => $method];
                if ($method === 'api') {
                    $attributes['prefix'] = $entry['api_prefix'] ?? 'api';
                }
                $router->group($attributes, function () use ($entry, $method): void {
                    if (! str_contains($entry['entrypoint'], '::')) {
                        $this->includeFile($entry['entrypoint']);
                    } else {
                        [$class] = explode('::', $entry['entrypoint']);
                        if (is_subclass_of($class, RegistersRoutes::class)) {
                            if ($method === 'web') {
                                $class::web();
                            } else {
                                $class::api();
                            }
                        }
                    }
                });
                $this->loaded[$entry['entrypoint']] = $call;
            }
        }
    }

    public function calledFor(string $group): bool
    {
        foreach ($this->invocations as $invocation) {
            if (($invocation['only'] === null || in_array($group, $invocation['only'], true)) && ! in_array($group, $invocation['except'], true)) {
                return true;
            }
        }

        return false;
    }

    private function includeFile(string $path): void
    {
        require Path::resolve($this->app->basePath(), $path);
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<array{group: string, entrypoint: string, kind: string, middleware_group: string, order: int, loaded_by: ?string, api_prefix?: string}> */
    public function entries(): array
    {
        $layout = $this->app->make(CompiledLayout::class);
        $paths = new RoutePaths($layout);
        $groups = (new GroupFolders($this->app->basePath()))->groups($layout);
        $template = $layout->roots()['routes']->path ?? '';
        $groups = $template === '' ? [] : [...$groups, ...$this->groupsForTemplate($template)];
        $registrarTemplate = $layout->hasKind('controller') ? $paths->registrarDirectoryTemplate() : null;
        if ($registrarTemplate !== null) {
            $groups = [...$groups, ...$this->groupsForTemplate($registrarTemplate)];
        }
        $groups = array_values(array_filter($groups, static fn (string $group): bool => count(PlacementContext::fromOption($group, $layout)->toArray()) === count($layout->dimensionNames())));
        $groups = array_values(array_unique($groups));
        foreach (array_keys($this->packages) as $group) {
            if (in_array($group, $groups, true)) {
                throw new \LogicException("Package route group [{$group}] conflicts with a layout group.");
            }
        }
        $groups = [...$groups, ...array_keys($this->packages)];
        sort($groups);
        $order = (array) $this->app->make('config')->get('mod.routes.order', []);
        $this->warnings = [];
        foreach ($order as $group) {
            if (is_string($group) && ! in_array($group, $groups, true)) {
                $this->warnings[] = sprintf(RouteContent::UNKNOWN, $group);
            }
        }
        $first = array_values(array_filter($order, static fn ($group): bool => is_string($group) && in_array($group, $groups, true)));
        $groups = array_values(array_unique([...$first, ...$groups]));
        $included = array_map(Path::normalize(...), array_diff(get_included_files(), $this->baseline));
        $entries = [];
        foreach ($groups as $index => $group) {
            if (isset($this->packages[$group])) {
                $package = $this->packages[$group];
                foreach ($package['class'] === null ? ['web', 'api', 'console'] : ['web', 'api'] as $method) {
                    if ($package['class'] !== null) {
                        $entrypoint = $package['class'].'::'.$method;
                        if (! is_subclass_of($package['class'], RegistersRoutes::class)) {
                            throw new \LogicException('Package route registrar must implement RegistersRoutes.');
                        }
                    } else {
                        $absolute = Path::resolve($this->app->basePath(), Path::join((string) $package['directory'], $method.'.php'));
                        if (! is_file($absolute)) {
                            continue;
                        }
                        $entrypoint = Path::relative($this->app->basePath(), $absolute) ?? $absolute;
                    }
                    $by = $this->loaded[$entrypoint] ?? null;
                    if ($by === null && in_array(Path::normalize(realpath(Path::resolve($this->app->basePath(), $entrypoint)) ?: $entrypoint), $included, true)) {
                        $by = $this->provider($entrypoint);
                    }
                    $entries[] = ['group' => $group, 'entrypoint' => $entrypoint, 'kind' => 'package', 'middleware_group' => $method, 'order' => $this->orders[$group] ?? $index + 1, 'loaded_by' => $by];
                }

                continue;
            }
            foreach (['web', 'api', 'console'] as $method) {
                $path = Path::join($paths->directory($group), $method.'.php');
                if (! is_file($this->app->basePath($path))) {
                    continue;
                }
                $by = $this->loaded[$path] ?? null;
                if ($by === null && in_array(Path::normalize(realpath($this->app->basePath($path)) ?: $this->app->basePath($path)), $included, true)) {
                    $by = $this->provider($path);
                }
                $entries[] = ['group' => $group, 'entrypoint' => $path, 'kind' => 'file', 'middleware_group' => $method, 'order' => $this->orders[$group] ?? $index + 1, 'loaded_by' => $by];
            }
            if ($registrarTemplate === null) {
                continue;
            }
            $folder = dirname($paths->registrar($group)['path']);
            foreach (glob($this->app->basePath($folder).'/*.php') ?: [] as $file) {
                $class = (new TokenExtractor)->extract((string) file_get_contents($file))['class'];
                if (! class_exists($class)) {
                    require_once $file;
                }
                if (! is_subclass_of($class, RegistersRoutes::class)) {
                    continue;
                }
                foreach (['web', 'api'] as $method) {
                    $entrypoint = $class.'::'.$method;
                    $entries[] = ['group' => $group, 'entrypoint' => $entrypoint, 'kind' => 'registrar', 'middleware_group' => $method, 'order' => $this->orders[$group] ?? $index + 1, 'loaded_by' => $this->loaded[$entrypoint] ?? null];
                }
            }
        }

        foreach ($entries as &$entry) {
            if ($entry['middleware_group'] === 'api') {
                $entry['api_prefix'] = $this->apiPrefix($entry['group']);
            }
        }

        return $entries;
    }

    private function apiPrefix(string $group): string
    {
        if (isset($this->packages[$group]['apiPrefix'])) {
            return $this->packages[$group]['apiPrefix'];
        }
        foreach ($this->invocations as $invocation) {
            if (($invocation['only'] === null || in_array($group, $invocation['only'], true)) && ! in_array($group, $invocation['except'], true)) {
                return $invocation['apiPrefix'];
            }
        }

        return 'api';
    }

    private function provider(string $path): string
    {
        foreach (array_keys($this->app->getLoadedProviders()) as $class) {
            if (! class_exists($class)) {
                continue;
            }
            $file = (new ReflectionClass($class))->getFileName();
            if ($file !== false && (str_starts_with(Path::normalize($file), Path::normalize(Path::resolve($this->app->basePath(), dirname(dirname($path)))))
                || str_contains((string) file_get_contents($file), $path))) {
                return 'provider: '.$class;
            }
        }

        return 'provider: unknown';
    }

    /** @return list<string> Match only the declared roots; never follow directory symlinks. */
    private function groupsForTemplate(string $template): array
    {
        preg_match_all('/\{(\w+)([+?]*)\}/', $template, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $regex = '';
        $offset = 0;
        $prefix = $template;
        foreach ($tokens as $index => $token) {
            if ($index === 0) {
                $prefix = rtrim(substr($template, 0, $token[0][1]), '/');
            }
            $regex .= preg_quote(substr($template, $offset, $token[0][1] - $offset), '#');
            $regex .= str_contains($token[2][0], '+') ? '(.+?)' : '([^/]+)';
            $offset = $token[0][1] + strlen($token[0][0]);
        }
        $regex = '#^'.$regex.preg_quote(substr($template, $offset), '#').'$#';
        $absolute = $this->app->basePath($prefix);
        if (! is_dir($absolute) || is_link($absolute)) {
            return [];
        }
        $directories = [$absolute];
        if ($tokens !== []) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                if ($item instanceof \SplFileInfo && $item->isDir() && ! $item->isLink()) {
                    $directories[] = $item->getPathname();
                }
            }
        }
        $groups = [];
        foreach ($directories as $directory) {
            $relative = Path::relative($this->app->basePath(), $directory);
            if ($relative !== null && preg_match($regex, $relative, $matches)) {
                unset($matches[0]);
                $groups[] = implode('/', $matches);
            }
        }

        return $groups;
    }
}
