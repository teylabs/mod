<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Rename\Git\Transaction;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Result;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\TestCase;

if (! isset($argv[1], $argv[2])) {
    throw new RuntimeException('Worker needs a project path and action.');
}

$test = new class('worker') extends TestCase {};
$test->bootApplicationUsing(static function (): void {});
app()->setBasePath($argv[1]);
config()->set('mod.layout', 'modules');
Mod::scaffold('model-only', fn (Scaffold $s) => $s->makes('model'));
app()->forgetInstance(CompiledLayout::class);
$recipe = $argv[4] ?? 'model-only';
if ($recipe === 'pages') {
    Mod::scaffold('pages', fn (Scaffold $s) => $s->makes('page', name: '{name}/Show'));
}
$phase = $argv[3] ?? '';
$transaction = new Transaction($argv[1], static function (string $current) use ($phase): void {
    if ($current === $phase) {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            throw new RuntimeException('Cannot open checkpoint barrier.');
        }
        fwrite(STDOUT, 'BARRIER '.$current.' PID '.getmypid()."\n");
        fflush(STDOUT);
        $connection = @stream_socket_accept($server, 60);
        if ($connection === false) {
            throw new RuntimeException('Checkpoint barrier exceeded its 60-second hard timeout: '.$current);
        }
        fclose($connection);
        fclose($server);
        throw new RuntimeException('Checkpoint barrier was released instead of terminating its worker: '.$current);
    }
});
$show = static function (Result $result): void {
    foreach ($result->plan->warnings as $warning) {
        fwrite(STDOUT, $warning['message']."\n");
    }
};
exit($argv[2] === 'recover'
    ? $transaction->recover(new Request(null, null, null, recover: true, yes: true), $show, fn () => true)
    : $transaction->execute(new Request('Inventory:Widget', 'Inventory:Gadget', $recipe, yes: true), app(Planner::class)->build(...), $show, fn () => true));
