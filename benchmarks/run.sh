#!/bin/bash
# The Yii skeleton (tests/Fixtures/app, made by tests/create-app.sh) on PHP-FPM behind nginx and
# on swerve, same machine, same number of processes, opcache on, debug off (APP_ENV=prod,
# APP_DEBUG=false in the app's .env). wrk -t4 -c64 -d10s against two routes:
#   /json  the test app's JSON route
#   /      the skeleton's home page, with a session cookie (the page starts the session for
#          its CSRF token)
#
#   FPM_BENCH=/path/to/fpm-bench EXT=/path/to/phasync.so benchmarks/run.sh
#
# FPM_BENCH  a directory with serve.sh <docroot> <port> <children>, which runs PHP-FPM behind
#            nginx in the foreground
# EXT        phasync-ext's .so, for the runs with the extension
# WORKERS    processes for both (default 4); PORT the first of three ports (default 18810)
# LOCK       wrk runs take this lock, so that only one benchmark runs at a time on the machine
set -eu
cd "$(dirname "$0")"
FPM_BENCH=${FPM_BENCH:?set FPM_BENCH} EXT=${EXT:?set EXT}
WORKERS=${WORKERS:-4} PORT=${PORT:-18810} LOCK=${LOCK:-$FPM_BENCH/bench.lock}
app=$(cd ../tests/Fixtures/app && pwd)
out=results
mkdir -p $out

wait_up() { for _ in $(seq 100); do curl -sf -o /dev/null "http://127.0.0.1:$1/json" && return; sleep 0.1; done; echo "port $1 did not answer" >&2; exit 1; }

bench() { # $1 name, $2 port
    local cookie
    cookie=$(curl -s -D - -o /dev/null "http://127.0.0.1:$2/" | grep -io 'PHPSESSID=[a-z0-9]*')
    for route in json home; do
        local path=/json header=()
        [ $route = home ] && path=/ header=(-H "Cookie: $cookie")
        curl -sf -o /dev/null "${header[@]}" "http://127.0.0.1:$2$path"  # warm up
        flock "$LOCK" wrk -t4 -c64 -d2s "${header[@]}" "http://127.0.0.1:$2$path" > /dev/null
        flock "$LOCK" wrk -t4 -c64 -d10s "${header[@]}" "http://127.0.0.1:$2$path" | tee "$out/$1-$route.txt"
    done
}

"$FPM_BENCH/serve.sh" "$app/public" $PORT $WORKERS > /dev/null 2>&1 &
fpm=$!
wait_up $PORT
bench fpm $PORT
kill $fpm; wait $fpm 2>/dev/null || true

for variant in plain ext; do
    port=$((PORT + 1))
    [ $variant = ext ] && port=$((PORT + 2))
    args=(-d opcache.enable_cli=1)
    [ $variant = ext ] && args+=(-d "extension=$EXT")
    (cd "$app" && exec php "${args[@]}" vendor/bin/swerve --workers=$WORKERS --no-access-log -q --http=127.0.0.1:$port swerve.php) &
    swerve=$!
    wait_up $port
    bench swerve-$variant $port
    kill $swerve; wait $swerve 2>/dev/null || true
done

# The table of the README
rps() { awk '/Requests\/sec/ { printf "%d", $2 }' "$out/$1-$2.txt" | sed ':a;s/\B[0-9]\{3\}\>/,&/;ta'; }
ratio() { awk -v a="$(awk '/Requests\/sec/ {print $2}' "$out/$1-$3.txt")" -v b="$(awk '/Requests\/sec/ {print $2}' "$out/$2-$3.txt")" 'BEGIN { printf "%.1f×", a / b }'; }
{
    echo "| Yii skeleton, $WORKERS processes | PHP-FPM | swerve | swerve + phasync-ext |"
    echo "|---|---:|---:|---:|"
    echo "| JSON route | $(rps fpm json) req/s | $(rps swerve-plain json) req/s ($(ratio swerve-plain fpm json)) | $(rps swerve-ext json) req/s ($(ratio swerve-ext fpm json)) |"
    echo "| Home page with session | $(rps fpm home) req/s | $(rps swerve-plain home) req/s ($(ratio swerve-plain fpm home)) | $(rps swerve-ext home) req/s ($(ratio swerve-ext fpm home)) |"
} | tee $out/table.md
