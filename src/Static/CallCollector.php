<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use BlastRadius\Core\ClosureName;
use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Second pass: turns calls inside methods and functions into edges `caller -> callee`.
 * Runs after the whole code base is indexed, so the hierarchy is complete.
 *
 * Receivers resolved so far: `$this`, `$this->prop` (typed or constructor-promoted),
 * typed parameters, and local variables assigned from `new X` or from another known
 * receiver. Plus `new X`, static / self / parent / static calls and plain functions.
 * Untyped receivers are matched by method name (POSSIBLE, capped). Dynamic names and
 * `call_user_func` are recorded as UNRESOLVED.
 */
final class CallCollector extends NodeVisitorAbstract
{
    public const DYNAMIC_METHOD = '<dynamic method call>';

    public const DYNAMIC_CLASS = '<dynamic class>';

    public const DYNAMIC_FUNCTION = '<dynamic function call>';

    /** Prefix of the target when an untyped call matches too many classes: `*::method`. */
    public const ANY_CLASS = '*::';

    private const CALLABLE_FUNCTIONS = ['call_user_func', 'call_user_func_array'];

    /** Static calls that dispatch the class they are called on (Dispatchable trait). */
    private const DISPATCH_METHODS = ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse', 'dispatchif', 'dispatchunless'];

    /** Global helpers that dispatch the event or job passed as `new X`. */
    private const DISPATCH_FUNCTIONS = ['event', 'dispatch', 'dispatch_sync'];

    /** Static calls on these facades dispatch the event or job passed as `new X`. */
    private const DISPATCH_FACADES = [
        'Illuminate\\Support\\Facades\\Event' => ['dispatch', 'fire', 'until'],
        'Illuminate\\Support\\Facades\\Bus' => ['dispatch', 'dispatchsync', 'dispatchnow'],
        'Illuminate\\Support\\Facades\\Queue' => ['push'],
    ];

    /**
     * Methods the framework calls itself. An untyped `$x->get()` must not be linked to a cast's
     * get() just because the names match: nobody in your code calls it, Laravel does.
     * Keys are the framework type, values the lower-cased method names it calls.
     */
    private const FRAMEWORK_HOOKS = [
        'Illuminate\\Contracts\\Database\\Eloquent\\CastsAttributes' => ['get', 'set'],
        'Illuminate\\Contracts\\Database\\Eloquent\\CastsInboundAttributes' => ['set'],
        'Illuminate\\Http\\Resources\\Json\\JsonResource' => ['toarray', 'with', 'additional'],
        'Illuminate\\Foundation\\Http\\FormRequest' => ['rules', 'authorize', 'messages', 'attributes', 'prepareforvalidation', 'passedvalidation', 'withvalidator'],
        'Illuminate\\Console\\Command' => ['handle'],
        'Illuminate\\Contracts\\Queue\\ShouldQueue' => ['handle'],
        'Illuminate\\Mail\\Mailable' => ['build', 'envelope', 'content', 'attachments', 'headers'],
        'Illuminate\\Contracts\\Validation\\ValidationRule' => ['validate'],
        'Illuminate\\Contracts\\Validation\\Rule' => ['passes', 'message'],
        'Illuminate\\Notifications\\Notification' => ['via', 'tomail', 'todatabase', 'toarray'],
    ];

    /** What the framework calls on a FormRequest that a controller method receives. */
    private const FORM_REQUEST = 'Illuminate\\Foundation\\Http\\FormRequest';

    private const FORM_REQUEST_METHODS = ['prepareForValidation', 'authorize', 'rules', 'messages', 'attributes', 'withValidator', 'passedValidation'];

    /** @var list<Edge> */
    private array $edges = [];

    /** @var list<string|null> */
    private array $classStack = [];

    /** @var list<string|null> enclosing method or function; null where calls are not attributed */
    private array $callerStack = [];

    /** @var list<bool> per open closure: whether it started its own caller scope */
    private array $closureScopes = [];

    /**
     * Known variable types per enclosing method or function. `lateBound` means the object
     * may be a subclass or an implementation, so overriding methods are POSSIBLE targets.
     *
     * @var list<array<string, array{type: string, lateBound: bool}>>
     */
    private array $varStack = [];

    /**
     * @param  array<string, true>  $functions  functions declared in the scanned code
     */
    public function __construct(
        private readonly string $file,
        private readonly Hierarchy $hierarchy,
        private readonly array $functions = [],
        private readonly int $possibleFanOut = 10,
        private readonly string $root = '',
    ) {}

    /**
     * @return list<Edge>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    public function enterNode(AstNode $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->classStack[] = $node->namespacedName?->toString();
        } elseif ($node instanceof Stmt\ClassMethod) {
            $class = $this->currentClass();
            $this->callerStack[] = $class === null ? null : $class.'::'.$node->name->toString();
            $this->varStack[] = $this->paramTypes($node->params);

            if ($class !== null) {
                $this->formRequestEdges($class.'::'.$node->name->toString(), $node->params);
            }
        } elseif ($node instanceof Stmt\Function_) {
            $this->callerStack[] = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->varStack[] = $this->paramTypes($node->params);
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            if ($this->callerStack === []) {
                // Outside any method (route files, console routes): the closure is its own caller.
                $this->callerStack[] = ClosureName::for($this->root, $this->file, $node->getStartLine());
                $this->varStack[] = $this->paramTypes($node->params);
                $this->closureScopes[] = true;
            } else {
                // Inside a method: share the enclosing scope, closures usually capture it.
                if ($this->varStack !== []) {
                    $top = array_key_last($this->varStack);
                    $this->varStack[$top] = $this->paramTypes($node->params) + $this->varStack[$top];
                }

                $this->closureScopes[] = false;
            }
        }

        $caller = $this->callerStack === [] ? null : $this->callerStack[array_key_last($this->callerStack)];

        if ($caller === null) {
            return null;
        }

        if ($node instanceof Expr\New_) {
            $this->onNew($caller, $node);
        } elseif ($node instanceof Expr\StaticCall) {
            $this->onStaticCall($caller, $node);
        } elseif ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $this->onMethodCall($caller, $node);
        } elseif ($node instanceof Expr\FuncCall) {
            $this->onFuncCall($caller, $node);
        }

        return null;
    }

    public function leaveNode(AstNode $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        } elseif ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            array_pop($this->callerStack);
            array_pop($this->varStack);
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            if (array_pop($this->closureScopes)) {
                array_pop($this->callerStack);
                array_pop($this->varStack);
            }
        } elseif ($node instanceof Expr\Assign) {
            // Handled on leave, so calls on the right-hand side still see the old type.
            $this->onAssign($node);
        }

        return null;
    }

    private function onNew(string $caller, Expr\New_ $node): void
    {
        if ($node->class instanceof Stmt\Class_) {
            return;
        }

        $class = $node->class;

        if (! $class instanceof Name) {
            $this->edge($caller, self::DYNAMIC_CLASS, EdgeKind::Instantiates, Confidence::Unresolved, $node);

            return;
        }

        $type = $this->typeOf($class);

        if ($type === null) {
            return;
        }

        $this->edge($caller, $type, EdgeKind::Instantiates, Confidence::Confirmed, $node);

        $constructor = $this->hierarchy->resolveMethod($type, '__construct');

        if ($constructor !== null) {
            $this->edge($caller, $constructor, EdgeKind::Calls, Confidence::Confirmed, $node);
        }

        if ($class->toLowerString() === 'static') {
            foreach ($this->hierarchy->descendants($type) as $subclass) {
                $this->edge($caller, $subclass, EdgeKind::Instantiates, Confidence::Possible, $node);
            }
        }
    }

    private function onStaticCall(string $caller, Expr\StaticCall $node): void
    {
        if (! $node->name instanceof Identifier) {
            $this->edge($caller, self::DYNAMIC_METHOD, EdgeKind::StaticCalls, Confidence::Unresolved, $node);

            return;
        }

        if (! $node->class instanceof Name) {
            $this->edge($caller, self::DYNAMIC_CLASS, EdgeKind::StaticCalls, Confidence::Unresolved, $node);

            return;
        }

        $type = $this->typeOf($node->class);

        if ($type === null) {
            return;
        }

        $method = $node->name->toString();
        $resolved = $this->hierarchy->resolveMethod($type, $method);

        if ($resolved === null) {
            if (in_array(strtolower($method), self::DISPATCH_METHODS, true) && $this->hierarchy->declares($type)) {
                $this->edge($caller, $type, EdgeKind::Dispatches, Confidence::Confirmed, $node);

                return;
            }

            if ($this->hierarchy->isFacade($type)) {
                // The real target is decided by the container; the Laravel adapter rewrites this.
                $this->edge($caller, $type.'::'.$method, EdgeKind::StaticCalls, Confidence::Possible, $node);

                return;
            }

            if (in_array(strtolower($method), self::DISPATCH_FACADES[$type] ?? [], true)) {
                $this->dispatchFromArgs($caller, $node->args, $node);

                return;
            }
        }

        $this->addMethodEdges($caller, $type, $method, EdgeKind::StaticCalls, $node->class->toLowerString() === 'static', $node);
    }

    /**
     * @param  array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder>  $args
     */
    private function dispatchFromArgs(string $caller, array $args, AstNode $at): void
    {
        $first = $args[0] ?? null;

        if ($first instanceof AstNode\Arg && $first->value instanceof Expr\New_ && $first->value->class instanceof Name) {
            $type = $this->typeOf($first->value->class);

            if ($type !== null) {
                $this->edge($caller, $type, EdgeKind::Dispatches, Confidence::Confirmed, $at);
            }
        }
    }

    private function onMethodCall(string $caller, Expr\MethodCall|Expr\NullsafeMethodCall $node): void
    {
        if (! $node->name instanceof Identifier) {
            $this->edge($caller, self::DYNAMIC_METHOD, EdgeKind::Calls, Confidence::Unresolved, $node);

            return;
        }

        $method = $node->name->toString();
        $receiver = $this->receiverType($node->var);

        if ($receiver !== null) {
            $this->addMethodEdges($caller, $receiver['type'], $method, EdgeKind::Calls, $receiver['lateBound'], $node);

            return;
        }

        // Unknown receiver type: match by method name. No declaration at all usually means
        // a vendor or framework method, which has no node in the graph, so nothing is added.
        $candidates = array_values(array_filter(
            $this->hierarchy->declarationsOf($method),
            fn (string $declaration) => ! $this->isFrameworkHook($declaration),
        ));

        if (count($candidates) > $this->possibleFanOut) {
            $this->edge($caller, self::ANY_CLASS.$method, EdgeKind::Calls, Confidence::Unresolved, $node);

            return;
        }

        foreach ($candidates as $candidate) {
            $this->edge($caller, $candidate, EdgeKind::Calls, Confidence::Possible, $node);
        }
    }

    private function onFuncCall(string $caller, Expr\FuncCall $node): void
    {
        $name = $node->name;

        if (! $name instanceof Name) {
            $this->edge($caller, self::DYNAMIC_FUNCTION, EdgeKind::Calls, Confidence::Unresolved, $node);

            return;
        }

        if (in_array($name->toLowerString(), self::DISPATCH_FUNCTIONS, true)) {
            $this->dispatchFromArgs($caller, $node->args, $node);
        }

        // Unqualified calls inside a namespace fall back to the global function at runtime;
        // NameResolver leaves the namespaced candidate in this attribute.
        $candidates = [];
        $namespaced = $name->getAttribute('namespacedName');

        if ($namespaced instanceof Name) {
            $candidates[] = $namespaced->toString();
        }

        $candidates[] = $name->toString();

        foreach ($candidates as $function) {
            if (isset($this->functions[$function])) {
                $this->edge($caller, $function, EdgeKind::Calls, Confidence::Confirmed, $node);

                return;
            }
        }

        if (in_array($name->toLowerString(), self::CALLABLE_FUNCTIONS, true)) {
            $this->edge($caller, self::DYNAMIC_FUNCTION, EdgeKind::Calls, Confidence::Unresolved, $node);
        }
    }

    /**
     * A controller method that takes a FormRequest makes Laravel build it, authorize it and
     * validate with its rules(): link the method to those.
     *
     * @param  array<AstNode\Param>  $params
     */
    private function formRequestEdges(string $caller, array $params): void
    {
        foreach ($params as $param) {
            $type = TypeNames::of($param->type, $this->currentClass());

            if ($type === null || ! $this->hierarchy->isA($type, self::FORM_REQUEST)) {
                continue;
            }

            foreach (self::FORM_REQUEST_METHODS as $method) {
                $target = $this->hierarchy->resolveMethod($type, $method);

                if ($target !== null) {
                    $this->edge($caller, $target, EdgeKind::Calls, Confidence::Confirmed, $param);
                }
            }
        }
    }

    private function isFrameworkHook(string $declaration): bool
    {
        $split = strrpos($declaration, '::');

        if ($split === false) {
            return false;
        }

        $class = substr($declaration, 0, $split);
        $method = strtolower(substr($declaration, $split + 2));

        foreach (self::FRAMEWORK_HOOKS as $base => $methods) {
            if (in_array($method, $methods, true) && $this->hierarchy->isA($class, $base)) {
                return true;
            }
        }

        return false;
    }

    private function onAssign(Expr\Assign $node): void
    {
        if (! $node->var instanceof Expr\Variable || ! is_string($node->var->name) || $this->varStack === []) {
            return;
        }

        $top = array_key_last($this->varStack);
        $type = $this->assignedType($node->expr);

        if ($type === null) {
            unset($this->varStack[$top][$node->var->name]);
        } else {
            $this->varStack[$top][$node->var->name] = $type;
        }
    }

    /**
     * @return array{type: string, lateBound: bool}|null
     */
    private function assignedType(Expr $expr): ?array
    {
        if ($expr instanceof Expr\New_ && $expr->class instanceof Name) {
            $type = $this->typeOf($expr->class);

            return $type === null ? null : ['type' => $type, 'lateBound' => $expr->class->toLowerString() === 'static'];
        }

        return $this->receiverType($expr);
    }

    /**
     * @return array{type: string, lateBound: bool}|null
     */
    private function receiverType(Expr $var): ?array
    {
        if ($var instanceof Expr\Variable && is_string($var->name)) {
            if ($var->name === 'this') {
                $class = $this->currentClass();

                return $class === null ? null : ['type' => $class, 'lateBound' => true];
            }

            return $this->varStack === [] ? null : ($this->varStack[array_key_last($this->varStack)][$var->name] ?? null);
        }

        if (($var instanceof Expr\PropertyFetch || $var instanceof Expr\NullsafePropertyFetch)
            && $var->var instanceof Expr\Variable && $var->var->name === 'this'
            && $var->name instanceof Identifier) {
            $class = $this->currentClass();
            $type = $class === null ? null : $this->hierarchy->propertyType($class, $var->name->toString());

            return $type === null ? null : ['type' => $type, 'lateBound' => true];
        }

        return null;
    }

    /**
     * @param  array<AstNode\Param>  $params
     * @return array<string, array{type: string, lateBound: bool}>
     */
    private function paramTypes(array $params): array
    {
        $types = [];

        foreach ($params as $param) {
            $type = TypeNames::of($param->type, $this->currentClass());

            if ($type !== null && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $types[$param->var->name] = ['type' => $type, 'lateBound' => true];
            }
        }

        return $types;
    }

    /**
     * CONFIRMED edge to the method that runs for `$type`; when the call is late-bound
     * (`$this`, `static`, a typed parameter or property) other implementations in
     * subclasses or implementing classes are POSSIBLE targets too.
     */
    private function addMethodEdges(string $caller, string $type, string $method, EdgeKind $kind, bool $lateBound, AstNode $at): void
    {
        $target = $this->hierarchy->resolveMethod($type, $method);

        if ($target !== null) {
            $this->edge($caller, $target, $kind, Confidence::Confirmed, $at);
        }

        if ($lateBound) {
            foreach ($this->hierarchy->overriders($type, $method) as $overrider) {
                $this->edge($caller, $overrider, $kind, Confidence::Possible, $at);
            }
        }
    }

    private function typeOf(Name $name): ?string
    {
        return match ($name->toLowerString()) {
            'self', 'static' => $this->currentClass(),
            'parent' => ($class = $this->currentClass()) === null ? null : $this->hierarchy->parentOf($class),
            default => $name->toString(),
        };
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : $this->classStack[array_key_last($this->classStack)];
    }

    private function edge(string $from, string $to, EdgeKind $kind, Confidence $confidence, AstNode $at): void
    {
        $this->edges[] = new Edge($from, $to, $kind, $confidence, $this->file, $at->getStartLine());
    }
}
