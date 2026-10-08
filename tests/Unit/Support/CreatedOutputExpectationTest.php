<?php

use PHPUnit\Framework\ExpectationFailedException;

/*
 * The created-file expectation: the same assertion holds for make:* output
 * on Unix (relative path) and on Windows (absolute path, backslashes).
 */

it('matches the created-file line whether the path is relative or absolute', function (string $output) {
    expect($output)->toContainCreated('DTO', 'src/Domain/Billing/Data/InvoiceData.php');
})->with([
    'unix' => ["   INFO  DTO [src/Domain/Billing/Data/InvoiceData.php] created successfully.\n"],
    'windows' => ["   INFO  DTO [C:\\Users\\runneradmin\\AppData\\Local\\Temp\\tey-mod-root-09fb\\src\\Domain\\Billing\\Data\\InvoiceData.php] created successfully.  \r\n"],
]);

it('fails on another label, another file, or a path that only ends like it', function (string $output) {
    expect($output)->toContainCreated('DTO', 'src/Domain/Billing/Data/InvoiceData.php');
})->throws(ExpectationFailedException::class)->with([
    'label' => ['Class [src/Domain/Billing/Data/InvoiceData.php] created successfully.'],
    'file' => ['DTO [src/Domain/Billing/Data/LineData.php] created successfully.'],
    'partial name' => ['DTO [src/Domain/Billing/Data/OldInvoiceData.php] created successfully.'],
]);
