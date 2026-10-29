<?php

use Tey\Mod\Maintenance\SafeFilesystem;

it('names the declared path and the existing one when it refuses a case alias', function () {
    $root = sys_get_temp_dir().'/mod-case-'.bin2hex(random_bytes(4));
    mkdir($root.'/resources/js/pages/auth', 0777, true);

    try {
        expect(fn () => SafeFilesystem::path($root, 'resources/js/pages/Auth/Login.vue'))
            ->toThrow(RuntimeException::class, 'Case alias or collision at declared path resources/js/pages/Auth: the checkout already has resources/js/pages/auth.');
    } finally {
        rmdir($root.'/resources/js/pages/auth');
        rmdir($root.'/resources/js/pages');
        rmdir($root.'/resources/js');
        rmdir($root.'/resources');
        rmdir($root);
    }
});
