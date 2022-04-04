moebius/loop
============

A powerful and simple event loop implementation which works on top of either
`react/event-loop` or `amphp/amp`. If neither of those event loop implementations,
are installed in your project, the built in event-loop will be used.

The purpose of this package is to enable `moebius/coroutine` to be used in any
existing async project.

In other words, the goal is to enable writing your code in a truly sequential
style:

```
$server = new HttpServer(function (ServerRequestInterface $request) {

    sleep(20); // YES, this is non-blocking code(!)
    return Response::plaintext("Hello World!\n");

});
```


Use case
--------

If you are building components that need an event loop, you can use `moebius/loop`
as a translation layer, and your component will happily run in either environment.


Drivers
-------

If you have any of the supported event-loop implementations installed in your project,
`moebius/loop` will automatically use those implementations.

Currently we have implemented drivers for:

 * `react/event-loop`
 * `amphp/amp`

If none of these event loop implementations are installed, the native event loop
based on `stream_select()` will be used.


Virtualization bridges
----------------------

While you can easily use an existing event loop driver, it is also possible to
add a compatability layer on top of `moebius/loop`.

This effectively means that you can run async components from Amp or React in the
same project by installing a "loop bridge" component.

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
