<?php

/*
 * Discovery: cold scan vs cache replay on a fixed tree.
 *
 *     php tests/Benchmark/discovery.php [runs=15]
 *
 * Builds a temporary modules-layout tree with 200 discoverable classes
 * (10 modules x 10 providers + 10 listeners, plus one event per module),
 * writes the discovery cache once, then times Discovery::inventory() in a
 * fresh PHP process per run: cold (no cache file: walk, reverse-map,
 * autoload, reflect) and replay (read the cache file). Bootstrap is not
 * timed. Prints the median, min and max in milliseconds. Numbers only;
 * nothing here is a claim beyond this tree on this machine.
 */

use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Layout\CompiledLayout;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const MODULES = 10;
const PER_MODULE = 10;

/**
 * @return array<string, mixed>
 */
function benchmarkPreset(): array
{
    /** @var array<string, mixed> $definition */
    $definition = require dirname(__DIR__).'/Fixtures/Layouts/modules.php';
    $definition['kinds']['listener'] = ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:listener', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Listeners']];

    return $definition;
}

function benchmarkDiscovery(string $root, string $cache): Discovery
{
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\') && is_file($file = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php')) {
            require $file;
        }
    });

    return new Discovery(CompiledLayout::fromArray(benchmarkPreset()), new DiscoveryOptions(cachePath: $cache), $root);
}

// Child process: time one inventory() call and print milliseconds.
if (($argv[1] ?? null) === '--child') {
    [, , $root, $cache] = $argv;
    $discovery = benchmarkDiscovery($root, $cache);

    $start = hrtime(true);
    $inventory = $discovery->inventory();
    $elapsed = (hrtime(true) - $start) / 1e6;

    fwrite(STDOUT, json_encode(['ms' => $elapsed, 'source' => $discovery->source(), 'entries' => count($inventory->entries)], JSON_THROW_ON_ERROR));

    exit(0);
}

$runs = max(1, (int) ($argv[1] ?? 15));
$root = sys_get_temp_dir().'/tey-mod-bench-'.bin2hex(random_bytes(6));
mkdir($root, 0700);

/**
 * @param  list<float>  $values
 * @return array{median: float, min: float, max: float}
 */
function summary(array $values): array
{
    sort($values);
    $count = count($values);
    $median = $count % 2 === 1 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;

    return ['median' => round($median, 2), 'min' => round($values[0], 2), 'max' => round($values[$count - 1], 2)];
}

try {
    for ($m = 1; $m <= MODULES; $m++) {
        $module = "Module{$m}";
        $ns = "App\\Modules\\{$module}";
        $write = static function (string $relative, string $code) use ($root): void {
            @mkdir(dirname($root.'/'.$relative), 0700, true);
            file_put_contents($root.'/'.$relative, "<?php\n\n{$code}\n");
        };

        $write("app/Modules/{$module}/Events/Happened.php", "namespace {$ns}\\Events;\n\nclass Happened {}");

        for ($i = 1; $i <= PER_MODULE; $i++) {
            $write("app/Modules/{$module}/Providers/P{$i}ServiceProvider.php", "namespace {$ns}\\Providers;\n\nclass P{$i}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}");
            $write("app/Modules/{$module}/Listeners/L{$i}.php", "namespace {$ns}\\Listeners;\n\nclass L{$i}\n{\n    public function handle(\\{$ns}\\Events\\Happened \$event): void {}\n}");
        }
    }

    $cache = $root.'/bootstrap/cache/mod-discovery.php';
    $written = benchmarkDiscovery($root, $cache)->writeCache();

    if (count($written->entries) !== MODULES * PER_MODULE * 2) {
        throw new RuntimeException('Unexpected inventory size: '.count($written->entries));
    }

    $results = ['cold' => [], 'replay' => []];
    $php = escapeshellarg(PHP_BINARY);
    $self = escapeshellarg(__FILE__);

    for ($run = 0; $run < $runs; $run++) {
        foreach (['cold' => $root.'/no-cache.php', 'replay' => $cache] as $mode => $path) {
            $output = shell_exec("{$php} {$self} --child ".escapeshellarg($root).' '.escapeshellarg($path));
            /** @var array{ms: float, source: string, entries: int} $result */
            $result = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);

            if ($result['source'] !== ($mode === 'cold' ? 'scan' : 'cache') || $result['entries'] !== MODULES * PER_MODULE * 2) {
                throw new RuntimeException("Run {$run} {$mode}: unexpected result ".json_encode($result));
            }

            $results[$mode][] = $result['ms'];
        }
    }

    echo json_encode([
        'php' => PHP_VERSION,
        'classes' => MODULES * PER_MODULE * 2,
        'files' => MODULES * PER_MODULE * 2 + MODULES,
        'runs' => $runs,
        'cold_ms' => summary($results['cold']),
        'replay_ms' => summary($results['replay']),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    // Only ever remove the tree this script created.
    if (str_contains($root, '/tey-mod-bench-') && is_dir($root) && ! is_link($root)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($root);
    }
}
