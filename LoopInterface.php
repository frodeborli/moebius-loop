<?php
namespace Moebius;

/**
 * This interface defines all the functionality needed to work
 * with an efficient event loop.
 */
interface LoopInterface {

    /**
     * Stop the event loop. This is generally called because of an error
     * condition. The application should ideally stop by removing all
     * event listeners, timers and stream watchers.
     */
    public function terminate(int $exitCode): void;

    /**
     * Runs the event loop until the $doneCallback returns true.
     * This function is required to run coroutines, but might
     * not be supported by every third-party loop implementation.
     *
     * @param callable $doneCallback Function that returns true when the event loop has been drained enough
     */
    public function drain(callable $doneCallback): void;

    /**
     * Runs the event loop until it has nothing more to do.
     */
    public function run(): void;

    /**
     * Is the loop currently draining?
     */
    public function isDraining(): bool;

    /**
     * Add this callback to the end of the event loop. If the
     * event loop has not been started, ensure it will start.
     *
     * @param callable $callback Function which will be run on the next tick
     */
    public function defer(callable $callback): void;

    /**
     * Schedule a callback to run at a later time. Returns a function
     * which can be invoked to prevent the callback.
     *
     * @param callable $callback Function to run on timeout
     * @param float $delay Number of seconds to delay execution
     * @return callable Cancel function
     */
    public function setTimeout(callable $callback, float $delay): callable;

    /**
     * Schedule a callback to run at regular intervals. Returns a function
     * which can be invoked to cancel the interval.
     *
     * @param callable $callback Function to run on timeout
     * @param float $interval Delay between each execution
     * @return callable Cancel function
     */
    public function setInterval(callable $callback, float $interval): callable;

    /**
     * Run this callback when reading this stream will not
     * block.
     *
     * @param resource $stream          The stream to watch
     * @param callable $callback        The callback to invoke
     * @return callable                 Function which will cancel the read listener
     */
    public function onReadable($stream, callable $callback): callable;

    /**
     * Run this callback when writing to this stream will not
     * block.
     *
     * @param resource $stream          The stream to watch
     * @param callable $callback        The callback to invoke
     * @return callable                 Function which will cancel the write listener
     */
    public function onWritable($stream, callable $callback): callable;

    /**
     * Run this callback when the process receives a signal
     *
     * @param int $signal               The signal number to listen for
     * @param callable $callback        The callback to run
     * @return callable                 Function which will uninstall the signal handler
     */
    public function onSignal(int $signal, callable $callback): callable;
}

