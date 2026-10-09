<?php

namespace Tey\Mod\Boost;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Mod\Exceptions\ModException;
use Throwable;

#[IsReadOnly]
/** @internal Preview a registered mod writer through its existing PlanWriter path. */
final class PlanTool extends Tool
{
    protected string $name = 'mod-plan';

    protected string $description = 'Return a mod command dry-run JSON plan without writing or prompting. Pass the exact command name and an array of positional arguments and option tokens, such as ["Inventory:Widget", "History"]. Writes stay a reviewed php artisan mod:* command.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()->description('Exact registered mod:* command supporting --dry-run --json.')->required(),
            'arguments' => $schema->array()->items($schema->string())->description('Positional arguments and option tokens, e.g. ["Inventory:Widget", "--force"].')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $name = $request->get('command');
        $arguments = $request->get('arguments');
        if (! is_string($name) || ! str_starts_with($name, 'mod:') || ! is_array($arguments) || ! array_is_list($arguments)) {
            return Response::error('mod-plan needs a registered mod:* command and an arguments array of strings. Read mod-inventory to choose a command.');
        }
        foreach ($arguments as $argument) {
            if (! is_string($argument)) {
                return Response::error('mod-plan arguments must be strings. Pass positional arguments and option tokens in an array.');
            }
        }

        $command = Artisan::all()[$name] ?? null;
        if ($command === null || ! $command->getDefinition()->hasOption('dry-run') || ! $command->getDefinition()->hasOption('json')) {
            return Response::error('mod-plan cannot preview '.$name.'. Choose a command supporting --dry-run --json from mod-inventory.');
        }

        // Remove caller values for the enforced flags before Symfony parses them.
        $arguments = array_values(array_filter($arguments, static fn (string $argument): bool => preg_match('/^--(?:dry-run|json|no-interaction)(?:=.*)?$/D', $argument) !== 1));
        $command->mergeApplicationDefinition();
        $definition = $command->getDefinition();
        $output = new BufferedOutput;
        try {
            $tokens = new ArgvInput(['artisan', $name, ...$arguments], $definition);
            $parameters = $tokens->getArguments();
            foreach ($tokens->getOptions() as $option => $value) {
                if ($value !== null) {
                    $parameters['--'.$option] = $value;
                }
            }
            $parameters['--dry-run'] = true;
            $parameters['--json'] = true;
            $parameters['--no-interaction'] = true;
            $input = new ArrayInput($parameters, $definition);
            $input->setInteractive(false);
            $status = $command->run($input, $output);
        } catch (Throwable $exception) {
            if (! $exception instanceof ExceptionInterface && ! $exception instanceof ModException) {
                throw $exception;
            }

            return Response::error('mod-plan: '.$exception->getMessage().' Check the arguments for '.$name.'.');
        }
        $text = $output->fetch();
        $data = json_decode($text, true);
        if ($status !== 0 || ! is_array($data) || ! isset($data['command'], $data['files'], $data['inserts'], $data['warnings'], $data['would_write'])) {
            return Response::error('mod-plan could not read a JSON plan for '.$name.'. Check its arguments and preview with php artisan '.$name.' --dry-run --json.');
        }

        return Response::json($data);
    }
}
