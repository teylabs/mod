<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Listing\LayoutInventory;

/**
 * @internal The mod:list command and its JSON keys are public.
 *
 * @phpstan-import-type Report from LayoutInventory
 * @phpstan-import-type ScaffoldRow from LayoutInventory
 */
final class ListCommand extends Command
{
    protected $signature = 'mod:list {--json : Print the inventory as JSON} {--type= : Show every detail for one file type}';

    protected $description = 'Show what this layout generates, where it goes, and what is discovered';

    public function handle(LayoutInventory $inventory): int
    {
        try {
            $report = $inventory->read();
        } catch (ModException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
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
            $this->components->twoColumnDetail(Str::headline(Str::plural($report['token'])), implode(', ', $report['groups']) ?: '(none)');
        }
        $rows = [];
        foreach ($report['types'] as $type) {
            $rows[] = $detail ? [$type['id'], $type['command'], $type['folder'], $type['source']] : [$type['command'], $type['folder'], $type['source']];
        }
        $this->table($detail ? ['File type', 'Command', 'Folder', 'From'] : ['Generator', 'Folder', 'From'], $rows, 'compact');
        $this->renderScaffolds($report['scaffolds']['items'], $detail);
        if ($detail) {
            foreach ($report['types'] as $type) {
                $this->line('  '.$type['id'].':');
                $this->line('    Aliases: '.(implode(', ', $type['aliases']) ?: '(none)'));
                $this->line('    Stub: '.($type['stub'] ?? '(generator default)'));
                $this->line('    Base: '.($type['base'] ?? '(none)'));
                $this->line('    Discovery: '.(implode(', ', $type['discovery']) ?: 'off'));
                $this->line('    Relations: '.(implode(', ', $type['relations']) ?: '(none)'));
            }
            foreach ($report['discovery']['entries'] as $entry) {
                if ($filter !== null && $entry['kind'] !== $filter) {
                    continue;
                }
                $this->line('  '.$entry['type'].': '.$entry['class'].' ['.$entry['path'].']');
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
        $this->line(sprintf('  Discovery (%s): %d providers, %d commands, %d listeners, %d subscribers, %d directories, %d rejected.', $report['discovery']['source'] === 'cache' ? 'cache' : 'scanned, no cache', $counts['provider'], $counts['command'], $counts['listener'], $counts['subscriber'], $counts['directory'], $counts['rejected']));
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
        $rows = [];
        foreach ($scaffolds as $scaffold) {
            $rows[] = [$scaffold['name'], $scaffold['command'], $scaffold['source'], implode(', ', array_column($scaffold['members'], 'type'))];
        }
        $this->table(['Scaffold', 'Command', 'From', 'Members'], $rows, 'compact');
        if ($detail) {
            foreach ($scaffolds as $scaffold) {
                foreach ($scaffold['members'] as $member) {
                    $this->line('  '.$scaffold['name'].'.'.$member['alias'].': '.$member['type'].' → '.$member['folder'].' (name: '.($member['name'] ?? '{name}').', stub: '.($member['stub'] ?? 'default').', options: '.json_encode($member['options'], JSON_THROW_ON_ERROR).')');
                }
            }
        }
    }
}
