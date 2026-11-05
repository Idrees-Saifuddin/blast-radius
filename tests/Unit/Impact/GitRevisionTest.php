<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Impact;

use BlastRadius\Impact\Git;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GitRevisionTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function revisions(): array
    {
        return [
            'branch' => ['main', true],
            'feature branch' => ['feature/login-v2', true],
            'relative commit' => ['HEAD~1', true],
            'caret' => ['HEAD^', true],
            'origin' => ['origin/main', true],
            'sha' => ['2508a93', true],
            'looks like an option' => ['--output=/tmp/x', false],
            'single dash' => ['-p', false],
            'shell characters' => ['main; rm -rf /', false],
            'space' => ['main foo', false],
            'newline' => ["main\n--exec", false],
            'empty' => ['', false],
        ];
    }

    /**
     * @dataProvider revisions
     */
    #[DataProvider('revisions')]
    public function test_only_plain_revisions_are_accepted(string $revision, bool $safe): void
    {
        $this->assertSame($safe, Git::isSafeRevision($revision));
    }

    public function test_the_git_class_itself_refuses_option_like_revisions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid revision');

        (new Git(sys_get_temp_dir()))->mergeBase('--upload-pack=evil');
    }
}
