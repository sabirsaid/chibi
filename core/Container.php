<?php

namespace Chibi;

use ArrayAccess;
use Psr\Log\InvalidArgumentException;
use ReflectionClass;
use Chibi\Exceptions\ClassIsNotInstantiableException;

class Container implements ArrayAccess
{

    protected $items = [];
    protected $cache = [];

    public function __construct(array $items = [])
    {
        array_walk($items, function ($item, $key) {
            $this->offsetSet($key, $item);
        }, array_keys($items));
    }

    public function offsetGet($offset)
    {
        if (!$this->has($offset)) {
            return;
        }
        if (isset($this->cache[$offset])) {
            return $this->cache[$offset];
        }

        $item = $this->items[$offset]($this);
        $this->cache[$offset] = $item;
        return $item;
    }

    public function offsetSet($offset, $value)
    {
        $this->items[$offset] = $value;
    }

    public function offsetUnset($offset)
    {
        if ($this->has($offset)) {
            unset($this->items[$offset]);
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->items[$offset]);
    }

    public function has($offset)
    {
        return $this->offsetExists($offset);
    }

    public function __get($offset)
    {
        return $this->offsetGet($offset);
    }

    public function resolve($key, array $args = [])
    {
        $class = $this->offsetGet($key);

        if ($class === null) {
            $class = $key;
        }

        return $this->constructIt($class, $args);
    }

    protected function constructIt($className, array $args = [])
    {
        $reflector = new ReflectionClass($className);

        if (!$reflector->isInstantiable()) {
            throw new ClassIsNotInstantiableException("The {class} is not instantiable");
        }

        if (is_null(($constructor = $reflector->getConstructor()))) {
            return new $className;
        }

        $dependencies = $constructor->getParameters();
        foreach ($dependencies as $dependency) {
            if ($dependency->isArray() || $dependency->isOptional())
                continue;
            if (($class = $dependency->getClass()) === null)
                continue;
            if (get_class($this) === $class->name) {
                array_unshift($args, $this);
                continue;
            }

            array_unshift($args, $this->resolve($class->name));
        }
        return $reflector->newInstance($args);
    }

    /**
     * Instantiate the required Objects
     *
     * @param $className
     * @param $method
     * @param $args
     * @return array
     */
    public function resolveMethod($className, $method, $args)
    {
        $params = [];
        $reflector = $this->createReflector($method, $className);

        foreach ($reflector->getParameters() as $param) {
            if (is_null($class = $param->getClass())) {
                if (count($args) == 0) {
                    throw new InvalidArgumentException("Invalid number of arguments provided");
                }
                $params[] = array_shift($args);
                continue;
            }
            $params[] = $this->constructIt($class->getName());
        }
        return $params;
    }

    /**
     * Create a Reflector instance
     *
     * @param $method
     * @param null $className
     * @return \ReflectionFunction|\ReflectionMethod
     */
    protected function createReflector($method, $className = null)
    {
        if (is_callable($method)) {
            return new \ReflectionFunction($method);
        }
        return new \ReflectionMethod($className, $method);
    }
}
