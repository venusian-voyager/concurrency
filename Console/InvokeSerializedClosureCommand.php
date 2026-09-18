<?php

namespace Voyager\Concurrency\Console;

use Voyager\Console\Command;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'invoke-serialized-closure')]
class InvokeSerializedClosureCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected ?string $signature = 'invoke-serialized-closure {code? : The serialized closure}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected string $description = 'Invoke the given serialized closure';

    /**
     * Indicates whether the command should be shown in the Computer command list.
     *
     * @var bool
     */
    protected bool $hidden = true;

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public function handle()
    {
        try {
            $this->output->write(json_encode([
                'successful' => true,
                'result' => serialize($this->venusian->call(match (true) {
                    ! is_null($this->argument('code')) => unserialize($this->argument('code')),
                    isset($_SERVER['LARAVEL_INVOKABLE_CLOSURE']) => unserialize(
                        base64_decode($_SERVER['LARAVEL_INVOKABLE_CLOSURE'])
                    ),
                    default => fn () => null,
                })),
            ]));
        } catch (Throwable $e) {
            report($e);

            $reflection = new ReflectionClass($e);
            $constructor = $reflection->getConstructor();
            $parameters = [];

            if ($constructor) {
                $declaringClass = $constructor->getDeclaringClass()->getName();

                if ($declaringClass === $reflection->getName()) {
                    foreach ($constructor->getParameters() as $parameter) {
                        $parameters[$parameter->name] = $e->{$parameter->name} ?? null;
                    }
                }
            }

            $this->output->write(json_encode([
                'successful' => false,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'parameters' => $parameters,
            ]));
        }
    }
}
