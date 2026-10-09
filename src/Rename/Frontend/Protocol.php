<?php

namespace Tey\Mod\Rename\Frontend;

use JsonException;
use Symfony\Component\Filesystem\Path;
use Tey\Mod\Rename\Checklist;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Edit;
use Tey\Mod\Rename\InputFile;
use UnexpectedValueException;

/** @internal Every response is validated atomically before any candidate is accepted. */
final class Protocol
{
    /** @param array<string, InputFile> $files */
    public static function decode(string $stdout, array $files): Contribution
    {
        if (strlen($stdout) > 8 * 1024 * 1024) {
            throw new UnexpectedValueException('Excessive helper response.');
        }
        try {
            $data = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Malformed helper response.', previous: $exception);
        }
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || count($data) !== 5) {
            throw new UnexpectedValueException('Unsupported helper protocol.');
        }
        foreach (['edits', 'checklist', 'failures', 'dependencies'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                throw new UnexpectedValueException('Missing helper field.');
            }
        }
        $edits = [];
        $checklist = [];
        $ranges = [];
        foreach ($data['edits'] as $row) {
            if (! is_array($row) || count($row) !== 6 || ! is_string($row['before'] ?? null) || ! is_string($row['after'] ?? null) || ! is_int($row['offset'] ?? null) || ! in_array($row['category'] ?? null, ['frontend-import', 'frontend-identity'], true)) {
                throw new UnexpectedValueException('Invalid helper edit.');
            }
            $file = self::location($row, $files);
            $offset = $row['offset'];
            $end = $offset + strlen($row['before']);
            if ($row['before'] === '' || $offset < 0 || $end > strlen($file->bytes) || substr($file->bytes, $offset, strlen($row['before'])) !== $row['before'] || substr_count(substr($file->bytes, 0, $offset), "\n") + 1 !== $row['line'] || isset($data['failures'][$file->path]) || str_contains($row['after'], "\n") || str_contains($row['after'], "\r") || str_contains($row['after'], "\0") || strpbrk($row['after'], "'\"\\") !== false) {
                throw new UnexpectedValueException('Helper edit does not match original bytes.');
            }
            foreach ($ranges[$file->path] ?? [] as [$start, $stop]) {
                if ($offset < $stop && $end > $start) {
                    throw new UnexpectedValueException('Overlapping helper edits.');
                }
            }
            $ranges[$file->path][] = [$offset, $end];
            $edits[] = new Edit($file->path, $offset, $row['before'], $row['after'], $row['category'], $row['line']);
        }
        foreach ($data['checklist'] as $row) {
            if (! is_array($row) || count($row) !== 5 || ! is_string($row['category'] ?? null) || ! in_array($row['category'], ['frontend-import', 'dynamic-import', 'identity-string', 'css-asset'], true) || ! is_string($row['message'] ?? null) || strlen($row['message']) > 2000 || ! array_key_exists('suggestion', $row) || ($row['suggestion'] !== null && ! is_string($row['suggestion']))) {
                throw new UnexpectedValueException('Invalid helper checklist.');
            }
            $file = self::location($row, $files);
            $checklist[] = new Checklist($file->path, $row['line'], $row['category'], $row['message'], $row['suggestion']);
        }
        foreach ($data['failures'] as $path => $message) {
            if (! is_string($path) || ! isset($files[$path]) || ! is_string($message) || strlen($message) > 2000) {
                throw new UnexpectedValueException('Invalid helper failure.');
            }
        }
        $dependencies = [];
        foreach ($data['dependencies'] as $path => $hash) {
            if (! is_string($path) || ! Path::isAbsolute($path) || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || ! is_file($path) || hash_file('sha256', $path) !== $hash) {
                throw new UnexpectedValueException('Changed or invalid parser dependency.');
            }
            $dependencies[$path] = $hash;
        }

        // Failures are converted to located fallback by Frontend, using original bytes.
        return new Contribution($edits, $checklist, dependencies: $dependencies);
    }

    /** @param array<array-key, mixed> $row
     * @param  array<string, InputFile>  $files
     */
    private static function location(array $row, array $files): InputFile
    {
        if (! is_string($row['file'] ?? null) || ! isset($files[$row['file']]) || ! is_int($row['line'] ?? null)) {
            throw new UnexpectedValueException('Unknown helper location.');
        }
        $file = $files[$row['file']];
        if ($file->historicalMigration || $row['line'] < 1 || $row['line'] > substr_count($file->bytes, "\n") + 1) {
            throw new UnexpectedValueException('Invalid helper location.');
        }

        return $file;
    }
}
