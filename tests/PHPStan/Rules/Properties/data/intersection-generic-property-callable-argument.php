<?php // lint >= 8.1

declare(strict_types = 1);

namespace IntersectionGenericPropertyCallableArgument;

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
	// The value $c actually gets called with is one that is, simultaneously,
	// both a Foo and an object{bar: Bar} - so a callback explicitly typed to
	// accept just one of those two is still safe to assign, and must not be
	// rejected.
	$x->c = function (Foo $item): int {
		return $item->xyz;
	};

	// A callback that cannot possibly accept that value must still be
	// rejected.
	$x->c = function (int $item): int {
		return $item;
	};
}
