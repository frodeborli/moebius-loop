<?php
namespace Moebius\Loop;

use Amp\Loop;

/**
 * Moebius\LoopInterface driver for Amp
 */
class AmpDriver extends AbstractDriver {

    public function __construct() {
        register_shutdown_function(Loop::run(...));
    }

    public function terminate(): void {
        Loop::stop();
    }

    public function defer(callable $microtask): void {
        Loop::defer($microtask);
    }

    protected function _drain(callable $doneCallback): void {
        Loop::defer($stopper = function() use ($doneCallback, &$stopper) {
            if ($doneCallback()) {
                Loop::stop();
            } else {
                Loop::defer($stopper);
            }
        });
        Loop::run();
    }

    protected function _run(): void {
        Loop::run();
    }

    protected function _addReadListener($stream, callable $listener): callable {
        $id = Loop::onReadable($stream, $listener);
        return function() use ($id) {
            Loop::cancel($id);
        };
    }

    protected function _addWriteListener($stream, callable $listener): callable {
        $id = Loop::onWritable($stream, $listener);
        return function() use ($id) {
            Loop::cancel($id);
        };
    }

    protected function _addSignalListener(int $signal, callable $listener): callable {
        $id = Loop::onSignal($signal, $listener);
        return function() use ($id) {
            Loop::cancel($id);
        };
    }
}
