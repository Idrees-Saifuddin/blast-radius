<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Static;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Config;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Static\Hierarchy;
use BlastRadius\Static\SymbolIndexer;
use PHPUnit\Framework\TestCase;

final class CallCollectorTest extends TestCase
{
    /**
     * @return list<string> unique call edges as `from -> to [kind] CONFIDENCE`, sorted
     */
    private function callEdges(string $fixture): array
    {
        $table = (new SymbolIndexer(new Config(paths: [$fixture])))->index(__DIR__.'/../../Fixtures');
        $edges = [];

        foreach ($table->edges as $edge) {
            if (! $edge->kind->isHierarchy()) {
                $edges[] = "{$edge->from} -> {$edge->to} [{$edge->kind->value}] {$edge->confidence->value}";
            }
        }

        $edges = array_values(array_unique($edges));
        sort($edges);

        return $edges;
    }

    public function test_it_resolves_calls_with_the_right_confidence(): void
    {
        $actual = $this->callEdges('Calls');

        $expected = [
            'App\Orders\Base::make -> App\Orders\Base [new] CONFIRMED',
            'App\Orders\Base::make -> App\Orders\Child [new] POSSIBLE',
            'App\Orders\Base::make -> App\Orders\Service [new] POSSIBLE',
            'App\Orders\Base::run -> App\Orders\Base::step [call] CONFIRMED',
            'App\Orders\Base::run -> App\Orders\Child::step [call] POSSIBLE',
            'App\Orders\Child::step -> App\Orders\Base::step [static-call] CONFIRMED',
            'App\Orders\Order::create -> App\Orders\Order [new] CONFIRMED',
            'App\Orders\Order::create -> App\Orders\Order::__construct [call] CONFIRMED',
            'App\Orders\Service::handle -> <dynamic class> [new] UNRESOLVED',
            'App\Orders\Service::handle -> <dynamic method call> [call] UNRESOLVED',
            'App\Orders\Service::handle -> App\Orders\Base::step [call] CONFIRMED',
            'App\Orders\Service::handle -> App\Orders\Order [new] CONFIRMED',
            'App\Orders\Service::handle -> App\Orders\Order::__construct [call] CONFIRMED',
            'App\Orders\Service::handle -> App\Orders\Order::create [static-call] CONFIRMED',
            'App\Orders\Service::handle -> App\Orders\Service::helper [static-call] CONFIRMED',
        ];
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    public function test_it_resolves_typed_receivers_functions_and_dynamic_calls(): void
    {
        $expected = [
            // typed constructor-promoted property of an interface type: the interface method
            // is CONFIRMED, each implementation is POSSIBLE (container binding decides, Phase 3)
            'App\Inject\Checkout::pay -> App\Inject\Gateway::charge [call] CONFIRMED',
            'App\Inject\Checkout::pay -> App\Inject\PaypalGateway::charge [call] POSSIBLE',
            'App\Inject\Checkout::pay -> App\Inject\StripeGateway::charge [call] POSSIBLE',
            // nullable promoted property via ?->, and a typed parameter
            'App\Inject\Checkout::pay -> App\Inject\Mailer::send [call] CONFIRMED',
            // typed property, and a local `$local = new Logger` (only before it is reassigned)
            'App\Inject\Checkout::pay -> App\Inject\Logger::log [call] CONFIRMED',
            'App\Inject\Checkout::pay -> App\Inject\Logger [new] CONFIRMED',
            // untyped $untyped->send(), and $local->log() after $local was reassigned to a
            // string: matched by method name, only one class declares each, so POSSIBLE
            'App\Inject\Checkout::pay -> App\Inject\Mailer::send [call] POSSIBLE',
            'App\Inject\Checkout::pay -> App\Inject\Logger::log [call] POSSIBLE',
            // unqualified function call resolved to the namespaced function; strlen() ignored
            'App\Inject\Checkout::pay -> App\Inject\audit [call] CONFIRMED',
            // call_user_func and $fn()
            'App\Inject\Checkout::pay -> <dynamic function call> [call] UNRESOLVED',
            // new static: the class and its constructor CONFIRMED, subclasses POSSIBLE
            'App\Inject\Checkout::build -> App\Inject\Checkout [new] CONFIRMED',
            'App\Inject\Checkout::build -> App\Inject\Checkout::__construct [call] CONFIRMED',
            'App\Inject\Checkout::build -> App\Inject\SpecialCheckout [new] POSSIBLE',
            'App\Inject\Checkout::build -> App\Inject\StripeGateway [new] CONFIRMED',
        ];
        sort($expected);

        $this->assertSame($expected, $this->callEdges('Injection'));
    }

    public function test_a_reassigned_variable_loses_its_type(): void
    {
        $table = (new SymbolIndexer(new Config(paths: ['Injection'])))->index(__DIR__.'/../../Fixtures');
        $confirmed = array_filter($table->edges, fn ($e) => $e->to === 'App\Inject\Logger::log' && $e->confidence === Confidence::Confirmed);
        $possible = array_filter($table->edges, fn ($e) => $e->to === 'App\Inject\Logger::log' && $e->confidence === Confidence::Possible);

        // $this->logger->log() and $local->log() before reassignment are CONFIRMED;
        // the call after reassignment is only matched by name
        $this->assertCount(2, $confirmed);
        $this->assertCount(1, $possible);
    }

    public function test_untyped_calls_beyond_the_fan_out_cap_become_one_unresolved_edge(): void
    {
        $dir = sys_get_temp_dir().'/blast-'.uniqid();
        mkdir($dir.'/app', 0777, true);
        file_put_contents($dir.'/app/Many.php', '<?php namespace M;
            class A { function run() {} } class B { function run() {} } class C { function run() {} }
            class D { function go($x) { $x->run(); } }');

        try {
            $capped = (new SymbolIndexer(new Config(paths: ['app'], possibleFanOut: 2)))->index($dir);
            $open = (new SymbolIndexer(new Config(paths: ['app'], possibleFanOut: 3)))->index($dir);
        } finally {
            unlink($dir.'/app/Many.php');
            rmdir($dir.'/app');
            rmdir($dir);
        }

        $describe = fn ($table) => array_values(array_map(
            fn ($e) => "{$e->to} {$e->confidence->value}",
            array_filter($table->edges, fn ($e) => $e->from === 'M\D::go'),
        ));

        $this->assertSame(['*::run UNRESOLVED'], $describe($capped));
        $this->assertSame(['M\A::run POSSIBLE', 'M\B::run POSSIBLE', 'M\C::run POSSIBLE'], $describe($open));
    }

    public function test_methods_the_framework_calls_are_not_guessed_from_names(): void
    {
        $this->assertSame([
            // only Plain declares get() and toArray() for ordinary code; the cast's and the resource's are framework hooks
            'App\Hooks\Caller::run -> App\Hooks\Plain::get [call] POSSIBLE',
            'App\Hooks\Caller::run -> App\Hooks\Plain::toArray [call] POSSIBLE',
        ], $this->callEdges('Hooks'));
    }

    public function test_a_controller_method_taking_a_form_request_is_linked_to_its_rules_and_authorize(): void
    {
        $this->assertSame([
            'App\Forms\OrderController::store -> App\Forms\StoreOrder::authorize [call] CONFIRMED',
            'App\Forms\OrderController::store -> App\Forms\StoreOrder::rules [call] CONFIRMED',
        ], $this->callEdges('FormRequests'));
    }

    public function test_call_edges_do_not_leak_into_the_hierarchy(): void
    {
        $table = (new SymbolIndexer(new Config(paths: ['Calls'])))->index(__DIR__.'/../../Fixtures');
        $hierarchy = new Hierarchy($table);

        $this->assertSame([], array_filter(
            $hierarchy->outgoing('App\Orders\Service'),
            fn ($edge) => ! $edge->kind->isHierarchy(),
        ));
        $this->assertSame(EdgeKind::Extends, $hierarchy->outgoing('App\Orders\Service')[0]->kind);
    }
}
