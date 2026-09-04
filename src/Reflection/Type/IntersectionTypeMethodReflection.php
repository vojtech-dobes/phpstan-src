<?php declare(strict_types = 1);

namespace PHPStan\Reflection\Type;

use PHPStan\PhpDoc\ResolvedPhpDocBlock;
use PHPStan\Reflection\Assertions;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedFunctionVariant;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\Php\ExtendedDummyParameter;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_bool;

final class IntersectionTypeMethodReflection implements ExtendedMethodReflection
{

	private ?ExtendedMethodReflection $methodWithMostParameters = null;

	/**
	 * @param ExtendedMethodReflection[] $methods
	 */
	public function __construct(private string $methodName, private array $methods)
	{
	}

	public function getDeclaringClass(): ClassReflection
	{
		return $this->getMethodWithMostParameters()->getDeclaringClass();
	}

	public function isStatic(): bool
	{
		foreach ($this->methods as $method) {
			if ($method->isStatic()) {
				return true;
			}
		}

		return false;
	}

	public function isPrivate(): bool
	{
		foreach ($this->methods as $method) {
			if (!$method->isPrivate()) {
				return false;
			}
		}

		return true;
	}

	public function isPublic(): bool
	{
		foreach ($this->methods as $method) {
			if ($method->isPublic()) {
				return true;
			}
		}

		return false;
	}

	public function getName(): string
	{
		return $this->methodName;
	}

	public function getPrototype(): ClassMemberReflection
	{
		return $this;
	}

	public function getVariants(): array
	{
		$returnTypes = [];
		$phpDocReturnTypes = [];
		$nativeReturnTypes = [];

		$parametersByPosition = [];
		foreach ($this->methods as $method) {
			$variants = $method->getVariants();

			foreach ($variants as $acceptor) {
				$returnTypes[] = $acceptor->getReturnType();
				$phpDocReturnTypes[] = $acceptor->getPhpDocReturnType();
				$nativeReturnTypes[] = $acceptor->getNativeReturnType();

				foreach ($acceptor->getParameters() as $i => $parameter) {
					$parametersByPosition[$i][] = $parameter;
				}
			}
		}

		$returnType = $returnTypes[0];
		for ($i = 1, $count = count($returnTypes); $i < $count; $i++) {
			$returnType = TypeCombinator::intersect($returnType, $returnTypes[$i]);
		}
		$phpDocReturnType = $phpDocReturnTypes[0];
		for ($i = 1, $count = count($phpDocReturnTypes); $i < $count; $i++) {
			$phpDocReturnType = TypeCombinator::intersect($phpDocReturnType, $phpDocReturnTypes[$i]);
		}
		$nativeReturnType = $nativeReturnTypes[0];
		for ($i = 1, $count = count($nativeReturnTypes); $i < $count; $i++) {
			$nativeReturnType = TypeCombinator::intersect($nativeReturnType, $nativeReturnTypes[$i]);
		}

		$parameters = $this->intersectParameters($parametersByPosition);

		return array_map(static fn (ExtendedParametersAcceptor $acceptor): ExtendedParametersAcceptor => new ExtendedFunctionVariant(
			$acceptor->getTemplateTypeMap(),
			$acceptor->getResolvedTemplateTypeMap(),
			$parameters,
			$acceptor->isVariadic(),
			$returnType,
			$phpDocReturnType,
			$nativeReturnType,
			$acceptor->getCallSiteVarianceMap(),
		), $this->getMethodWithMostParameters()->getVariants());
	}

	/**
	 * Parameters are in a contravariant position, but since an intersection type
	 * calls into a single real method through several differently-resolved
	 * reflections (e.g. the same generic method with a different template
	 * type binding per intersected type), the value passed at the call site
	 * has to satisfy every one of them at once - so, unlike return types,
	 * intersecting still is the correct combination here, not a union.
	 *
	 * @param array<int, list<ParameterReflection>> $parametersByPosition
	 * @return list<ExtendedParameterReflection>
	 */
	private function intersectParameters(array $parametersByPosition): array
	{
		return array_map(static function ($positionalParameters) {
			$first = $positionalParameters[0];

			$type = $first->getType();
			$phpDocType = $first instanceof ExtendedParameterReflection ? $first->getPhpDocType() : $type;
			$nativeType = $first instanceof ExtendedParameterReflection ? $first->getNativeType() : new MixedType();

			for ($j = 1, $count = count($positionalParameters); $j < $count; $j++) {
				$parameter = $positionalParameters[$j];
				$type = TypeCombinator::intersect($type, $parameter->getType());
				if (!($parameter instanceof ExtendedParameterReflection)) {
					continue;
				}

				$phpDocType = TypeCombinator::intersect($phpDocType, $parameter->getPhpDocType());
				$nativeType = TypeCombinator::intersect($nativeType, $parameter->getNativeType());
			}

			return new ExtendedDummyParameter(
				$first->getName(),
				$type,
				$first->isOptional(),
				$first->passedByReference(),
				$first->isVariadic(),
				$first->getDefaultValue(),
				$nativeType,
				$phpDocType,
				$first instanceof ExtendedParameterReflection ? $first->getOutType() : null,
				$first instanceof ExtendedParameterReflection ? $first->isImmediatelyInvokedCallable() : TrinaryLogic::createMaybe(),
				$first instanceof ExtendedParameterReflection ? $first->getClosureThisType() : null,
				$first instanceof ExtendedParameterReflection ? $first->getAttributes() : [],
				$first instanceof ExtendedParameterReflection ? $first->getAllowedConstants() : null,
				$first instanceof ExtendedParameterReflection ? $first->isPureUnlessCallableIsImpureParameter() : TrinaryLogic::createNo(),
			);
		}, array_values($parametersByPosition));
	}

	public function getOnlyVariant(): ExtendedParametersAcceptor
	{
		$variants = $this->getVariants();
		if (count($variants) !== 1) {
			throw new ShouldNotHappenException();
		}

		return $variants[0];
	}

	public function getNamedArgumentsVariants(): ?array
	{
		return null;
	}

	public function isDeprecated(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (MethodReflection $method): TrinaryLogic => $method->isDeprecated());
	}

	public function getDeprecatedDescription(): ?string
	{
		$descriptions = [];
		foreach ($this->methods as $method) {
			if (!$method->isDeprecated()->yes()) {
				continue;
			}
			$description = $method->getDeprecatedDescription();
			if ($description === null) {
				continue;
			}

			$descriptions[] = $description;
		}

		if (count($descriptions) === 0) {
			return null;
		}

		return implode(' ', $descriptions);
	}

	public function isFinal(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (MethodReflection $method): TrinaryLogic => $method->isFinal());
	}

	public function isFinalByKeyword(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (ExtendedMethodReflection $method): TrinaryLogic => $method->isFinalByKeyword());
	}

	public function isInternal(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (MethodReflection $method): TrinaryLogic => $method->isInternal());
	}

	public function isBuiltin(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (ExtendedMethodReflection $method): TrinaryLogic => is_bool($method->isBuiltin()) ? TrinaryLogic::createFromBoolean($method->isBuiltin()) : $method->isBuiltin());
	}

	public function getThrowType(): ?Type
	{
		$types = [];

		foreach ($this->methods as $method) {
			$type = $method->getThrowType();
			if ($type === null) {
				continue;
			}

			$types[] = $type;
		}

		if (count($types) === 0) {
			return null;
		}

		$result = $types[0];
		for ($i = 1, $count = count($types); $i < $count; $i++) {
			$result = TypeCombinator::intersect($result, $types[$i]);
		}
		return $result;
	}

	public function hasSideEffects(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (MethodReflection $method): TrinaryLogic => $method->hasSideEffects());
	}

	public function isPure(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (ExtendedMethodReflection $method): TrinaryLogic => $method->isPure());
	}

	public function getPureUnlessCallableIsImpureParameters(): array
	{
		return MergedPureUnlessCallableIsImpureParameters::merge($this->methods);
	}

	public function getDocComment(): ?string
	{
		return null;
	}

	public function getAsserts(): Assertions
	{
		$assertions = Assertions::createEmpty();

		foreach ($this->methods as $method) {
			$assertions = $assertions->union($method->getAsserts());
		}

		return $assertions;
	}

	public function acceptsNamedArguments(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (MethodReflection $method): TrinaryLogic => $method->acceptsNamedArguments());
	}

	public function getSelfOutType(): ?Type
	{
		return null;
	}

	public function returnsByReference(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (ExtendedMethodReflection $method): TrinaryLogic => $method->returnsByReference());
	}

	public function isAbstract(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (ExtendedMethodReflection $method): TrinaryLogic => is_bool($method->isAbstract()) ? TrinaryLogic::createFromBoolean($method->isAbstract()) : $method->isAbstract());
	}

	public function getAttributes(): array
	{
		return $this->getMethodWithMostParameters()->getAttributes();
	}

	public function mustUseReturnValue(): TrinaryLogic
	{
		return TrinaryLogic::lazyMaxMin($this->methods, static fn (ExtendedMethodReflection $method): TrinaryLogic => $method->mustUseReturnValue());
	}

	public function getResolvedPhpDoc(): ?ResolvedPhpDocBlock
	{
		return $this->getMethodWithMostParameters()->getResolvedPhpDoc();
	}

	/**
	 * Since every intersected method should be compatible,
	 * selects the method whose variant has the widest parameter list,
	 * so intersection ordering does not affect call validation.
	 */
	private function getMethodWithMostParameters(): ExtendedMethodReflection
	{
		if ($this->methodWithMostParameters !== null) {
			return $this->methodWithMostParameters;
		}

		$methodWithMostParameters = $this->methods[0];
		$maxParameters = 0;
		foreach ($this->methods as $method) {
			foreach ($method->getVariants() as $variant) {
				if (count($variant->getParameters()) <= $maxParameters) {
					continue;
				}

				$maxParameters = count($variant->getParameters());
				$methodWithMostParameters = $method;
			}
		}

		$this->methodWithMostParameters = $methodWithMostParameters;

		return $methodWithMostParameters;
	}

}
