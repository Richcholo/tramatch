<?php

namespace App\Services\Crawling;

use App\Models\DestinationSource;
use App\Models\DestinationSourceSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EthicalSourceFetcher
{
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private RobotsPolicyService $robots,
        private DestinationSourceParser $parser
    ) {
    }

    public function fetch(
        DestinationSource $source,
        int $attempts = 1
    ): DestinationSourceSnapshot {
        for ($attempt = 1; ; $attempt++) {
            $startedAt = now();
            $previousHash = $source->snapshots()
                ->latest('fetched_at')
                ->value('content_hash');

            try {
                return $this->attemptFetch($source, $attempt, $startedAt, $previousHash);
            } catch (ConnectionException $exception) {
                $classification = $this->classify($exception);

                $source->refresh();

                $suffix = $classification['retryable']
                    ? " (gave up after {$attempts} attempt(s))"
                    : ' (not retried: this is not a transient failure)';

                $source->update([
                    'status' => 'failed',
                    'last_checked_at' => now(),
                    'fetchability' => $classification['fetchability'],
                    'fetchability_note' => $classification['note'],
                    'fetchability_checked_at' => now(),
                    'error_message' => $classification['message']
                        .$suffix
                        .$this->caBundleHint(),
                ]);

                if (!$classification['retryable'] || $attempt >= $attempts) {
                    throw $exception;
                }

                report($exception);

                sleep(min(10 * $attempt, 30));
            }
        }
    }

    private function classify(ConnectionException $exception): array
    {
        $message = $exception->getMessage();
        $clean = $this->cleanTransportMessage($message);

        if (stripos($message, 'Could not resolve host') !== false) {
            return [
                'retryable' => false,
                'fetchability' => DestinationSource::DEAD,
                'note' => 'The host does not resolve, so nothing can be crawled'
                    .' from it. Point this source at a different domain or clear it.',
                'message' => 'The host does not resolve: '.$clean,
            ];
        }

        if (stripos($message, 'certificate') !== false) {
            return [
                'retryable' => false,
                'fetchability' => DestinationSource::UNREACHABLE,
                'note' => 'The TLS certificate could not be verified. Fix the trust'
                    .' store on this machine rather than disabling verification.',
                'message' => 'TLS verification failed: '.$clean,
            ];
        }

        return [
            'retryable' => true,
            'fetchability' => DestinationSource::UNREACHABLE,
            'note' => 'The host did not answer on this attempt. This can be'
                .' temporary, so retry before replacing the source.',
            'message' => 'Could not reach the source: '.$clean,
        ];
    }

    private function cleanTransportMessage(string $message): string
    {
        if (preg_match('/cURL error (\d+):\s*([^(\r\n]+)/i', $message, $match) === 1) {
            return 'cURL error '.$match[1].': '.trim($match[2]);
        }

        return trim($message);
    }

    private function caBundleHint(): string
    {
        foreach (['curl.cainfo', 'openssl.cafile'] as $key) {
            $path = (string) ini_get($key);

            if ($path !== '' && is_file($path)) {
                return '';
            }
        }

        return ' Note: this PHP process has no CA bundle configured'.
            ' (curl.cainfo and openssl.cafile are empty or missing), which'.
            ' causes exactly this failure. If php.ini changed recently,'.
            ' restart php artisan serve or Apache so the new setting is'.
            ' picked up.';
    }

    private function attemptFetch(
        DestinationSource $source,
        int $attempt,
        CarbonInterface $startedAt,
        ?string $previousHash
    ): DestinationSourceSnapshot {
        $requestedUrl = $source->crawlUrl();
        $this->validateUrl($requestedUrl);

        $policy = $this->robots->check($source);

        if (!$policy['allowed']) {
            $source->update([
                'allowed_by_policy' => false,
                'status' => 'blocked',
                'last_checked_at' => now(),
                'error_message' => $policy['reason'],
            ]);

            $this->log($source, $attempt, 'blocked', $startedAt, [
                'error_message' => $policy['reason'],
                'robots_state' => $policy['state'] ?? 'disallow',
            ]);

            throw new RuntimeException($policy['reason']);
        }

        $source->update([
            'allowed_by_policy' => true,
            'status' => 'crawling',
            'last_checked_at' => now(),
            'crawl_delay_seconds' => $policy['delay'],
            'error_message' => null,
        ]);

        sleep(max($this->minDelay(), (int) $policy['delay']));

        $headers = [
            'User-Agent' => (string) config(
                'crawling.user_agent',
                'TraMatchBot/1.0 (+mailto:admin@example.com)'
            ),
            'Accept' => 'text/html,application/xhtml+xml',
        ];

        if ($source->etag) {
            $headers['If-None-Match'] = $source->etag;
        }

        if ($source->last_modified) {
            $headers['If-Modified-Since'] = $source->last_modified;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(8)
                ->withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => self::MAX_REDIRECTS,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'track_redirects' => false,
                    ],
                ])
                ->get($requestedUrl);
        } catch (ConnectionException $exception) {
            $this->log($source, $attempt, 'failed', $startedAt, [
                'error_message' => 'The host did not answer: '
                    .$this->cleanTransportMessage($exception->getMessage()),
                'requested_url' => $requestedUrl,
            ]);

            throw $exception;
        }

        $status = $response->status();
        $finalUrl = (string) $response->effectiveUri();

        if ($finalUrl !== '' && $finalUrl !== $requestedUrl) {
            $verdict = $this->reviewRedirect($finalUrl);

            if ($verdict['blocked']) {
                $source->update([
                    'status' => 'failed',
                    'error_message' => $verdict['message'],
                ]);

                $this->log($source, $attempt, 'failed', $startedAt, [
                    'http_status' => $status,
                    'requested_url' => $requestedUrl,
                    'final_url' => $finalUrl,
                    'error_message' => $verdict['message'],
                ]);

                throw new RuntimeException($verdict['message']);
            }
        } else {
            $finalUrl = $requestedUrl;
        }

        $source->update([
            'last_checked_at' => now(),
            'etag' => $response->header('ETag') ?: $source->etag,
            'last_modified' => $response->header('Last-Modified') ?: $source->last_modified,
        ]);

        if ($status === 304) {
            $source->update([
                'status' => 'unchanged',
                'last_success_at' => now(),
                'error_message' => null,
            ]);

            $this->log($source, $attempt, 'unchanged', $startedAt, [
                'http_status' => $status,
                'content_changed' => false,
                'requested_url' => $requestedUrl,
                'final_url' => $finalUrl,
            ]);

            return $source->snapshots()->latest('fetched_at')->firstOrFail();
        }

        if ($status < 200 || $status >= 300) {
            $refusal = $this->describeRefusal($response);

            $source->update([
                'status' => 'failed',
                'error_message' => $refusal['message'],
                'fetchability' => $refusal['fetchability'],
                'fetchability_note' => $refusal['note'],
                'fetchability_checked_at' => now(),
            ]);

            $this->log($source, $attempt, 'failed', $startedAt, [
                'http_status' => $status,
                'requested_url' => $requestedUrl,
                'final_url' => $finalUrl,
                'error_message' => $refusal['message'],
            ]);

            throw new RuntimeException($refusal['message']);
        }

        $contentType = strtolower(
            (string) $response->header('Content-Type')
        );

        if (
            !str_contains($contentType, 'text/html')
            && !str_contains($contentType, 'application/xhtml+xml')
        ) {
            $message = 'Source did not return an HTML document.';

            $source->update([
                'status' => 'failed',
                'error_message' => $message,
            ]);

            $this->log($source, $attempt, 'failed', $startedAt, [
                'http_status' => $status,
                'error_message' => $message,
                'requested_url' => $requestedUrl,
                'final_url' => $finalUrl,
            ]);

            throw new RuntimeException($message);
        }

        $body = $response->body();

        if (strlen($body) > 2 * 1024 * 1024) {
            $message = 'Source document exceeded the 2 MB crawl limit.';

            $source->update([
                'status' => 'failed',
                'error_message' => $message,
            ]);

            $this->log($source, $attempt, 'failed', $startedAt, [
                'http_status' => $status,
                'error_message' => $message,
                'requested_url' => $requestedUrl,
                'final_url' => $finalUrl,
            ]);

            throw new RuntimeException($message);
        }

        $hash = hash('sha256', $body);
        $path = 'crawl-snapshots/'
            . $source->id
            . '/'
            . now()->format('Ymd_His')
            . '.html';

        Storage::disk('local')->put($path, $body);

        $snapshot = $source->snapshots()->create([
            'content_hash' => $hash,
            'raw_content_path' => $path,
            'http_status' => $status,
            'content_type' => $contentType,
            'fetched_at' => now(),
            'parser_version' => '1.0',
        ]);

        $extracted = $this->parser->extract($body);
        $created = $this->parser->createProposals($source, $extracted);

        $source->update(array_merge([
            'status' => 'success',
            'last_success_at' => now(),
            'error_message' => null,
        ], $this->clearedFetchability($source)));

        $this->log($source, $attempt, 'success', $startedAt, [
            'http_status' => $status,
            'bytes' => strlen($body),
            'content_hash' => $hash,
            'content_changed' => $previousHash === null
                ? null
                : $previousHash !== $hash,
            'extracted_fields' => array_keys($extracted),
            'proposals_created' => $created,
            'requested_url' => $requestedUrl,
            'final_url' => $finalUrl,
        ]);

        return $snapshot;
    }

    private function clearedFetchability(DestinationSource $source): array
    {
        $stale = [
            DestinationSource::DEAD,
            DestinationSource::UNREACHABLE,
            DestinationSource::BOT_WALL,
            DestinationSource::ROBOTS_BLOCKED,
        ];

        if (!in_array((string) $source->fetchability, $stale, true)) {
            return [];
        }

        return [
            'fetchability' => null,
            'fetchability_note' => null,
            'fetchability_checked_at' => null,
        ];
    }

    private function describeRefusal(Response $response): array
    {
        $status = $response->status();
        $server = strtolower((string) $response->header('Server'));
        $body = strtolower(substr($response->body(), 0, 600));

        if ($status === 404 || $status === 410) {
            return [
                'message' => "Source returned HTTP {$status}.",
                'fetchability' => DestinationSource::DEAD,
                'note' => 'The page is gone. Point this source at a current page'
                    .' or clear it.',
            ];
        }

        if ($status === 403 || $status === 401) {
            $wall = $this->identifyWall($server, $body);

            return [
                'message' => "Source returned HTTP {$status} ({$wall['label']}).",
                'fetchability' => DestinationSource::BOT_WALL,
                'note' => $wall['note'],
            ];
        }

        if ($status >= 500) {
            return [
                'message' => "Source returned HTTP {$status}.",
                'fetchability' => DestinationSource::UNREACHABLE,
                'note' => 'The site returned a server error. This is often'
                    .' temporary, so retry before replacing the source.',
            ];
        }

        return [
            'message' => "Source returned HTTP {$status}.",
            'fetchability' => DestinationSource::UNREACHABLE,
            'note' => 'The site answered with an unexpected status. Check the page'
                .' in a browser before trusting anything else about it.',
        ];
    }

    private function identifyWall(string $server, string $body): array
    {
        $challenge = str_contains($server, 'cloudflare')
            || str_contains($body, 'just a moment')
            || str_contains($body, 'cf-browser-verification')
            || str_contains($body, 'enable javascript and cookies');

        if ($challenge) {
            return [
                'label' => 'edge security challenge',
                'note' => 'A Cloudflare challenge page was returned instead of the'
                    .' article. The site is not refusing crawlers by policy; it wants a'
                    .' browser to solve a JavaScript challenge, so no amount of'
                    .' request tweaking will read it. Treat this source as'
                    .' human-only.',
            ];
        }

        if (str_contains($body, 'azure waf') || str_contains($body, 'azwaf')) {
            return [
                'label' => 'WAF block',
                'note' => 'An Azure web application firewall refused the request.'
                    .' These rules commonly reject traffic from a data centre IP,'
                    .' so the page may still be readable from a home connection.'
                    .' It is not a robots.txt decision.',
            ];
        }

        return [
            'label' => 'access denied',
            'note' => 'The server refused the request without saying why. Honest'
                .' Accept and Accept-Language headers are already sent and change'
                .' nothing here, so this is not a fixable request problem. Find a'
                .' different source for this destination.',
        ];
    }

    private function minDelay(): int
    {
        return (int) config('crawling.min_delay_seconds', 5);
    }

    private function reviewRedirect(string $finalUrl): array
    {
        try {
            $this->validateUrl($finalUrl);
        } catch (RuntimeException $exception) {
            return [
                'blocked' => true,
                'message' => 'The source redirected to a URL that is not allowed: '
                    .$exception->getMessage(),
            ];
        }

        $verdict = $this->robots->robotsVerdictFor($finalUrl);

        if ($verdict['state'] === 'disallow') {
            return [
                'blocked' => true,
                'message' => 'The source redirected to a URL that robots.txt disallows: '
                    .$finalUrl,
            ];
        }

        return ['blocked' => false, 'message' => ''];
    }

    private function log(
        DestinationSource $source,
        int $attempt,
        string $outcome,
        ?CarbonInterface $startedAt = null,
        array $attributes = []
    ): void {
        $finishedAt = now();

        $source->crawls()->create(array_merge([
            'attempt' => $attempt,
            'outcome' => $outcome,
            'started_at' => $startedAt ?? $finishedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $startedAt
                ? max(0, $finishedAt->diffInMilliseconds($startedAt, true))
                : null,
        ], $attributes));
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Only HTTP and HTTPS sources are allowed.');
        }

        if (
            $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.local')
            || $host === '127.0.0.1'
            || $host === '::1'
        ) {
            throw new RuntimeException('The source host is not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (
                !filter_var(
                    $host,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )
            ) {
                throw new RuntimeException('Private IP addresses are not allowed.');
            }
        }
    }
}
