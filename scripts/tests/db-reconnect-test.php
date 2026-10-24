<?php
// php scripts/tests/db-reconnect-test.php — queries survive the server dropping an idle connection
// (shared hosts close idle connections quickly; AI calls keep PHP busy for 20-60 s without touching the DB)
require __DIR__ . '/_assert.php';

t('query after idle disconnect reconnects', function () {
    q('SET SESSION wait_timeout = 1');
    sleep(3);
    eq((int) q_val('SELECT 1'), 1);
});
t('time zone is set again after reconnect', function () {
    $before = q_val('SELECT @@session.time_zone');
    q('SET SESSION wait_timeout = 1');
    sleep(3);
    eq(q_val('SELECT @@session.time_zone'), $before);
});
t('no silent retry inside a transaction', function () {
    $threw = false;
    try {
        transaction(function () {
            q('SET SESSION wait_timeout = 1');
            sleep(3);
            q('SELECT 1');
        });
    } catch (PDOException $e) {
        $threw = true;
    }
    ok($threw, 'a dropped connection inside a transaction must surface');
    eq((int) q_val('SELECT 1'), 1, 'next query outside the transaction works');
});
t_done();
