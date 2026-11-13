<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

/**
 * An object of a final service class that the tested path never calls, made
 * without its constructor: the services it needs are not part of the test.
 */
final class Unused
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function instance(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
