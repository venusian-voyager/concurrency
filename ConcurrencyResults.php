<?php

namespace Voyager\Concurrency;

use Throwable;
use Voyager\Contracts\Signals\NamedSignal;

/**
 * What an async() call's tasks came back with, delivered as loop mail once every task has settled.
 * Dispatched as "concurrency:{name}".
 */
final readonly class ConcurrencyResults implements NamedSignal
{
    /**
     * @param array<array-key, mixed> $results the tasks that returned, keyed as the tasks were
     * @param array<array-key, Throwable> $failures the tasks that threw, keyed as the tasks were
     */
    public function __construct(
        public string $name,
        public array $results,
        public array $failures,
    ) {}

    public function name(): string
    {
        return "concurrency:{$this->name}";
    }

    public function successful(): bool
    {
        return $this->failures === [];
    }
}
