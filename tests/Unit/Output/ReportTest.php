<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Output;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use BlastRadius\Impact\Change;
use BlastRadius\Impact\ImpactAnalyzer;
use BlastRadius\Output\HtmlReport;
use BlastRadius\Output\ReportData;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private function edge(string $from, string $to, Confidence $c = Confidence::Confirmed, EdgeKind $k = EdgeKind::Calls): Edge
    {
        return new Edge($from, $to, $k, $c, '', 0);
    }

    /**
     * @return array{list<Node>, list<Edge>}
     */
    private function graph(): array
    {
        $nodes = [
            new Node(NodeKind::Route, 'route GET /a', '', 0, 0),
            new Node(NodeKind::Method, 'C::a', '/r/app/C.php', 5, 9),
            new Node(NodeKind::Method, 'S::s', '/r/app/S.php', 3, 4),
            new Node(NodeKind::Test, 'T::t', '/r/tests/T.php', 0, 0),
        ];
        $edges = [
            $this->edge('route GET /a', 'C::a', Confidence::Confirmed, EdgeKind::RoutesTo),
            $this->edge('route GET /a', '*::x', Confidence::Unresolved),
            $this->edge('C::a', 'S::s'),
            $this->edge('C::a', 'S::p', Confidence::Possible),
            $this->edge('S::s', 'C::a'),
            $this->edge('T::t', 'S::s', Confidence::Confirmed, EdgeKind::Covers),
        ];

        return [$nodes, $edges];
    }

    public function test_it_builds_one_flow_per_entry_point_with_forward_links_only(): void
    {
        [$nodes, $edges] = $this->graph();
        $data = ReportData::build('/r', $nodes, $edges, 6);

        $this->assertCount(1, $data['entries']);
        $flow = $data['entries'][0]['flow'];

        $this->assertSame(
            [['route GET /a', 0, 'CONFIRMED'], ['C::a', 1, 'CONFIRMED'], ['*::x', 1, 'UNRESOLVED'], ['S::s', 2, 'CONFIRMED'], ['S::p', 2, 'POSSIBLE']],
            array_map(fn ($n) => [$n['n'], $n['d'], $n['c']], $flow['nodes']),
        );
        // the S::s -> C::a back-edge is left out, so the layers stay readable
        $this->assertCount(4, $flow['links']);
        $this->assertFalse($flow['truncated']);
        $this->assertSame(['kind' => 'method', 'file' => 'app/C.php', 'line' => 5, 'end' => 9], $data['nodes']['C::a']);
        $this->assertSame('dynamic', $data['nodes']['*::x']['kind']);
        $this->assertSame(['T::t'], $data['coveredBy']['S::s']);
        $this->assertSame(['CONFIRMED' => 3, 'POSSIBLE' => 1, 'UNRESOLVED' => 1], $data['meta']['edges']);
        $this->assertSame(1, $data['meta']['tests']);
    }

    public function test_depth_limits_the_flow(): void
    {
        [$nodes, $edges] = $this->graph();
        $names = array_map(fn ($n) => $n['n'], ReportData::build('/r', $nodes, $edges, 1)['entries'][0]['flow']['nodes']);

        $this->assertSame(['route GET /a', 'C::a', '*::x'], $names);
    }

    public function test_a_huge_flow_is_truncated_and_says_so(): void
    {
        $nodes = [new Node(NodeKind::Route, 'route GET /big', '', 0, 0)];
        $edges = [$this->edge('route GET /big', 'f0')];

        for ($i = 0; $i < 400; $i++) {
            $edges[] = $this->edge("f{$i}", 'f'.($i + 1));
        }

        $flow = ReportData::build('/r', $nodes, $edges, 1000)['entries'][0]['flow'];

        $this->assertTrue($flow['truncated']);
        $this->assertCount(250, $flow['nodes']);
        $this->assertCount(249, $flow['links']);
    }

    public function test_impact_is_included_when_given(): void
    {
        [$nodes, $edges] = $this->graph();
        $impact = ImpactAnalyzer::analyze([new Change('S::s', Change::MODIFIED, 'app/S.php', true)], $nodes, $edges, 8, ['app/S.php']);

        $data = ReportData::build('/r', $nodes, $edges, 6, $impact, 'main');

        $this->assertSame('main', $data['impact']['base']);
        $this->assertSame('S::s', $data['impact']['changes'][0]['symbol']);
        $this->assertTrue($data['impact']['changes'][0]['signature']);
        $this->assertSame('CONFIRMED', $data['impact']['entries']['route GET /a']['confidence']);
        $this->assertSame(['T::t' => 'tests/T.php'], $data['impact']['tests']);
        $this->assertNull(ReportData::build('/r', $nodes, $edges, 6)['impact']);
    }

    public function test_the_html_is_self_contained_and_data_cannot_break_out_of_its_script_tag(): void
    {
        [$nodes, $edges] = $this->graph();
        $nodes[] = new Node(NodeKind::Route, 'route GET /</script><script>alert(1)</script>', '', 0, 0);

        $html = HtmlReport::render(ReportData::build('/r', $nodes, $edges, 6));

        $this->assertStringNotContainsString('</script><script>alert', $html);
        $this->assertStringNotContainsString('__BLASTRADIUS_DATA__', $html);

        preg_match('#<script id="report-data" type="application/json">(.*?)</script>#s', $html, $match);
        $decoded = json_decode($match[1] ?? throw new \LogicException('data script missing'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('route GET /</script><script>alert(1)</script>', array_column($decoded['entries'], 'name'));

        // nothing is fetched from the internet: no external script, stylesheet, image or link
        // (url(#id) points at a marker inside the same page, which is fine)
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src=|<link\b|<img\b|@import|url\((?!#)/i', $html);
        preg_match_all('#https?://[^\s"\'<)]+#', $html, $urls);
        $this->assertSame(['http://www.w3.org/2000/svg'], array_values(array_unique($urls[0])), 'only the SVG namespace may look like a URL');
    }
}
