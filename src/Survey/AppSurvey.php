<?php

namespace Tey\Mod\Survey;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\PlainFile\Identity;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Support\Path;
use Tey\Mod\Support\Stack;

/**
 * @internal Structural evidence, never an execution of scanned PHP or discovery callbacks.
 *
 * @phpstan-import-type Edge from PhpStructure
 *
 * @phpstan-type Group array{folder: string, namespace: ?string, classes: list<string>, files: list<string>}
 */
final readonly class AppSurvey
{
    public function __construct(private Application $app) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $scope = new Scope($this->app->basePath());
        $files = $scope->files();
        $layout = $this->layout($scope);
        $mapper = new ReverseMapper($layout);
        $parser = new PhpStructure;
        $types = [];
        foreach ($layout->fileTypes() as $id => $type) {
            $types[$id] = ['id' => $id, 'groups' => []];
        }
        $unplaced = [];
        $unresolved = [];
        $unparsed = [];
        $classes = [];
        $nodes = [];
        $edges = [];
        $artifacts = [];
        $patterns = [];
        foreach ($files as $path) {
            $structure = str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')
                ? $parser->read($scope->read($path), $path) : ['classes' => [], 'edges' => [], 'parsed' => true];
            if (! $structure['parsed']) {
                $unparsed[] = $path;
            }
            $nodes['file:'.$path] = $path;
            foreach ($structure['classes'] as $class) {
                $classes[strtolower($class)] = $class;
                $nodes[$class] = $path;
            }
            array_push($edges, ...$structure['edges']);
            $match = $mapper->fromPath($path);
            $artifact = $match->artifact;
            if ($artifact === null) {
                if ($match->candidates === [] && $match->outcome->value === 'not-owned') {
                    $unplaced[] = $path;
                } else {
                    $unresolved[] = ['path' => $path, 'reason' => $match->outcome->value];
                }

                continue;
            }
            $artifacts[$path] = $artifact;
            $id = $artifact->fileType->id;
            $namespaces = [];
            foreach ($structure['classes'] as $class) {
                $namespace = str_contains($class, '\\') ? substr($class, 0, (int) strrpos($class, '\\')) : '';
                $namespaces[$namespace][] = $class;
            }
            if ($namespaces === []) {
                $namespaces[''] = [];
            }
            foreach ($namespaces as $namespace => $members) {
                $key = dirname($path).'|'.$namespace;
                $types[$id]['groups'][$key] ??= ['folder' => dirname($path), 'namespace' => $members === [] ? null : $namespace, 'classes' => [], 'files' => []];
                array_push($types[$id]['groups'][$key]['classes'], ...$members);
                $types[$id]['groups'][$key]['files'][] = $path;
            }
            $form = Identity::forms($artifact, $layout);
            if ($id === 'page' && isset($form['name'])) {
                $nodes['page:'.$form['name']] = $path;
            }
            $pattern = str_replace($artifact->name, '{name}', basename($path));
            $patterns[$id][$pattern][] = $path;
        }
        $edges = [...$edges, ...$this->routes(), ...$this->policies($layout, $artifacts)];
        $vendor = [];
        $references = [];
        foreach ($edges as $edge) {
            $target = $classes[strtolower($edge['to'])] ?? $edge['to'];
            $edge['to'] = $target;
            if (! isset($nodes[$target]) && ! str_starts_with($target, 'page:')) {
                $path = $scope->vendorPath($target);
                if ($path === null) {
                    continue;
                }
                $vendor[$target] ??= ['class' => $target, 'path' => $path, 'used_by' => []];
                $vendor[$target]['used_by'][] = $edge['from'];
            }
            if (isset($nodes[$edge['from']]) || str_starts_with($edge['from'], 'route:')) {
                $references[json_encode($edge, JSON_THROW_ON_ERROR)] = $edge;
            }
        }
        foreach ($types as &$type) {
            $type['groups'] = array_values($type['groups']);
        }
        unset($type);
        $naming = [];
        foreach ($patterns as $id => $items) {
            foreach ($items as $pattern => $paths) {
                if (count($paths) > 1) {
                    $naming[] = ['type' => $id, 'pattern' => $pattern, 'files' => $paths];
                }
            }
        }
        $name = $this->app->make('config')->get('mod.layout');

        return $this->sorted([
            'layout' => is_string($name) && $name !== '' ? $name : 'laravel',
            'git' => (new GitIdentity($scope))->read($files),
            'types' => array_values($types), 'unplaced' => ['count' => count($unplaced), 'paths' => $unplaced],
            'unresolved' => $unresolved, 'unparsed' => $unparsed, 'skipped' => $scope->skipped,
            'classes' => array_map(static fn (string $class): array => ['class' => $class, 'path' => $nodes[$class]], array_values($classes)),
            'references' => array_values($references), 'vendor' => array_values($vendor),
            ...(new FileSets)->read($nodes, array_values($references), $artifacts), 'naming_patterns' => $naming,
        ]);
    }

    private function layout(Scope $scope): CompiledLayout
    {
        // Do not cold-load TemplateCatalog or Discovery: they may open configured external roots
        // or execute app PHP. An already compiled inventory layout includes those registrations.
        if ($this->app->resolved(CompiledLayout::class)) {
            return $this->app->make(CompiledLayout::class);
        }
        $config = $this->app->make('config');
        $raw = $config->get('mod.preset');
        $name = $config->get('mod.layout');
        /** @var array<string, mixed>|null $raw */
        $layout = is_array($raw) ? CompiledLayout::fromArray($raw)
            : $this->app->make(LayoutRegistry::class)->compile(is_string($name) && $name !== '' ? $name : 'laravel');
        // Stack only reads package.json and probes the conventional frontend directories.
        foreach (['package.json', 'tsconfig.json', 'resources/js'] as $path) {
            if ($scope->excluded((string) Path::relative($scope->root, $scope->resolve($path)))) {
                throw new ModException('Survey refuses links into protected files.');
            }
        }

        return $layout->withStack(new Stack($scope->root));
    }

    /** @return list<Edge> */
    private function routes(): array
    {
        $edges = [];
        foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
            $action = $route->getAction('controller');
            if (! is_string($action)) {
                continue;
            }
            $methods = $route->methods();
            sort($methods, SORT_STRING);
            $edges[] = ['from' => 'route:'.implode('|', $methods).' '.$route->uri(), 'kind' => 'route', 'to' => explode('@', $action, 2)[0]];
        }

        return $edges;
    }

    /** @param array<string, ResolvedArtifact> $artifacts
     * @return list<Edge>
     */
    private function policies(CompiledLayout $layout, array $artifacts): array
    {
        $pairs = $this->app->make(Gate::class)->policies();
        $resolver = new RelationResolver($layout, new PlacementResolver($layout));
        // Reuse discovery's definitions and relation resolver, but check existence in our parsed
        // class table rather than calling class_exists or invoking policy-guessing callbacks.
        foreach ((new DiscoveryOptions)->definitionsFor($layout) as $definition) {
            if ($definition->type !== DiscoveryType::Policy) {
                continue;
            }
            foreach ($artifacts as $artifact) {
                if ($artifact->fileType->id !== $definition->kindId || $artifact->fqcn() === null || isset($pairs[$artifact->fqcn()])) {
                    continue;
                }
                foreach ($layout->relationsFrom($definition->kindId) as $relation) {
                    if ($relation->toFileType === DiscoveryType::Policy->value && $relation->mode !== RelationMode::None
                        && $layout->rule($relation->toFileType) instanceof TemplateRule) {
                        $target = $resolver->resolve($artifact, $relation->id)->target?->fqcn();
                        if ($target !== null) {
                            $pairs[$artifact->fqcn()] = $target;
                        }
                    }
                }
            }
        }
        $edges = [];
        foreach ($pairs as $model => $policy) {
            $edges[] = ['from' => $model, 'kind' => 'policy', 'to' => $policy];
            $edges[] = ['from' => $policy, 'kind' => 'model', 'to' => $model];
        }

        return $edges;
    }

    /** @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function sorted(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->sorted($value);
            }
        }
        unset($value);
        if (array_is_list($data)) {
            $data = array_values(array_unique($data, SORT_REGULAR));
            usort($data, static fn (mixed $a, mixed $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        } else {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
