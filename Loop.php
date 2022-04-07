<?php
namespace Moebius;

use React\EventLoop\Loop as React;
use Amp\Loop as Amp;
use Moebius\Loop\ShutdownDetectedException;
use Moebius\Loop\InvalidTerminationException;

/**
 * Provides access to an underlying event loop implementation from React,
 * Amphp or a native event loop if no other implementation is found.
 */
final class Loop {

    /**
     * State when we're simply running the events during normal program
     * execution (before any fatal errors or exit() or die() being called)
     */
    const STATE_LAUNCHING = 'launching';

    /**
     * State when the event loop runs normally until there are no more
     * event listeners.
     */
    const STATE_FINISHING = 'finishing';

    /**
     * State when the event loop has finished
     */
    const STATE_FAILED = 'failed';

    /**
     * State when the event loop finishes normally
     */
    const STATE_DONE = 'done';

    /**
     * See {self::STATE_?}
     */
    private static string $state = self::STATE_LAUNCHING;

    /**
     * If an unhandled error occurred, it will be recorded here
     */
    private static ?\Throwable $fatalException = null;

    private static ?LoopInterface $instance = null;

    /**
     * If NULL, we're not shutting down. If true, we've detected a normal
     * shutdown and the event loop will begin draining. If false, we detected
     * an exit() or die() being called prematurely.
     */
    private static ?bool $normalShutdown = null;

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
            self::bootstrap();
            self::discoverEventLoopImplementation();
        }

        return self::$instance;
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

    private static function bootstrap(): void {
        set_error_handler(function(int $errorNumber, string $errorString, string $errorFile=null, int $errorLine): void {
            if (!(error_reporting() & $errorNumber)) {
                return false;
            }
            throw new \ErrorException($errorString, $errorNumber, E_ERROR, $errorFile, $errorLine);
        });

        set_exception_handler(function(\Throwable $e) {
            self::logException($e);
            self::$fatalException = $e;
        });

        register_shutdown_function(self::shutdownHandler(...));
    }

    private static function shutdownHandler() {
        if (self::$state === self::STATE_LAUNCHING) {
            /**
             * This may have been a fatal error condition, or it is simply
             * a normal scenario.
             */
            if (self::$fatalException !== null) {
                Loop::log('fatal', 'Shutting down due to unhandled '.get_class(self::$fatalException));
                self::$state = self::STATE_FAILED;
            } elseif (self::isDraining()) {
                Loop::log('fatal', 'Shutting down because die() or exit() was called inside event loop');
                self::$fatalException = new InvalidTerminationException();
                self::$state = self::STATE_FAILED;
            } else {
                // everything seems fine, proceed
                self::$state = self::STATE_FINISHING;
                try {
                    self::get()->run();
                    self::$state = self::STATE_DONE;
                } catch (\Throwable $e) {
                    self::logException($e);
                } finally {
                    if (self::$state !== self::STATE_DONE) {
                        self::$state = self::STATE_FAILED;
                    }
                }
            }
        } else {
            die("shutdown handler in state ".self::$state."\n");
        }
    }

    private static function runShutdownTicks() {
        register_shutdown_function(self::shutdownHandler(...)); //shutdownHandler(...));
        self::drain(function() {
            return true;
        });
    }

    public static function logException(\Throwable $e): void {
        self::log('error', get_class($e).' code='.$e->getCode().' '.$e->getMessage());
    }

    public static function log(string $severity, string $message): void {
        fwrite(STDERR, date('Y-m-d H:i:s').' '.$severity.' '.$message."\n");
    }
}
