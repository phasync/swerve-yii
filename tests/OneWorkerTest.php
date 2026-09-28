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
