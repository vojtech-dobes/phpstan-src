<?php // lint >= 8.1

declare(strict_types = 1);

namespace IntersectionGenericMethodCall;

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
abstract class Collection
{

	/** @var array<T> */
	public array $items = [];

	/**
	 * @template TOut
	 * @param (callable(T): TOut) $c
	 * @return array<TOut>
	 */
	public function map(callable $c)
	{
		return array_map($c, $this->items);
	}

}

/**
 * @extends Collection<Foo>
 */
class FooCollection extends Collection
{

}

/**
 * @param object{bar: Bar}&Foo $a
 * @param FooCollection&Collection<object{bar: Bar}> $col
 */
function run(Foo $a, FooCollection $col): void
{
	assertType('IntersectionGenericMethodCall\Foo&object{bar: IntersectionGenericMethodCall\Bar}', $a);
	assertType('IntersectionGenericMethodCall\Bar', $a->bar);
	assertType('int', $a->xyz);

	if (isset($col->items[0])) {
		assertType('IntersectionGenericMethodCall\Foo&object{bar: IntersectionGenericMethodCall\Bar}', $col->items[0]);
		assertType('int', $col->items[0]->xyz);
		assertType('IntersectionGenericMethodCall\Bar', $col->items[0]->bar);
	}

	$col->map(function ($item) {
		assertType('IntersectionGenericMethodCall\Foo&object{bar: IntersectionGenericMethodCall\Bar}', $item);
		assertType('IntersectionGenericMethodCall\Bar', $item->bar);
		assertType('int', $item->xyz);
	});
}
