<?php

/*
 * WebSockets from Yii routes and actions, on two workers: both ways, server push through
 * Swerve::subscribe(), clients leaving, the user taken from the request, a refusal.
 */

beforeAll(function () {
    $GLOBALS['ws-app'] = app_start();
});
afterAll(function () {
    app_stop($GLOBALS['ws-app'][0]);
});
beforeEach(function () {
    [, $this->addr, $this->log] = $GLOBALS['ws-app'];
});

it('echoes text and binary messages, several in a row', function () {
    $conn = ws_connect($this->addr, '/ws');
    foreach (['hello', 'again', 'ünïcödé ✓'] as $text) {
        ws_send($conn, $text);
        expect(ws_frame($conn))->toBe([1, "echo: $text"]);
    }
    $binary = \random_bytes(1000);
    ws_send($conn, $binary, 2);
    expect(ws_frame($conn))->toBe([2, $binary]);
    ws_send($conn, \pack('n', 1000), 8);
    expect(ws_frame($conn))->toBe([8, \pack('n', 1000)]);
    \fclose($conn);
});

it('pushes every published message to every client on both workers, in order', function () {
    $clients = [];
    for ($i = 0; $i < 16; ++$i) {
        $clients[] = ws_connect($this->addr, '/news');
    }
    expect(news_live_wait($this->addr, 2, 16))->toBe(16)
        ->and(\min(news_live_by_worker($this->addr, 2)))->toBeGreaterThan(0); // on both workers
    foreach (['one', 'two', 'three'] as $m) {
        expect(http("http://$this->addr/news/publish/$m")[1])->toBe('published');
    }
    foreach ($clients as $conn) {
        expect([ws_read($conn), ws_read($conn), ws_read($conn)])->toBe(['guest: one', 'guest: two', 'guest: three']);
    }

    // Half leave without a word, half say goodbye: every callback ends either way
    foreach ($clients as $i => $conn) {
        if ($i % 2) {
            ws_send($conn, \pack('n', 1000), 8);
            expect(ws_frame($conn))->toBe([8, \pack('n', 1000)]);
        }
        \fclose($conn);
    }
    expect(news_live_wait($this->addr, 2, 0))->toBe(0);
});

it('gives each socket the user who opened it, taken before WebSocket::from()', function () {
    $sockets = [];
    foreach (['ada', 'bob'] as $name) {
        $jar = jar();
        http("http://$this->addr/login/$name", $jar);
        $sockets[$name] = [ws_connect($this->addr, '/ws/who', $jar), ws_connect($this->addr, '/news', $jar)];
    }
    expect(news_live_wait($this->addr, 2, 2))->toBe(2);
    http("http://$this->addr/news/publish/hi");
    foreach ($sockets as $name => [$who, $news]) {
        ws_send($who, 'who');
        expect(ws_read($who))->toBe("taken: $name")
            ->and(ws_read($news))->toBe("$name: hi");
        \fclose($who);
        \fclose($news);
    }
    expect(news_live_wait($this->addr, 2, 0))->toBe(0);
});

it('answers an ordinary GET to a WebSocket route with 426', function () {
    [$status, $body] = http("http://$this->addr/ws");
    expect($status)->toBe(426)->and($body)->toBe('This address speaks WebSocket');
});

it('logged no errors', function () {
    expect(\file_get_contents($this->log))->not->toMatch('/error|exception|warning/i');
});
