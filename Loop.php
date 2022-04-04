<?php
namespace Moebius;

use React\EventLoop\Loop as React;
use Amp\Loop as Amp;

/**
 * Provides access to an underlying event loop implementation from React,
 * Amphp or a native event loop if no other implementation is found.
 */
final class Loop {
    private static ?LoopInterface $instance = null;

    /**
     * Runs the event loop until the $doneCallback returns true
     * or the event loop is empty.
     *
     * This function is required to run coroutines, but might
     * not be supported by every third-party loop implementation.
     *
     * @param callable $doneCallback Function that returns true when the event loop has been drained enough
     */
    public static function drain(callable $doneCallback): void {
        self::get()->drain($doneCallback);
    }

    /**
     * Is the loop currently draining?
     *
     * @return bool Is the event loop currently being drained?
     */
    public static function isDraining(): bool {
        return self::get()->isDraining();
    }

    /**
     * Add this callback to the end of the event loop. If the
     * event loop has not been started, ensure it will start.
     */
    public static function defer(callable $callback): void {
        self::get()->defer($callback);
    }

    /**
     * Schedule a callback to run at a later time. Returns a function
     * which can be invoked to prevent the callback.
     *
     * @param callable $callback Function to run on timeout
     * @param float $delay Number of seconds to delay execution
     * @return callable Cancel function
     */
    public static function setTimeout(callable $callback, float $timeout): callable {
        return self::get()->setTimeout($callback, $timeout);
    }

    /**
     * Schedule a callback to run at regular intervals. Returns a function
     * which can be invoked to cancel the interval.
     *
     * @param callable $callback Function to run on timeout
     * @param float $interval Delay between each execution
     * @return callable Cancel function
     */
    public static function setInterval(callable $callback, float $interval): callable {
        return self::get()->setInterval($callback, $interval);
    }

    /**
     * Run this callback when reading this stream will not
     * block.
     *
     * @param resource $stream          The stream to watch
     * @param callable $callback        The callback to invoke
     * @return callable                 Function which will cancel the read listener
     */
    public static function onReadable($stream, $callback): callable {
        return self::get()->onReadable($stream, $callback);
    }

    /**
     * Run this callback when writing to this stream will not
     * block.
     *
     * @param resource $stream          The stream to watch
     * @param callable $callback        The callback to invoke
     * @return callable                 Function which will cancel the write listener
     */
    public static function onWritable($stream, $callback): callable {
        return self::get()->onWritable($stream, $callback);
    }

    /**
     * Run this callback when the process receives a signal
     *
     * @param int $signal               The signal number to listen for
     * @param callable $callback        The callback to run
     * @return callable                 Function which will uninstall the signal handler
     */
    public static function onSignal(int $signalNumber, $callback): callable {
        return self::get()->onSignal($signalNumber, $callback);
    }

    /**
     * Get the underlying event loop implementation
     *
     * @return LoopInterface
     */
    private static function get(): LoopInterface {
        if (self::$instance === null) {
            self::discoverEventLoopImplementation();
        }

        return self::$instance;
    }

    public static function logException(\Throwable $e): void {
        fwrite(STDERR, gmdate('Y-m-d H:i:s')." ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\n".$e->getTraceAsString()."\n");
    }

    /**
     * Discover any alternative event loop implementations we can run
     */
    private static function discoverEventLoopImplementation(): void {
        if (class_exists(React::class)) {
            self::$instance = new Loop\ReactDriver();
        } elseif (class_exists(Amp::class)) {
            self::$instance = new Loop\AmpDriver();
        } else {
            self::$instance = new Loop\NativeDriver();
        }
    }

}
