<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

/** Two independent PHP processes must both reach an actual InnoDB lock wait. */
function race(PDO $pdo, string $lockSql, array $inputs): array
{
    $workers = [];
    $pdo->beginTransaction();
    try {
        $pdo->query($lockSql)->fetchAll();
        foreach ($inputs as $index => $input) {
            $command = [PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'allow_url_fopen=0', '-d',
                'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail',
                __DIR__ . '/worker.php'];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'],
                2 => ['file', getenv('PAGOU_NATIVE_RUN_DIR') . '/worker-' . $index . '.log', 'a']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not launch concurrent worker');
            }
            $workers[] = ['process' => $process, 'pipes' => $pipes];
            stream_set_timeout($pipes[1], 10);
            if (trim((string) fgets($pipes[1])) !== 'ready') {
                throw new RuntimeException('Concurrent worker did not initialize');
            }
            fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR) . "\n");
            fclose($pipes[0]);
        }
        $deadline = microtime(true) + 8;
        do {
            $waiting = (int) $pdo->query('SELECT COUNT(DISTINCT requesting_trx_id) FROM information_schema.INNODB_LOCK_WAITS')->fetchColumn();
            if ($waiting >= 2) {
                break;
            }
            // MariaDB caches InnoDB monitoring tables briefly between reads.
            usleep(200000);
        } while (microtime(true) < $deadline);
        check($waiting >= 2, 'Both independent workers reached a database row lock');
        $pdo->commit();
        $results = [];
        foreach ($workers as &$worker) {
            $output = fgets($worker['pipes'][1]);
            fclose($worker['pipes'][1]);
            $code = proc_close($worker['process']);
            $worker['process'] = null;
            if ($code !== 0 || $output === false) {
                throw new RuntimeException('Concurrent worker failed; inspect retained synthetic logs');
            }
            $results[] = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        }
        unset($worker);
        return $results;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($workers as $worker) {
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
    }
}

$results = race($pdo, 'SELECT id FROM tblinvoices WHERE id = 20 FOR UPDATE', [
    ['task' => 'attempt', 'amount' => 1000, 'card' => 'synthetic-a'],
    ['task' => 'attempt', 'amount' => 2000, 'card' => 'synthetic-b'],
]);
check(count(array_filter($results, static fn (array $r): bool => $r['created'])) === 1, 'Concurrent requests create exactly one attempt');
check($results[0]['id'] === $results[1]['id'], 'Concurrent requests converge on the same attempt');
check((int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts WHERE invoice_id = 20')->fetchColumn() === 1, 'Changed amount or card does not duplicate a concurrent attempt');

$issued = sessions()->issue('create', 7, null, null, 0, 'BRL', null);
$input = ['task' => 'claim', 'id' => $issued['id'], 'secret' => $issued['secret']];
$results = race($pdo, 'SELECT id FROM pagou_card_remote_input_sessions WHERE id = ' . $pdo->quote($issued['id']) . ' FOR UPDATE', [$input, $input]);
check(count(array_filter($results, static fn (array $r): bool => $r['claimed'])) === 1, 'Concurrent callbacks consume a session exactly once');
check(!$pdo->inTransaction() && Capsule::connection()->transactionLevel() === 0, 'Connection remains usable after concurrency tests');
