moebius/loop
============

A unified event loop API which works with React and Amp event loops. If neither
of the event loops are installed, a native event loop implementation will be used.

Other event loop implementations
--------------------------------

This event loop implementation will automatically use other popular event loop
implementations if it detects that they are installed. It will check for
implementations in this order:

 1. react/event-loop
 2. amphp/amp

If no implementations are found, it will use a built-in event loop implementation.

Compatability bridges
---------------------

If you want to run amphp and ReactPHP libraries, you can install event loop compatability
layers:

 * `composer require moebius/loop-reactbridge` will allow ReactPHP libraries to run
   on the moebius/loop.

Other bridge implementations will be added at a later stage.


API overview
------------

Run a function on the next event loop tick.

```
Moebius\Loop::defer(callable $callable): void;
```

Schedule a function after a timeout. The function returned can be used to cancel
the timer.

```
Moebius\Loop::setTimeout(callable $callable, float $seconds): callable;
```

Schedule a function to run at a particular interval. The function returned can
be used to cancel the interval.

```
Moebius\Loop::setInterval(callable $callable, float $interval): callable;
```

Listen for whenever a stream is readable. The function returned can be used to
uninstall the listener.

```
Moebius\Loop::onReadable(resource $stream, callable $callable): callable;
```

Listen for whenever a stream is writable. The function returned can be used to
uninstall the listener.

```
Moebius\Loop::onWritable(resource $stream, callable $callable): callable;
```

Listen for a signal. Multiple signal handlers can be installed on any one signal number.
The function returned can be used to uninstall the listener.

```
Moebius\Loop::onSignal(int $signalNumber, callable $callable): callable;
```
