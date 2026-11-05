<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use PhpParser\Node as AstNode;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects classes, interfaces, traits, enums, methods and functions with their line
 * ranges, plus the declared extends / implements / uses-trait edges between them.
 * Needs the NameResolver visitor to run first so names are fully qualified.
 */
final class SymbolCollector extends NodeVisitorAbstract
{
    /** @var list<Node> */
    private array $symbols = [];

    /** @var list<Edge> */
    private array $edges = [];

    /** @var array<string, array<string, string>> class => property => declared class type */
    private array $propertyTypes = [];

    /** @var list<string|null> enclosing class-likes; null for anonymous classes */
    private array $classStack = [];

    public function __construct(private readonly string $file) {}

    /**
     * @return list<Node>
     */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /**
     * @return list<Edge>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function propertyTypes(): array
    {
        return $this->propertyTypes;
    }

    public function enterNode(AstNode $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            $name = $node->namespacedName?->toString();
            $this->classStack[] = $name;

            if ($name !== null) {
                $this->add($this->kindOf($node), $name, $node);
                $this->addHierarchyEdges($name, $node);
            }
        } elseif ($node instanceof Stmt\TraitUse) {
            $class = $this->currentClass();

            if ($class !== null) {
                foreach ($node->traits as $trait) {
                    $this->edge($class, $trait->toString(), EdgeKind::UsesTrait, $node);
                }
            }
        } elseif ($node instanceof Stmt\Property) {
            $class = $this->currentClass();
            $type = TypeNames::of($node->type, $class);

            if ($class !== null && $type !== null) {
                foreach ($node->props as $property) {
                    $this->propertyTypes[$class][$property->name->toString()] = $type;
                }
            }
        } elseif ($node instanceof Stmt\ClassMethod) {
            $class = $this->currentClass();

            if ($class !== null) {
                $this->add(NodeKind::Method, $class.'::'.$node->name->toString(), $node);
                $this->addPromotedProperties($class, $node);
            }
        } elseif ($node instanceof Stmt\Function_) {
            $this->add(NodeKind::Function, $node->namespacedName?->toString() ?? $node->name->toString(), $node);
        }

        return null;
    }

    public function leaveNode(AstNode $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : $this->classStack[array_key_last($this->classStack)];
    }

    private function addPromotedProperties(string $class, Stmt\ClassMethod $method): void
    {
        if ($method->name->toLowerString() !== '__construct') {
            return;
        }

        foreach ($method->params as $param) {
            $type = TypeNames::of($param->type, $class);

            if ($param->isPromoted() && $type !== null && $param->var instanceof AstNode\Expr\Variable && is_string($param->var->name)) {
                $this->propertyTypes[$class][$param->var->name] = $type;
            }
        }
    }

    private function addHierarchyEdges(string $name, Stmt\ClassLike $node): void
    {
        if ($node instanceof Stmt\Class_ && $node->extends !== null) {
            $this->edge($name, $node->extends->toString(), EdgeKind::Extends, $node);
        }

        if ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $parent) {
                $this->edge($name, $parent->toString(), EdgeKind::Extends, $node);
            }
        }

        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->edge($name, $interface->toString(), EdgeKind::Implements, $node);
            }
        }
    }

    private function edge(string $from, string $to, EdgeKind $kind, AstNode $at): void
    {
        $this->edges[] = new Edge($from, $to, $kind, Confidence::Confirmed, $this->file, $at->getStartLine());
    }

    private function kindOf(Stmt\ClassLike $node): NodeKind
    {
        return match (true) {
            $node instanceof Stmt\Interface_ => NodeKind::Interface,
            $node instanceof Stmt\Trait_ => NodeKind::Trait,
            $node instanceof Stmt\Enum_ => NodeKind::Enum,
            default => NodeKind::Class_,
        };
    }

    private function add(NodeKind $kind, string $name, AstNode $node): void
    {
        $this->symbols[] = new Node($kind, $name, $this->file, $node->getStartLine(), $node->getEndLine());
    }
}
