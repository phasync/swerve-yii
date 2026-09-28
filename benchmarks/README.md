# Benchmarks

`run.sh` serves the Yii skeleton of the tests (`tests/create-app.sh 3`: yiisoft/app 1.4 with the
test routes) with PHP-FPM behind nginx and with swerve, and measures two routes with
`wrk -t4 -c64 -d10s` after a 2 s warm-up:

- `/json`: the test app's JSON route, through the skeleton's whole middleware stack.
- `/`: the skeleton's home page, rendered with its layout, with a session cookie (the page
  keeps its CSRF token in the session, in PHP's files handler).

Both run 4 processes: PHP-FPM `pm = static` with 4 children (nginx with 4 workers in front), and
swerve `--workers=4 --no-access-log` (nginx's access log is off too), without and with
phasync-ext. Opcache is on for both (`opcache.enable_cli=1` for swerve), APP_ENV=prod and
APP_DEBUG=false.

Machine: 2 × Intel Xeon E5-2697 v3 (56 threads), Linux 6.8, PHP 8.5.11 NTS, swerve
0.1.0-alpha12, phasync-ext 0.5.0-alpha8, wrk on the same machine over loopback. Other work
ran on the machine at the same time, so the absolute figures are rough; the ratios hold.

Raw output: [results/](results/). The table: [results/table.md](results/table.md).
