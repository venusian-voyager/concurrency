<?php

namespace Voyager\Concurrency;

use Closure;
use Exception;
use Throwable;
use Voyager\Process\Pool;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\NutsAndBolts\DataObjects\Arr;
use Voyager\Process\Factory as ProcessFactory;
use Voyager\Console\ComputerConsoleInstance;
use Voyager\Contracts\Concurrency\AsyncDriver;
use Voyager\Contracts\Process\ProcessResult;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Runs each task in a fresh `php computer invoke-serialized-closure` process: the closure goes
 * in through the environment, its result comes back on stdout.
 */
class ProcessDriver implements AsyncDriver
{
    /**
     * Create a new process based concurrency driver.
     */
    public function __construct(protected ProcessFactory $processFactory)
    {
        //
    }

    /**
     * Run the given tasks concurrently and return an array containing the results.
     */
    public function run(Closure|array $tasks): array
    {
        $command = ComputerConsoleInstance::formatCommandString('invoke-serialized-closure');

        $results = $this->processFactory->pool(function (Pool $pool) use ($tasks, $command) {
            foreach (Arr::wrap($tasks) as $key => $task) {
                $pool->as($key)->path(base_path())->env([
                    'VENUSIAN_INVOKABLE_CLOSURE' => base64_encode(
                        serialize(new SerializableClosure($task))
                    ),
                ])->command($command);
            }
        })->start()->wait();

        return $results->collect()->mapWithKeys(function ($result, $key) {
            return [$key => $this->decode($result)];
        })->all();
    }

    /**
     * Start the processes and wait on them through the loop. A process that fails or times out
     * is that task's failure; the others still come back.
     */
    public function async(Closure|array $tasks, string $name = 'default'): Promise
    {
        $command = ComputerConsoleInstance::formatCommandString('invoke-serialized-closure');
        $loop = ControlPanel::getInstance()->get(Loop::class);
        $tasks = Arr::wrap($tasks);
        $settled = $loop->promise();
        $outcomes = [];
        $left = count($tasks);

        $finish = function () use ($tasks, $settled, $name, $loop, &$outcomes): void {
            [$results, $failures] = [[], []];

            foreach (array_keys($tasks) as $key) {
                [$returned, $outcome] = $outcomes[$key];
                $returned ? $results[$key] = $outcome : $failures[$key] = $outcome;
            }

            $loop->post(new ConcurrencyResults($name, $results, $failures));

            $failures === [] ? $settled->resolve($results) : $settled->reject(reset($failures));
        };

        if ($left === 0) {
            $finish();

            return $settled;
        }

        $settle = function (int|string $key, bool $returned, mixed $outcome) use ($finish, &$outcomes, &$left): void {
            $outcomes[$key] = [$returned, $outcome];

            if (--$left === 0) {
                $finish();
            }
        };

        foreach ($tasks as $key => $task) {
            try {
                $waited = $this->processFactory->path(base_path())->env([
                    'VENUSIAN_INVOKABLE_CLOSURE' => base64_encode(
                        serialize(new SerializableClosure($task))
                    ),
                ])->start($command)->waitAsync();
            } catch (Throwable $e) {
                $settle($key, false, $e);
                continue;
            }

            $waited->then(
                function (ProcessResult $result) use ($settle, $key): null {
                    try {
                        $settle($key, true, $this->decode($result));
                    } catch (Throwable $e) {
                        $settle($key, false, $e);
                    }

                    return null;
                },
                function (Throwable $e) use ($settle, $key): null {
                    $settle($key, false, $e);

                    return null;
                },
            );
        }

        return $settled;
    }

    /**
     * What one invoke-serialized-closure process printed: the task's return value, or its
     * exception rebuilt and thrown.
     *
     * @throws Throwable
     */
    protected function decode(ProcessResult $result): mixed
    {
        if ($result->failed()) {
            throw new Exception('Concurrent process failed with exit code ['.$result->exitCode().']. Message: '.$result->errorOutput());
        }

        $output = $result->output();

        if (($pos = strpos($output, "\x1f\x8b")) !== false) {
            $output = substr($output, 0, $pos);
        }

        $result = json_decode($output, true);

        if (! $result['successful']) {
            throw new $result['exception'](
                ...(! empty(array_filter($result['parameters']))
                    ? $result['parameters']
                    : [$result['message']])
            );
        }

        return unserialize($result['result']);
    }
}
