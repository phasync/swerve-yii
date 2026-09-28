<?php

/*
 * The tests run the Yii application in tests/Fixtures/app (made by tests/create-app.sh) on a
 * real swerve, the way users run it. SWERVE_PHP_ARGS adds PHP options, such as loading
 * phasync-ext: CI runs the suite without and with it.
 */

/**
 * Start swerve on a free port (18800 to 18849) with the fixture application and wait until it
 * answers.
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address, its log file
 */
function app_start(int $workers = 2, array $env = []): array
{
    for ($port = 18800; $port < 18850; ++$port) {
        if (false !== $socket = @\stream_socket_server("tcp://127.0.0.1:$port")) {
            \fclose($socket);
            break;
        }
    }
    $addr     = "127.0.0.1:$port";
    $log      = \tempnam(\sys_get_temp_dir(), 'swerve-log');
    $app      = __DIR__ . '/Fixtures/app';
    $php      = \trim((string) \getenv('SWERVE_PHP_ARGS'));
    $cmd      = 'exec ' . \PHP_BINARY . " $php " . \escapeshellarg("$app/vendor/bin/swerve") . " --workers=$workers --grace=2 --http=$addr --log=" . \escapeshellarg($log) . ' ' . \escapeshellarg("$app/swerve.php");
    $proc     = \proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $app, $env + \getenv());
    $deadline = \microtime(true) + 20;
    while (0 === http("http://$addr/json")[0]) {
        if (\microtime(true) > $deadline) {
            throw new RuntimeException("swerve did not start:\n" . \file_get_contents($log));
        }
        \usleep(100_000);
    }

    return [$proc, $addr, $log];
}

/**
 * Stop swerve as SIGTERM does (a graceful drain), and return its exit code. $signal false only
 * waits for it to exit, after a SIGTERM sent before.
 */
function app_stop($proc, bool $signal = true): int
{
    if ($signal) {
        \proc_terminate($proc, \SIGTERM);
    }
    $deadline = \microtime(true) + 10;
    while (($status = \proc_get_status($proc))['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }
    // Before PHP 8.3 only the proc_get_status() that saw the exit has the exit code
    $code = \proc_close($proc);

    return $status['running'] ? $code : $status['exitcode'];
}

/**
 * A curl handle for a request, with the cookies of $jar (a file) when given.
 *
 * @param array<int, mixed> $options more curl options
 */
function request(string $url, ?string $jar = null, array $options = []): CurlHandle
{
    $ch = \curl_init($url);
    \curl_setopt_array($ch, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 10]);
    \curl_setopt_array($ch, $options);
    if (null !== $jar) {
        \curl_setopt($ch, \CURLOPT_COOKIEFILE, $jar);
        \curl_setopt($ch, \CURLOPT_COOKIEJAR, $jar);
    }

    return $ch;
}

/**
 * Send a request: [status, body].
 *
 * @param array<int, mixed> $options more curl options
 *
 * @return array{0: int, 1: string}
 */
function http(string $url, ?string $jar = null, array $options = []): array
{
    $ch   = request($url, $jar, $options);
    $body = \curl_exec($ch);
    // curl writes the cookie jar when its handle is freed
    $status = \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
    unset($ch);

    return [$status, (string) $body];
}

/**
 * Send requests at once: the bodies, in order.
 *
 * @param CurlHandle[] $handles
 *
 * @return string[]
 */
function http_all(array $handles): array
{
    $multi = \curl_multi_init();
    foreach ($handles as $ch) {
        \curl_multi_add_handle($multi, $ch);
    }
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.1);
    } while ($running > 0);

    return \array_map(fn ($ch) => (string) \curl_multi_getcontent($ch), $handles);
}

/** A fresh cookie jar. */
function jar(): string
{
    return \tempnam(\sys_get_temp_dir(), 'swerve-jar');
}

/** A GET of /csrf, which starts the session: the CSRF token. */
function csrf_token(string $addr, string $jar): string
{
    return http("http://$addr/csrf", $jar)[1];
}

/**
 * A WebSocket client (RFC 6455), minimal: the handshake, checking the 101.
 *
 * @return resource the blocking connection, with a 5 s timeout
 */
function ws_connect(string $addr, string $path)
{
    $conn = \stream_socket_client("tcp://$addr", $errno, $error, 5);
    \stream_set_timeout($conn, 5);
    $key = \base64_encode(\random_bytes(16));
    \fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (!\str_ends_with($head, "\r\n\r\n") && false !== $line = \fgets($conn)) {
        $head .= $line;
    }
    expect($head)->toStartWith('HTTP/1.1 101')
        ->and(\strtolower($head))->toContain('sec-websocket-accept: ' . \strtolower(\base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))));

    return $conn;
}

/** Send a text frame, masked as a client must. */
function ws_send($conn, string $payload): void
{
    $n    = \strlen($payload);
    $mask = \random_bytes(4);
    \fwrite($conn, "\x81" . ($n < 126 ? \chr(0x80 | $n) : \chr(0x80 | 126) . \pack('n', $n)) . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv($n, 4) + 1), 0, $n)));
}

/** The payload of the next frame from the server (unmasked, shorter than 126 bytes). */
function ws_read($conn): string
{
    $head = \fread($conn, 2);
    $n    = \ord($head[1]) & 0x7F;

    return $n > 0 ? \fread($conn, $n) : '';
}
