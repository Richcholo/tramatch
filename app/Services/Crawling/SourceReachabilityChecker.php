<?php

namespace App\Services\Crawling;

use App\Models\DestinationSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SourceReachabilityChecker
{
    private const SPARSE_TEXT = 700;

    private const FRAMEWORK_MARKERS = [
        '__NEXT_DATA__',
        '/_next/',
        'self.__next_f',
        'ng-version',
        '__NUXT__',
        'data-reactroot',
        'window.__INITIAL_STATE__',
    ];

    public function __construct(private readonly RobotsPolicyService $robots) {}

    public function check(DestinationSource $source): array
    {
        $url = $source->crawlUrl();
        $verdict = $this->robots->robotsVerdictFor($url);

        if ($verdict['state'] === 'disallow') {
            return $this->record(
                $source,
                DestinationSource::ROBOTS_BLOCKED,
                $verdict['reason']
            );
        }

        $prefix = $verdict['state'] === 'unknown'
            ? 'robots.txt was unavailable, so the page was tried anyway. '
            : '';

        try {
            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withHeaders([
                    'User-Agent' => (string) config('crawling.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-PH,en;q=0.9',
                ])
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);
        } catch (ConnectionException $exception) {
            return $this->record(
                $source,
                DestinationSource::UNREACHABLE,
                $prefix.Str::limit($exception->getMessage(), 180)
            );
        }

        $status = $response->status();

        if (in_array($status, [404, 410], true)) {
            return $this->record(
                $source,
                DestinationSource::DEAD,
                $prefix."HTTP {$status}"
            );
        }

        if ($status < 200 || $status >= 300) {
            return $this->record(
                $source,
                DestinationSource::BOT_WALL,
                $prefix."HTTP {$status}"
            );
        }

        $body = $response->body();
        $text = $this->visibleText($body);
        $length = strlen($text);

        if ($length < self::SPARSE_TEXT) {
            $framework = $this->frameworkMarker($body);

            if ($framework !== null) {
                return $this->record(
                    $source,
                    DestinationSource::JS_RENDERED,
                    $prefix."Rendered by JavaScript ({$framework}); only {$length} "
                        .'characters arrive without JavaScript.'
                );
            }

            return $this->record(
                $source,
                DestinationSource::THIN_PAGE,
                $prefix."Only {$length} characters of text — the page is "
                    .'reachable but nearly empty, so it will yield nothing.'
            );
        }

        return $this->record(
            $source,
            DestinationSource::FETCHABLE,
            $prefix."{$length} characters of readable text."
        );
    }

    private function record(
        DestinationSource $source,
        string $fetchability,
        string $note
    ): array {
        $source->forceFill([
            'fetchability' => $fetchability,
            'fetchability_checked_at' => now(),
            'fetchability_note' => $note,
        ])->save();

        return [
            'fetchability' => $fetchability,
            'note' => $note,
        ];
    }

    private function visibleText(string $body): string
    {
        $stripped = preg_replace(
            '#<(script|style|noscript)\b[^>]*>.*?</\1>#is',
            ' ',
            $body
        );

        return trim(preg_replace(
            '/\s+/',
            ' ',
            html_entity_decode(strip_tags((string) $stripped))
        ));
    }

    private function frameworkMarker(string $body): ?string
    {
        foreach (self::FRAMEWORK_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return $marker;
            }
        }

        return null;
    }
}
