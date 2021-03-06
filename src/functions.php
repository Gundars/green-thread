<?php

namespace Amp\GreenThread;

use Amp\Deferred;
use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use React\Promise\PromiseInterface as ReactPromise;

/**
 * Await a promise within an async function created by Amp\GreenThread\async().
 *
 * @param \Amp\Promise|\React\Promise\PromiseInterface|array $promise
 *
 * @return mixed Promise resolution value.
 *
 * @throws \Throwable Promise failure reason.
 */
function await($promise) {
    while (!$promise instanceof Promise) {
        try {
            if (\is_array($promise)) {
                $promise = Promise\all($promise);
                break;
            }

            if ($promise instanceof ReactPromise) {
                $promise = Promise\adapt($promise);
                break;
            }

            // No match, continue to throwing below.
        } catch (\Throwable $exception) {
            // Conversion to promise failed, fall-through to throwing below.
        }

        throw new InvalidAwaitError(
            $promise,
            \sprintf(
                "Unexpected await; Expected an instance of %s or %s or an array of such instances",
                Promise::class,
                ReactPromise::class
            ),
            $exception ?? null
        );
    }

    return \Fiber::yield($promise);
}

/**
 * Creates a green thread using the given callable and argument list.
 *
 * @param callable $callback
 * @param mixed ...$args
 *
 * @return \Amp\Promise
 */
function async(callable $callback, ...$args): Promise {
    try {
        $fiber = new \Fiber($callback);

        $awaited = $fiber->resume(...$args);

        if ($fiber->status() !== \Fiber::STATUS_SUSPENDED) {
            return new Success($awaited);
        }

        if (!$awaited instanceof Promise) {
            return new Failure(new InvalidAwaitError($awaited, "Use Amp\GreenThread\await() to await promises"));
        }

        $deferred = new Deferred;

        $onResolve = function ($exception, $value) use (&$onResolve, $fiber, $deferred) {
            static $thrown, $result, $immediate = true;

            $thrown = $exception;
            $result = $value;

            if (!$immediate) {
                $immediate = true;
                return;
            }

            try {
                do {
                    if ($thrown) {
                        // Throw exception at last await.
                        $awaited = $fiber->throw($thrown);
                    } else {
                        // Send the new value and execute to next await.
                        $awaited = $fiber->resume($result);
                    }

                    if ($fiber->status() !== \Fiber::STATUS_SUSPENDED) {
                        $deferred->resolve($awaited);
                        $onResolve = null;
                        return;
                    }

                    if (!$awaited instanceof Promise) {
                        throw new InvalidAwaitError($awaited, "Use Amp\GreenThread\await() to await promises");
                    }

                    $immediate = false;
                    $awaited->onResolve($onResolve);
                } while ($immediate);

                $immediate = true;
            } catch (\Throwable $exception) {
                $deferred->fail($exception);
                $onResolve = null;
            } finally {
                $thrown = null;
                $result = null;
            }
        };

        $awaited->onResolve($onResolve);
    } catch (\Throwable $exception) {
        return new Failure($exception);
    }

    return $deferred->promise();
}

/**
 * Returns a callable that when invoked creates a new green thread using the given call able Amp\GreenThread\async(),
 * passing any arguments to the function as the argument list to async() and returning promise created by async().
 *
 * @param callable $callback Green thread to create each time the function returned is invoked.
 *
 * @return callable Creates a new green thread each time the returned function is invoked. The arguments given to
 *    the returned function are passed through to the callable.
 */
function asyncify(callable $callback): callable {
    return function (...$args) use ($callback): Promise {
        return async($callback, ...$args);
    };
}
