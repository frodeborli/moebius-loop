<?php
namespace Moebius\Loop;

use React\EventLoop\Loop;

/**
 * Moebius\LoopInterface driver for ReactPHP
 */
class ReactDriver extends AbstractDriver {

    public function __construct() {
        register_shutdown_function(Loop::run(...));
    }

    public function defer(callable $microtask): void {
        Loop::futureTick($microtask);
    }

    protected function _drain(callable $doneCallback): void {
        Loop::futureTick($stopper = function() use ($doneCallback, &$stopper) {
            if ($doneCallback()) {
                Loop::stop();
            } else {
                Loop::futureTick($stopper);
            }
        });
        Loop::run();
    }

    protected function _addReadListener($stream, callable $listener): callable {
        Loop::addReadStream($stream, $listener);
        return function() use ($stream) {
            Loop::removeReadStream($stream);
        };
    }

    protected function _addWriteListener($stream, callable $listener): callable {
        Loop::addWriteStream($stream, $listener);
        return function() use ($stream) {
            Loop::removeWriteStream($stream);
        };
    }

    protected function _addSignalListener(int $signal, callable $listener): callable {
        Loop::addSignal($signal, $listener);
        return function() use ($signal, $listener) {
            Loop::removeSignal($signal, $listener);
        };
    }
}
