<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The .htaccess deny rules.
 *
 * These are the only thing standing between the application root and the public
 * internet, and there is exactly one way to test them short of deploying: read
 * the rules and assert what they refuse. They have already leaked once --
 * storage/logs/laravel.log served with a 200, carrying the database password and
 * APP_KEY -- and a second leak shipped unnoticed because nothing here existed to
 * notice it.
 *
 * The duplication between the two files is deliberate and is itself the thing
 * most likely to rot: which one the server reads depends on the deployment
 * layout, so a rule added to one copy and not the other protects half the
 * installs and none of the other half.
 */
class HtaccessRulesTest extends TestCase
{
    /**
     * Both copies. public/.htaccess is read when the document root is public/
     * (Layout A); the root one when the application root is the document root
     * (Layout B), which is what a control-panel upload produces.
     */
    private const COPIES = ['.htaccess', 'public/.htaccess'];

    private function rules(string $path): string
    {
        $full = base_path($path);

        $this->assertFileExists(
            $full,
            $path.' is missing, so one of the two deployment layouts has no deny rules at all'
        );

        return (string) file_get_contents($full);
    }

    /**
     * The alternation inside a `<FilesMatch>` pattern.
     */
    private function filesMatchPattern(string $contents): string
    {
        preg_match('#<FilesMatch "([^"]+)"#', $contents, $matches);

        $this->assertNotEmpty(
            $matches[1],
            'no FilesMatch rule found, so no individual file is refused'
        );

        return $matches[1];
    }

    /**
     * The directory names in a `RedirectMatch 404 ^/(...)(/|$)` rule.
     */
    private function deniedDirectories(string $contents): array
    {
        preg_match_all('#RedirectMatch 404 \^/\(([^)]*)\)\(/\|\$\)#', $contents, $matches);

        $names = [];

        foreach ($matches[1] as $group) {
            $names = [...$names, ...explode('|', $group)];
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * The gap this was written for.
     *
     * The worker script is the one file here that gets copied to a path cron
     * points at, and the natural place to put that is the document root. Nothing
     * refused it: the directory rules only cover named directories, and the
     * front-controller rule has `!-f`, so a real file next to index.php is served
     * as-is. A copy of queue-worker.sh was therefore readable at
     * /queue-worker.sh, disclosing the deployment layout and the log path.
     *
     * It holds no secrets, which is not the same as being fine to publish.
     */
    #[Test]
    public function a_shell_script_at_the_document_root_is_refused(): void
    {
        foreach (self::COPIES as $path) {
            $pattern = $this->filesMatchPattern($this->rules($path));

            $this->assertSame(
                1,
                preg_match('~'.$pattern.'~', 'queue-worker.sh'),
                $path.' would serve /queue-worker.sh. The directory rules only cover named '
                .'directories, and the front-controller rule has !-f, so a real file in the '
                .'document root is returned as-is.'
            );
        }
    }

    /**
     * The secrets that already leaked once, refused in both copies.
     */
    #[Test]
    public function environment_and_lockfiles_are_refused_in_both_copies(): void
    {
        $dangerous = ['.env', '.env.production', 'composer.json', 'composer.lock', 'phpunit.xml'];

        foreach (self::COPIES as $path) {
            $pattern = $this->filesMatchPattern($this->rules($path));

            foreach ($dangerous as $file) {
                $this->assertSame(
                    1,
                    preg_match('~'.$pattern.'~', $file),
                    $path.' would serve /'.$file
                );
            }
        }
    }

    /**
     * The duplication drifting apart.
     *
     * Which file the server reads depends on the deployment layout, so a
     * directory refused in one copy but not the other leaves half the installs
     * unprotected with nothing to show for it.
     */
    #[Test]
    public function both_copies_refuse_the_same_directories(): void
    {
        $root = $this->deniedDirectories($this->rules('.htaccess'));
        $public = $this->deniedDirectories($this->rules('public/.htaccess'));

        $this->assertNotEmpty($root, 'the root .htaccess refuses no directories at all');
        $this->assertNotEmpty($public, 'public/.htaccess refuses no directories at all');

        $this->assertSame(
            $root,
            $public,
            'the two copies refuse different directories. Only one is read per layout, so a '
            .'name present in just one of them protects half the installs.'
        );
    }

    /**
     * The specific directories that hold everything worth stealing.
     */
    #[Test]
    public function the_application_directories_are_refused(): void
    {
        $expected = ['app', 'bootstrap', 'config', 'database', 'deploy', 'routes', 'tests', 'vendor'];

        foreach (self::COPIES as $path) {
            $denied = $this->deniedDirectories($this->rules($path));

            foreach ($expected as $directory) {
                $this->assertContains(
                    $directory,
                    $denied,
                    $path.' would serve /'.$directory.'/, which holds application source'
                );
            }
        }
    }

    /**
     * storage/ is only partly refused by name, so the log and the crawl snapshots
     * must be in that list specifically. Denying all of /storage would break the
     * public/storage symlink that serves uploaded images.
     */
    #[Test]
    public function the_log_and_snapshot_directories_are_refused_by_name(): void
    {
        foreach (self::COPIES as $path) {
            $contents = $this->rules($path);

            foreach (['logs', 'private', 'app', 'framework'] as $directory) {
                $this->assertMatchesRegularExpression(
                    '#RedirectMatch 404 \^/storage/\([^)]*\b'.$directory.'\b#',
                    $contents,
                    $path.' does not refuse /storage/'.$directory.'/ by name'
                );
            }
        }
    }

    /**
     * A directory listing of public_html/ would enumerate app/, config/ and
     * vendor/ even with every path above refused, because the names themselves
     * are the disclosure.
     */
    #[Test]
    public function neither_copy_offers_a_directory_index(): void
    {
        foreach (self::COPIES as $path) {
            $this->assertMatchesRegularExpression(
                '/Options[^\n]*-Indexes/',
                $this->rules($path),
                $path.' does not disable directory indexes'
            );
        }
    }
}
