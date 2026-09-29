<?php

namespace Voyager\Concurrency;

use Closure;
use Voyager\Contracts\Concurrency\Driver;
use Voyager\NutsAndBolts\Collection;


class SyncDriver implements Driver
{
    /**
     * Run the given tasks concurrently and return an array containing the results.
     */
    public function run(Closure|array $tasks): array
    {
        return Collection::wrap($tasks)->map(
            fn ($task) => $task()
        )->all();
    }
}
