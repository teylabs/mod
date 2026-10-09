<?php

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Generation\Support\CommandResult;

// Text snapshots compare content and spacing independently of checkout newlines.
// Copy/no-write guarantees continue to use strict byte comparisons.
expect()->extend('toEqualText', function (string $expected, string $message = '') {
    $actual = $this->value;
    Assert::assertIsString($actual);
    Assert::assertSame(str_replace("\r\n", "\n", $expected), str_replace("\r\n", "\n", $actual), $message);

    return $this;
});

expect()->extend('toEqualPath', function (string $expected) {
    $actual = $this->value;
    Assert::assertIsString($actual);
    expect(Path::normalize($actual))->toBe(Path::normalize($expected));

    return $this;
});

expect()->extend('toContainPath', function (string $expected) {
    $actual = $this->value;
    Assert::assertIsString($actual);
    expect(Path::normalize($actual))->toContain(Path::normalize($expected));

    return $this;
});

/*
 * "<Label> [<path>] created successfully." for a file below the application:
 * make:* prints the path relative on Unix and absolute on Windows (Laravel
 * only strips "<base path>/"), so the base path may precede it, with either
 * separator.
 */
expect()->extend('toContainCreated', function (string $label, string $path) {
    $actual = $this->value;
    Assert::assertIsString($actual);
    $pattern = '#'.preg_quote($label, '#').' \[(?:[^\]]*/)?'.preg_quote(Path::normalize($path), '#').'\] created successfully\.#';
    Assert::assertMatchesRegularExpression($pattern, str_replace('\\', '/', $actual), "Expected \"{$label} [{$path}] created successfully.\" in:\n{$actual}");

    return $this;
});

expect()->extend('toBeValidPhp', function () {
    $path = $this->value;
    Assert::assertIsString($path);
    Assert::assertFileExists($path);
    $process = new Process([PHP_BINARY, '-l', $path]);
    $process->run();
    Assert::assertTrue($process->isSuccessful(), "[{$path}] is not valid PHP: ".trim($process->getOutput().$process->getErrorOutput()));

    return $this;
});

expect()->extend('toHaveGenerated', function (string $path, ?string $namespace = null) {
    $result = $this->value;
    Assert::assertInstanceOf(CommandResult::class, $result);
    Assert::assertNotNull($result->basePath, 'The command result must carry its owned application root.');
    $absolute = Path::join($result->basePath, $path);
    $result->assertSuccessful();
    Assert::assertFileExists($absolute);
    expect($result->output)->toContainPath($path);
    if ($namespace !== null) {
        expect((string) file_get_contents($absolute))->toContain("namespace {$namespace};");
    }
    expect($absolute)->toBeValidPhp();

    return $this;
});
