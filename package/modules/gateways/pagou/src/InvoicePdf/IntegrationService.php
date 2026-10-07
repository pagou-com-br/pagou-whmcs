<?php

declare(strict_types=1);

namespace Pagou\Whmcs\InvoicePdf;

use PDO;
use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;

final class IntegrationService
{
    public function __construct(private readonly PDO $pdo, private readonly string $root)
    {
    }

    public static function storage(string $root): PrivateStorage
    {
        $configured = getenv('PAGOU_PRIVATE_STORAGE_DIR');
        return new PrivateStorage(
            is_string($configured) && trim($configured) !== '' ? trim($configured) : dirname($root) . '/pagou-whmcs-private',
            $root,
        );
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        try {
            $theme = $this->configuration('Template');
            $state = $this->integration()->inspect($theme);
            return $state + [
                'available' => true,
                'nativeAttachments' => $this->configuration('EnablePDFInvoices') === 'on',
                'message' => $state['installed']
                    ? 'Integração presente no tema ativo. Homologue o PDF e o e-mail antes de liberar o meio de pagamento.'
                    : 'O tema ativo ainda usa seu gerador atual. A integração é opcional e preserva outros gateways.',
            ];
        } catch (\Throwable $exception) {
            return ['available' => false, 'installed' => false, 'message' => 'Integração automática indisponível. Confira o tema, as permissões e o armazenamento privado.'];
        }
    }

    public function usesNativeAttachment(): bool
    {
        $state = $this->inspect();
        return ($state['installed'] ?? false) === true && ($state['nativeAttachments'] ?? false) === true;
    }

    public function change(string $expectedTheme, string $expectedHash, bool $install): void
    {
        if ($expectedTheme !== $this->configuration('Template')) {
            throw new \RuntimeException('O tema ativo mudou. Atualize esta página antes de continuar.');
        }
        if ($install && !class_exists(\TCPDF::class)) {
            throw new \RuntimeException('O gerador de PDF do WHMCS não está disponível nesta instalação.');
        }
        $this->integration()->change($expectedTheme, $expectedHash, $install);
    }

    private function integration(): TemplateIntegration
    {
        if ($this->root === '') {
            throw new \RuntimeException('Instalação WHMCS indisponível.');
        }
        return new TemplateIntegration($this->root, self::storage($this->root));
    }

    private function configuration(string $key): string
    {
        $query = $this->pdo->prepare('SELECT value FROM tblconfiguration WHERE setting = :setting LIMIT 1');
        $query->execute(['setting' => $key]);
        return (string) ($query->fetchColumn() ?: '');
    }
}
