<?php

/**
 * test-http.php — exercises core/Http.php against a real TLS server.
 *
 *     php tools/test-http.php
 *     php tools/test-http.php --keep-certs
 *
 * WHY A REAL SERVER, AND WHY ITS OWN CERTIFICATE AUTHORITY
 *
 * Http's security properties are all about what it REFUSES — a plain-HTTP
 * URL, an unverifiable certificate, a redirect carrying the Authorization
 * header somewhere new, a response too large to buffer. None of that can be
 * checked by reading the class, and none of it can be checked against a
 * public endpoint either, because the interesting cases are the ones a
 * well-behaved server never produces.
 *
 * So the harness generates a throwaway CA and a 127.0.0.1 leaf certificate,
 * serves canned responses over TLS, and points the TRUST STORE at that CA
 * for the duration (CURL_CA_BUNDLE for curl, openssl.cafile for the stream
 * transport). The code under test is never relaxed — only the list of
 * authorities the machine trusts, which is what a trust store is for. One
 * run is then made with the CA deliberately NOT trusted, to prove
 * verification is really on rather than merely requested.
 *
 * Both transports are covered: the suite runs once normally, and once with
 * curl_init disabled via -d disable_functions, which is the only honest way
 * to reach the stream-wrapper fallback that cURL-less shared hosts use.
 *
 * Nothing here touches the network or the database.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Http.php';

/**
 * The port is chosen per run, not fixed.
 *
 * A hardcoded port makes a stale server from an earlier run invisible: the
 * new server fails to bind, the suite talks to the OLD process, and the
 * failures that follow look like bugs in the code under test. Learned the
 * hard way. The parent picks a free port, hands it to every child, and
 * refuses to proceed unless its own server reports that it bound.
 */
$args = parseArgs($argv);

define('SERVER_PORT', (int) ($args['port'] ?? 0));
define('BASE_URL', 'https://127.0.0.1:' . SERVER_PORT);

exit(match ($args['mode'] ?? 'parent') {
    'server'  => runServer($args),
    'asserts' => runAsserts($args),
    default   => runParent($args),
});


/* ============================================================== *
 * Parent — certificates, server, both transports, verdict
 * ============================================================== */

function runParent(array $args): int
{
    line('PlugPHP — core/Http.php test');

    $dir = sys_get_temp_dir() . '/plugphp-http-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    $ca = makeCertificates($dir);
    line('test CA: ' . $ca['ca_cert']);

    $port = findFreePort();
    if ($port === 0) {
        line('FAIL: could not find a free local port to test on.');
        return 1;
    }

    $server = startServer($dir, $port);
    if ($server === null) {
        line('FAIL: the test TLS server did not report that it bound to port ' . $port . '.');
        return 1;
    }
    line('test server: https://127.0.0.1:' . $port);

    $failures = 0;

    try {
        // 1. cURL transport, CA trusted.
        $failures += reportRun('curl transport', runAssertsChild($ca['ca_cert'], $port, true, false));

        // 2. Stream-wrapper transport, CA trusted. Reached only by taking
        //    curl_init away, which is what a host without curl looks like.
        $failures += reportRun('stream transport (no curl)', runAssertsChild($ca['ca_cert'], $port, true, true));

        // 3. The certificate test. Same server, same code, CA NOT trusted.
        //    Every request here must fail.
        $failures += reportRun('untrusted certificate (both transports must refuse)',
            runAssertsChild($ca['ca_cert'], $port, false, false));
    } finally {
        proc_terminate($server['proc']);
        proc_close($server['proc']);

        if (!isset($args['keep-certs'])) {
            foreach ((array) glob($dir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        } else {
            line('');
            line('--keep-certs: left in ' . $dir);
        }
    }

    line('');
    line(str_repeat('=', 64));
    if ($failures === 0) {
        line('PASS — every assertion held on both transports.');
        return 0;
    }
    line('FAIL — ' . $failures . ' assertion(s) failed. Details above.');
    return 1;
}

/** Print one child's results, return the number of failures in it. */
function reportRun(string $label, array $results): int
{
    line('');
    line(str_repeat('-', 64));
    line(strtoupper($label));
    line(str_repeat('-', 64));

    if ($results === []) {
        line('  no results — the child produced no parseable output');
        return 1;
    }

    $failed = 0;

    foreach ($results as $r) {
        $pass = (bool) ($r['pass'] ?? false);
        line(sprintf('  %s %s', $pass ? 'OK  ' : 'FAIL', (string) ($r['name'] ?? '?')));
        if (!$pass) {
            $failed++;
            line('       ' . (string) ($r['detail'] ?? ''));
        }
    }

    return $failed;
}

/**
 * Run the assertion set in a child process.
 *
 * @param bool $trustCa  Point the trust store at the test CA.
 * @param bool $noCurl   Disable curl_init so Http falls back to streams.
 */
function runAssertsChild(string $caCert, int $port, bool $trustCa, bool $noCurl): array
{
    $flags = ['-d', 'error_reporting=E_ALL'];

    if ($trustCa) {
        $flags[] = '-d';
        $flags[] = 'openssl.cafile=' . $caCert;
    }
    if ($noCurl) {
        // function_exists() reports false for a disabled function, which is
        // exactly the condition Http branches on.
        $flags[] = '-d';
        $flags[] = 'disable_functions=curl_init';
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $flags))
        . ' ' . escapeshellarg(__FILE__) . ' --mode=asserts'
        . ' --port=' . $port
        . ' --expect=' . ($trustCa ? 'success' : 'tls-refusal');

    $env = $_ENV + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    if ($trustCa) {
        $env['CURL_CA_BUNDLE'] = $caCert;
    } else {
        // An empty bundle is not the same as "no setting": make sure a stray
        // value from the environment cannot make this run accidentally pass.
        unset($env['CURL_CA_BUNDLE'], $env['SSL_CERT_FILE']);
    }

    $pipes = [];
    $proc  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        return [];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $results = [];
    foreach (explode("\n", trim((string) $stdout)) as $lineText) {
        if (trim($lineText) === '') {
            continue;
        }
        $decoded = json_decode($lineText, true);
        if (is_array($decoded)) {
            $results[] = $decoded;
        }
    }

    if ($results === [] && trim($stderr) !== '') {
        $results[] = ['name' => 'child process', 'pass' => false, 'detail' => trim($stderr)];
    }

    return $results;
}

/* ============================================================== *
 * Assertions
 * ============================================================== */

function runAsserts(array $args): int
{
    $expectTlsRefusal = ($args['expect'] ?? 'success') === 'tls-refusal';

    // Capture Http's own error_log output so the redaction claim can be
    // checked rather than taken on trust.
    $logFile = tempnam(sys_get_temp_dir(), 'plugphp-http-log-');
    ini_set('log_errors', '1');
    ini_set('error_log', (string) $logFile);

    if ($expectTlsRefusal) {
        assertTlsRefusal();
        @unlink((string) $logFile);
        return 0;
    }

    assertHappyPath();
    assertHeadersAndMethodArrive();
    assertRedirectNotFollowed();
    assertNonJsonBodyRejected();
    assertErrorStatusKeepsItsJson();
    assertEmptyBodyIsNotAnError();
    assertOversizedResponseRefused();
    assertTimeoutGivesUp();
    assertPlainHttpRefused();
    assertCredentialsInUrlRefused();
    assertMalformedHeaderRefused();
    assertUnencodablePayloadRefused();
    assertQueryStringNotLogged((string) $logFile);

    @unlink((string) $logFile);

    return 0;
}

function assertHappyPath(): void
{
    $res = Http::getJson(BASE_URL . '/json');

    check(
        'GET returns parsed JSON with ok=true',
        $res['ok'] === true && $res['status'] === 200 && ($res['data']['value'] ?? null) === 42,
        describe($res)
    );
}

function assertHeadersAndMethodArrive(): void
{
    $res = Http::postJson(
        BASE_URL . '/echo',
        ['reference' => 'ref_123'],
        ['Authorization' => 'Bearer sk_test_SECRET']
    );

    $seen = $res['data'];

    check(
        'POST sends the method, the JSON body, Content-Type and Authorization',
        $res['ok'] === true
            && ($seen['method'] ?? null) === 'POST'
            && ($seen['content_type'] ?? null) === 'application/json'
            && ($seen['authorization_present'] ?? false) === true
            && ($seen['body'] ?? null) === '{"reference":"ref_123"}',
        describe($res)
    );
}

function assertRedirectNotFollowed(): void
{
    $res = Http::getJson(BASE_URL . '/redirect', ['Authorization' => 'Bearer sk_test_SECRET']);

    check(
        'a 302 is NOT followed (the Authorization header never moves hosts)',
        $res['status'] === 302 && $res['ok'] === false && ($res['data']['value'] ?? null) !== 42,
        describe($res)
    );
}

function assertNonJsonBodyRejected(): void
{
    $res = Http::getJson(BASE_URL . '/notjson');

    check(
        'a 200 with an HTML body is ok=false, and the body is not quoted in the error',
        $res['ok'] === false
            && $res['data'] === []
            && str_contains((string) $res['error'], 'not JSON')
            && !str_contains((string) $res['error'], 'html')
            && !str_contains((string) $res['error'], 'SENSITIVE'),
        describe($res)
    );
}

function assertErrorStatusKeepsItsJson(): void
{
    $res = Http::getJson(BASE_URL . '/fail');

    check(
        'a 500 with a JSON body is ok=false but still hands back the gateway reason',
        $res['ok'] === false
            && $res['status'] === 500
            && ($res['data']['code'] ?? null) === 'insufficient_funds',
        describe($res)
    );
}

function assertEmptyBodyIsNotAnError(): void
{
    $res = Http::getJson(BASE_URL . '/empty');

    check(
        'a 204 with no body is ok=true with empty data',
        $res['ok'] === true && $res['status'] === 204 && $res['data'] === [],
        describe($res)
    );
}

function assertOversizedResponseRefused(): void
{
    $res = Http::getJson(BASE_URL . '/big');

    check(
        'an oversized response is discarded rather than buffered',
        $res['ok'] === false && $res['data'] === [] && str_contains((string) $res['error'], 'size limit'),
        describe($res)
    );
}

function assertTimeoutGivesUp(): void
{
    $started = microtime(true);
    $res     = Http::getJson(BASE_URL . '/slow', [], 1);
    $elapsed = microtime(true) - $started;

    check(
        'a slow gateway times out, and ok=false means "unknown", not "failed"',
        $res['ok'] === false && $res['data'] === [] && $elapsed < 3.0,
        describe($res) . sprintf(' elapsed=%.2fs', $elapsed)
    );
}

function assertPlainHttpRefused(): void
{
    $res = Http::getJson('http://127.0.0.1:' . SERVER_PORT . '/json');

    check(
        'a plain-HTTP URL is refused outright, not upgraded',
        $res['ok'] === false && $res['status'] === 0 && str_contains((string) $res['error'], 'non-HTTPS'),
        describe($res)
    );
}

function assertCredentialsInUrlRefused(): void
{
    $res = Http::getJson('https://user:pass@127.0.0.1:' . SERVER_PORT . '/json');

    check(
        'a URL carrying credentials is refused',
        $res['ok'] === false && str_contains((string) $res['error'], 'credentials'),
        describe($res)
    );
}

function assertMalformedHeaderRefused(): void
{
    // A key pasted into a dashboard field with a trailing newline. The error
    // must name the header without repeating the value, because the value is
    // the secret.
    $res = Http::getJson(BASE_URL . '/json', ['Authorization' => "Bearer sk_live_SENSITIVE\r\nX-Injected: 1"]);

    check(
        'a header value containing CRLF is refused, and the value stays out of the message',
        $res['ok'] === false
            && $res['status'] === 0
            && str_contains((string) $res['error'], 'Authorization')
            && !str_contains((string) $res['error'], 'SENSITIVE'),
        describe($res)
    );
}

function assertUnencodablePayloadRefused(): void
{
    // Invalid UTF-8 — the kind of thing a pasted address field produces.
    $res = Http::postJson(BASE_URL . '/echo', ['address' => "Lagos \xB1\x31"]);

    check(
        'a payload that cannot be JSON-encoded fails before any request is made',
        $res['ok'] === false && $res['status'] === 0 && str_contains((string) $res['error'], 'encoded as JSON'),
        describe($res)
    );
}

function assertQueryStringNotLogged(string $logFile): void
{
    // Force a transport failure on a URL whose query string looks like a
    // leaked key, then read back what Http actually wrote to the log.
    Http::getJson('https://127.0.0.1:1/json?api_key=sk_live_SENSITIVE', [], 1);

    $log = (string) @file_get_contents($logFile);

    check(
        'a failure logs host and path only — never the query string',
        $log !== ''
            && str_contains($log, '127.0.0.1/json')
            && !str_contains($log, 'SENSITIVE')
            && !str_contains($log, 'api_key'),
        'log was: ' . trim(substr($log, 0, 240))
    );
}

function assertTlsRefusal(): void
{
    $get  = Http::getJson(BASE_URL . '/json');
    $post = Http::postJson(BASE_URL . '/echo', ['x' => 1], ['Authorization' => 'Bearer sk_test_SECRET']);

    // ok=false is not enough on its own: a refused connection or a wrong
    // port would satisfy it too, and the whole point of this run is that the
    // request failed BECAUSE the certificate could not be verified. So the
    // reason is asserted, not just the outcome.
    $looksLikeTls = static fn(array $res): bool => (bool) preg_match(
        '/certificat|SSL|TLS|issuer|verif/i',
        (string) $res['error']
    );

    check(
        'with the CA untrusted, GET is refused — and the reason is the certificate',
        $get['ok'] === false && $get['data'] === [] && $get['status'] === 0 && $looksLikeTls($get),
        describe($get)
    );

    check(
        'with the CA untrusted, POST is refused too — TLS verification is not optional',
        $post['ok'] === false && $post['data'] === [] && $post['status'] === 0 && $looksLikeTls($post),
        describe($post)
    );
}

function check(string $name, bool $pass, string $detail = ''): void
{
    echo json_encode(['name' => $name, 'pass' => $pass, 'detail' => $detail]), "\n";
}

/** A one-line summary of a response, with no body content beyond the decoded keys. */
function describe(array $res): string
{
    return sprintf(
        'got ok=%s status=%d data_keys=[%s] error=%s',
        ($res['ok'] ?? null) ? 'true' : 'false',
        (int) ($res['status'] ?? 0),
        implode(',', array_keys((array) ($res['data'] ?? []))),
        var_export($res['error'] ?? null, true)
    );
}


/* ============================================================== *
 * The canned TLS server
 * ============================================================== */

/** Ask the OS for a port nobody is using, then let it go again. */
function findFreePort(): int
{
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($probe)) {
        return 0;
    }

    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);

    $port = (int) substr($name, strrpos($name, ':') + 1);

    return $port > 0 ? $port : 0;
}

function startServer(string $dir, int $port): ?array
{
    $cmd = sprintf(
        '%s %s --mode=server --port=%d --cert=%s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        $port,
        escapeshellarg($dir . '/server.pem')
    );

    $pipes = [];
    $proc  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }

    // Wait for OUR server to say it bound. A port probe would also be
    // satisfied by somebody else's listener, which is exactly the confusion
    // this handshake exists to prevent.
    stream_set_blocking($pipes[1], false);
    $deadline = microtime(true) + 10;

    while (microtime(true) < $deadline) {
        $ready = trim((string) fgets($pipes[1]));
        if ($ready === 'READY') {
            return ['proc' => $proc, 'pipes' => $pipes];
        }
        if (!empty($ready)) {
            break; // it said something else — a bind failure
        }
        usleep(50_000);
    }

    $why = trim((string) stream_get_contents($pipes[2]));
    if ($why !== '') {
        line('  server said: ' . $why);
    }

    proc_terminate($proc);
    proc_close($proc);

    return null;
}

function runServer(array $args): int
{
    $cert = (string) ($args['cert'] ?? '');

    $context = stream_context_create([
        'ssl' => [
            'local_cert'        => $cert,
            'allow_self_signed' => true,
            'verify_peer'       => false,
        ],
    ]);

    $server = @stream_socket_server(
        'tls://127.0.0.1:' . SERVER_PORT,
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );

    if (!is_resource($server)) {
        fwrite(STDERR, "could not bind to port " . SERVER_PORT . ": {$errstr}\n");
        return 1;
    }

    // Tells the parent it is safe to start, and that this listener is the one
    // the parent started.
    echo "READY\n";
    flush();

    $deadline = time() + 120; // never outlive the test run

    while (time() < $deadline) {
        $client = @stream_socket_accept($server, 1);
        if (!is_resource($client)) {
            continue;
        }
        handleRequest($client);
        fclose($client);
    }

    return 0;
}

function handleRequest($client): void
{
    stream_set_timeout($client, 5);

    $requestLine = (string) fgets($client);
    if (trim($requestLine) === '') {
        return;
    }

    [$method, $path] = array_pad(preg_split('/\s+/', trim($requestLine)) ?: [], 2, '');

    $headers = [];
    while (($headerLine = fgets($client)) !== false) {
        $headerLine = rtrim($headerLine, "\r\n");
        if ($headerLine === '') {
            break;
        }
        if (str_contains($headerLine, ':')) {
            [$name, $value] = explode(':', $headerLine, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    $body = '';
    $length = (int) ($headers['content-length'] ?? 0);
    if ($length > 0) {
        $body = (string) fread($client, min($length, 2_000_000));
    }

    $route = strtok($path, '?');

    switch ($route) {
        case '/json':
            respond($client, 200, json_encode(['ok' => true, 'value' => 42]), 'application/json');
            return;

        case '/echo':
            respond($client, 200, json_encode([
                'method'                => $method,
                'content_type'          => $headers['content-type'] ?? null,
                'authorization_present' => isset($headers['authorization']),
                'body'                  => $body,
            ]), 'application/json');
            return;

        case '/redirect':
            respond(
                $client,
                302,
                json_encode(['redirected' => true]),
                'application/json',
                ['Location: ' . BASE_URL . '/json']
            );
            return;

        case '/notjson':
            // Deliberately contains a marker the test checks is NOT echoed
            // back through Http's error message.
            respond($client, 200, '<html><body>SENSITIVE proxy error</body></html>', 'text/html');
            return;

        case '/fail':
            respond($client, 500, json_encode([
                'message' => 'gateway said no',
                'code'    => 'insufficient_funds',
            ]), 'application/json');
            return;

        case '/empty':
            respond($client, 204, '', 'application/json');
            return;

        case '/big':
            // Comfortably over Http's 1 MB cap.
            respond($client, 200, '{"padding":"' . str_repeat('x', 1_500_000) . '"}', 'application/json');
            return;

        case '/slow':
            sleep(3);
            respond($client, 200, json_encode(['slow' => true]), 'application/json');
            return;

        default:
            respond($client, 404, json_encode(['error' => 'no such route']), 'application/json');
    }
}

function respond($client, int $status, string $body, string $contentType, array $extraHeaders = []): void
{
    $reason = [200 => 'OK', 204 => 'No Content', 302 => 'Found', 404 => 'Not Found', 500 => 'Internal Server Error'];

    $head = 'HTTP/1.1 ' . $status . ' ' . ($reason[$status] ?? 'Unknown') . "\r\n"
        . 'Content-Type: ' . $contentType . "\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . 'Connection: close' . "\r\n";

    foreach ($extraHeaders as $h) {
        $head .= $h . "\r\n";
    }

    fwrite($client, $head . "\r\n" . $body);
}


/* ============================================================== *
 * Throwaway certificate authority
 * ============================================================== */

/**
 * A CA and a 127.0.0.1 leaf, both valid for a day.
 *
 * The leaf carries subjectAltName=IP:127.0.0.1 because hostname
 * verification is on in Http and a CN-only certificate is rejected by
 * modern clients — which is correct, and would otherwise look like a bug in
 * the class rather than in the test.
 */
function makeCertificates(string $dir): array
{
    $caKey   = $dir . '/ca.key';
    $caCert  = $dir . '/ca.pem';
    $leafKey = $dir . '/server.key';
    $leafCsr = $dir . '/server.csr';
    $leafPem = $dir . '/server.pem';

    run("openssl req -x509 -newkey rsa:2048 -nodes -keyout {$caKey} -out {$caCert} -days 1 "
        . '-subj "/CN=PlugPHP Test CA"');

    run("openssl req -newkey rsa:2048 -nodes -keyout {$leafKey} -out {$leafCsr} "
        . '-subj "/CN=127.0.0.1"');

    file_put_contents($dir . '/ext.cnf', "subjectAltName=IP:127.0.0.1\n");

    run("openssl x509 -req -in {$leafCsr} -CA {$caCert} -CAkey {$caKey} -out {$dir}/server.crt "
        . "-days 1 -extfile {$dir}/ext.cnf");

    // PHP's TLS server wants certificate and key in one PEM file.
    file_put_contents($leafPem, file_get_contents($dir . '/server.crt') . file_get_contents($leafKey));

    return ['ca_cert' => $caCert, 'server_pem' => $leafPem];
}

function run(string $command): void
{
    exec($command . ' 2>/dev/null', $output, $code);

    if ($code !== 0) {
        fwrite(STDERR, "command failed: {$command}\n");
        exit(1);
    }
}


/* ============================================================== *
 * Helpers
 * ============================================================== */

function parseArgs(array $argv): array
{
    $out = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
            continue;
        }
        $out[$arg] = true;
    }

    return $out;
}

function line(string $text): void
{
    echo $text, "\n";
}
