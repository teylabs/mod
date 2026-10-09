<?php

namespace Tey\Mod\Tests\Support;

use Symfony\Component\Console\Command\Command;

/** The same checker drives the real inventory gate and A4's negative fixture. */
final class BoostCoverage
{
    /** @param iterable<Command> $commands
     * @return list<string>
     */
    public static function missing(iterable $commands, string $skill, string $guideline): array
    {
        $text = str_replace("\r\n", "\n", $skill."\n".$guideline);
        preg_match('/<!-- mod-shared-options:start -->(.*?)<!-- mod-shared-options:end -->/s', $text, $shared);
        $lines = preg_split('/\n|(?=\bmod:)/', $text) ?: [];
        $missing = [];
        foreach ($commands as $command) {
            $name = $command->getName();
            if ($name === null || ! str_starts_with($name, 'mod:')) {
                continue;
            }
            foreach ([$name, ...$command->getAliases()] as $alias) {
                if (! self::mentions($text, $alias)) {
                    $missing[] = $alias." isn't mentioned in resources/boost/skills/mod-development/SKILL.md.";
                }
            }
            foreach ($command->getDefinition()->getOptions() as $option) {
                $flag = '--'.$option->getName();
                $negative = $option->isNegatable() ? '--no-'.$option->getName() : null;
                $covered = self::mentions($shared[1] ?? '', $flag);
                $negativeCovered = $negative === null || self::mentions($shared[1] ?? '', $negative);
                foreach ($lines as $line) {
                    if (self::mentions($line, $name)) {
                        $covered = $covered || self::mentions($line, $flag);
                        $negativeCovered = $negativeCovered || ($negative !== null && self::mentions($line, $negative));
                    }
                }
                if (! $covered) {
                    $missing[] = $name.' '.$flag." isn't mentioned in resources/boost/skills/mod-development/SKILL.md.";
                }
                if (! $negativeCovered) {
                    $missing[] = $name.' '.$negative." isn't mentioned in resources/boost/skills/mod-development/SKILL.md.";
                }
            }
        }

        return array_values(array_unique($missing));
    }

    private static function mentions(string $text, string $token): bool
    {
        return preg_match('/(?<![a-zA-Z0-9_.:-])'.preg_quote($token, '/').'(?![a-zA-Z0-9_.-])/', $text) === 1;
    }
}
