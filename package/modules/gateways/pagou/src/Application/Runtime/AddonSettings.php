<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Configuration\EncryptedCredentialStore;

final class AddonSettings
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values, private readonly ?string $encryptedApiKey = null)
    {
    }

    public static function fromPdo(PDO $pdo): self
    {
        $values = (new CentralSettingsStore($pdo))->values();
        $methodSettings = [
            'pagou_pix' => ['pix_due_enabled', 'pix_expiration_seconds', 'pix_due_expiration_days'],
            'pagou_boleto' => ['boleto_grace_period', 'boleto_email_template'],
            'pagou_creditcard' => [],
        ];
        $centralKeys = [];
        $central = $pdo->query("SELECT setting_key FROM pagou_settings WHERE is_secret = 0");
        while ($central !== false && ($row = $central->fetch(PDO::FETCH_ASSOC)) !== false) {
            $centralKeys[(string) ($row['setting_key'] ?? '')] = true;
        }
        $statement = $pdo->query(
            "SELECT gateway, setting, value FROM tblpaymentgateways "
            . "WHERE gateway IN ('pagou_pix', 'pagou_boleto', 'pagou_creditcard')"
        );
        if ($statement !== false) {
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $gateway = (string) ($row['gateway'] ?? '');
                $setting = (string) ($row['setting'] ?? '');
                if (in_array($setting, $methodSettings[$gateway] ?? [], true) && !isset($centralKeys[$setting])) {
                    $values[$setting] = (string) ($row['value'] ?? '');
                }
            }
        }

        $encryptedApiKey = null;
        try {
            $encryptedApiKey = (new EncryptedCredentialStore($pdo))->load();
        } catch (\Throwable) {
            // Readiness reports the missing or unreadable credential later.
        }

        return new self($values, $encryptedApiKey);
    }

    public function lateChargesNeedReview(string $method): bool
    {
        return \Pagou\Whmcs\Payment\LateChargeRules::needsReview($this->values, $method);
    }

    public function apiKey(): string
    {
        $environment = getenv('PAGOU_API_KEY');
        $value = is_string($environment) && trim($environment) !== ''
            ? trim($environment)
            : trim($this->encryptedApiKey ?? '');
        if ($value === '') {
            throw new \RuntimeException('Configure a credencial Pagou no addon antes de emitir cobranças.');
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = trim($this->values[$key] ?? '');

        return $value === '' ? $default : $value;
    }

    public function integer(string $key, int $default): int
    {
        $value = $this->values[$key] ?? '';

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->values)) {
            return $default;
        }

        return in_array(strtolower($this->values[$key]), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public function decimal(string $key, float $default = 0.0): float
    {
        $value = str_replace(',', '.', trim($this->values[$key] ?? ''));

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @return list<int> */
    public function documentFieldIds(): array
    {
        $ids = [];
        $keys = $this->boolean('prefer_cnpj')
            ? ['cnpj_field_id', 'cpf_field_id']
            : ['cpf_field_id', 'cnpj_field_id'];
        foreach ($keys as $key) {
            $value = $this->integer($key, 0);
            if ($value > 0) {
                $ids[] = $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
