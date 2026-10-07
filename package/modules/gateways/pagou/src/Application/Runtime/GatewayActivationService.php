<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;

/**
 * Activates the production-ready Pagou gateways through the official WHMCS
 * Local API and verifies the safe postcondition before returning success.
 */
final class GatewayActivationService
{
    /** @var callable(string, array<string, mixed>): array<string, mixed> */
    private $localApi;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $localApi
     */
    public function __construct(
        private readonly PDO $pdo,
        callable $localApi,
    ) {
        $this->localApi = $localApi;
    }

    /**
     * @return array{gateway:string,label:string,outcome:string}
     */
    public function activate(string $gateway): array
    {
        $definition = $this->definition($gateway);
        $before = $this->status($gateway);
        if ($before['active']) {
            if ($before['visible']) {
                $this->hide($gateway);

                return ['gateway' => $gateway, 'label' => $definition['label'], 'outcome' => 'visibility_corrected'];
            }

            return ['gateway' => $gateway, 'label' => $definition['label'], 'outcome' => 'already_active'];
        }

        $result = ($this->localApi)('ActivateModule', [
            'moduleType' => 'gateway',
            'moduleName' => $gateway,
            'parameters' => $definition['defaults'],
        ]);
        $this->assertSuccess($result, 'ativar ' . $definition['label']);

        $after = $this->status($gateway);
        if (!$after['active']) {
            throw new \RuntimeException('O WHMCS não confirmou a ativação do gateway.');
        }
        if ($after['visible']) {
            $this->hide($gateway);
        }

        return ['gateway' => $gateway, 'label' => $definition['label'], 'outcome' => 'activated_hidden'];
    }

    /**
     * @return array{active:bool,visible:bool}
     */
    private function status(string $gateway): array
    {
        $statement = $this->pdo->prepare(
            'SELECT setting, value FROM tblpaymentgateways WHERE gateway = :gateway'
        );
        $statement->execute(['gateway' => $gateway]);
        $active = false;
        $visible = false;
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $active = true;
            if (strtolower((string) ($row['setting'] ?? '')) !== 'visible') {
                continue;
            }
            $visible = in_array(strtolower(trim((string) ($row['value'] ?? ''))), ['on', '1', 'yes', 'true'], true);
        }

        return compact('active', 'visible');
    }

    private function hide(string $gateway): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE tblpaymentgateways SET value = '' "
            . "WHERE gateway = :gateway AND setting = 'visible'"
        );
        $statement->execute(['gateway' => $gateway]);

        $after = $this->status($gateway);
        if (!$after['active'] || $after['visible']) {
            throw new \RuntimeException('O WHMCS não confirmou que o gateway permaneceu ativo e oculto.');
        }
    }

    /** @param array<string, mixed> $result */
    private function assertSuccess(array $result, string $operation): void
    {
        if (($result['result'] ?? null) === 'success') {
            return;
        }

        throw new \RuntimeException('O WHMCS não conseguiu ' . $operation . '.');
    }

    /**
     * @return array{label:string,defaults:array<string, string>}
     */
    private function definition(string $gateway): array
    {
        return match ($gateway) {
            'pagou_pix' => [
                'label' => 'Pix',
                'defaults' => [
                    'visible' => '',
                ],
            ],
            'pagou_boleto' => [
                'label' => 'Boleto',
                'defaults' => [
                    'visible' => '',
                ],
            ],
            'pagou_creditcard' => [
                'label' => 'Cartão de crédito',
                'defaults' => [
                    'visible' => '',
                ],
            ],
            default => throw new \InvalidArgumentException('Gateway indisponível para ativação assistida.'),
        };
    }
}
