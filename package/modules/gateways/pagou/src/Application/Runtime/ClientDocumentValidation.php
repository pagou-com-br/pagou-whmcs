<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Pagou\Whmcs\Domain\Document;

/**
 * Checks the CPF or CNPJ a client in Brazil types on registration or on a profile edit,
 * in the document fields the module reads, so a wrong number is fixed before any charge.
 */
final class ClientDocumentValidation
{
    public const MESSAGE = 'O CPF ou CNPJ informado não é válido. Confira os números.';

    /**
     * @param array<string, mixed> $vars The fields WHMCS validates, with `customfield` by field id.
     * @return list<string>
     */
    public static function errors(array $vars, AddonSettings $settings): array
    {
        // Abroad there may be no CPF at all; an empty field keeps the WHMCS required rule.
        if (!$settings->boolean('document_check_on_profile', true) || strtoupper(trim((string) ($vars['country'] ?? ''))) !== 'BR') {
            return [];
        }
        $submitted = is_array($vars['customfield'] ?? null) ? $vars['customfield'] : [];
        foreach ($settings->documentFieldIds() as $id) {
            $value = $submitted[$id] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            try {
                Document::fromString((string) $value);
            } catch (\InvalidArgumentException) {
                return [self::MESSAGE];
            }
        }

        return [];
    }
}
