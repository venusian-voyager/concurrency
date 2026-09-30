<?php

namespace Voyager\Concurrency;

use Closure;
use Voyager\Vessel\ControlPanel;
use Laravel\SerializableClosure\SerializableClosure;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;

/**
 * One concurrency task, run in a pool worker through the worker's container, so its parameters
 * are resolved as the process driver's invoke-serialized-closure resolves them.
 *
 * The closure crosses already serialized and is rebuilt inside handle(): one the worker can't
 * rebuild, say because the class it was written in doesn't load there, fails as this task's
 * error instead of taking the worker down with it.
 */
final readonly class RunTask implements ShouldPool
{
    public string $closure;

    public function __construct(Closure $task)
    {
        $this->closure = serialize(new SerializableClosure($task));
    }

    public function handle(): mixed
    {
        return ControlPanel::getInstance()->call(unserialize($this->closure)->getClosure());
    }
}
