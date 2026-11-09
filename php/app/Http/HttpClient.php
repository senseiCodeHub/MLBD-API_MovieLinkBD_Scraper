<?php
declare(strict_types=1);

namespace Mlbd\Http;

/**
 * Small cURL wrapper used by the scraper.
 *
 * It supports single requests and a bounded parallel queue (curl_multi) so the
 * catalogue crawl and the media probes keep the same concurrency profile as the
 * original Python implementation.
 */
final class HttpClient
{
    private int $timeout;
    private int $connectTimeout;

    public function __construct(int $timeout = 30, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Perform one request.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options  follow (bool), timeout (int),
     *                                        max_redirects (int), body (string),
     *                                        method (string)
     * @return array{ok:bool,status:int,headers:array<string,string>,body:string,url:string,error:?string}
     */
    public function request(string $url, array $headers = [], array $options = []): array
    {
        $attempts = max(1, 1 + (int) ($options['retries'] ?? 2));
        $result = ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'url' => $url, 'error' => 'no attempt made'];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = $this->handle($url, $headers, $options);
            $body = curl_exec($ch);
            $result = $this->result($ch, $body, $url);
            curl_close($ch);

            if ($attempt >= $attempts || !$this->isRetryable($result)) {
                break;
            }

            // Short, jittered backoff: enough to ride out a reset connection or
            // a one-off 5xx edge node without stalling the sync for long.
            usleep((int) (220000 * (2 ** ($attempt - 1)) + random_int(0, 120000)));
        }

        return $result;
    }

    /**
     * Only transport hiccups and gateway 5xx are worth another attempt.
     * SSL, DNS and Cloudflare responses are deterministic for this host.
     *
     * @param array<string, mixed> $result
     */
    private function isRetryable(array $result): bool
    {
        $error = (string) ($result['error'] ?? '');
        if ($error !== '') {
            return !preg_match('/SSL|certificate|resolve|Could not resolve|URL using bad/i', $error);
        }

        return in_array((int) ($result['status'] ?? 0), [500, 502, 504], true);
    }

    /**
     * Fetch several URLs in parallel with a bounded number of in-flight handles.
     *
     * The callback receives ($url, $result) as each response completes.
     *
     * @param list<string>          $urls
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     * @param callable(string, array):void $onResponse
     */
    public function multiGet(
        array $urls,
        int $concurrency,
        callable $onResponse,
        array $headers = [],
        array $options = []
    ): void {
        $urls = array_values($urls);
        if ($urls === []) {
            return;
        }

        $concurrency = max(1, $concurrency);
        $queue = $urls;
        $active = [];
        $multi = curl_multi_init();

        $addNext = function () use (&$queue, &$active, $multi, $headers, $options): void {
            if ($queue === []) {
                return;
            }
            $url = array_shift($queue);
            $ch = $this->handle($url, $headers, $options);
            $key = spl_object_id($ch);
            $active[$key] = ['url' => $url, 'handle' => $ch];
            curl_multi_add_handle($multi, $ch);
        };

        for ($i = 0; $i < $concurrency; $i++) {
            $addNext();
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($status !== CURLM_OK) {
                break;
            }

            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                $key = spl_object_id($ch);
                $url = $active[$key]['url'] ?? '';
                $body = curl_multi_getcontent($ch);
                $result = $this->result($ch, $body, $url);

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                unset($active[$key]);

                $onResponse($url, $result);
                $addNext();
            }

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 || $active !== []);

        curl_multi_close($multi);
    }

    /**
     * GET a URL and decode its JSON body.
     *
     * @param array<string, string> $headers
     * @return mixed
     */
    public function getJson(string $url, array $headers = [], int $timeout = 30)
    {
        $response = $this->request($url, $headers, ['timeout' => $timeout]);
        if (!$response['ok']) {
            return null;
        }
        $data = json_decode($response['body'], true);
        return $data === null ? null : $data;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     * @return \CurlHandle
     */
    private function handle(string $url, array $headers, array $options)
    {
        $ch = curl_init();

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        $timeout = (int) ($options['timeout'] ?? $this->timeout);
        $follow = (bool) ($options['follow'] ?? true);

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS      => (int) ($options['max_redirects'] ?? 8),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_USERAGENT      => $headers['user-agent'] ?? ($headers['User-Agent'] ?? 'Mozilla/5.0'),
        ];

        // Some Windows/XAMPP builds ship no CA store; shared hosts usually do,
        // so only override the bundle when one is configured explicitly.
        $caBundle = (string) config('scraper.ca_bundle', '');
        if ($caBundle !== '' && is_file($caBundle)) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        if ($method === 'HEAD') {
            $curlOptions[CURLOPT_NOBODY] = true;
            $curlOptions[CURLOPT_CUSTOMREQUEST] = 'HEAD';
        } elseif ($method !== 'GET') {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
            if (isset($options['body'])) {
                $curlOptions[CURLOPT_POSTFIELDS] = (string) $options['body'];
            }
        }

        // Collect response headers across redirects; later blocks win.
        $responseHeaders = [];
        $curlOptions[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = [];
                }
                return $length;
            }
            $parts = explode(':', $trimmed, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        };

        curl_setopt_array($ch, $curlOptions);

        // Stash the header collector on the handle through a closure table.
        self::$headerCollectors[spl_object_id($ch)] = &$responseHeaders;

        return $ch;
    }

    /** @var array<int, array<string, string>> */
    private static array $headerCollectors = [];

    /**
     * @param \CurlHandle|resource $ch
     * @param string|false         $body
     * @return array{ok:bool,status:int,headers:array<string,string>,body:string,url:string,error:?string}
     */
    private function result($ch, $body, string $requestedUrl): array
    {
        $key = spl_object_id($ch);
        $headers = self::$headerCollectors[$key] ?? [];
        unset(self::$headerCollectors[$key]);

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;

        return [
            'ok'      => $error === null && $body !== false,
            'status'  => $status,
            'headers' => $headers,
            'body'    => $body === false ? '' : (string) $body,
            'url'     => $effectiveUrl !== '' ? $effectiveUrl : $requestedUrl,
            'error'   => $error,
        ];
    }
}
