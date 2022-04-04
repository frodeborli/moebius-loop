<?php
namespace Moebius\Loop;

use Moebius;
use Closure;

class Callables {
    private bool $destroyed = false;
    private $destructor = null;
    private array $closures = [];
    private int $index = 0;

    public function __destruct() {
        $this->destroy();
    }

    public function setDestructor(callable $destructor): void {
        $this->destructor = Closure::fromCallable($destructor);
    }

    private function destroy(): void {
        if ($this->destroyed) {
            throw new \Exception("Callables destroyed");
        }
        if ($this->destructor === null) {
            throw new \Exception("Destructor not configured");
        }
        $destructor = $this->destructor;
        $this->destroyed = true;
        $this->closures = [];
        $this->destructor = null;
        $destructor();
    }

    public function invoke(): void {
        if ($this->destroyed) {
            throw new \Exception("Callables destroyed");
        }
        foreach ($this->closures as $closure) {
            try {
                $closure();
            } catch (\Throwable $e) {
                Moebius::logException($e);
            }
        }
    }

    public function add(callable $callable): callable {
        if ($this->destroyed) {
            throw new \Exception("Callables destroyed");
        }
        $closure = Closure::fromCallable($callable);
        $index = $this->index++;
        $this->closures[$index] = $closure;
        return function() use ($index) {
            unset($this->closures[$index]);
            if ($this->closures === []) {
                $this->destruct();
            }
        };
    }
}
