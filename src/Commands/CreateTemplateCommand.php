<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Prompts\SearchPrompt;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Tey\Mod\Layout\BuiltIn\TemplateAnchors;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Support\Path;
use Tey\Mod\Templates\ClassLookup;
use Tey\Mod\Templates\InvalidTemplate;
use Tey\Mod\Templates\TemplateCatalog;
use Tey\Mod\Templates\TemplateDestination;
use Tey\Mod\Templates\TemplateWriter;
use Tey\Mod\Templates\TokenExtractor;
use Tey\Mod\Templates\TypeStubs;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

/** Create a generator template from a language type, an existing stub or a source file. */
class CreateTemplateCommand extends Command
{
    protected $signature = 'mod:template {type? : Starting type, or the path when given alone} {path? : Template name or path}
        {--from= : Existing class or PHP file; omit its value to search}
        {--into= : Destination template path for --from}
        {--force : Overwrite an existing template}';

    protected $description = 'Create a generator template for the active layout';

    private const RESERVED = ['autoload', 'bases', 'cache', 'clear', 'list', 'template'];

    public function handle(CompiledLayout $layout, LayoutRegistry $registry, TypeStubs $stubs, TemplateCatalog $catalog): int
    {
        try {
            $name = config('mod.layout', 'laravel');
            if (! is_string($name)) {
                throw new RuntimeException('Set mod.layout to the name of a layout before running mod:template.');
            }
            $destination = new TemplateDestination($this->laravel->basePath(), $registry->layout($name), $layout);
            // hasParameterOption distinguishes a bare --from from no --from at all.
            if ($this->input->hasParameterOption('--from')) {
                return $this->extract($layout, $destination, $name);
            }
            if ($this->option('into') !== null) {
                throw new RuntimeException('--into answers --from. Run mod:template --from=<class> --into=<path>.');
            }
            $type = $this->argument('type');
            $type = $type === null ? null : $this->stringValue($type);
            $path = $this->argument('path');
            $path = $path === null ? null : $this->stringValue($path);
            if ($path === null && $type !== null) {
                $path = $type;
                $type = 'class';
            }
            if ($type === null) {
                if (! $this->interactive()) {
                    throw new RuntimeException('mod:template needs a name or path. Run mod:template <name>, or mod:template <type> <path>.');
                }
                $prompt = new SearchPrompt('Which type should the template start from?', fn (string $value): array => array_values(array_filter($stubs->types(), static fn (string $t): bool => str_contains($t, strtolower($value)))));
                $prompt->highlighted = 0;
                $type = $this->stringValue($prompt->prompt());
                $path = $this->stringValue(text('What should the template be called, or where should it live?', default: $destination->bare('tool'), required: true));
            }
            if ($this->looksLikePath($type)) {
                $fix = "mod:template {$path} {$type}";
                $this->correction("The type comes first. Run {$fix}?", "The type comes first: {$fix}.");
                [$type, $path] = [$path, $type];
            }
            if ($type === 'markdown' || ($layout->hasKind($type) && ! $layout->kind($type)->isClass())) {
                throw new RuntimeException("Plain-file templates aren't supported yet; mod:template makes class templates.");
            }
            if (! in_array($type, $stubs->types(), true)) {
                $near = $this->nearest($type, $stubs->types());
                $message = "There is no type [{$type}].".($near === null ? '' : " Did you mean [{$near}]?")."\nTypes: class, interface, trait, enum, or a file type of the {$name} layout (php artisan mod:list).";
                if (! $this->interactive()) {
                    throw new RuntimeException($message);
                }
                $type = $this->stringValue(suggest("There is no type [{$type}]. Which type?", $stubs->types(), default: $near ?? 'class', required: true));
                if (! in_array($type, $stubs->types(), true)) {
                    throw new RuntimeException($message);
                }
            }
            $originalBare = ! $this->looksLikePath($path);
            $folderName = $path;
            $path = $originalBare ? $destination->bare($path) : $path;
            $path = $this->correctPath($path, $destination, $layout, $name);
            $parsed = $destination->parse($path.'.stub');
            if (in_array($parsed->id, self::RESERVED, true)) {
                $id = $parsed->id;
                if (! $this->interactive()) {
                    throw new RuntimeException("mod:{$id} is one of mod's own commands. Choose another name, such as mod:template lister.");
                }
                $replacement = $this->stringValue(text("mod:{$id} is one of mod's own commands. What should the template be called?", required: true));
                $path = Path::join(dirname($path), $replacement);
                $parsed = $destination->parse($path.'.stub');
                if (in_array($parsed->id, self::RESERVED, true)) {
                    throw new RuntimeException('Choose a name outside mod\'s own commands. Run mod:template <name>.');
                }
            }
            if ($layout->hasKind($parsed->id) && ! isset($layout->templates()[$parsed->id])) {
                $id = $parsed->id;
                $message = "mod:{$id} already exists: it is the layout's {$id} file type.\nTo customize what mod:{$id} starts as, publish its stub: stubs/mod.{$id}.stub.\nTo start a new template from the {$id} stub, name it: mod:template {$id} <name>.";
                $this->correction("mod:{$id} already exists. Customize its stub instead?", $message);
                $published = $this->laravel->basePath('stubs/mod.'.$id.'.stub');
                $this->overwrite('stubs/mod.'.$id.'.stub', $published);
                $contents = $stubs->read($id)['contents'];
                if (! is_dir(dirname($published))) {
                    mkdir(dirname($published), 0755, true);
                }
                if (file_put_contents($published, $contents) === false) {
                    throw new RuntimeException("Cannot publish [stubs/mod.{$id}.stub]. Check its permissions.");
                }
                $this->components->info("Published stub [stubs/mod.{$id}.stub]. mod:{$id} now starts from it.");

                return self::SUCCESS;
            }
            $source = $stubs->read($type, $parsed);
            if ($source['needs'] !== null) {
                $needs = $source['needs'];
                $placeholder = $source['placeholder'];
                $commandPath = $originalBare ? $folderName : $path;
                $this->correction("The {$type} stub needs {$needs}, which a template can't supply. Start from a class instead?", "The {$type} stub needs {$needs} ({{ {$placeholder} }}), which a template can't supply.\nStart from a class instead (mod:template class {$commandPath}), or use ->generates() with mod's {$type} command.");
                $source = $stubs->read('class');
            }

            return $this->create($destination, $path, $source['contents'], $source['label'], $catalog);
        } catch (RuntimeException $exception) {
            foreach (explode("\n", $exception->getMessage()) as $line) {
                $this->components->error($line);
            }

            return self::FAILURE;
        }
    }

    private function stringValue(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('mod:template needs text for its type, path, and source values.');
        }

        return $value;
    }

    private function interactive(): bool
    {
        return $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)));
    }

    private function correction(string $question, string $error): void
    {
        if (! $this->interactive()) {
            throw new RuntimeException($error);
        }
        if (! confirm($question, default: true)) {
            throw new RuntimeException('Cancelled; nothing was written.');
        }
    }

    private function looksLikePath(string $value): bool
    {
        return str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, '@') || str_contains($value, '[') || str_ends_with($value, '.stub');
    }

    /** @param list<string> $values */
    private function nearest(string $value, array $values): ?string
    {
        usort($values, static fn (string $a, string $b): int => [levenshtein(strtolower($value), strtolower($a)), abs(strlen($value) - strlen($a)), $a] <=> [levenshtein(strtolower($value), strtolower($b)), abs(strlen($value) - strlen($b)), $b]);

        return isset($values[0]) && levenshtein(strtolower($value), strtolower($values[0])) <= 2 ? $values[0] : null;
    }

    private function correctPath(string $path, TemplateDestination $destination, CompiledLayout $layout, string $name): string
    {
        $path = Path::normalize($path);
        $path = str_ends_with($path, '.stub') ? substr($path, 0, -5) : $path;
        if (str_starts_with($path, 'app/')) {
            $fix = substr($path, 4);
            $this->correction("Template paths are relative to app/. Use {$fix}?", "Template paths are relative to app/, like ->generates(in:). Drop app/: mod:template {$fix}.");
            $path = $fix;
        }
        preg_match_all('#(?:^|/)@([^/]+)#', $path, $anchors);
        foreach ($anchors[1] as $anchor) {
            if (! in_array($anchor, [...TemplateAnchors::NAMES, ...$layout->dimensionNames()], true)) {
                $choices = [...$layout->dimensionNames(), 'group'];
                $near = $this->nearest($anchor, $choices);
                $message = "There is no anchor [@{$anchor}].".($near === null ? '' : " Did you mean @{$near}?")." The {$name} layout's anchors: @".implode(', @', $choices).'.';
                if ($near === null) {
                    throw new RuntimeException($message);
                }
                $this->correction("There is no anchor [@{$anchor}]. Did you mean @{$near}?", $message);
                $path = str_replace('@'.$anchor, '@'.$near, $path);
            }
        }
        try {
            $parsed = $destination->parse($path.'.stub');
        } catch (InvalidTemplate $error) {
            if (str_contains($error->getMessage(), 'does not keep') && preg_match('#^([^@]+)/(@[^/]+)/(.*)$#', $path, $match) === 1) {
                $prefix = rtrim($match[1], '/');
                $fix = $match[2].'/'.$prefix.'/'.$match[3];
                $destination->parse($fix.'.stub');
                $token = $layout->dimensionNames()[0] ?? 'group';
                $groupFolder = '';
                foreach ($layout->rules() as $rule) {
                    if ($rule instanceof TemplateRule) {
                        $literals = [];
                        foreach ($rule->segments() as $segment) {
                            if ($segment->dimension === $token) {
                                $groupFolder = implode('/', $literals);
                                break 2;
                            }
                            if ($segment->literal !== null) {
                                $literals[] = $segment->literal;
                            }
                        }
                    }
                }
                $message = "The {$name} layout keeps ".Str::plural($token)." in {$groupFolder}/, not {$prefix}/.";
                $this->correction("{$message} Use {$fix}?", "{$message} Did you mean {$fix}?");
                $path = $fix;
                $parsed = $destination->parse($path.'.stub');
            } else {
                throw $error;
            }
        }

        return $destination->canonical($path.'.stub', $parsed) === $path.'.stub' ? $path : substr($destination->canonical($path.'.stub', $parsed), 0, -5);
    }

    private function overwrite(string $display, string $absolute): void
    {
        if (! file_exists($absolute) || $this->option('force')) {
            return;
        }
        if (! $this->interactive()) {
            throw new RuntimeException("Template [{$display}] already exists. Use --force to overwrite it.");
        }
        if (! confirm("Template [{$display}] already exists. Overwrite it?", default: false)) {
            throw new RuntimeException('Cancelled; nothing was written.');
        }
    }

    /** @param array{replaced: string, left: list<string>}|null $extraction */
    private function create(TemplateDestination $destination, string $path, string $contents, string $starts, TemplateCatalog $catalog, ?array $extraction = null): int
    {
        $parsed = $destination->parse($path.'.stub');
        $commands = ['mod:'.$parsed->id, 'mod:'.str_replace('-', '', $parsed->id)];
        foreach ($this->laravel->make(CompiledLayout::class)->kinds() as $kind) {
            if ($kind->id === $parsed->id) {
                continue;
            }
            foreach ($kind->command === null ? [] : [$kind->command, ...$kind->aliases] as $command) {
                if (in_array(strtolower($command), $commands, true)) {
                    throw new RuntimeException("{$command} already exists as the {$kind->id} file type. Choose another template name.");
                }
            }
        }
        foreach (self::RESERVED as $reserved) {
            if (in_array('mod:'.$reserved, $commands, true)) {
                throw new RuntimeException("mod:{$reserved} is one of mod's own commands. Choose another template name.");
            }
        }
        $display = 'stubs/mod/'.$path.'.stub';
        $file = $this->laravel->basePath($display);
        // A differently cased filename must not create a second canonical command.
        foreach ($catalog->templates() as $id => $template) {
            if ($id === $parsed->id && ! Path::same($template['file'], $file) && ($template['source'] === 'app' || str_starts_with($template['source'], 'app (overrides '))) {
                throw new RuntimeException("mod:{$id} already comes from [{$template['path']}]. Use that template path with --force, or choose another name.");
            }
        }
        $this->overwrite($display, $file);
        (new TemplateWriter($this->laravel->basePath()))->write($path.'.stub', $contents, file_exists($file));
        if ($parsed->notice !== null) {
            $this->components->info($parsed->notice);
        }
        $this->components->info("Template [{$display}] created.");
        $this->detail('Starts as', $starts);
        if ($extraction !== null) {
            $this->detail('Replaced', $extraction['replaced']);
            foreach ($extraction['left'] as $left) {
                $this->detail('Left as it is', $left);
            }
        }
        $this->detail('Command', 'mod:'.$parsed->id);
        if ($parsed->slots !== []) {
            $this->detail('Options', implode(', ', array_map(static fn (string $slot): string => '--'.$slot, $parsed->slots)));
        }
        $preview = $destination->preview($parsed);
        $this->detail('Writes', $preview['writes']);
        $this->detail('Try', $preview['try']);
        $this->newLine();
        if ($extraction !== null) {
            $this->line('  Edit the template to generalize the rest.');
        }

        return self::SUCCESS;
    }

    private function detail(string $label, string $value): void
    {
        $width = (new Terminal)->getWidth();
        $dots = max(1, min(150, $width - 4) - 2 - strlen($label) - strlen($value));
        $this->output->writeln('  '.$label.' '.str_repeat('.', $dots).' '.$value.'  ', OutputInterface::OUTPUT_RAW);
    }

    private function extract(CompiledLayout $layout, TemplateDestination $destination, string $layoutName): int
    {
        $lookup = new ClassLookup($this->laravel->basePath(), $layout);
        $from = $this->option('from');
        $from = $from === null ? '' : $this->stringValue($from);
        if ($from === '') {
            if (! $this->interactive()) {
                throw new RuntimeException('--from needs a class name or a file path, such as --from=DocumentWasUploaded.');
            }
            $from = $this->stringValue(search('Which class should the template start from?', static function (string $value) use ($lookup): array {
                return array_values(array_map(static fn (array $r): string => $r['class'], array_filter($lookup->all(), static fn (array $r): bool => str_contains(strtolower($r['class']), strtolower($value)))));
            }));
        }
        $matches = $lookup->matches($from);
        if ($matches === []) {
            foreach ($lookup->all() as $candidate) {
                if (str_replace('\\', '', $candidate['class']) === $from) {
                    $class = $candidate['class'];
                    $slash = str_replace('\\', '/', $class);
                    $this->correction("Your shell may have removed the backslashes. Use {$class}?", "There is no class or file [{$from}].\nYour shell may have removed the backslashes. Did you mean {$class}?\nQuote it, or use slashes: --from={$slash}");
                    $matches = [$candidate];
                    break;
                }
            }
        }
        if ($matches === []) {
            $near = $lookup->near($from)[0] ?? null;
            $message = "There is no class [{$from}] in the app.";
            if ($near === null) {
                if (! str_contains($from, '/') && ! str_contains($from, '\\')) {
                    foreach ($layout->roots() as $root) {
                        $prefix = str_replace('\\', '', $root->namespace ?? '');
                        if ($prefix !== '' && str_starts_with($from, $prefix)) {
                            $slash = str_replace('\\', '/', rtrim($root->namespace ?? '', '\\'));
                            throw new RuntimeException("There is no class or file [{$from}].\nYour shell may have removed the backslashes. Quote the class name, or use slashes: --from={$slash}/Path/To/Class.");
                        }
                    }
                }
                throw new RuntimeException($message.' Use --from=<class> or --from=<file.php>; quote backslashes or use slashes.');
            }
            $this->correction("There is no class {$from}. Did you mean {$near['name']}?", $message."\nDid you mean {$near['name']} ({$near['class']})?");
            $matches = [$near];
        }
        if (count($matches) > 1) {
            $classes = array_column($matches, 'class');
            $message = "Several classes are named [{$from}]:\n".implode("\n", $classes)."\nAdd part of the namespace to choose one, such as --from=".$this->disambiguation($classes).'.';
            if (! $this->interactive()) {
                throw new RuntimeException($message);
            }
            $class = $this->stringValue(select("Several classes are named {$from}. Which one?", $classes));
            $matches = array_values(array_filter($matches, static fn (array $r): bool => $r['class'] === $class));
        }
        $record = $matches[0];
        $source = file_get_contents($record['file']);
        if ($source === false) {
            throw new RuntimeException("Cannot read [{$record['file']}]. Check its permissions.");
        }
        $extracted = (new TokenExtractor)->extract($source);
        $suggestion = $destination->suggest($record['file'], $record['name']);
        $into = $this->option('into');
        $into = $into === null ? '' : $this->stringValue($into);
        if ($into === '') {
            if ($this->interactive()) {
                $where = $this->stringValue(text('Where should the template live?', default: dirname($suggestion), required: true));
                $called = $this->stringValue(text('What should the template be called?', default: basename($suggestion), required: true));
                $into = Path::join($where, $called);
            } else {
                $into = $suggestion;
            }
        }
        $into = $this->correctPath($into, $destination, $layout, $layoutName);
        $parsed = $destination->parse($into.'.stub');
        if (in_array($parsed->id, self::RESERVED, true) || ($layout->hasKind($parsed->id) && ! isset($layout->templates()[$parsed->id]))) {
            throw new RuntimeException("mod:{$parsed->id} already exists. Choose another --into template name.");
        }
        $this->components->info('Using '.$record['class'].'.');

        return $this->create($destination, $into, $extracted['contents'], $record['class'], $this->laravel->make(TemplateCatalog::class), $extracted);
    }

    /** @param list<string> $classes */
    private function disambiguation(array $classes): string
    {
        $parts = explode('\\', $classes[count($classes) - 1]);
        $name = (string) array_pop($parts);
        $others = $classes;
        array_pop($others);
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            foreach ($others as $other) {
                if (! str_contains($other, '\\'.$parts[$i].'\\')) {
                    return $parts[$i].'/'.$name;
                }
            }
        }

        return str_replace('\\', '/', $classes[0]);
    }
}
