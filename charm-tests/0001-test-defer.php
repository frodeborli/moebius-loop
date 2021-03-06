<?php
require(__DIR__.'/../vendor/autoload.php');

Moebius\Loop::defer(function() {
    echo "This function was deferred\n";
});

$c2 = Moebius\Loop::setTimeout(function() {
    echo "2 second later should not happen\n";
}, 0.2);

Moebius\Loop::setTimeout(function() use ($c2) {
    echo "1 second later\n";
    $c2();
}, 0.1);

$c3 = Moebius\Loop::setInterval(function() {
    echo "Every 0.5 second\n";
}, 0.05);

Moebius\Loop::setTimeout(function() use ($c3) {
    $c3();
}, 0.25);

echo "Final function\n";
