<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * CLAUDE.md's footprint rule: the commercial product the August ERP base was once
 * studied from is never named anywhere in the repository, its history included;
 * and commit messages never say which tool or model wrote them.
 *
 * Central's history starts where the August ERP base was brought in (the commit
 * that added config/client.php); what the repository held before that commit is
 * the previous system, kept for reference, and is not checked.
 */
class FootprintTest extends TestCase
{
    /** The word is assembled so this file does not name it either. */
    private const WORD = 'accu'.'rate';

    public function test_the_original_product_is_not_named_anywhere(): void
    {
        $result = Process::path(base_path())->run(['git', 'grep', '-i', '-l', self::WORD, '--', '.']);

        $this->assertSame('', trim($result->output()), "Named in:\n".$result->output());
    }

    public function test_nor_anywhere_in_the_history(): void
    {
        $range = $this->rangeSinceBootstrap();
        // Any letter case: [Aa][Cc]… for the content and the paths, --regexp-ignore-case for the messages.
        $pattern = implode('', array_map(fn (string $c) => '['.strtoupper($c).$c.']', str_split(self::WORD)));
        $git = fn (array $args) => trim(Process::path(base_path())->run(['git', ...$args])->output());

        $this->assertSame('', $git(['log', $range, '--format=%h', '-G', $pattern]), 'commits whose changes name it');
        $this->assertSame('', $git(['log', $range, '--format=%h', '--regexp-ignore-case', '--grep', self::WORD]), 'commit messages that name it');
        $paths = array_filter(explode("\n", $git(['log', $range, '--format=', '--name-only'])), fn (string $path) => stripos($path, self::WORD) !== false);
        $this->assertSame([], array_values(array_unique($paths)), 'paths that name it');
    }

    public function test_commit_messages_never_say_which_tool_or_model_wrote_them(): void
    {
        $messages = Process::path(base_path())->run(['git', 'log', $this->rangeSinceBootstrap(), '--format=%B'])->output();

        $this->assertDoesNotMatchRegularExpression('/^\s*(co-authored-by:.*(claude|anthropic)|claude-session:)/im', $messages);
    }

    /** `<bootstrap>..HEAD`: the commits after the one that brought the base in. */
    private function rangeSinceBootstrap(): string
    {
        $shallow = trim(Process::path(base_path())->run(['git', 'rev-parse', '--is-shallow-repository'])->output());
        if ($shallow !== 'false') {
            $this->markTestSkipped('A shallow clone has no history to read (CI checks out with fetch-depth 0).');
        }
        $bootstrap = trim(Process::path(base_path())->run(['git', 'log', '--format=%H', '--diff-filter=A', '--', 'config/client.php'])->output());
        $bootstrap = trim((string) strrchr("\n".$bootstrap, "\n"));

        return $bootstrap === '' ? 'HEAD' : $bootstrap.'..HEAD';
    }
}
