<?php
namespace Moebius\Loop;

use Moebius\LoopInterface;
use Closure, WeakReference, WeakMap;
use function get_resource_id;

/**
 * Abstract Moebius\LoopInterface driver class to simplify wrapping other event loop
 * implementations.
 */
abstract class AbstractDriver implements LoopInterface {

    abstract public function defer(callable $microtask): void;

    abstract protected function _drain(callable $doneCallback): void;
    abstract protected function _addReadListener($stream, callable $listener): callable;
    abstract protected function _addWriteListener($stream, callable $listener): callable;
    abstract protected function _addSignalListener(int $signal, callable $listener): callable;
    abstract public function terminate(int $exitCode): void;
    abstract protected function _run(): void;

    public function __construct() {
        $this->subscribers = new WeakMap();
    }

    private array $readListeners = [];
    private array $writeListeners = [];
    private array $signalListeners = [];
    private WeakMap $subscribers;
    private bool $draining = false;
    private int $nextTickerId = 1;
    private array $_managers = [];

    public function drain(callable $doneCallback): void {
        if ($this->isDraining()) {
            throw new \Exception("Loop is already draining, so you should not be trying to drain it... Fix your code!");
        }
        $this->draining = true;
        $this->_drain($doneCallback);
        $this->draining = false;
    }

    public function isDraining(): bool {
        return $this->draining;
    }

    public function run(): void {
        $this->draining = true;
        $this->_run();
        $this->draining = false;
    }

    public function setTimeout(callable $callback, float $delay): callable {
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

    public function onReadable($stream, callable $listener): callable {
        $streamId = get_resource_id($stream);
        if (!isset($this->readListeners[$streamId]) || null === $this->readListeners[$streamId]->get()) {
            $collection = new Callables();
            $this->readListeners[$streamId] = WeakReference::create($collection);
            $unsubscribe = $this->_addReadListener($stream, $collection->invoke(...));
            $collection->setDestructor(function() use ($unsubscribe, $streamId) {
                unset($this->readListeners[$streamId]);
                $unsubscribe();
            });
        } else {
            $collection = $this->readListeners[$streamId]->get();
        }
        return $collection->add($listener);
    }

    public function onWritable($stream, callable $listener): callable {
        $streamId = get_resource_id($stream);
        if (!isset($this->writeListeners[$streamId]) || null === $this->writeListeners[$streamId]->get()) {
            $collection = new Callables();
            $this->writeListeners[$streamId] = WeakReference::create($collection);
            $unsubscribe = $this->_addWriteListener($stream, $collection->invoke(...));
            $collection->setDestructor(function() use ($unsubscribe, $streamId) {
                unset($this->writeListeners[$streamId]);
                $unsubscribe();
            });
        } else {
            $collection = $this->writeListeners[$streamId]->get();
        }
        return $collection->add($listener);
    }

    public function onSignal(int $signalId, callable $listener): callable {
        if (!isset($this->signalListeners[$signalId]) || null === $this->signalListeners[$signalId]->get()) {
            $collection = new Callables();
            $this->signalListeners[$signalId] = WeakReference::create($collection);
            $unsubscribe = $this->_addSignalListener($signalId, $collection->invoke(...));
            $collection->setDestructor(function() use ($unsubscribe, $signalId) {
                unset($this->signalListeners[$signalId]);
                $unsubscribe();
            });
        } else {
            $collection = $this->signalListeners[$signalId]->get();
        }
        return $collection->add($listener);
    }
}
