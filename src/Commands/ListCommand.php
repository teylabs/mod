<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\BuiltIn\GeneratorSources;
use Tey\Mod\Listing\LayoutInventory;
use Tey\Mod\Listing\RoutesSection;
use Tey\Mod\Routing\ModRoutes;
use Throwable;

/**
 * @internal The mod:list command and its JSON keys are public.
 *
 * @phpstan-import-type Report from LayoutInventory
 * @phpstan-import-type ScaffoldRow from LayoutInventory
 */
final class ListCommand extends Command
{
    protected $signature = 'mod:list {--json : Print the inventory as JSON} {--type= : Show every detail for one file type} {--vendor= : Show scaffolds from one vendor}';

    protected $description = 'Show what this layout generates, where it goes, and what is discovered';

    public function handle(LayoutInventory $inventory): int
    {
        try {
            $report = $inventory->read();
        } catch (Throwable $exception) {
            if (! $exception instanceof ModException) {
                throw $exception;
            }
            if ($this->option('json')) {
                $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        }
        $vendor = $this->option('vendor');
        if (is_string($vendor)) {
            $report['scaffolds']['items'] = array_values(array_filter($report['scaffolds']['items'], static fn (array $row): bool => $row['vendor'] === $vendor || str_starts_with($row['id'], $vendor.'/')));
            $report['scaffolds']['problems'] = array_values(array_filter($report['scaffolds']['problems'], static fn (array $row): bool => str_starts_with($row['name'], $vendor.'/')));
        }
        $filter = $this->option('type');
        if (is_string($filter)) {
            $report['types'] = array_values(array_filter($report['types'], static fn (array $type): bool => $type['id'] === $filter));
            if ($report['types'] === []) {
                $message = "mod:list --type={$filter} has no generator here. Run mod:list to choose a file type.";
                if ($this->option('json')) {
                    $this->line(json_encode(['error' => $message], JSON_THROW_ON_ERROR));
                } else {
                    $this->components->error($message);
                }

                return self::FAILURE;
            }
            $report['scaffolds']['items'] = [];
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->render($report, is_string($filter) || $this->output->isVerbose(), is_string($filter) ? $filter : null);

        return self::SUCCESS;
    }

    /** @param Report $report */
    private function render(array $report, bool $detail, ?string $filter): void
    {
        $this->components->twoColumnDetail('Layout', $report['layout'].($report['extends'] === null ? '' : ' (extends '.$report['extends'].')'));
        $this->components->twoColumnDetail('Path', $report['path'] ?? '(no groups)');
        if ($report['token'] !== null) {
            $groups = array_map(static fn (string $group): string => in_array($group, $report['disabled'], true) ? $group.' <fg=yellow>(disabled)</>' : $group, $report['groups']);
            $this->components->twoColumnDetail(Str::headline(Str::plural($report['token'])), implode(', ', $groups) ?: '(none)');
        }
        if ($report['unknown_disabled'] !== []) {
            $this->components->warn('mod.disabled names no existing group: '.implode(', ', $report['unknown_disabled']).'.');
        }
        $rows = [];
        foreach ($report['types'] as $type) {
            $rows[] = $detail ? [$type['id'], $type['command'], $type['folder'], $type['source']] : [$type['command'], $type['folder'], $type['source']];
        }
        $this->table($detail ? ['File type', 'Command', 'Folder', 'From'] : ['Generator', 'Folder', 'From'], $rows, 'compact');
        foreach ($report['assets']['owners'] as $owner) {
            $this->components->twoColumnDetail('Assets '.$owner['owner'], implode(', ', $owner['files']));
            foreach ($owner['entries'] as $entry) {
                $this->line('  '.($entry['path'] ?? $entry['declared']).($entry['exists'] ? ' (entry)' : ' (absent optional entry)'));
            }
        }
        foreach ($report['frontend_owners'] as $owner) {
            $paths = array_filter([$owner['components'], $owner['pages'], $owner['views']]);
            $this->components->twoColumnDetail('Frontend '.$owner['owner'], implode(', ', $paths).($owner['alias'] === null ? '' : ' ['.$owner['alias'].']').($owner['page_name'] === null ? '' : ' '.$owner['page_name']));
        }
        $this->renderScaffolds($report['scaffolds']['items'], $detail);
        if ($filter === null && $report['views'] !== []) {
            $this->line('  Views');
            foreach ($report['views'] as $view) {
                $this->line('  '.($view['namespace'] ?? '(app)').': '.$view['path']);
                foreach ($view['components'] as $component) {
                    $this->line('    <'.$component['tag'].' />');
                }
            }
        }
        foreach ($report['connections'] as $row) {
            $this->components->twoColumnDetail('Connection '.$row['group'], $row['connection'].($row['declared'] ? '' : ' (default)'));
        }
        RoutesSection::render($this, $report['routes'], $this->laravel->make(ModRoutes::class));
        if ($detail) {
            foreach ($report['types'] as $type) {
                $this->line('  '.$type['id'].':');
                $this->line('    Aliases: '.(implode(', ', $type['aliases']) ?: '(none)'));
                $this->line('    Stub: '.($type['stub'] ?? '(generator default)'));
                foreach ($report['templates']['items'] as $template) {
                    if ($template['type'] === $type['id'] && str_starts_with($template['source'], GeneratorSources::PREFIX)) {
                        $this->line('    '.substr($template['source'], strlen(GeneratorSources::PREFIX)).' ..... template '.$template['path'].GeneratorSources::TEMPLATE_LABEL);
                    }
                }
                $this->line('    Base: '.($type['base'] ?? '(none)'));
                $this->line('    Discovery: '.(implode(', ', $type['discovery']) ?: 'off'));
                $this->line('    Relations: '.(implode(', ', $type['relations']) ?: '(none)'));
            }
            foreach ($report['discovery']['entries'] as $entry) {
                if ($filter !== null && $entry['kind'] !== $filter) {
                    continue;
                }
                $this->line('  '.$entry['type'].': '.$entry['class'].(isset($entry['target']) ? ' -> '.$entry['target'] : '').' ['.$entry['path'].']');
            }
            foreach ($report['discovery']['rejections'] as $entry) {
                if ($filter !== null && $entry['kind'] !== $filter) {
                    continue;
                }
                $this->line('  Rejected '.$entry['path'].': '.$entry['reason'].' — '.$entry['detail']);
            }
        }
        if ($report['templates']['problems'] !== []) {
            $this->line('  Templates with problems');
            foreach ($report['templates']['problems'] as $problem) {
                $this->line('  '.$problem['path'].' ... '.$problem['reason']);
            }
        }
        if ($report['scaffolds']['problems'] !== []) {
            $this->line('  Scaffolds with problems');
            foreach ($report['scaffolds']['problems'] as $problem) {
                $this->line('  '.$problem['name'].' ... '.$problem['reason']);
            }
        }
        $notices = $report['templates']['notices'];
        if ($report['discovery']['stale_cache'] !== null) {
            $notices[] = $report['discovery']['stale_cache'];
        }
        if ($notices !== []) {
            $this->line('  Notices');
            foreach ($notices as $notice) {
                $this->line('  '.$notice);
            }
        }
        if (! $report['discovery']['enabled']) {
            $this->line('  Discovery: off');

            return;
        }
        $counts = $report['discovery']['counts'];
        $this->line(sprintf('  Discovery (%s): %d providers, %d commands, %d listeners, %d subscribers, %d factories, %d policies, %d directories, %d rejected.', $report['discovery']['source'] === 'cache' ? 'cache' : 'scanned, no cache', $counts['provider'], $counts['command'], $counts['listener'], $counts['subscriber'], $counts['factory'], $counts['policy'], $counts['directory'], $counts['rejected']));
    }

    /**
     * Kept separate so the dot-path registry can supply nested rows after L9 merges.
     *
     * @param  list<ScaffoldRow>  $scaffolds
     */
    private function renderScaffolds(array $scaffolds, bool $detail): void
    {
        if ($scaffolds === []) {
            return;
        }
        $this->line('  Scaffolds');
        foreach ($scaffolds as $scaffold) {
            foreach ($scaffold['notices'] as $notice) {
                $this->line('  ['.$notice['code'].'] '.$notice['message']);
            }
        }
        $tree = array_filter($scaffolds, static fn (array $row): bool => isset($row['children'])) !== [];
        if ($tree) {
            $this->table(['Scaffold', 'Uses', 'From'], $this->scaffoldTreeRows($scaffolds), 'compact');
        } else {
            $rows = [];
            foreach ($scaffolds as $scaffold) {
                $rows[] = [$scaffold['id'], $scaffold['command'], ($scaffold['from'] ?? $scaffold['source']).(isset($scaffold['origin']) ? ' ('.$scaffold['origin'].')' : ''), implode(', ', array_column($scaffold['members'], 'type'))];
            }
            $this->table(['Scaffold', 'Command', 'From', 'Members'], $rows, 'compact');
        }
        if ($detail) {
            foreach ($scaffolds as $scaffold) {
                foreach ($scaffold['members'] as $member) {
                    $this->line('  '.$scaffold['id'].'.'.$member['alias'].': '.$member['type'].' → '.$member['folder'].' (name: '.($member['name'] ?? '{name}').', stub: '.($member['stub'] ?? 'default').', options: '.json_encode($member['options'], JSON_THROW_ON_ERROR).')');
                }
            }
        }
    }

    /** @param list<ScaffoldRow> $scaffolds
     * @return list<array{string, string, string}>
     */
    private function scaffoldTreeRows(array $scaffolds): array
    {
        $nodes = [];
        foreach ($scaffolds as $scaffold) {
            $nodes[$scaffold['id']] = $scaffold;
        }
        $rows = [];
        $visit = function (string $name, string $prefix, string $branch) use (&$visit, &$rows, $nodes): void {
            $node = $nodes[$name] ?? null;
            if ($node === null) {
                return;
            }
            $rows[] = [$prefix.$branch.$name, $node['uses'] ?? '', ($node['from'] ?? $node['source'])];
            foreach ($node['members'] as $member) {
                if ($member['type'] === 'folder') {
                    $rows[] = [$prefix.'  '.$member['alias'], $member['folder'].'/**', 'folder'];
                }
            }
            $children = $node['children'] ?? [];
            foreach ($children as $index => $child) {
                $next = $prefix.($branch === '' ? '' : ($branch === '└ ' ? '  ' : '│ '));
                $visit($child, $next, $index === count($children) - 1 ? '└ ' : '├ ');
            }
        };
        foreach ($scaffolds as $scaffold) {
            if (! str_contains($scaffold['id'], '.')) {
                $visit($scaffold['id'], '', '');
            }
        }

        return $rows;
    }
}
