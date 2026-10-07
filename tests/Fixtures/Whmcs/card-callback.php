<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime {
    final class AddonSettings
    {
        public static function fromPdo(\PDO $pdo): self
        {
            return new self();
        }
        public function documentFieldIds(): array
        {
            return [1];
        }
        public function integer(string $key, int $default): int
        {
            return $default;
        }
        public function boolean(string $key, bool $default): bool
        {
            return $default;
        }
        public function string(string $key, string $default): string
        {
            return $default;
        }
    }
    final class RuntimeFactory
    {
        public static function pdo(): \PDO
        {
            return $GLOBALS['pdo'];
        }
        public static function migrate(): void
        {
        }
        public static function runtime(): object
        {
            return new class {
                public function assertCardInvoice(int $invoice, int $client, ?int $amount = null): void
                {
                }
            };
        }
        public static function localApi(): callable
        {
            return static fn (): array => ['result' => 'success', 'customfields' => ['customfield' => [['id' => 1, 'value' => '12345678901']]]];
        }
        public static function card(): object
        {
            return new class {
                public function registerOpaqueCard(string $customer, array $payload, object $key): object
                {
                    $GLOBALS['effects'][] = 'register';
                    if (!empty($GLOBALS['scenario']['uncertain'])) {
                        throw new \Pagou\Whmcs\Payment\Card\Exception\CardUncertainOperation('timeout');
                    }
                    return new \Pagou\Whmcs\Payment\Card\Dto\StoredCard($GLOBALS['newCard'], $customer, 'Visa', '4242');
                }
                public function charge(object $request, object $key): object
                {
                    $GLOBALS['effects'][] = 'charge';
                    return new \Pagou\Whmcs\Payment\Card\Dto\CardCharge('charge-1', \Pagou\Whmcs\Payment\Card\CardStatus::Paid, 1200);
                }
                public function deleteCard(string $id, object $key): void
                {
                    $GLOBALS['effects'][] = 'delete';
                }
            };
        }
        public static function cardReconciliation(\PDO $pdo): object
        {
            return new class {
                public function process(object $charge): string
                {
                    $GLOBALS['effects'][] = 'reconcile';
                    return 'applied';
                }
            };
        }
    }
}
namespace WHMCS\Database {
    final class Capsule
    {
        public static function connection(): object
        {
            return new class {
                public function transaction(callable $write): void
                {
                    $pdo = $GLOBALS['pdo'];
                    $pdo->beginTransaction();
                    try {
                        $write();
                        $pdo->commit();
                    } catch (\Throwable $error) {
                        $pdo->rollBack();
                        throw $error;
                    }
                }
            };
        }
    }
}
namespace WHMCS\User {
    final class Client
    {
        public static function findOrFail(int $id): self
        {
            return new self();
        }
        public function payMethods(): object
        {
            return new class {
                public function lockForUpdate(): self
                {
                    return $this;
                }
                public function findOrFail(int $id): object
                {
                    if (!empty($GLOBALS['scenario']['stale'])) {
                        throw new \RuntimeException('Stale Pay Method');
                    }
                    return new class {
                        public object $payment;
                        public function __construct()
                        {
                            $this->payment = new class {
                                public function getRemoteToken(): string
                                {
                                    return $GLOBALS['oldReference'];
                                }
                                public function refresh(): void
                                {
                                }
                                public function setCardNumber(string $number): void
                                {
                                }
                                public function setCardType(string $brand): void
                                {
                                }
                                public function saveOrFail(): void
                                {
                                    if (!empty($GLOBALS['scenario']['save_failure'])) {
                                        throw new \RuntimeException('Save failed');
                                    }
                                }
                            };
                        }
                        public function isRemoteCreditCard(): bool
                        {
                            return true;
                        }
                    };
                }
            };
        }
    }
}
namespace {
    require $argv[2] . '/vendor/autoload.php';
    final class App
    {
        // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Native WHMCS API name.
        public static function load_function(string $name): void
        {
        }
    }
    function getGatewayVariables(string $gateway): array
    {
        return ['type' => 'RemoteInput', 'systemurl' => 'https://merchant.example/cliente'];
    }
    function logActivity(string $message): void
    {
    }
    function checkCbInvoiceID(int $id, string $gateway): int
    {
        return $id;
    }
    function callback3DSecureRedirect(int $invoice, bool $paid): never
    {
        echo $paid ? 'paid-redirect' : 'failed-redirect';
        exit;
    }
    function invoiceSaveRemoteCard(...$args): void
    {
        $GLOBALS['effects'][] = 'save-invoice-card';
        if (!empty($GLOBALS['scenario']['save_failure'])) {
            throw new RuntimeException('Save failed');
        }
    }
    function createCardPayMethod(...$args): void
    {
        $GLOBALS['pdo']->exec("INSERT INTO native_cards VALUES (2, 'new')");
    }
    function updateCardPayMethod(...$args): void
    {
        $GLOBALS['pdo']->exec("UPDATE native_cards SET reference = 'new' WHERE id = 1");
    }
    $GLOBALS['scenario'] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    $scenario = $GLOBALS['scenario'];
    $GLOBALS['effects'] = [];
    $GLOBALS['pdo'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    (new \Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner($GLOBALS['pdo']))->migrate(require $argv[2] . '/package/modules/addons/pagou_payments/migrations.php');
    $GLOBALS['pdo']->exec("CREATE TABLE native_cards (id INTEGER PRIMARY KEY, reference TEXT)");
    $GLOBALS['pdo']->exec("INSERT INTO native_cards VALUES (1, 'old')");
    $customer = '11111111-1111-4111-8111-111111111111';
    $GLOBALS['newCard'] = '22222222-2222-4222-8222-222222222222';
    $GLOBALS['oldReference'] = (new \Pagou\Whmcs\Payment\Card\RemoteCardReference($customer, '33333333-3333-4333-8333-333333333333'))->encode();
    (new \Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardCustomerStore($GLOBALS['pdo']))->save(7, $customer, '12345678901');
    $store = new \Pagou\Whmcs\Payment\Card\RemoteInput\PdoRemoteInputSessionStore($GLOBALS['pdo']);
    $workflow = $scenario['workflow'] ?? 'payment';
    $session = $store->issue($workflow, 7, $workflow === 'payment' ? 19 : null, $workflow === 'update' ? 1 : null, 1200, 'BRL', $GLOBALS['oldReference']);
    if (in_array($scenario['status'] ?? '', ['processing', 'completed'], true)) {
        $store->claim($session['id'], $session['secret']);
    }
    if (($scenario['status'] ?? '') === 'completed') {
        $store->complete($session['id'], ['workflow' => $workflow, 'pending' => true]);
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
    $_POST = ['session' => $session['id'], 'authorization' => !empty($scenario['bad_secret']) ? 'invalid' : $session['secret'],
        'card_token' => 'opaque-fixture-token', 'holder' => 'Cliente Teste', 'expires_at' => '2030-12'];
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        echo json_encode(['http' => http_response_code() ?: 200, 'body' => $body,
            'effects' => $GLOBALS['effects'],
            'session' => $GLOBALS['pdo']->query('SELECT status, result_json FROM pagou_card_remote_input_sessions')->fetch(PDO::FETCH_ASSOC),
            'native' => $GLOBALS['pdo']->query('SELECT * FROM native_cards ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
    });
}
