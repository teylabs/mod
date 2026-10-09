<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Examples\Support;

/** Exact console expectations, with fixture paths independent of the workspace. */
final class PlacementScenario
{
    /** @param array<string, string> $rows
     * @param  list<array{string, string}>  $messages
     */
    public static function output(string $command, string $name, int $count, array $rows, array $messages): string
    {
        $output = "\n   INFO  {$command} will write {$count} ".($count === 1 ? 'file' : 'files')." for {$name}.  \n\n";
        foreach ($rows as $path => $alias) {
            $output .= '  '.$path.' '.str_repeat('.', max(2, 72 - strlen($path) - strlen($alias) - 4)).' '.$alias."\n";
        }
        $output .= "\n";
        foreach ($messages as [$level, $message]) {
            $output .= "   {$level}  {$message}  \n\n";
        }

        return $output;
    }
}
