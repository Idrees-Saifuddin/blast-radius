<?php

declare(strict_types=1);

namespace BlastRadius\Runtime;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Finds the methods worth timing: those with a body, in named classes, traits and enums.
 * Magic methods are skipped (they fire constantly and say little), except __invoke.
 * Anonymous classes are skipped entirely.
 */
final class MethodLocator extends NodeVisitorAbstract
{
    /** @var list<array{string, int, int}> span name, start and end offset of the method */
    public array $methods = [];

    /** @var list<string> */
    private array $classes = [];

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Expr\New_ && $node->class instanceof Stmt\Class_) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Stmt\ClassLike) {
            $name = $node->namespacedName?->toString();

            // Anonymous classes are never visited; a class without a name cannot be reported.
            $this->classes[] = $name ?? '';

            return null;
        }

        if ($node instanceof Stmt\ClassMethod && $node->stmts !== null && $this->classes !== []) {
            $class = $this->classes[array_key_last($this->classes)];
            $name = $node->name->toString();

            if ($class !== '' && (! str_starts_with($name, '__') || $name === '__invoke')) {
                $this->methods[] = [$class.'::'.$name, $node->getStartFilePos(), $node->getEndFilePos()];
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }

        return null;
    }
}
