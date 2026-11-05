<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Impact;

use BlastRadius\Impact\DiffParser;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    public function test_it_parses_hunks_new_deleted_and_single_line_changes(): void
    {
        $diff = <<<'DIFF'
diff --git a/app/A.php b/app/A.php
index 111..222 100644
--- a/app/A.php
+++ b/app/A.php
@@ -10,2 +10,3 @@ class A
-old
-old2
+new
+new2
+new3
@@ -30 +31,0 @@
-gone
diff --git a/app/New.php b/app/New.php
new file mode 100644
--- /dev/null
+++ b/app/New.php
@@ -0,0 +1,5 @@
+x
diff --git a/app/Old.php b/app/Old.php
deleted file mode 100644
--- a/app/Old.php
+++ /dev/null
@@ -1,4 +0,0 @@
-x
DIFF;

        [$a, $new, $old] = DiffParser::parse($diff);

        $this->assertSame('app/A.php', $a->path);
        $this->assertSame([[10, 12]], $a->addedRanges, 'a pure deletion has no new-side range');
        $this->assertSame([[10, 11], [30, 30]], $a->removedRanges);

        $this->assertTrue($new->isNew);
        $this->assertSame([[1, 5]], $new->addedRanges);
        $this->assertSame([], $new->removedRanges);

        $this->assertTrue($old->isDeleted);
        $this->assertSame('app/Old.php', $old->path);
        $this->assertSame([[1, 4]], $old->removedRanges);
    }
}
