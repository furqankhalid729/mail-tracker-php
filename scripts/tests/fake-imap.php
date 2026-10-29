<?php
/**
 * Minimal fake IMAP server for tests (plain TCP, no TLS).
 *   php scripts/tests/fake-imap.php <port> <fixture.json> [connections=1]
 * fixture.json: {"list": ["* LIST ... raw lines"], "boxes": {"INBOX": {"uidvalidity": 1, "messages": {"1": "raw rfc822"}}}}
 * Prints "READY" once listening. Accepts any LOGIN.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
[, $port, $fixtureFile] = $argv;
$connections = (int) ($argv[3] ?? 1);
$fx = json_decode((string) file_get_contents($fixtureFile), true);

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
echo "READY\n";
fflush(STDOUT);

for ($n = 0; $n < $connections; $n++) {
    $c = @stream_socket_accept($server, 30);
    if (!$c) {
        break;
    }
    fwrite($c, "* OK fake IMAP ready\r\n");
    $box = null;
    while (($line = fgets($c)) !== false) {
        if (!preg_match('/^(\S+) (.+?)\r?\n$/', $line, $m)) {
            continue;
        }
        [$tag, $cmd] = [$m[1], $m[2]];
        if (preg_match('/^LOGIN /i', $cmd)) {
            fwrite($c, "$tag OK LOGIN completed\r\n");
        } elseif (preg_match('/^LIST /i', $cmd)) {
            foreach ($fx['list'] as $l) {
                fwrite($c, $l . "\r\n");
            }
            fwrite($c, "$tag OK LIST completed\r\n");
        } elseif (preg_match('/^(?:EXAMINE|SELECT) "((?:[^"\\\\]|\\\\.)*)"/i', $cmd, $bm)) {
            $name = str_replace(['\\"', '\\\\'], ['"', '\\'], $bm[1]);
            if (!isset($fx['boxes'][$name])) {
                fwrite($c, "$tag NO Mailbox does not exist\r\n");
                $box = null;
                continue;
            }
            $box = $name;
            fwrite($c, '* ' . count($fx['boxes'][$name]['messages']) . " EXISTS\r\n");
            fwrite($c, '* OK [UIDVALIDITY ' . $fx['boxes'][$name]['uidvalidity'] . "] UIDs valid\r\n");
            fwrite($c, "$tag OK [READ-ONLY] EXAMINE completed\r\n");
        } elseif (preg_match('/^UID SEARCH/i', $cmd)) {
            $uids = $box ? array_keys($fx['boxes'][$box]['messages']) : [];
            fwrite($c, '* SEARCH' . ($uids ? ' ' . implode(' ', $uids) : '') . "\r\n$tag OK SEARCH completed\r\n");
        } elseif (preg_match('/^UID FETCH (\d+)/i', $cmd, $fm)) {
            $raw = $box ? ($fx['boxes'][$box]['messages'][$fm[1]] ?? null) : null;
            if ($raw !== null) {
                fwrite($c, "* 1 FETCH (UID {$fm[1]} BODY[] {" . strlen($raw) . "}\r\n" . $raw . ")\r\n");
            }
            fwrite($c, "$tag OK FETCH completed\r\n");
        } elseif (preg_match('/^LOGOUT/i', $cmd)) {
            fwrite($c, "* BYE\r\n$tag OK LOGOUT completed\r\n");
            break;
        } else {
            fwrite($c, "$tag BAD unknown command\r\n");
        }
    }
    fclose($c);
}
