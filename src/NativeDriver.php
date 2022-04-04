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

    public function __construct() {
        pcntl_async_signals(true);
        register_shutdown_function($this->shutdownHandler(...));
    }

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
        $this->draining = true;
        try {
            do {
                $count = $this->tick();
            } while (!$doneCallback() && $count > 0);
            $this->draining = false;
        } catch (\Throwable $e) {
            $this->draining = false;
            throw $e;
        }
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

        $counter = 0;
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
            }
        }
        $tickTime = (1000000 * (microtime(true) - $startTime)) | 0;
        return $counter;
    }

    /**
     * Function is invoked when all standard PHP code has finished running.
     */
    private function shutdownHandler(): void {
        // The event loop self activates on shutdown
        $this->draining = true;

        $count = $this->tick();
        if ($count > 0) {
            register_shutdown_function($this->shutdownHandler(...));
        } else {
            $this->draining = false;
        }
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
        $this->enableStreamSelect();
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
        $this->enableStreamSelect();
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

    private function enableStreamSelect(): void {
        if (!$this->isStreamSelectScheduled) {
            $this->isStreamSelectScheduled = true;
            $this->defer($this->doStreamSelect(...));
        }
    }

    /**
     * Function which is scheduled to run in the event loop as long as there are
     * streams that need to be monitored for events.
     */
    private function doStreamSelect(): void {
        if (!$this->isStreamSelectScheduled) {
            // The stream select is no longer scheduled so we abort
            return;
        }
        $this->isStreamSelectScheduled = false;

        $exceptStreams = [];

        $readStreams = [];
        foreach ($this->readableStreamListeners as $streamId => $info) {
            if (!is_resource($info[0])) {
                unset($this->readableStreamListeners[$streamId]);
                continue;
            }
            $readStreams[] = $info[0];
        }

        $writeStreams = [];
        foreach ($this->writableStreamListeners as $streamId => $info) {
            if (!is_resource($info[0])) {
                unset($this->writableStreamListeners[$streamId]);
                continue;
            }
            $writeStreams[] = $info[0];
        }

        if (count($writeStreams) === 0 && count($readStreams) === 0 && count($exceptStreams) === 0) {
            // we have no streams to monitor
            return;
        }

        if ($this->queueHigh - $this->queueLow === 0) {
            // nothing in the work queue, so there is no hurry
            $sleepTime = 100000;
            $this->nextSleepTime = microtime(true) + 0.1;
        } elseif ($this->nextSleepTime < microtime(true)) {
            // scheduled sleep as safety measure against busy loops
            $sleepTime = 50000;
            $this->nextSleepTime = microtime(true) + 0.05;
        } else {
            // have enough to do, so sleep minimally
            $sleepTime = 0;
        }
        $matches = stream_select($readStreams, $writeStreams, $exceptStreams, 0, $sleepTime);

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

        // re-enable stream select callback since we still have streams to monitor
        $this->enableStreamSelect();
    }

    /**
     * Do we currently have a stream_select() callback scheduled?
     */
    private bool $isStreamSelectScheduled = false;

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
