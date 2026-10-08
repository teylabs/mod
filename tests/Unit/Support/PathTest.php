<?php

use Tey\Mod\Support\Path;

it('normalizes paths without dropping absolute roots', function (string $input, string $expected) {
    expect(Path::normalize($input))->toBe($expected);
})->with([
    ['app\\Models\\Invoice.php', 'app/Models/Invoice.php'],
    ['./app//Models/', 'app/Models'],
    ['/tmp/app/', '/tmp/app'],
    ['/', '/'],
    ['C:\\', 'C:/'],
    ['C:\\work\\app', 'C:/work/app'],
    ['\\\\server\\share\\app', '//server/share/app'],
    ['', ''],
]);

it('joins paths skipping empty parts while retaining roots and zero segments', function () {
    expect(Path::join('', 'x', ''))->toBe('x')
        ->and(Path::join('/tmp/', '', '/app\\Models', 'Invoice.php'))->toBe('/tmp/app/Models/Invoice.php')
        ->and(Path::join('/', 'app'))->toBe('/app')
        ->and(Path::join('C:\\', 'app'))->toBe('C:/app')
        ->and(Path::join('\\\\server\\share', 'app'))->toBe('//server/share/app')
        ->and(Path::join('0', 'x'))->toBe('0/x')
        ->and(Path::join('', ''))->toBe('');
});

it('compares separators neutrally and uses Windows case semantics', function () {
    expect(Path::same('C:\\work\\app\\Invoice.php', 'C:/work/app/Invoice.php'))->toBeTrue()
        ->and(Path::same('C:\\Work\\app', 'c:/work/APP'))->toBe(PHP_OS_FAMILY === 'Windows')
        ->and(Path::same('/tmp/app', '/tmp/other'))->toBeFalse();
});

it('returns only separator-neutral remainders inside the base', function () {
    expect(Path::relative('C:\\work', 'C:/work/app\\Invoice.php'))->toBe('app/Invoice.php')
        ->and(Path::relative('C:\\work', 'c:/WORK/app'))->toBe(PHP_OS_FAMILY === 'Windows' ? 'app' : null)
        ->and(Path::relative('/tmp/app/', '/tmp/app'))->toBe('')
        ->and(Path::relative('/tmp/app', '/tmp/application/File.php'))->toBeNull()
        ->and(Path::relative('/tmp/app', '/tmp/app/../outside'))->toBeNull()
        ->and(Path::relative('', 'app\\File.php'))->toBe('app/File.php')
        ->and(Path::relative('', '/app/File.php'))->toBeNull()
        ->and(Path::relative('/', '/app/File.php'))->toBe('app/File.php');
});
