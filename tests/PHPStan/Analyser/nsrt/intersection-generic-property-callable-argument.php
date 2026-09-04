<?php // lint >= 8.1

declare(strict_types = 1);

namespace IntersectionGenericPropertyCallableArgument;

use function PHPStan\Testing\assertType;

class Bar
{

}

class Foo
{

	public function __construct(public readonly ?Bar $bar, public readonly int $xyz)
	{
	}

}

/**
 * @template T
 */
abstract class Box
{

	/** @var callable(T): int */
	public $c;

}

/**
 * @extends Box<Foo>
 */
class FooBox extends Box
{

}

/**
 * @param FooBox&Box<object{bar: Bar}> $x
 */
function run($x): void
{
	assertType('callable(IntersectionGenericPropertyCallableArgument\Foo&object{bar: IntersectionGenericPropertyCallableArgument\Bar}): int', $x->c);
}
