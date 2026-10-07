<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;

final class ClientDocumentResolver
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AddonSettings $settings,
    ) {
    }

    /** @param array<string, mixed> $client */
    public function resolve(array $client, int $clientId): string
    {
        $ids = $this->settings->documentFieldIds();
        $fields = $client['customfields']['customfield'] ?? [];
        if (is_array($fields) && !array_is_list($fields)) {
            $fields = [$fields];
        }
        foreach ($ids as $id) {
            foreach (is_array($fields) ? $fields : [] as $field) {
                if (!is_array($field) || (int) ($field['id'] ?? 0) !== $id) {
                    continue;
                }
                $document = $this->normalise($field['value'] ?? null);
                if ($document !== null) {
                    return $document;
                }
            }
        }

        if ($ids !== []) {
            $placeholders = [];
            $parameters = ['client_id' => $clientId];
            foreach ($ids as $index => $id) {
                $key = 'field_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $id;
            }
            $statement = $this->pdo->prepare(
                'SELECT fieldid, value FROM tblcustomfieldsvalues '
                . 'WHERE relid = :client_id AND fieldid IN (' . implode(', ', $placeholders) . ')'
            );
            $statement->execute($parameters);
            $stored = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $stored[(int) ($row['fieldid'] ?? 0)] = $row['value'] ?? null;
            }
            foreach ($ids as $id) {
                $document = $this->normalise($stored[$id] ?? null);
                if ($document !== null) {
                    return $document;
                }
            }
        }

        throw new \RuntimeException('CPF ou CNPJ não encontrado nos campos configurados.');
    }

    private function normalise(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $document = preg_replace('/\D+/', '', (string) $value) ?? '';

        return in_array(strlen($document), [11, 14], true) ? $document : null;
    }
}
