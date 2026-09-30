<?php

namespace Voyager\Concurrency;

use Error;
use Closure;
use Exception;
use Throwable;
use ReflectionClass;
use ReflectionProperty;
use ReflectionException;
use InvalidArgumentException;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\NutsAndBolts\DataObjects\Arr;
use Voyager\Contracts\Concurrency\AsyncDriver;
use Voyager\Contracts\IOPools\WorkerPools\WorkerPool;
use Voyager\Contracts\IOPools\WorkerPools\RemoteException;

/**
 * Runs each task as a gig on IOPools' worker pools: the thread workers or the process workers by
 * name, or with "auto", the thread workers when they are on and the process workers otherwise.
 * The workers are booted once and reused, so a task pays for no app boot of its own.
 */
class PoolDriver implements AsyncDriver
{
    /**
     * @param 'auto'|'thread'|'process' $pool
     */
    public function __construct(
        protected readonly FrameworkCore $app,
        protected readonly string $pool = 'auto',
    ) {}

    /**
     * @throws Throwable the first task in key order that failed, as its own class
     */
    public function run(Closure|array $tasks): array
    {
        [$results, $failures] = $this->gather($tasks)->wait();

        if ($failures !== []) {
            throw reset($failures);
        }

        return $results;
    }

    public function async(Closure|array $tasks, string $name = 'default'): Promise
    {
        $settled = $this->loop()->promise();

        $this->gather($tasks)->then(function (array $outcome) use ($settled, $name): null {
            [$results, $failures] = $outcome;

            $this->loop()->post(new ConcurrencyResults($name, $results, $failures));

            $failures === [] ? $settled->resolve($results) : $settled->reject(reset($failures));

            return null;
        });

        return $settled;
    }

    /**
     * Sends every task and settles once all of them have, with what each returned and what each
     * threw, both keyed in the tasks' own order.
     *
     * @param Closure|array<array-key, Closure> $tasks
     * @return Promise array{0: array<array-key, mixed>, 1: array<array-key, Throwable>}
     */
    protected function gather(Closure|array $tasks): Promise
    {
        $tasks = Arr::wrap($tasks);
        $all = $this->loop()->promise();
        $outcomes = [];
        $left = count($tasks);

        if ($left === 0) {
            $all->resolve([[], []]);

            return $all;
        }

        $pool = $this->workers();

        $settle = function (int|string $key, bool $returned, mixed $outcome) use ($tasks, $all, &$outcomes, &$left): void {
            $outcomes[$key] = [$returned, $outcome];

            if (--$left > 0) {
                return;
            }

            [$results, $failures] = [[], []];

            foreach (array_keys($tasks) as $task) {
                [$returned, $outcome] = $outcomes[$task];
                $returned ? $results[$task] = $outcome : $failures[$task] = $outcome;
            }

            $all->resolve([$results, $failures]);
        };

        foreach ($tasks as $key => $task) {
            try {
                $sent = $pool->submit(new RunTask($task));
            } catch (Throwable $e) {
                $settle($key, false, $e);
                continue;
            }

            $sent->then(
                function (mixed $value) use ($settle, $key): mixed {
                    $settle($key, true, $value);

                    return $value;
                },
                function (Throwable $e) use ($settle, $key): null {
                    $settle($key, false, self::original($e));

                    return null;
                },
            );
        }

        return $all;
    }

    /**
     * @throws InvalidArgumentException the pool doesn't exist or isn't on
     */
    protected function workers(): WorkerPool
    {
        $binding = match ($this->pool) {
            'auto' => $this->app->isBound('thread-workers') ? 'thread-workers' : 'process-workers',
            'thread' => 'thread-workers',
            'process' => 'process-workers',
            default => throw new InvalidArgumentException(
                "The pool concurrency driver has no \"{$this->pool}\" pool: use 'auto', 'thread' or 'process'."
            ),
        };

        if (! $this->app->isBound($binding)) {
            throw new InvalidArgumentException(match ($this->pool) {
                'auto' => 'The pool concurrency driver runs on a worker pool, and none is on: enable io-pools.pool_workers.threads or io-pools.pool_workers.process.',
                'thread' => 'The thread workers are off: enable io-pools.pool_workers.threads for the pool concurrency driver.',
                default => 'The process workers are off: enable io-pools.pool_workers.process for the pool concurrency driver.',
            });
        }

        return $this->app->get($binding);
    }

    protected function loop(): Loop
    {
        return $this->app->get(Loop::class);
    }

    /**
     * A task's exception as its own class, so a catch around run() catches it as it would the
     * blocking task's. The worker hands back only the class, message and trace: the rebuilt
     * exception carries the message, and the worker's RemoteException, trace and all, as its
     * previous. A class that can't be rebuilt here stays the RemoteException.
     */
    protected static function original(Throwable $e): Throwable
    {
        if (! $e instanceof RemoteException || ! is_a($e->remote_class, Throwable::class, true)) {
            return $e;
        }

        try {
            $original = new ReflectionClass($e->remote_class)->newInstanceWithoutConstructor();
        } catch (ReflectionException) {
            return $e;
        }

        $base = $original instanceof Error ? Error::class : Exception::class;

        new ReflectionProperty($base, 'message')->setValue($original, substr($e->getMessage(), strlen($e->remote_class) + 2));
        new ReflectionProperty($base, 'previous')->setValue($original, $e);

        return $original;
    }
}
