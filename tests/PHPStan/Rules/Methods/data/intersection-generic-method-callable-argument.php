<?php // lint >= 8.1

declare(strict_types = 1);

namespace IntersectionGenericMethodCallableArgument;

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
 * @param object{bar: Bar} $item
 */
function acceptsFooShape(object $item): Bar
{
	return $item->bar;
}

/**
 * @param FooCollection&Collection<object{bar: Bar}> $col
 */
function run(FooCollection $col): void
{
	$col->map(function (Foo $item): int {
		return $item->xyz;
	});

	$col->map(acceptsFooShape(...));

	$col->map(function (int $item): int {
		return $item;
	});
}

/**
 * This is unsafe, because now Foo::$bar -> Bar isn't guaranteed for acceptsFooShape().
 */
function runPlain(FooCollection $col): void
{
	$col->map(acceptsFooShape(...));
}
