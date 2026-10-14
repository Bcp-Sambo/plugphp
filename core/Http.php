<?php

/**
 * Http — THE ONLY WAY TO MAKE AN OUTBOUND REQUEST IN THIS PROJECT.
 *
 * Two methods, both speaking JSON, because the only thing this project calls
 * out to is a payment gateway's REST API. GET and POST are what those APIs
 * need; PUT and DELETE are not added until something actually needs them.
 *
 * Security properties that are NOT configurable, deliberately:
 *
 *   - HTTPS only. An http:// URL is refused, not upgraded.
 *   - Certificate verification is always on. There is no parameter to
 *     disable it, so no amount of "just make it work" pressure can produce
 *     a request that silently accepts any certificate.
 *   - Redirects are never followed. A 3xx response would otherwise re-send
 *     the Authorization header to whatever host the response named — that is
 *     a secret-leak primitive, and no gateway API needs redirects.
 *   - Responses are capped. A caller cannot be made to buffer an unbounded
 *     body by the far end.
 *   - Nothing that could carry a secret is ever logged: not a header, not a
 *     request body, and not a URL's query string.
 *
 * AI AGENTS: never use curl, file_get_contents() on a URL, fsockopen() or a
 * stream wrapper directly in a module. If you need a request shape that is
 * not here, say so — do not reach around this class, and never add a
 * parameter that turns TLS verification off.
 *
 * The URL always comes from code — an endpoint constant in a gateway class.
 * Never pass a URL read from the database, from a request, or from an API
 * response: that is the shape of a server-side request forgery, and this
 * class does no host allowlisting of its own.
 *
 * Updater::httpGet() stays separate and private on purpose. It fetches the
 * file that can overwrite core/, so its host list, its redirect policy and
 * its byte cap are decided in the class that acts on them rather than
 * inherited from a general-purpose helper that something else might widen.
 */
final class Http
{
    /** Seconds. Long enough for a gateway on a bad day, short enough to not hang a checkout. */
    public const DEFAULT_TIMEOUT = 15;

    /**
     * Shared hosting commonly caps max_execution_time at 30s. A request
     * allowed to outlive that gets killed mid-flight, which looks to the
     * customer like a dead checkout rather than a failed payment.
     */
    private const MAX_TIMEOUT = 30;

    /** Connecting should be fast or not at all; a slow DNS answer is not worth the whole budget. */
    private const CONNECT_TIMEOUT = 5;

    /** 1 MB. Gateway JSON is a few kilobytes; anything near this is a wrong turn. */
    private const MAX_RESPONSE_BYTES = 1048576;

    /**
     * POST $payload as a JSON body.
     *
     * @param array<string, mixed>  $payload Encoded as the JSON request body.
     * @param array<string, string> $headers Extra headers as name => value,
     *                                       e.g. ['Authorization' => 'Bearer sk_live_…'].
     *                                       Content-Type and Accept are set already.
     * @return array{ok: bool, status: int, data: array, error: ?string}
     */
    public static function postJson(
        string $url,
        array $payload,
        array $headers = [],
        ?int $timeout = null
    ): array {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            // Reachable from real input, not just from a bug: an address field
            // carrying invalid UTF-8 will do it. The message is curl-free and
            // carries no payload content.
            return self::failure(0, 'The request could not be encoded as JSON.');
        }

        return self::send('POST', $url, $body, $headers, $timeout);
    }

    /**
     * GET a JSON document.
     *
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, data: array, error: ?string}
     */
    public static function getJson(string $url, array $headers = [], ?int $timeout = null): array
    {
        return self::send('GET', $url, null, $headers, $timeout);
    }

    /* ============================================================== *
     * The one path both methods take
     * ============================================================== */

    /**
     * WHAT THE RETURN VALUE MEANS, AND WHY IT IS NOT AN EXCEPTION
     *
     * `ok => false` means **we do not know what happened** — the request did
     * not complete, or the answer was unreadable. It does NOT mean "the
     * operation failed". For a payment verification those are opposite
     * outcomes: a timeout must leave the order pending and re-verifiable,
     * never mark it failed or unpaid.
     *
     * A gateway timing out is an expected runtime condition on shared
     * hosting, not a programming error, so it is returned rather than thrown
     * — a payment return page has to render something sensible for the
     * customer either way. `data` is `[]` on every failure, so a caller that
     * reads `$res['data']['status'] === 'success'` without checking `ok`
     * still falls through to "not paid" instead of to "paid".
     *
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, data: array, error: ?string}
     */
    private static function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        ?int $timeout
    ): array {
        $urlError = self::rejectUnsafeUrl($url);
        if ($urlError !== null) {
            return self::failure(0, $urlError);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $name  = (string) $name;
            $value = trim((string) $value);

            // A secret pasted into the dashboard with a trailing newline is a
            // real occurrence, and a header value containing CR or LF is
            // header injection. Refuse by NAME only — the value is never
            // repeated back, since the value is the secret.
            if (preg_match('/[\r\n]/', $name . $value) || $name === '' || !preg_match('/^[A-Za-z0-9-]+$/', $name)) {
                return self::failure(0, 'A request header was rejected as malformed: ' . substr($name, 0, 40) . '.');
            }

            $headerLines[] = $name . ': ' . $value;
        }

        $headerLines[] = 'Accept: application/json';
        if ($body !== null) {
            $headerLines[] = 'Content-Type: application/json';
        }

        $timeout = max(1, min(self::MAX_TIMEOUT, $timeout ?? self::DEFAULT_TIMEOUT));

        $response = function_exists('curl_init')
            ? self::viaCurl($method, $url, $body, $headerLines, $timeout)
            : self::viaStream($method, $url, $body, $headerLines, $timeout);

        if ($response['error'] !== null) {
            // Host and path only — never the query string, which is where a
            // careless caller would have put a key.
            error_log('Http: ' . $method . ' ' . self::redact($url) . ' failed: ' . $response['error']);
            return self::failure($response['status'], $response['error']);
        }

        return self::decode($response['status'], $response['body']);
    }

    /** Turn a raw response into the documented shape. */
    private static function decode(int $status, string $raw): array
    {
        $ok = $status >= 200 && $status < 300;

        if (trim($raw) === '') {
            // A 204, or a 2xx with nothing in it. Not an error — but there is
            // nothing to read, so data stays empty and a caller checking a
            // field finds it absent.
            return [
                'ok'     => $ok,
                'status' => $status,
                'data'   => [],
                'error'  => $ok ? null : 'The gateway answered ' . $status . ' with an empty body.',
            ];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            // An HTML error page from a proxy is the usual cause. The body is
            // NOT included in the message: it is attacker-influenced content
            // that would be rendered or logged downstream.
            return self::failure($status, 'The gateway answered ' . $status . ' with a body that was not JSON.');
        }

        return [
            'ok'     => $ok,
            'status' => $status,
            'data'   => $decoded,
            // A 4xx/5xx with a readable JSON body is still a failure, but the
            // body is handed back: gateways put their own reason codes there,
            // and the caller needs them.
            'error'  => $ok ? null : 'The gateway answered ' . $status . '.',
        ];
    }

    /**
     * HTTPS, no credentials in the URL, nothing but a host and a path.
     *
     * Returns an error string, or null when the URL is acceptable.
     */
    private static function rejectUnsafeUrl(string $url): ?string
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            return 'Refused a non-HTTPS request URL.';
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return 'Refused a request URL that could not be parsed.';
        }

        // user:pass@host — curl would authenticate with it, and it is never
        // how a gateway endpoint is written. Almost always a mistake.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Refused a request URL carrying credentials.';
        }

        return null;
    }

    /**
     * Make a transport-layer message safe to return and to log.
     *
     * PHP's own stream warnings quote the URL in full — query string
     * included — so a key that a caller put in a query would otherwise reach
     * the error log through the error message rather than through the URL.
     * Every URL-shaped run of characters is replaced with the redacted form,
     * not just the one that was requested, and the result is capped so a
     * verbose message cannot flood the log.
     */
    private static function scrubMessage(string $message, string $url): string
    {
        $message = (string) preg_replace(
            '#https?://[^\s\'\"()<>]+#i',
            self::redact($url),
            $message
        );

        // Strip the "file_get_contents(...):" prefix PHP adds, which is noise
        // once the URL inside it has been reduced.
        $message = (string) preg_replace('#^\w+\([^)]*\):\s*#', '', trim($message));

        return substr($message, 0, 200);
    }

    /** Scheme, host and path. The query string is dropped, not shortened. */
    private static function redact(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return '[unparseable url]';
        }

        return 'https://' . $parts['host'] . ($parts['path'] ?? '');
    }

    /** @return array{status: int, body: string, error: ?string} */
    private static function failure(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => $error];
    }

    /* ============================================================== *
     * Transports
     * ============================================================== */

    /** @return array{status: int, body: string, error: ?string} */
    private static function viaCurl(
        string $method,
        string $url,
        ?string $body,
        array $headerLines,
        int $timeout
    ): array {
        $received = '';
        $overflow = false;

        $ch = curl_init($url);

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, $timeout),
            // Never follow a redirect: the Authorization header would be
            // re-sent to a host chosen by the response. See the class note.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => self::userAgent(),
            // Stream into a closure rather than buffering the whole body, so
            // the cap is enforced as bytes arrive instead of after the fact.
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$received, &$overflow): int {
                $received .= $chunk;
                if (strlen($received) > self::MAX_RESPONSE_BYTES) {
                    $overflow = true;
                    return 0; // short write — tells curl to abort the transfer
                }
                return strlen($chunk);
            },
        ];

        // CURLOPT_PROTOCOLS_STR arrived in PHP 8.2; composer.json allows 8.0.
        // Referencing an undefined constant is a fatal Error, so each form is
        // used only where it exists — and CURLOPT_PROTOCOLS is deprecated in
        // 8.4, which is why the newer one is preferred when available.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $executed = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);

        // No curl_close(): a no-op since PHP 8.0, and it emits a deprecation
        // notice on 8.5. The handle frees itself when $ch goes out of scope.
        unset($ch);

        if ($overflow) {
            return ['status' => $status, 'body' => '', 'error' => 'The response exceeded the size limit and was discarded.'];
        }

        if ($executed === false && $curlErr !== '') {
            // curl messages name the host and the TLS or DNS problem, never
            // the request headers. Scrubbed anyway: any URL it quotes back is
            // reduced to host and path, so a key in a query string cannot
            // ride out through an error message.
            return ['status' => $status, 'body' => '', 'error' => self::scrubMessage($curlErr, $url)];
        }

        if ($status === 0) {
            return ['status' => 0, 'body' => '', 'error' => 'No response from the gateway.'];
        }

        return ['status' => $status, 'body' => $received, 'error' => null];
    }

    /**
     * Fallback for hosts built without curl.
     *
     * @return array{status: int, body: string, error: ?string}
     */
    private static function viaStream(
        string $method,
        string $url,
        ?string $body,
        array $headerLines,
        int $timeout
    ): array {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return [
                'status' => 0,
                'body'   => '',
                'error'  => 'This server has neither curl nor allow_url_fopen, so it cannot reach the gateway.',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method'           => $method,
                'header'           => implode("\r\n", $headerLines),
                'content'          => $body ?? '',
                'timeout'          => $timeout,
                'protocol_version' => 1.1,
                'follow_location'  => 0,
                'max_redirects'    => 0,
                // Read the body of a 4xx/5xx instead of getting false: gateway
                // reason codes live in exactly those responses.
                'ignore_errors'    => true,
                'user_agent'       => self::userAgent(),
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                // Not SNI-dependent, but stated so a future edit has to argue
                // with it rather than quietly inherit a weaker default.
                'allow_self_signed' => false,
            ],
        ]);

        // One byte over the cap, so an oversized body is detectable rather
        // than silently truncated into something that may still parse.
        //
        // The warning is suppressed for the caller's sake but its text is the
        // only account of WHY this failed — a rejected certificate and a
        // refused connection are indistinguishable without it, and on a host
        // with no curl this is the only diagnostic anyone gets. So it is
        // captured and scrubbed rather than discarded.
        error_clear_last();
        $raw = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES + 1);

        if ($raw === false) {
            $reason = self::scrubMessage((string) (error_get_last()['message'] ?? ''), $url);

            return [
                'status' => 0,
                'body'   => '',
                'error'  => $reason !== '' ? $reason : 'No response from the gateway.',
            ];
        }

        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            return ['status' => 0, 'body' => '', 'error' => 'The response exceeded the size limit and was discarded.'];
        }

        $status = self::statusFromStreamHeaders($http_response_header ?? []);
        if ($status === 0) {
            return ['status' => 0, 'body' => '', 'error' => 'The response had no readable status line.'];
        }

        return ['status' => $status, 'body' => $raw, 'error' => null];
    }

    /**
     * Pull the status code out of $http_response_header.
     *
     * Reads the LAST status line present. With redirects disabled there is
     * normally one, but a 1xx informational response can precede the real
     * one and the last line is the answer in either case.
     *
     * @param array<int, string> $responseHeaders
     */
    private static function statusFromStreamHeaders(array $responseHeaders): int
    {
        $status = 0;

        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }

    /** Identifies the kit and its version, so a gateway's logs are useful to a merchant. */
    private static function userAgent(): string
    {
        $version = class_exists('Updater') ? Updater::VERSION : 'dev';

        return 'PlugPHP/' . $version;
    }
}
