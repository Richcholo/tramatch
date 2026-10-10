<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Files the deployment creates must never be visible to git.
 *
 * deploy.sh refuses to run against a dirty tree, which is the right guard: a
 * deploy carrying uncommitted edits makes the server diverge and turns the next
 * pull into a conflict. But it cannot tell an operator's work apart from an
 * artifact the deployment itself just wrote, so a single unignored artifact
 * blocks every deploy from then on, naming a file nobody touched.
 *
 * That is not hypothetical here. `public_html` was untracked and unignored, and
 * it cost a deploy before anyone added it. queue-worker.sh is the same shape and
 * worse, because it is *installed* by deploy.sh into a path that has to be
 * reachable by cron -- so every deploy creates it, by design.
 *
 * Checked by asking git, rather than by pattern-matching .gitignore, so a
 * negation or an ordering change upstream cannot make this pass while the real
 * behaviour differs.
 */
class DeployArtifactsAreIgnoredTest extends TestCase
{
    /**
     * Where the worker can legitimately be installed, and which rule covers each.
     *
     * deploy.sh defaults to the application root, which on a Layout B host is
     * public_html/ itself. Layout A symlinks public_html to public/, so the same
     * instruction lands in public/ -- and nothing else would match that, because
     * public/ is tracked.
     *
     * Note that `public_html/queue-worker.sh` is matched by the `/public_html`
     * rule, not by a worker-specific one. It is listed anyway, because the
     * outcome is what matters and it is the position a dev box mimicking Layout B
     * would produce. Do not read this list as "each has its own entry": check
     * with `git check-ignore -v` before concluding anything.
     */
    private const WORKER_POSITIONS = [
        'queue-worker.sh',
        'public/queue-worker.sh',
        'public_html/queue-worker.sh',
    ];

    #[Test]
    public function an_installed_worker_is_ignored_wherever_it_lands(): void
    {
        foreach (self::WORKER_POSITIONS as $path) {
            $result = $this->git(['check-ignore', '-q', '--', $path]);

            $this->assertSame(
                0,
                $result['status'],
                $path.' is not ignored, so installing the worker there makes git report an '
                .'uncommitted change and deploy.sh refuses to run on every subsequent deploy, '
                .'naming a file the operator never touched.'
            );
        }
    }

    /**
     * The web-root artifacts the layout scripts create.
     */
    #[Test]
    public function the_layout_artifacts_are_ignored(): void
    {
        foreach (['public_html', 'public_html.hostinger-backup'] as $path) {
            $result = $this->git(['check-ignore', '-q', '--', $path]);

            $this->assertSame(
                0,
                $result['status'],
                $path.' is not ignored. setup-website.sh creates it, and an unignored one blocks '
                .'every deploy.'
            );
        }
    }

    /**
     * The counter-check, so this file cannot pass by everything being ignored.
     */
    #[Test]
    public function real_project_files_are_not_ignored(): void
    {
        foreach (['deploy/deploy.sh', 'deploy/queue-worker.sh', 'public/.htaccess', 'artisan'] as $path) {
            $result = $this->git(['check-ignore', '-q', '--', $path]);

            $this->assertSame(
                1,
                $result['status'],
                $path.' is ignored, so the deploy would never carry it to the server'
            );
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return array{status: int, output: string}
     */
    private function git(array $arguments): array
    {
        $command = 'git '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1';

        exec($command, $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }
}
