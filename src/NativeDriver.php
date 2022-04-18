<?php
namespace Moebius\Loop;

use Moebius\Loop;
use Moebius\LoopInterface;
use Closure;
use Fiber;
use const SIG_DFL;
use function pcntl_signal, pcntl_async_signals, array_filter, count, stream_select;
use function register_shutdown_function, get_resource_id;

/**
 * Abstract class providing the stream based functionality
 * for a simple event loop, used when there are no other
 * event loop implementation available.
 */
class NativeDriver implements LoopInterface {

    public bool $debug = false;
    private int $tickCount = 0;

    public function __construct() {
        pcntl_async_signals(true);
        if (getenv('DEBUG')) {
            $this->debug = true;
        }

        //register_shutdown_function($this->shutdownHandler(...));
    }

    /**
     * Stop the event loop. This is generally called because of an error
     * condition. The application should ideally stop by removing all
     * event listeners, timers and stream watchers.
     */
    public function terminate(int $exitCode): void {
        $this->terminating = true;
        foreach ($this->signalHandlers as $signo => $void) {
            pcntl_signal($signo, SIG_DFL);
        }
    }

    /**
     * Once this is true, the program is in the process of terminating.
     * No further ticks will be executed.
     */
    private bool $terminating = false;

    /**
     * All callbacks scheduled to run
     */
    private array $queue = [];

    /**
     * Record the index of the next tick function in the queue,
     * much faster than using array_shift.
     */
    private int $queueLow = 0;

    /**
     * Record the index of the next unused tick function offset
     * in the queue, much faster than using array_shift.
     */
    private int $queueHigh = 0;

    /**
     * Allow busy loop until the next sleep time
     */
    private float $nextSleepTime = 0;

    /**
     * Is the event loop going to run until it is empty?
     */
    private bool $draining = false;

    /**
     * Runs the event loop until the $doneCallback returns true.
     */
    public function drain(callable $doneCallback): void {
        if ($this->isDraining()) {
            throw new \Exception("Loop is already draining, so you should not be trying to drain it... Fix your code!");
        }
        $total = count($this->readableStreamListeners) + count($this->writableStreamListeners) + ($this->queueHigh - $this->queueLow);
        if ($total === 0) {
            throw new \Exception("Trying to drain empty event loop");
        }
        $this->draining = true;
        do {
            $count = $this->tick();
        } while (!$doneCallback() && $count > 0);
        $this->draining = false;
    }

    /**
     * Run event loop until empty
     */
    public function run(): void {
        $this->draining = true;
        while ($this->tick() > 0);
        $this->draining = false;
    }

    public function isDraining(): bool {
        return $this->draining;
    }


    /**
     * Schedule a callback function to run at the first opportunity
     */
    public function defer(callable $callback): void {
        $this->queue[$this->queueHigh++] = Closure::fromCallable($callback);
    }

    /**
     * Schedule a callback to run at a later time. Returns a function
     * which can be invoked to prevent the callback.
     *
     * @param callable $callback Function to run on timeout
     * @param float $delay Number of seconds to delay execution
     * @return callable Cancel function
     */
    public function setTimeout(callable $callback, float $timeout): callable {
        if ($timeout <= 0) {
            throw new \Exception("Timeout must be greater than 0");
        }
        $cancelled = false;
        $timeout += microtime(true);
        $this->defer($ticker = function() use ($callback, &$cancelled, &$ticker, $timeout) {
            if ($cancelled) {
                return;
            }
            if ($timeout < microtime(true)) {
                $callback();
            } else {
                $this->defer($ticker);
            }
        });
        return function() use (&$cancelled) {
            $cancelled = true;
        };
    }

    /**
     * Schedule a callback to run at regular intervals. Returns a function
     * which can be invoked to cancel the interval.
     *
     * @param callable $callback Function to run on timeout
     * @param float $interval Delay between each execution
     * @return callable Cancel function
     */
    public function setInterval(callable $callback, float $interval): callable {
        if ($interval <= 0) {
            throw new \Exception("Interval must be greater than 0");
        }
        $cancelled = false;
        $timeout = microtime(true) + $interval;
        $this->defer($ticker = function() use ($callback, $interval, &$cancelled, &$ticker, &$timeout) {
            if ($cancelled) {
                return;
            }
            if ($timeout < microtime(true)) {
                $timeout += $interval;
                $callback();
            }
            $this->defer($ticker);
        });
        return function() use (&$cancelled) {
            $cancelled = true;
        };
    }

    /**
     * Run one iteration of the tick functions we currently have in the queue.
     */
    private function tick(): int {
        if (Fiber::getCurrent()) {
            throw new \Exception("Tick invoked from inside a fiber");
        }

        $this->tickCount++;

        if ($this->debug) {
            echo "\rmoebius/loop: #".$this->tickCount." queue=".($this->queueHigh-$this->queueLow)." readers=".count($this->readableStreamListeners)." writers=".count($this->writableStreamListeners)." terminating=".($this->terminating?1:0)."\n";
        }

        if ($this->terminating) {
            return 0;
        }

        $counter = $this->doStreamSelect();
        if ($counter === 0) {
            usleep(10000);
        }

        $stopIndex = $this->queueHigh;

        $startTime = microtime(true);
        while ($this->queueLow < $stopIndex) {
            $callback = $this->queue[$this->queueLow];
            unset($this->queue[$this->queueLow++]);
            $counter++;
            try {
                $callback();
            } catch (\Throwable $e) {
                Loop::logException($e);
                Loop::log('fatal', 'Shutting down due to unhandled exception in tick function');
                $this->terminate();
            }
        }

        return $counter;
    }

    /**
     * Function is invoked when all standard PHP code has finished running.
     */
    private function shutdownHandler(): void {
        /**
         * This shutdown logic is borrowed from the ReactPHP event loop implementation
         */
        $error = error_get_last();
        if ((isset($error['type']) ? $error['type'] : 0) & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
            var_dump($error);
            return;
        }

        if ($this->terminating) {
            return;
        }

        // The event loop self activates on shutdown
/*
        $this->draining = true;

        $count = $this->tick();
        if ($count > 0) {
echo "register_shutdown 1\n";
            register_shutdown_function($this->shutdownHandler(...));
        } else {
            $this->draining = false;
        }
*/
    }

    /**
     * Function is invoked whenever we receive a signal which is monitored
     */
    private function signalHandler(int $signo, mixed $siginfo): void {
        if (!isset($this->signalHandlers[$signo])) {
            return;
        }
        $handlers = $this->signalHandlers[$signo];
        foreach ($handlers as $handler) {
            try {
                $handler($signo, $siginfo);
            } catch (\Throwable $error) {
                Loop::logException($error);
                Loop::log('fatal', 'Shutting down due to unhandled exception in signal handler function');
                $this->terminate();
            }
        }
    }

    /**
     * Run this callback when reading this stream will not
     * block.
     *
     * @param resource $stream   The callback to invoke
     * @param callable $callback        The callback to invoke
     */
    public function onReadable($stream, callable $callback): callable {

        $streamId = get_resource_id($stream);
        if (isset($this->readableStreamListeners[$streamId])) {
            $this->readableStreamListeners[$streamId][1][] = $callback;
        } else {
            $this->readableStreamListeners[$streamId] = [ $stream, [ $callback ] ];
        }
        return function() use ($stream, $callback) {
            $this->removeReadableHandler($stream, $callback);
        };
    }

    private function removeReadableHandler($stream, callable $callback): void {
        $streamId = get_resource_id($stream);
        if (!isset($this->readableStreamListeners[$streamId])) {
            return;
        }
        $removedCount = 0;
        $this->readableStreamListeners[$streamId][1] = array_filter($this->readableStreamListeners[$streamId][1], function($existing) use ($callback, &$removedCount) {
            if ($callback === $existing) {
                $removedCount++;
            }
            return $callback !== $existing;
        });
        if ($removedCount === 0) {
            throw new \Exception("No callback was removed");
        }
        if (count($this->readableStreamListeners[$streamId][1]) === 0) {
            unset($this->readableStreamListeners[$streamId]);
        }
    }

    /**
     * Run this callback when writing to this stream will not
     * block.
     *
     * @param resource $stream   The callback to invoke
     * @param callable $callback        The callback to invoke
     */
    public function onWritable($stream, callable $callback): callable {
        $streamId = get_resource_id($stream);
        if (isset($this->writableStreamListeners[$streamId])) {
            $this->writableStreamListeners[$streamId][1][] = $callback;
        } else {
            $this->writableStreamListeners[$streamId] = [ $stream, [ $callback ] ];
        }
        return function() use ($stream, $callback) {
            $this->removeWritableHandler($stream, $callback);
        };
    }

    private function removeWritableHandler($stream, callable $callback): void {
        $streamId = get_resource_id($stream);
        if (!isset($this->writableStreamListeners[$streamId])) {
            return;
        }
        $removedCount = 0;
        $this->writableStreamListeners[$streamId][1] = array_filter($this->writableStreamListeners[$streamId][1], function($existing) use ($callback, &$removedCount) {
            if ($callback === $existing) {
                $removedCount++;
                return false;
            }
            return true;
        });
        if ($removedCount === 0) {
            throw new \Exception("No callback was removed");
        }
        if (count($this->writableStreamListeners[$streamId][1]) === 0) {
            unset($this->writableStreamListeners[$streamId]);
        }
    }

    /**
     * Run this callback when the process receives a signal
     *
     * @param int $signal           The signal number to listen for
     * @param callable $callback    The callback to run
     */
    public function onSignal(int $signal, callable $callback): callable {
        if (!isset($this->signalHandlers[$signal])) {
            pcntl_signal($signal, $this->signalHandler(...));
        }
        $this->signalHandlers[$signal][] = $callback;
        return function() use ($signal, $callback) {
            $this->removeSignalHandler($signal, $callback);
        };
    }

    /**
     *
     * @param int $signal           The signal number to listen for
     * @param callable $callback    The callback to run
     * Remove a signal handler
     */
    private function removeSignalHandler(int $signal, callable $callback): void {
        if (!isset($this->signalHandlers[$signal])) {
            return;
        }
        $this->signalHandlers[$signal] = array_filter($this->signalHandlers[$signal], function($existing) use ($callback) {
            return $existing !== $callback;
        });
        if (count($this->signalHandlers[$signal]) === 0) {
            pcntl_signal($signal, SIG_DFL);
        }
    }

    /**
     * Function which is scheduled to run in the event loop as long as there are
     * streams that need to be monitored for events.
     */
    private function doStreamSelect(): int {
        // holds any file descriptors with id < 1024
        $readStreams = [];
        $writeStreams = [];
        $exceptStreams = [];

        // total count of streams we're doing select on
        $count = 0;

        foreach ($this->readableStreamListeners as $streamId => $info) {
            if (!is_resource($info[0])) {
                unset($this->readableStreamListeners[$streamId]);
                continue;
            }

            $count++;
            $readStreams[] = $info[0];
            $exceptStreams[] = $info[0];
        }

        foreach ($this->writableStreamListeners as $streamId => $info) {
            if (!is_resource($info[0])) {
                unset($this->writableStreamListeners[$streamId]);
                continue;
            }
            $count++;
            $writeStreams[] = $info[0];
            $exceptStreams[] = $info[0];
        }

        if ($count === 0) {
            // there are no streams to monitor
            return 0;
        }

        if ($this->queueHigh - $this->queueLow === 0) {
            // nothing in the work queue, so there is no hurry
            $sleepTime = 50000;
            $this->nextSleepTime = microtime(true) + 0.1;
        } elseif ($this->nextSleepTime < microtime(true)) {
            // scheduled sleep as safety measure against busy loops
            $sleepTime = 10000;
            $this->nextSleepTime = microtime(true) + 0.05;
        } else {
            // have enough to do, so sleep minimally
            $sleepTime = 0;
        }

        try {
            $oReadStreams = $readStreams;
            $oWriteStreams = $writeStreams;
            $matches = stream_select($readStreams, $writeStreams, $exceptStreams, 0, $sleepTime);
            if ($this->debug) {
                echo " - stream_select readable=".count($readStreams)." write=".count($writeStreams)." except=".count($exceptStreams)."\n";
            }
        } catch (\ErrorException $e) {
            if ($e->getCode() === 2) {
                throw new StreamSelectError("Too many connections. stream_select() does not support file descriptors ids above 1023. Consider using another event loop implementation (for example from React)");

                /**
                 * Below is an attempt to implement a fallback stream_select() which scans through all
                 * the streams. It seems fairly fast, but I couldn't find a way to safely detect if a stream
                 * would block.
                 *
                 * Perhaps something can be done via pcntl_fork then pcntl_exec - since file descriptors will
                 * survive such a fork.
                 */
                $readStreams = $oReadStreams;
                $writeStreams = $oWriteStreams;
                $t = microtime(true);
                foreach ($readStreams as $streamId => $readStream) {
                    $meta = stream_get_meta_data($readStream);
                    $willBlock = true;
                    if ($meta['unread_bytes'] > 0) {
                        $willBlock = false;
                    } elseif (!$meta['eof']) {
                        $willBlock = false;
                    }
                    if ($willBlock) {
                        unset($readStreams[$streamId]);
                    } else {
                        stream_set_blocking($readStream, false);
                    }
                }

                foreach ($writeStreams as $streamId => $readStream) {
                    $meta = stream_get_meta_data($readStream);
                    $willBlock = true;
                    if ($meta['unread_bytes'] === 0) {
                        $willBlock = false;
                    }
                    if ($willBlock) {
                        unset($writeStreams[$streamId]);
                    } else {
                        stream_set_blocking($writeStream, false);
                    }
                }
            } else {
                throw $e;
            }
        }

        if ($exceptStreams !== []) {
            echo "exception on ".count($exceptStreams)." streams\n";
        }

        foreach ($readStreams as $stream) {
            $streamId = get_resource_id($stream);
            foreach ($this->readableStreamListeners[$streamId][1] as $callback) {
                $this->defer($callback);
            }
        }

        foreach ($writeStreams as $stream) {
            $streamId = get_resource_id($stream);
            foreach ($this->writableStreamListeners[$streamId][1] as $callback) {
                $this->defer($callback);
            }
        }
        return $count;
    }

    /**
     * Map of streams to callbacks that needs notification when the stream
     * becomes readable.
     *
     * @type SplObjectStorage<resource, array<callable>>
     */
    private array $readableStreamListeners = [];

    /**
     * Map of streams to callbacks that needs notification when the stream
     * becomes writable.
     *
     * @type SplObjectStorage<resource, array<callable>>
     */
    private array $writableStreamListeners = [];

    /**
     * Map of signals number to arrays of callbacks which will be invoked when
     * the process receives a signal.
     */
    private array $signalHandlers = [];
}
