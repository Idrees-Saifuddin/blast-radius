<?php

require __DIR__.'/../../vendor/autoload.php';

$dir = $argv[1] ?? sys_get_temp_dir().'/blast-ui-samples';
@mkdir($dir, 0777, true);

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use BlastRadius\Impact\Change;
use BlastRadius\Impact\ImpactAnalyzer;
use BlastRadius\Output\HtmlReport;
use BlastRadius\Output\ReportData;

$e = fn ($f, $t, $c = Confidence::Confirmed, $k = EdgeKind::Calls) => new Edge($f, $t, $k, $c, '', 0);
$nodes = [
    new Node(NodeKind::Route, 'route POST /api/purchases', '', 0, 0),
    new Node(NodeKind::Route, 'route GET /ping', '', 0, 0),
    new Node(NodeKind::Job, 'job App\Jobs\Recalc', '', 0, 0),
    new Node(NodeKind::Method, 'App\Http\PurchaseController::store', '/r/app/Http/PurchaseController.php', 12, 20),
    new Node(NodeKind::Method, 'App\Services\PurchaseService::updateStock', '/r/app/Services/PurchaseService.php', 15, 30),
    new Node(NodeKind::Method, 'App\Services\DbGateway::adjust', '/r/app/Services/DbGateway.php', 8, 12),
    new Node(NodeKind::Test, 'Tests\PurchaseTest::test_store', '/r/tests/PurchaseTest.php', 0, 0),
];
$edges = [
    $e('route POST /api/purchases', 'App\Http\PurchaseController::store', Confidence::Confirmed, EdgeKind::RoutesTo),
    $e('App\Http\PurchaseController::store', 'App\Services\PurchaseService::updateStock'),
    $e('App\Services\PurchaseService::updateStock', 'App\Services\DbGateway::adjust'),
    $e('App\Services\PurchaseService::updateStock', 'App\Services\NullGateway::adjust', Confidence::Possible),
    $e('App\Services\PurchaseService::updateStock', '*::delete', Confidence::Unresolved),
    $e('route GET /ping', 'App\Http\PingController::__invoke', Confidence::Confirmed, EdgeKind::RoutesTo),
    $e('job App\Jobs\Recalc', 'App\Services\PurchaseService::updateStock', Confidence::Confirmed, EdgeKind::Handles),
    $e('Tests\PurchaseTest::test_store', 'App\Services\PurchaseService::updateStock', Confidence::Confirmed, EdgeKind::Covers),
];
$impact = ImpactAnalyzer::analyze([new Change('App\Services\PurchaseService::updateStock', Change::MODIFIED, 'app/Services/PurchaseService.php', true)], $nodes, $edges, 8, ['app/Services/PurchaseService.php']);

file_put_contents($dir.'/with-impact.html', HtmlReport::render(ReportData::build('/r', $nodes, $edges, 6, $impact, 'main')));
file_put_contents($dir.'/live.html', HtmlReport::render(ReportData::build('/r', $nodes, $edges, 6, $impact, 'main', true)));
file_put_contents($dir.'/plain.html', HtmlReport::render(ReportData::build('/r', $nodes, $edges, 6)));
file_put_contents($dir.'/empty.html', HtmlReport::render(ReportData::build('/r', [], [], 6)));
echo "generated\n";
