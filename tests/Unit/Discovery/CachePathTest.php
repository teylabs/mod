<?php

use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Tests\Fixtures\Layouts;

it('resolves cache locations with canonical separators including absolute Windows paths', function (string $path, string $expected) {
    $discovery = new Discovery(Layouts::ordinary(), new DiscoveryOptions(cachePath: $path), 'C:\\project');
    expect($discovery->cache()->path)->toEqualPath($expected);
})->with([
    ['bootstrap\\cache\\mod.php', 'C:/project/bootstrap/cache/mod.php'],
    ['D:\\cache\\mod.php', 'D:/cache/mod.php'],
    ['\\\\server\\share\\mod.php', '//server/share/mod.php'],
    ['/var/cache/mod.php', '/var/cache/mod.php'],
]);
