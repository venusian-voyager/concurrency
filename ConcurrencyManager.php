<?php

namespace Voyager\Concurrency;

use Voyager\Process\Factory as ProcessFactory;
use Voyager\NutsAndBolts\MultipleInstanceManager;
use Closure;
use RuntimeException;
use InvalidArgumentException;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\Concurrency\AsyncDriver;
use Spatie\Fork\Fork;

/**
 * @mixin \Voyager\Contracts\Concurrency\Driver
 */
class ConcurrencyManager extends MultipleInstanceManager
{
    /**
     * Get a driver instance by name.
     *
     * @param string|null $name
     * @return mixed
     */
    public function driver(?string $name = null): mixed
    {
        return $this->instance($name);
    }

    /**
     * Create an instance of the process concurrency driver.
     *
     * @return \Voyager\Concurrency\ProcessDriver
     */
    public function createProcessDriver(): ProcessDriver
    {
        return new ProcessDriver($this->app->make(ProcessFactory::class));
    }

    /**
     * Create an instance of the fork concurrency driver.
     *
     * @return \Voyager\Concurrency\ForkDriver
     *
     * @throws \RuntimeException
     */
    public function createForkDriver(): ForkDriver
    {
        if (! class_exists(Fork::class)) {
            throw new RuntimeException('Please install the "spatie/fork" Composer package in order to utilize the "fork" driver.');
        }

        return new ForkDriver;
    }

    /**
     * Create an instance of the pool concurrency driver.
     *
     * @param  array{pool?: 'auto'|'thread'|'process'}  $config
     */
    public function createPoolDriver(array $config): PoolDriver
    {
        return new PoolDriver($this->app, $config['pool'] ?? 'auto');
    }

    /**
     * Run the tasks on the default driver without blocking. Their results arrive together as
     * ConcurrencyResults mail named "concurrency:{$name}", and the promise settles with them.
     *
     * @param  Closure|array<array-key, Closure>  $tasks
     * @return Promise the results keyed as the tasks were, or the first failure in key order
     *
     * @throws InvalidArgumentException the default driver only runs tasks blocking
     */
    public function async(Closure|array $tasks, string $name = 'default'): Promise
    {
        $driver = $this->driver();

        if (! $driver instanceof AsyncDriver) {
            throw new InvalidArgumentException(
                "The [{$this->getDefaultInstance()}] concurrency driver only runs tasks blocking: async() needs the process or pool driver."
            );
        }

        return $driver->async($tasks, $name);
    }

    /**
     * Create an instance of the sync concurrency driver.
     *
     * @return \Voyager\Concurrency\SyncDriver
     */
    public function createSyncDriver(): SyncDriver
    {
        return new SyncDriver;
    }

    /**
     * Get the default instance name.
     *
     * @return string
     */
    public function getDefaultInstance(): string
    {
        return $this->app['config']['concurrency.default']
            ?? $this->app['config']['concurrency.driver']
            ?? 'process';
    }

    /**
     * Set the default instance name.
     *
     * @param string $name
     * @return void
     */
    public function setDefaultInstance(string $name): void
    {
        $this->app['config']['concurrency.default'] = $name;
        $this->app['config']['concurrency.driver'] = $name;
    }

    /**
     * Get the instance specific configuration.
     *
     * @param string $name
     * @return array
     */
    public function getInstanceConfig(string $name): array
    {
        return $this->app['config']->get(
            'concurrency.drivers.'.$name, ['driver' => $name],
        );
    }
}
