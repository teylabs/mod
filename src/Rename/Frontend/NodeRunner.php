<?php

namespace Tey\Mod\Rename\Frontend;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Tey\Mod\Rename\Process;

/** @internal Arguments and stdin are passed without a shell. */
final class NodeRunner implements Runner
{
    public function run(string $basePath, string $input): RunResult
    {
        $node = (new ExecutableFinder)->find('node');
        $helper = dirname(__DIR__, 3).'/resources/js/rename/helper.cjs';
        $dependencies = [];
        foreach ([$helper, ...($node === null ? [] : [$node]), ...array_map(static fn (string $name): string => $basePath.'/'.$name, ['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'])] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                return new RunResult(null, 'Node helper dependency cannot be read.', $dependencies);
            }
            $dependencies[$path] = $hash;
        }
        if ($node === null || ! isset($dependencies[$helper])) {
            return new RunResult(null, 'Node parsing is unavailable.', $dependencies);
        }
        $process = new Process([$node, $helper], $basePath, ['NODE_PATH' => false, 'NODE_OPTIONS' => false, 'FORCE_COLOR' => false]);
        $process->setInput($input);
        $process->setTimeout(20);
        $excessive = false;
        $size = 0;
        try {
            $process->run(function (string $type, string $bytes) use (&$size, &$excessive, $process): void {
                $size += strlen($bytes);
                if ($size > 8 * 1024 * 1024) {
                    $excessive = true;
                    $process->stop(0);
                }
            });
        } catch (RuntimeException $exception) {
            return new RunResult(null, 'Node helper failed: '.substr($exception->getMessage(), 0, 1000), $dependencies);
        }
        if ($excessive || ! $process->isSuccessful()) {
            return new RunResult(null, 'Node helper failed: '.substr($process->getErrorOutput(), 0, 1000), $dependencies);
        }

        return new RunResult($process->getOutput(), substr($process->getErrorOutput(), 0, 1000), $dependencies);
    }
}
