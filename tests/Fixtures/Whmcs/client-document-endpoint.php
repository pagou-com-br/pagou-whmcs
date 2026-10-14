<?php

declare(strict_types=1);

namespace WHMCS\Authentication {
    final class CurrentUser
    {
        public function client(): ?object
        {
            return ($GLOBALS['scenario']['anonymous'] ?? false) === true ? null : (object) ['id' => 20];
        }
    }
}

namespace Pagou\Whmcs\Application\Runtime {
    final class RuntimeFactory
    {
        public static function runtime(): object
        {
            return new class {
                /** @return array<string, string> */
                public function saveInvoiceDocument(int $invoice, string $method, int $client, string $document): array
                {
                    $GLOBALS['effects'][] = ['invoice' => $invoice, 'method' => $method, 'client' => $client, 'document' => $document];

                    return $GLOBALS['scenario']['result'] ?? ['status' => 'ok'];
                }
            };
        }
    }
}

namespace {
    $GLOBALS['scenario'] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['effects'] = [];
    $_SESSION = ['pagou_invoice_progress' => [10 => 'fixture-token']];
    $_SERVER['REQUEST_METHOD'] = $GLOBALS['scenario']['method'] ?? 'POST';
    $_SERVER['HTTP_ACCEPT'] = $GLOBALS['scenario']['accept'] ?? 'application/json';
    $_POST = array_replace(['invoice' => '10', 'method' => 'pix', 'token' => 'fixture-token', 'document' => '529.982.247-25'], $GLOBALS['scenario']['input'] ?? []);
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        echo json_encode(['http' => http_response_code() ?: 200, 'body' => json_decode((string) $body, true), 'effects' => $GLOBALS['effects']], JSON_THROW_ON_ERROR);
    });
}
