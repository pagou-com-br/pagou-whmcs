<?php

declare(strict_types=1);

use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;
use Pagou\Whmcs\Payment\Card\RemoteInput\PdoRemoteInputSessionStore;
use WHMCS\Database\Capsule;

require __DIR__ . '/bootstrap.php';
$pdo = Capsule::connection()->getPdo();
$pdo->exec('SET innodb_lock_wait_timeout = 10');
echo "ready\n";
flush();
$input = json_decode((string) fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
try {
    if ($input['task'] === 'attempt') {
        $result = (new PdoCardAttemptStore($pdo))->begin(20, 7, $input['amount'], $input['card'], 1);
        echo json_encode(['created' => $result['created'], 'id' => $result['id']], JSON_THROW_ON_ERROR) . "\n";
    } else {
        $result = (new PdoRemoteInputSessionStore($pdo))->claim($input['id'], $input['secret']);
        echo json_encode(['claimed' => $result['status'] === 'processing'], JSON_THROW_ON_ERROR) . "\n";
    }
} catch (RuntimeException $error) {
    if ($error instanceof PDOException || $input['task'] !== 'claim') {
        throw $error;
    }
    echo json_encode(['claimed' => false], JSON_THROW_ON_ERROR) . "\n";
}
