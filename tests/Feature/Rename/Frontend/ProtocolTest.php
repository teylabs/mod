<?php

use Tey\Mod\Rename\Frontend\Protocol;
use Tey\Mod\Rename\InputFile;

it('accepts byte exact located edits and rejects unsafe protocol output atomically', function () {
    $source = "// 🍁\r\nimport X from './Widget.vue';\r\n";
    $file = new InputFile('resources/js/a.ts', $source, 0644);
    $edit = ['file' => $file->path, 'offset' => strpos($source, './Widget.vue'), 'before' => './Widget.vue', 'after' => './Gadget.vue', 'line' => 2, 'category' => 'frontend-import'];
    $valid = ['version' => 1, 'edits' => [$edit], 'checklist' => [], 'failures' => [], 'dependencies' => []];
    expect(Protocol::decode(json_encode($valid, JSON_THROW_ON_ERROR), [$file->path => $file])->edits)->toHaveCount(1);
    foreach ([['version' => 2], ['edits' => [[...$edit, 'offset' => 9999]]], ['edits' => [[...$edit, 'before' => 'wrong']]], ['edits' => [[...$edit, 'line' => 1]]], ['edits' => [[...$edit, 'file' => '../escape']]], ['edits' => [$edit, $edit]], ['extra' => true], ['failures' => [$file->path => 'broken']]] as $change) {
        expect(fn () => Protocol::decode(json_encode([...$valid, ...$change], JSON_THROW_ON_ERROR), [$file->path => $file]))->toThrow(UnexpectedValueException::class);
    }
    expect(fn () => Protocol::decode(json_encode($valid, JSON_THROW_ON_ERROR).'noise', [$file->path => $file]))->toThrow(UnexpectedValueException::class);
});

it('refuses code injection, malformed locations and unverified parser dependencies', function () {
    $file = new InputFile('resources/js/a.ts', "import X from './Widget.vue';", 0644);
    $edit = ['file' => $file->path, 'offset' => 15, 'before' => './Widget.vue', 'after' => './Gadget.vue', 'line' => 1, 'category' => 'frontend-import'];
    $valid = ['version' => 1, 'edits' => [$edit], 'checklist' => [], 'failures' => [], 'dependencies' => []];
    foreach ([['edits' => [[...$edit, 'after' => "./x'; console.log('injected')"]]], ['edits' => [[...$edit, 'after' => './x\\u0027']]], ['checklist' => [['file' => $file->path, 'line' => 99, 'category' => 'identity-string', 'message' => 'Review', 'suggestion' => null]]], ['dependencies' => ['/tmp/missing-mod-parser' => str_repeat('0', 64)]]] as $change) {
        expect(fn () => Protocol::decode(json_encode([...$valid, ...$change], JSON_THROW_ON_ERROR), [$file->path => $file]))->toThrow(UnexpectedValueException::class);
    }
});
