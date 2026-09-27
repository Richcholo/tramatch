<?php

namespace App\Services\Crawling;

use App\Models\DestinationSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RobotsPolicyService
{
    public function check(
        DestinationSource $source,
        ?string $userAgent = null
    ): array {
        $userAgent ??= (string) config('crawling.token', 'TraMatchBot');

        if (
            $source->robots_checked_at
            && $source->robots_checked_at->gt(now()->subHours(24))
        ) {
            return $this->evaluate(
                (string) $source->robots_content,
                (int) $source->robots_status,
                $source->crawlUrl(),
                $userAgent,
                (int) $source->crawl_delay_seconds
            );
        }

        $robotsUrl = $this->robotsUrl($source->crawlUrl());

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/plain',
                ])
                ->get($robotsUrl);
        } catch (ConnectionException $exception) {
            return $this->unreadable(
                'robots.txt could not be retrieved ('
                    .$this->cleanTransportMessage($exception).').',
                $userAgent
            );
        }

        $status = $response->status();
        $content = $response->successful()
            ? substr($response->body(), 0, 512 * 1024)
            : '';

        $source->update([
            'robots_status' => $status,
            'robots_content' => $content,
            'robots_checked_at' => now(),
        ]);

        return $this->evaluate(
            $content,
            $status,
            $source->crawlUrl(),
            $userAgent,
            (int) $source->crawl_delay_seconds
        );
    }

    public function robotsVerdictFor(string $url, ?string $userAgent = null): array
    {
        $userAgent ??= (string) config('crawling.token', 'TraMatchBot');

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/plain',
                ])
                ->get($this->robotsUrl($url));
        } catch (ConnectionException) {
            return [
                'state' => 'unknown',
                'reason' => 'robots.txt could not be retrieved.',
            ];
        }

        $status = $response->status();
        $content = $response->successful()
            ? substr($response->body(), 0, 512 * 1024)
            : '';

        $evaluated = $this->evaluate(
            $content,
            $status,
            $url,
            $userAgent,
            $this->minDelay()
        );

        return [
            'state' => $evaluated['state'],
            'reason' => $evaluated['reason'],
        ];
    }

    private function unreadable(string $reason, string $userAgent): array
    {
        return [
            'allowed' => true,
            'state' => 'unknown',
            'delay' => $this->minDelay(),
            'reason' => $reason,
        ];
    }

    private function cleanTransportMessage(ConnectionException $exception): string
    {
        $message = $exception->getMessage();

        if (preg_match('/cURL error (\d+):\s*([^(\r\n]+)/i', $message, $match) === 1) {
            return 'cURL error '.$match[1].': '.trim($match[2]);
        }

        return trim($message);
    }

    private function minDelay(): int
    {
        return (int) config('crawling.min_delay_seconds', 5);
    }

    private function robotsUrl(string $sourceUrl): string
    {
        $parts = parse_url($sourceUrl);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port . '/robots.txt';
    }

    private function evaluate(
        string $content,
        int $status,
        string $sourceUrl,
        string $userAgent,
        int $defaultDelay
    ): array {
        $delay = max($this->minDelay(), $defaultDelay);

        if ($status === 404) {
            return [
                'allowed' => true,
                'state' => 'allow',
                'delay' => $delay,
                'reason' => 'robots.txt was not found; no path restrictions were published.',
            ];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'allowed' => true,
                'state' => 'unknown',
                'delay' => $delay,
                'reason' => 'robots.txt returned HTTP '.$status
                    .' and could not be read, so its restrictions are unknown.'
                    .' Trying the page once and recording what happened.',
            ];
        }

        $groups = $this->parseGroups($content);
        $path = parse_url($sourceUrl, PHP_URL_PATH) ?: '/';
        $selectedGroups = $this->selectGroups($groups, $userAgent);

        if (empty($selectedGroups)) {
            return [
                'allowed' => true,
                'state' => 'allow',
                'delay' => $delay,
                'reason' => 'No matching robots.txt group was found.',
            ];
        }

        $rules = [];
        $crawlDelay = $defaultDelay;

        foreach ($selectedGroups as $group) {
            foreach ($group['rules'] as $rule) {
                if ($rule['type'] === 'crawl-delay') {
                    $crawlDelay = max(
                        $this->minDelay(),
                        (int) ceil((float) $rule['value'])
                    );

                    continue;
                }

                if ($rule['value'] === '') {
                    continue;
                }

                if ($this->matches($path, $rule['value'])) {
                    $rules[] = $rule;
                }
            }
        }

        $delay = max($this->minDelay(), $crawlDelay);

        if (empty($rules)) {
            return [
                'allowed' => true,
                'state' => 'allow',
                'delay' => $delay,
                'reason' => 'No matching Allow or Disallow rule was found.',
            ];
        }

        usort($rules, function (array $left, array $right) {
            $length = strlen($right['value']) <=> strlen($left['value']);

            if ($length !== 0) {
                return $length;
            }

            return ($right['type'] === 'allow') <=> ($left['type'] === 'allow');
        });

        $bestRule = $rules[0];

        return [
            'allowed' => $bestRule['type'] === 'allow',
            'state' => $bestRule['type'] === 'allow' ? 'allow' : 'disallow',
            'delay' => $delay,
            'reason' => $bestRule['type'] === 'allow'
                ? 'The matching robots.txt rule allows this path.'
                : 'The matching robots.txt rule disallows this path.',
        ];
    }

    private function parseGroups(string $content): array
    {
        $groups = [];
        $current = null;
        $hasRules = false;

        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));

            if ($line === '') {
                if ($current !== null && $hasRules) {
                    $groups[] = $current;
                    $current = null;
                    $hasRules = false;
                }

                continue;
            }

            [$key, $value] = array_pad(
                explode(':', $line, 2),
                2,
                ''
            );

            $key = strtolower(trim($key));
            $value = trim($value);

            if ($key === 'user-agent') {
                if ($current !== null && $hasRules) {
                    $groups[] = $current;
                    $current = null;
                    $hasRules = false;
                }

                $current ??= [
                    'agents' => [],
                    'rules' => [],
                ];

                $current['agents'][] = strtolower($value);
                continue;
            }

            $current ??= [
                'agents' => [],
                'rules' => [],
            ];

            if (in_array($key, ['allow', 'disallow', 'crawl-delay'], true)) {
                $current['rules'][] = [
                    'type' => $key,
                    'value' => $value,
                ];

                $hasRules = true;
            }
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function selectGroups(array $groups, string $userAgent): array
    {
        $userAgent = strtolower($userAgent);
        $exact = [];
        $wildcard = [];

        foreach ($groups as $group) {
            if (in_array($userAgent, $group['agents'], true)) {
                $exact[] = $group;
            } elseif (in_array('*', $group['agents'], true)) {
                $wildcard[] = $group;
            }
        }

        return !empty($exact) ? $exact : $wildcard;
    }

    private function matches(string $path, string $pattern): bool
    {
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\\*', '.*', $pattern);

        return preg_match('#^' . $pattern . '#', $path) === 1;
    }
}
