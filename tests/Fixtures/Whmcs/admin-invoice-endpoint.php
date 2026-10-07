<?php

declare(strict_types=1);

namespace WHMCS\Authentication {
    final class CurrentUser
    {
        public function admin(): ?object
        {
            if (($GLOBALS['scenario']['anonymous'] ?? false) === true) {
                return null;
            }
            return new class {
                public int $id = 1;
                public bool $isDisabled;

                public function __construct()
                {
                    $this->isDisabled = $GLOBALS['scenario']['disabled'] ?? false;
                }

                public function hasPermission(string $name): bool
                {
                    // WHMCS native permission labels, including permission 35 (singular).
                    return in_array($name, $GLOBALS['scenario']['permissions'] ?? ['Manage Invoice'], true);
                }

                public function getModulePermissions(): array
                {
                    return ($GLOBALS['scenario']['module'] ?? true) ? ['pagou_payments' => true] : [];
                }
            };
        }
    }
}

namespace Pagou\Whmcs\Application\Runtime {
    final class RuntimeFactory
    {
        public static function runtime(): object
        {
            return new class {
                public function adminSummary(int $id): array
                {
                    return ['state' => 'ready', 'method' => 'boleto', 'attemptId' => 'current', 'remoteId' => 'remote', 'pdfUrl' => '/pdf'];
                }

                public function requestInvoiceCancellation(int $id, string $reason, int $admin, bool $replace): void
                {
                    $GLOBALS['effects'][] = ['invoice' => $id, 'replace' => $replace];
                }

                public function nativePixRefund(int $invoice, int $account, string $action): array
                {
                    if ($action !== 'read') {
                        $GLOBALS['effects'][] = ['refresh' => $invoice, 'account' => $account, 'action' => $action];
                    }
                    return ['supported' => true, 'refundStatus' => 'requested', 'ready' => false];
                }

                public function advanceInvoice(int $id): object
                {
                    $GLOBALS['effects'][] = ['advance' => $id];
                    return (object) ['failed' => 0, 'uncertain' => 0];
                }
            };
        }
    }
}

namespace {
    $GLOBALS['scenario'] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['effects'] = [];
    $_SESSION = ['tkval' => 'fixture-token'];
    $_SERVER['REQUEST_METHOD'] = $GLOBALS['scenario']['method'] ?? 'POST';
    $_POST = ['transaction_id' => '22', 'invoice_id' => '406689', 'token' => 'fixture-token', 'action' => 'replace-boleto', 'expected_attempt' => 'current'];
    $_POST = array_replace($_POST, $GLOBALS['scenario']['input'] ?? []);
    $_GET = ['invoice_id' => '406689', 'transaction_id' => '22'];
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        echo json_encode(['http' => http_response_code() ?: 200, 'body' => json_decode($body, true), 'effects' => $GLOBALS['effects']], JSON_THROW_ON_ERROR);
    });
}
