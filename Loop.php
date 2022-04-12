<?php
namespace Moebius;


use React\EventLoop\Loop as React;
use Amp\Loop as Amp;
use Moebius\Loop\ShutdownDetectedException;
use Moebius\Loop\InvalidTerminationException;
use Charm\Event\{
    StaticEventEmitterInterface,
    StaticEventEmitterTrait
};

/**
 * Provides access to an underlying event loop implementation from React,
 * Amphp or a native event loop if no other implementation is found.
 */
final class Loop implements StaticEventEmitterInterface {
    use StaticEventEmitterTrait;

    /**
     * How much time can he application run after a signal or termination
     * request has been received in seconds.
     *
     * @var float
     */
    public static float $gracePeriod = 5;

    /**
     * Event dispatched immediately before a new state
     */
    const ON_EXIT_STATE = 'EXIT_STATE';

    /**
     * Event dispatched immediately after a new state was entered
     */
    const ON_ENTER_STATE = 'ENTER_STATE';

    /**
     * Phase where no events have been run.
     */
    const STATE_NEW = 'STATE_NEW';

    /**
     * Phase where the event loop will be running ticks in between synchronous
     * code.
     *
     * In this phase errors and exceptions will fall through to the application.
     */
    const STATE_LAUNCHING = 'STATE_LAUNCHING';

    /**
     * Phase where the event loop will be running until there are no more
     * events in the queue.
     *
     * In this phase, errors and exceptions will be captured and trigger a transition
     * to the failed state.
     */
    const STATE_RUNNING = 'STATE_RUNNING';

    /**
     * State when the event loop has finished
     */
    const STATE_FAILED = 'STATE_FAILED';

    /**
     * State when the event loop is terminating because of
     * a signal such as CTRL+C
     */
    const STATE_SIGNALED = 'STATE_SIGNALED';

    /**
     * State when the event loop finishes normally
     */
    const STATE_DONE = 'STATE_DONE';

    /**
     * See {self::STATE_?}
     */
    private static string $state = self::STATE_NEW;

    /**
     * Grace period timeout
     */
    private static float $graceTimeout = 0;

    /**
     * If an unhandled error occurred, it will be recorded here
     */
    private static ?\Throwable $fatalException = null;
    private static ?LoopInterface $instance = null;

    private static function setState(string $state): void {
        switch (self::$state) {
            case self::STATE_NEW:
                if (!in_array($state, [ self::STATE_LAUNCHING, self::STATE_RUNNING, self::STATE_FAILED, self::STATE_SIGNALED ])) {
                    goto illegal_state_transition;
                }
                break;
            case self::STATE_LAUNCHING:
                if (!in_array($state, [ self::STATE_RUNNING, self::STATE_FAILED, self::STATE_SIGNALED ])) {
                    goto illegal_state_transition;
                }
                break;
            case self::STATE_RUNNING:
                if (!in_array($state, [ self::STATE_FAILED, self::STATE_DONE, self::STATE_SIGNALED])) {
                    goto illegal_state_transition;
                }
                break;
            case self::STATE_FAILED:
                if ($state !== self::STATE_SIGNALED) {
                    goto illegal_state_transition;
                }
                break;
            case self::STATE_DONE:
                if ($state !== self::STATE_SIGNALED) {
                    goto illegal_state_transition;
                }
                break;
            case self::STATE_SIGNALED:
                goto illegal_state_transition;
                break;
            default:
                throw new \Exception("Unknown current state ".self::$state);
        }

        self::events()->emit(self::ON_EXIT_STATE, (object) [ "from" => self::$state, "to" => $state ], false);
        $previousState = self::$state;
        self::$state = $state;
        self::events()->emit(self::ON_ENTER_STATE, (object) [ "from" => $previousState, "to" => $state ], false);
        return;
        illegal_state_transition: throw new \Exception("Illegal state transition from ".self::$state." to $state");
    }

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
        if (self::$state === self::STATE_NEW) {
            self::setState(self::STATE_LAUNCHING);
        } elseif (self::$state === self::STATE_FAILED) {
            throw new \Exception("Loop is in a failed state");
        } elseif (self::$state === self::STATE_DONE) {
            throw new \Exception("Application has finished.");
        }

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
     * Enqueue a callback in the event loop. If the event loop has not been
     * started, it will be started as soon as all non-blocking code has
     * finished.
     */
    public static function defer(callable $callback): void {
        self::assertCanEnqueue();
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
        self::assertCanEnqueue();
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
        self::assertCanEnqueue();
        return self::get()->setInterval($callback, $interval);
    }

    /**
     * Run a callback when reading this stream will not
     * block.
     *
     * @param resource $stream          The stream to watch
     * @param callable $callback        The callback to invoke
     * @return callable                 Function which will cancel the read listener
     */
    public static function onReadable($stream, $callback): callable {
        self::assertCanEnqueue();
        return self::get()->onReadable($stream, $callback);
    }

    /**
     * Run a callback when writing to this stream will not
     * block.
     *
     * @param resource $stream          The stream to watch
     * @param callable $callback        The callback to invoke
     * @return callable                 Function which will cancel the write listener
     */
    public static function onWritable($stream, $callback): callable {
        self::assertCanEnqueue();
        return self::get()->onWritable($stream, $callback);
    }

    /**
     * Run a callback when the process receives a signal
     *
     * @param int $signal               The signal number to listen for
     * @param callable $callback        The callback to run
     * @return callable                 Function which will uninstall the signal handler
     */
    public static function onSignal(int $signalNumber, $callback): callable {
        self::assertCanEnqueue();
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
        /**
         * Install signal handler which will run during the RUNNING state
         * to handle shutdown.
         */
        self::events()->on(self::ON_ENTER_STATE, function($e) {
            static $sigintCancel = null, $sigtermCancel = null;

            if ($sigintCancel) {
                $sigintCancel();
                $sigintCancel = null;
            }
            if ($sigtermCancel) {
                $sigtermCancel();
                $sigtermCancel = null;
            }

            if ($e->to === self::STATE_RUNNING) {
                $sigintCancel = self::onSignal(\SIGINT, self::exitSignalHandler(...));
                $sigtermCancel = self::onSignal(\SIGTERM, self::exitSignalHandler(...));
            }
        });

        register_shutdown_function(self::shutdownHandler(...));
    }

    private static function exitSignalHandler(int $signal) {
        if (self::$state !== self::STATE_SIGNALED) {
            self::$graceTimeout = microtime(true) + self::$gracePeriod;
            self::setState(self::STATE_SIGNALED, [
                'signal' => $signal,
                'graceTimeout' => self::$graceTimeout,
                'gracePeriod' => self::$gracePeriod,
            ]);
        }
    }

    private static function errorHandler(int $errorNumber, string $errorString, string $errorFile=null, int $errorLine) {
        if (!(error_reporting() & $errorNumber)) {
            return false;
        }
        throw new \ErrorException($errorString, $errorNumber, E_ERROR, $errorFile, $errorLine);
    }

    private static function exceptionHandler(\Throwable $e) {
        self::logException($e);
        self::$fatalException = $e;
    }

    private static function shutdownHandler() {
        self::setState(self::STATE_RUNNING);

        try {
            self::get()->run();
            self::setState(self::STATE_DONE);
        } catch (\Throwable $e) {
            self::logException($e);
            self::setState(self::STATE_FAILED);
        }
    }

    public static function logException(\Throwable $e): void {
        self::log('error', get_class($e).' code='.$e->getCode().' '.$e->getMessage().' '.$e->getTraceAsString());
    }

    public static function log(string $severity, string $message): void {
        fwrite(STDERR, date('Y-m-d H:i:s').' '.$severity.' '.$message."\n");
    }

    private static function canEnqueue(): bool {
        if (microtime(true) < self::$graceTimeout) {
            return true;
        }
        return self::$state !== self::STATE_FAILED && self::$state !== self::STATE_DONE;
    }

    private static function assertCanEnqueue(): void {
        if (self::canEnqueue()) {
            return;
        }
        if (self::$state === self::STATE_DONE) {
            throw new \Exception("The application has finished. Can't enqueue.");
        }
        if (self::$state === self::STATE_FAILED) {
            throw new \Exception("The application is in a failed state. Can't enqueue.");
        }
        if (self::$state === self::STATE_SIGNALED) {
            throw new \Exception("The application has received a termination request. Can't enqueue currently.");
        }
        throw new \Exception("The application is unable to enqueue tasks.");
    }
}

