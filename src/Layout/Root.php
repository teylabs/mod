<?php

namespace Tey\Mod\Layout;

use Closure;
use Tey\Mod\Generation\Stub;

/**
 * A root's file types, declared inside `->mounts(..., fn (Root $root) => $root->generates(...))`.
 *
 * File types declared here live in this root unless their `in:` names another.
 * Returns to the layout chain when the closure ends.
 *
 * @api
 */
final readonly class Root
{
    /**
     *  created by Layout::mounts()
     *
     * @internal
     */
    public function __construct(
        public string $name,
        private Layout $layout,
    ) {}

    /** Declare paths relative to this mount; page and view names are explicit.
     * @param  list<string>|null  $pageEntries
     *
     * @api
     */
    public function frontend(?string $pages = null, ?string $components = null, ?string $css = null, ?string $views = null, ?string $pageName = null, ?string $viewNamespace = null, ?string $alias = null, ?array $pageEntries = null): self
    {
        $this->layout->frontendAtRoot($this->name, $pages, $components, $css, $views, $pageName, $viewNamespace, $alias, $pageEntries);

        return $this;
    }

    /**
     * Declare static globs and executable entries relative to this mount.
     *
     * @param  list<string>  $files
     * @param  list<string>  $entries
     *
     * @api
     */
    public function assets(array $files = [], array $entries = []): self
    {
        $this->layout->assetsAtRoot($this->name, $files, $entries);

        return $this;
    }

    /**
     * @param  string|null  $discover  'folder' or 'anywhere'
     * @param  list<string>|null  $discoverExcept
     * @param  (Closure(FileType): mixed)|null  $using
     * @param  list<string>|null  $aliases
     *
     * @api
     */
    public function generates(
        string $id,
        ?string $in = null,
        ?string $suffix = null,
        ?string $fixed = null,
        ?bool $timestamped = null,
        string|false|null $command = null,
        ?int $priority = null,
        ?bool $nested = null,
        ?string $discover = null,
        ?array $discoverExcept = null,
        ?Closure $using = null,
        ?string $ungrouped = null,
        ?array $aliases = null,
        ?Stub $stub = null,
        ?string $label = null,
    ): self {
        $this->layout->generates($id, $in, $suffix, $fixed, $timestamped, $command, $priority, $nested, $discover, $discoverExcept, function (FileType $kind) use ($using): void {
            $kind->withinRoot($this->name);

            if ($using !== null) {
                $using($kind);
            }
        }, $ungrouped, $aliases, $stub, $label);

        return $this;
    }
}
