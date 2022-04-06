<?php
namespace Moebius\Loop;

class ShutdownDetectedException extends \Exception {
    public function __construct() {
        parent::__construct("Detected call to kill() or die() in coroutine");
    }
}
