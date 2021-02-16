<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UnionType;
use Rector\CodingStyle\ValueObject\ObjectMagicMethods;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\TypeDeclaration\Reflection\ReflectionTypeResolver;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeFromStrictTypedCallRector\ReturnTypeFromStrictTypedCallRectorTest
 */
final class ReturnTypeFromStrictTypedCallRector extends AbstractRector
{
    /**
     * @var ReflectionTypeResolver
     */
    private $reflectionTypeResolver;

    public function __construct(ReflectionTypeResolver $reflectionTypeResolver)
    {
        $this->reflectionTypeResolver = $reflectionTypeResolver;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Add return type from strict return type of call', [
            new CodeSample(
                <<<'CODE_SAMPLE'
final class SomeClass
{
    public function getData()
    {
        return $this->getNumber();
    }

    private function getNumber(): int
    {
        return 1000;
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
final class SomeClass
{
    public function getData(): int
    {
        return $this->getNumber();
    }

    private function getNumber(): int
    {
        return 1000;
    }
}
CODE_SAMPLE

            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class, Function_::class, Closure::class, ArrowFunction::class];
    }

    /**
     * @param ClassMethod|Function_|Closure|ArrowFunction $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::SCALAR_TYPES)) {
            return null;
        }

        if ($node->returnType !== null) {
            return null;
        }

        if ($this->isNames($node, ObjectMagicMethods::METHOD_NAMES)) {
            return null;
        }

        /** @var Return_[] $returns */
        $returns = $this->betterNodeFinder->findInstanceOf($node->stmts, Return_::class);

        $returnedStrictTypes = $this->collectStrictReturnTypes($returns);
        if ($returnedStrictTypes === []) {
            return null;
        }

        if (count($returnedStrictTypes) === 1) {
            $node->returnType = $returnedStrictTypes[0];
            return $node;
        }

        if ($this->isAtLeastPhpVersion(PhpVersionFeature::UNION_TYPES)) {
            $node->returnType = new UnionType($returnedStrictTypes);
            return $node;
        }

        return null;
    }

    /**
     * @param Return_[] $returns
     * @return Node[]
     */
    private function collectStrictReturnTypes(array $returns): array
    {
        $returnedStrictTypeNodes = [];

        foreach ($returns as $return) {
            if ($return->expr === null) {
                return [];
            }

            $returnedExpr = $return->expr;
            if ($returnedExpr instanceof MethodCall || $returnedExpr instanceof StaticCall || $returnedExpr instanceof FuncCall) {
                if ($returnedExpr instanceof MethodCall) {
                    $returnNode = $this->resolveMethodCallReturnNode($returnedExpr);
                    if ($returnNode === null) {
                        return [];
                    }

                    $returnedStrictTypeNodes[] = $returnNode;
                }

                if ($returnedExpr instanceof StaticCall) {
                    $returnNode = $this->resolveStaticCallReturnNode($returnedExpr);
                    if ($returnNode === null) {
                        return [];
                    }

                    $returnedStrictTypeNodes[] = $returnNode;
                }

                if ($returnedExpr instanceof FuncCall) {
                    $returnNode = $this->resolveFuncCallReturnNode($returnedExpr);
                    if ($returnNode === null) {
                        return [];
                    }

                    $returnedStrictTypeNodes[] = $returnNode;
                }
            }
        }

        return array_unique($returnedStrictTypeNodes);
    }

    private function resolveMethodCallReturnNode(MethodCall $methodCall): ?Node
    {
        $classMethod = $this->nodeRepository->findClassMethodByMethodCall($methodCall);
        if ($classMethod instanceof ClassMethod) {
            return $classMethod->returnType;
        }

        $returnType = $this->reflectionTypeResolver->resolveMethodCallReturnType($methodCall);
        if ($returnType === null) {
            return null;
        }

        return $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($returnType);
    }

    private function resolveStaticCallReturnNode(StaticCall $staticCall): ?Node
    {
        $classMethod = $this->nodeRepository->findClassMethodByStaticCall($staticCall);
        if ($classMethod instanceof ClassMethod) {
            return $classMethod->returnType;
        }

        $returnType = $this->reflectionTypeResolver->resolveStaticCallReturnType($staticCall);
        if ($returnType === null) {
            return null;
        }

        return $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($returnType);
    }

    private function resolveFuncCallReturnNode(FuncCall $funcCall): ?\PhpParser\Node
    {
        $function = $this->nodeRepository->findFunctionByFuncCall($funcCall);
        if ($function instanceof Function_) {
            return $function->returnType;
        }

        $returnType = $this->reflectionTypeResolver->resolveFuncCallReturnType($funcCall);
        if ($returnType === null) {
            return null;
        }

        return $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($returnType);
    }
}
