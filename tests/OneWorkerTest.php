<?php

/*
 * One worker, started for each test: the session hijack, the drain, memory.
 */

beforeEach(function () {
    [$this->proc, $this->addr, $this->log] = app_start(1);
});
afterEach(function () {
    if (null !== $this->proc) {
        app_stop($this->proc);
    }
});

it('gives a visitor without a session cookie a new session, after one with', function () {
    $jar = jar();
    http("http://$this->addr/login/ada", $jar);
    [, $body] = http("http://$this->addr/isolation/secret", $jar);
    expect(\json_decode($body, true)['session'])->toBe('secret');

    $id = \json_decode(http("http://$this->addr/session", $jar)[1], true)['id'];

    // The same worker, which served that session last: PHP still knows its id
    expect(\json_decode(http("http://$this->addr/session")[1], true))->toBe(['id' => null, 'v' => null])
        ->and(http("http://$this->addr/whoami")[1])->toBe('(guest)');
    $data = \json_decode(http("http://$this->addr/counter")[1], true);
    expect($data['id'])->not->toBe($id)->and($data['n'])->toBe(1)->and($data['v'])->toBeNull();
});

// Flash remembers the last session id it saw: the same worker must see the session again
// Each request sets its value in every service that keeps request state, waits while the others
// run, and reads it back: twice, the second time in the sessions the first started
it('keeps each request\'s state its own while requests overlap in the worker', function () {
    $values = ['nb', 'en', 'de', 'fr', 'sv', 'da', 'fi', 'is'];
    $jars   = \array_map(fn () => jar(), $values);
    foreach ([1, 2] as $round) {
        $bodies = http_all(\array_map(fn ($v, $jar) => request("http://$this->addr/interleave/$v", $jar), $values, $jars));
        foreach ($values as $i => $v) {
            expect(\json_decode($bodies[$i], true))->toBe(\array_fill_keys(['route', 'provider', 'session', 'flash', 'user', 'locale', 'title', 'url'], $v));
        }
    }
});

it('shows a flash message once', function () {
    $jar = jar();
    expect(http("http://$this->addr/flash/set", $jar)[1])->toBe('set')
        ->and(http("http://$this->addr/flash/get", $jar)[1])->toBe('Saved')
        ->and(http("http://$this->addr/flash/get", $jar)[1])->toBe('(none)')
        ->and(http("http://$this->addr/flash/get", $jar)[1])->toBe('(none)');
});

it('turns a PHP warning into a 500 page, as Yii does under PHP-FPM, and serves on', function () {
    [$status, $body] = http("http://$this->addr/warning");
    expect($status)->toBe(500)->and($body)->not->toContain('not reached')
        ->and(http("http://$this->addr/json")[0])->toBe(200);
});

it('finishes a slow request when told to stop', function () {
    $ch    = request("http://$this->addr/slow");
    $multi = \curl_multi_init();
    \curl_multi_add_handle($multi, $ch);
    $stopAt = \microtime(true) + 0.3;
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.05);
        if (null !== $stopAt && \microtime(true) > $stopAt) {
            \proc_terminate($this->proc, \SIGTERM);
            $stopAt = null;
        }
    } while ($running > 0);
    $exit       = app_stop($this->proc, false);
    $this->proc = null;
    expect(\curl_getinfo($ch, \CURLINFO_RESPONSE_CODE))->toBe(200)
        ->and(\curl_multi_getcontent($ch))->toBe('done')
        ->and($exit)->toBe(0)
        ->and(\file_get_contents($this->log))->not->toMatch('/error|exception|warning/i');
});

// The callback runs after the request's turn, when the services serve other requests
it('shows another request\'s user to a WebSocket callback that reads CurrentUser itself', function () {
    $jar = jar();
    http("http://$this->addr/login/ada", $jar);
    $conn = ws_connect($this->addr, '/ws/who', $jar);

    // bob's request holds the worker's turn for 0.3 s, logged in, meanwhile ada's socket asks
    $ch    = request("http://$this->addr/isolation/bob", jar());
    $multi = \curl_multi_init();
    \curl_multi_add_handle($multi, $ch);
    $until = \microtime(true) + 0.1;
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.02);
    } while (\microtime(true) < $until);
    ws_send($conn, 'inside');
    ws_send($conn, 'taken');
    expect(ws_read($conn))->toBe('inside: bob')
        ->and(ws_read($conn))->toBe('taken: ada');
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.05);
    } while ($running > 0);
    expect(\json_decode(\curl_multi_getcontent($ch), true)['user'])->toBe('bob');
    \fclose($conn);
});

it('serves HTTP requests promptly with 250 WebSockets open', function () {
    $echo = $news = [];
    for ($i = 0; $i < 125; ++$i) {
        $echo[] = ws_connect($this->addr, '/ws');
        $news[] = ws_connect($this->addr, '/news');
    }
    expect(news_live_wait($this->addr, 1, 125))->toBe(125);

    $jar = jar();
    for ($i = 0; $i < 20; ++$i) {
        $start           = \microtime(true);
        [$status, $body] = http("http://$this->addr/", $jar);
        expect($status)->toBe(200)->and($body)->toContain('Hello!')
            ->and(\microtime(true) - $start)->toBeLessThan(0.25);
    }

    // And the sockets still work, both ways
    http("http://$this->addr/news/publish/still-here");
    foreach ($news as $conn) {
        expect(ws_read($conn))->toBe('guest: still-here');
    }
    foreach ($echo as $i => $conn) {
        ws_send($conn, "m$i");
    }
    foreach ($echo as $i => $conn) {
        expect(ws_read($conn))->toBe("echo: m$i");
    }
    \array_map(fclose(...), [...$echo, ...$news]);
    expect(news_live_wait($this->addr, 1, 0))->toBe(0)
        ->and(\file_get_contents($this->log))->not->toMatch('/error|exception|warning/i');
});

it('closes open WebSockets with 1001 when told to stop, and exits cleanly', function () {
    $clients = [];
    for ($i = 0; $i < 5; ++$i) {
        $clients[] = ws_connect($this->addr, '/ws');
        $clients[] = ws_connect($this->addr, '/news');
    }
    expect(news_live_wait($this->addr, 1, 5))->toBe(5);
    \proc_terminate($this->proc, \SIGTERM);
    foreach ($clients as $conn) {
        expect(ws_frame($conn))->toBe([8, \pack('n', 1001)]);
        ws_send($conn, \pack('n', 1001), 8); // the goodbye back, and closing, as a browser does
        expect(ws_frame($conn))->toBeNull();
        \fclose($conn);
    }
    $exit       = app_stop($this->proc, false);
    $this->proc = null;
    expect($exit)->toBe(0)
        ->and(\file_get_contents($this->log))->not->toMatch('/error|exception|warning/i');
});

it('keeps memory flat over 10,000 requests', function () {
    $jar = jar();
    http("http://$this->addr/", $jar);
    $ch    = request("http://$this->addr/", $jar);
    $count = function (int $n) use ($ch) {
        for ($i = 0; $i < $n; ++$i) {
            expect(\curl_exec($ch))->toContain('Hello!');
        }
    };
    $count(1_000);
    $before = (int) http("http://$this->addr/memory")[1];
    $count(9_000);
    $after = (int) http("http://$this->addr/memory")[1];
    expect($after - $before)->toBeLessThan(256 * 1024);
})->group('slow');
