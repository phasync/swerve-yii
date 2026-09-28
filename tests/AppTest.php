<?php

/*
 * The Yii skeleton (yiisoft/app) with the routes of tests/Fixtures/routes.php, on swerve.
 */

// One server, two workers, for the whole file
beforeAll(function () {
    $GLOBALS['app'] = app_start();
});
afterAll(function () {
    app_stop($GLOBALS['app'][0]);
});
beforeEach(function () {
    [, $this->addr, $this->log] = $GLOBALS['app'];
});

it('serves the skeleton home page', function () {
    [$status, $body] = http("http://$this->addr/");
    expect($status)->toBe(200)->and($body)->toContain('Hello!');
});

it('serves a JSON route', function () {
    [$status, $body] = http("http://$this->addr/json");
    expect($status)->toBe(200)->and(\json_decode($body, true))->toBe(['framework' => 'yii', 'ok' => true]);
});

it('answers 404 with the skeleton\'s page', function () {
    [$status, $body] = http("http://$this->addr/missing");
    expect($status)->toBe(404)->and($body)->toContain('404');
});

it('accepts a form POST with its CSRF token, and refuses one without', function () {
    $jar             = jar();
    $token           = csrf_token($this->addr, $jar);
    [$status, $body] = http("http://$this->addr/form", $jar, [\CURLOPT_POSTFIELDS => \http_build_query(['_csrf' => $token, 'name' => 'Ada'])]);
    expect($status)->toBe(200)->and(\json_decode($body, true))->toMatchArray(['name' => 'Ada']);

    [$status] = http("http://$this->addr/form", $jar, [\CURLOPT_POSTFIELDS => \http_build_query(['name' => 'Ada'])]);
    expect($status)->toBe(422);
});

it('parses a JSON POST', function () {
    $jar             = jar();
    $token           = csrf_token($this->addr, $jar);
    [$status, $body] = http("http://$this->addr/echo", $jar, [
        \CURLOPT_POSTFIELDS => '{"name":"Ada","n":[1,2]}',
        \CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $token"],
    ]);
    expect($status)->toBe(200)->and(\json_decode($body, true))->toBe(['name' => 'Ada', 'n' => [1, 2]]);
});

it('receives an upload', function () {
    $jar   = jar();
    $token = csrf_token($this->addr, $jar);
    $file  = \tempnam(\sys_get_temp_dir(), 'upload');
    \file_put_contents($file, $data = \random_bytes(300_000));
    [$status, $body] = http("http://$this->addr/upload", $jar, [\CURLOPT_POSTFIELDS => [
        '_csrf' => $token,
        'title' => 'A file',
        'doc'   => new CURLFile($file, 'application/octet-stream', 'data.bin'),
    ]]);
    \unlink($file);
    expect($status)->toBe(200)->and(\json_decode($body, true))->toMatchArray([
        'files' => ['doc' => ['data.bin', 300_000, \md5($data)]],
    ]);
});

it('keeps each request\'s state its own, with 10 requests in flight', function () {
    $handles = [];
    for ($i = 1; $i <= 10; ++$i) {
        $handles[] = request("http://$this->addr/isolation/v$i", jar());
    }
    foreach (http_all($handles) as $i => $body) {
        $v = 'v' . ($i + 1);
        expect(\json_decode($body, true))->toBe([
            'attribute' => $v,
            'route'     => $v,
            'provider'  => "/isolation/$v",
            'session'   => $v,
            'user'      => $v,
        ]);
    }
});

it('counts in the session across requests on both workers', function () {
    $jar  = jar();
    $pids = [];
    for ($n = 1; $n <= 20; ++$n) {
        // A new connection each time: the kernel spreads them over the workers
        $data = \json_decode(http("http://$this->addr/counter", $jar, [\CURLOPT_FORBID_REUSE => true, \CURLOPT_FRESH_CONNECT => true])[1], true);
        expect($data['n'])->toBe($n);
        $pids[$data['pid']] = true;
    }
    expect(\count($pids))->toBe(2);
});

it('logs in and out', function () {
    $jar = jar();
    expect(http("http://$this->addr/whoami", $jar)[1])->toBe('(guest)')
        ->and(http("http://$this->addr/login/ada", $jar)[1])->toBe('logged in')
        ->and(http("http://$this->addr/whoami", $jar)[1])->toBe('ada')
        ->and(http("http://$this->addr/whoami")[1])->toBe('(guest)')
        ->and(http("http://$this->addr/logout", $jar)[1])->toBe('logged out')
        ->and(http("http://$this->addr/whoami", $jar)[1])->toBe('(guest)');
});

it('streams a response as it is produced', function () {
    $start  = \microtime(true);
    $chunks = [];
    http("http://$this->addr/stream", null, [\CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$chunks, $start) {
        $chunks[] = [$data, \microtime(true) - $start];

        return \strlen($data);
    }]);
    expect(\implode('', \array_column($chunks, 0)))->toBe("first\nlast\n")
        ->and($chunks[0][0])->toBe("first\n")
        ->and($chunks[0][1])->toBeLessThan(0.5)
        ->and(\end($chunks)[1])->toBeGreaterThan(0.9);
});

it('logged no errors', function () {
    expect(\file_get_contents($this->log))->not->toMatch('/error|exception|warning/i');
});
