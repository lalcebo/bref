<?php declare(strict_types=1);

namespace Bref\ConsoleRuntime;

use Bref\Bref;
use Bref\Context\Context;
use Bref\LazySecretsLoader;
use Bref\Runtime\LambdaRuntime;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
class Main
{
    public static function run(): void
    {
        LazySecretsLoader::loadSecretEnvironmentVariables();

        Bref::triggerHooks('beforeStartup');
        Bref::events()->beforeStartup();

        $lambdaRuntime = LambdaRuntime::fromEnvironmentVariable('console');

        $appRoot = getenv('LAMBDA_TASK_ROOT');
        $handlerFile = $appRoot . '/' . getenv('_HANDLER');
        if (! is_file($handlerFile)) {
            $lambdaRuntime->failInitialization("Handler `$handlerFile` doesn't exist", 'Runtime.NoSuchHandler');
        }

        Bref::events()->afterStartup();

        /** @phpstan-ignore-next-line */
        while (true) {
            $lambdaRuntime->processNextEvent(function ($event, Context $context) use ($handlerFile): array {
                if (is_array($event)) {
                    // Backward compatibility with the former CLI invocation format
                    $cliOptions = $event['cli'] ?? '';
                } elseif (is_string($event)) {
                    $cliOptions = $event;
                } else {
                    $cliOptions = '';
                }

                $timeout = max(1, $context->getRemainingTimeInMillis() / 1000 - 1);
                $command = sprintf('php %s %s 2>&1', $handlerFile, $cliOptions);
                $process = Process::fromShellCommandline($command, null, null, null, $timeout);

                $process->run(function ($type, $buffer): void {
                    echo $buffer;
                });

                $exitCode = $process->getExitCode();

                if ($exitCode > 0) {
                    throw new CommandFailed($process->getOutput());
                }

                return [
                    'exitCode' => $exitCode, // will always be 0
                    'output' => $process->getOutput(),
                ];
            });
        }
    }
}
