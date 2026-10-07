<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

final class OperationalFilters
{
    /**
     * @param array<string,string> $filters
     * @return list<string>
     */
    public static function errors(string $page, array $filters): array
    {
        $errors = [];
        foreach (['invoice' => 'Fatura', 'page' => 'Página'] as $key => $label) {
            $value = $filters[$key] ?? '';
            $max = $key === 'page' ? 9999 : 2147483647;
            if ($value !== '' && (preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1 || (int) $value > $max)) {
                $errors[] = $label . ': informe um número inteiro positivo válido.';
            }
        }
        $options = [
            'method' => ['pix', 'boleto', 'card'],
            'period' => ['today', '7d', '30d', '90d'],
            'status' => $page === 'payments'
                ? ['queued','pending','ready','awaiting_registration','authorized','action_required','paid','settled','cancelled','canceled','cancel_requested','superseded','refunded','reversed','charged_back','failed','uncertain','unknown']
                : ['queued','pending','leased','retrying','uncertain','succeeded','superseded','failed','started'],
        ];
        foreach ($options as $key => $values) {
            if (($filters[$key] ?? '') !== '' && !in_array($filters[$key], $values, true)) {
                $errors[] = ['method' => 'Método', 'period' => 'Período', 'status' => 'Situação'][$key] . ': selecione uma opção válida.';
            }
        }
        if ($page === 'payments' && !in_array($filters['group'] ?? '', ['', 'all'], true)) {
            $errors[] = 'Agrupamento: selecione uma opção válida.';
        }
        if (($filters['type'] ?? '') !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/D', $filters['type']) !== 1) {
            $errors[] = 'Tipo: selecione uma opção válida.';
        }
        return $errors;
    }
}
