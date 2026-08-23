<?php

declare(strict_types=1);

namespace Dan\Harness\Dev\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Calls into our own code (the Dan\ monorepo namespaces) that pass two or more
 * arguments must name all of them. Vendor callees, single-argument calls,
 * variadic signatures and spread calls are exempt.
 *
 * @implements Rule<CallLike>
 */
final class RequireNamedArgumentsRule implements Rule
{
    private const OWN_NAMESPACE_PREFIX = 'Dan\\';
    private const MIN_ARGUMENT_COUNT = 2;

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable()) {
            return [];
        }

        $args = $node->getArgs();
        if (count($args) < self::MIN_ARGUMENT_COUNT) {
            return [];
        }

        $hasUnnamedArgument = false;
        foreach ($args as $arg) {
            if ($arg->unpack) {
                return [];
            }
            if ($arg->name === null) {
                $hasUnnamedArgument = true;
            }
        }
        if (!$hasUnnamedArgument) {
            return [];
        }

        $callee = $this->resolveOwnCallee(node: $node, scope: $scope);
        if ($callee === null) {
            return [];
        }
        [
            $variants,
            $calleeDescription,
        ] = $callee;

        foreach ($variants as $variant) {
            if ($variant->isVariadic()) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Call to %s with %d arguments must use named arguments.',
                $calleeDescription,
                count($args),
            ))->identifier('dan.namedArguments')->build(),
        ];
    }

    /**
     * Resolves the called signature, or null when the callee is unknown or
     * not declared in our own namespace.
     *
     * @return array{list<ParametersAcceptor>, string}|null
     */
    private function resolveOwnCallee(CallLike $node, Scope $scope): ?array
    {
        if ($node instanceof New_) {
            if (!$node->class instanceof Name) {
                return null;
            }
            $className = $scope->resolveName($node->class);
            if (!$this->reflectionProvider->hasClass($className)) {
                return null;
            }
            $classReflection = $this->reflectionProvider->getClass($className);
            if (!$classReflection->hasConstructor()) {
                return null;
            }
            $constructor = $classReflection->getConstructor();
            if (!str_starts_with($constructor->getDeclaringClass()->getName(), self::OWN_NAMESPACE_PREFIX)) {
                return null;
            }

            return [
                $constructor->getVariants(),
                $constructor->getDeclaringClass()->getName() . '::__construct()',
            ];
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            if (!$node->name instanceof Identifier) {
                return null;
            }
            $methodName = $node->name->toString();
            $calledOnType = $scope->getType($node->var);
            if (!$calledOnType->hasMethod($methodName)->yes()) {
                return null;
            }
            $method = $calledOnType->getMethod($methodName, $scope);
            if (!str_starts_with($method->getDeclaringClass()->getName(), self::OWN_NAMESPACE_PREFIX)) {
                return null;
            }

            return [
                $method->getVariants(),
                $method->getDeclaringClass()->getName() . '::' . $methodName . '()',
            ];
        }

        if ($node instanceof StaticCall) {
            if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
                return null;
            }
            $className = $scope->resolveName($node->class);
            if (!$this->reflectionProvider->hasClass($className)) {
                return null;
            }
            $classReflection = $this->reflectionProvider->getClass($className);
            $methodName = $node->name->toString();
            if (!$classReflection->hasMethod($methodName)) {
                return null;
            }
            $method = $classReflection->getMethod($methodName, $scope);
            if (!str_starts_with($method->getDeclaringClass()->getName(), self::OWN_NAMESPACE_PREFIX)) {
                return null;
            }

            return [
                $method->getVariants(),
                $method->getDeclaringClass()->getName() . '::' . $methodName . '()',
            ];
        }

        if ($node instanceof FuncCall) {
            if (!$node->name instanceof Name) {
                return null;
            }
            if (!$this->reflectionProvider->hasFunction($node->name, $scope)) {
                return null;
            }
            $function = $this->reflectionProvider->getFunction($node->name, $scope);
            if (!str_starts_with($function->getName(), self::OWN_NAMESPACE_PREFIX)) {
                return null;
            }

            return [
                $function->getVariants(),
                $function->getName() . '()',
            ];
        }

        return null;
    }
}
