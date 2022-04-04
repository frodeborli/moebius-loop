<?php
namespace Moebius\Loop\Utils;

use ArrayAccess;
use WeakMap;

/**
 * A class which allows storing arbitrary properties on objects.
 * Relaxed equality check allows properties to be recorded on multiple
 * equivalent Closure instances.
 */
class WeakProperties implements ArrayAccess {

    private WeakMap $storage;

    public function __construct() {
        $this->storage = new WeakMap();
    }

    public function offsetExists(mixed $object): bool {
        if (!is_object($object)) {
            return false;
        }
        if (isset($this->storage[$object])) {
            return true;
        }
        foreach ($this->storage as $existingObject => $existingValue) {
            if ($existingObject == $object) {
                $this->storage[$object] = $this->storage[$existingObject];
                return true;
            }
        }
        return false;
    }
    public function offsetGet(mixed $object): ?object {
        if (!is_object($object)) {
            return null;
        }
        if (!$this->offsetExists($object)) {
            $this->storage[$object] = (object) [];
        }
        return $this->storage[$object];
    }

    public function offsetSet(mixed $object, mixed $value): void {
        throw new \Exception("Can't set on WeakProperties this way.");
    }

    public function offsetUnset(mixed $object): void {
        unset($this->storage[$object]);
    }

}
