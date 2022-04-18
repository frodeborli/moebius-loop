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
        if (!$this->destroyed) {
            $this->destroy();
        }
    }

    public function setDestructor(callable $destructor): void {
        $this->destructor = Closure::fromCallable($destructor);
    }

    private function destroy(): void {
        if ($this->destroyed) {
            throw new \Exception("Callables already destroyed");
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

    public function invoke(mixed ...$args): void {
        if ($this->destroyed) {
            throw new \Exception("Callables destroyed");
        }
        foreach ($this->closures as $closure) {
            try {
                $closure(...$args);
            } catch (\Throwable $e) {
var_dump($e);die();
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
                $this->destroy();
            }
        };
    }
}
