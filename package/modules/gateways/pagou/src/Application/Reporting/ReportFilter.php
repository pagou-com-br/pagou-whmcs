<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReportFilter
{
    public readonly DateTimeImmutable $start;
    public readonly DateTimeImmutable $end;
    public readonly DateTimeImmutable $today;
    public readonly string $kind;
    public readonly string $method;
    public readonly string $status;
    public readonly int $client;
    public readonly int $invoice;
    public readonly int $page;
    public readonly string $query;
    public readonly string $document;
    public readonly string $party;

    /** @param array<string, string> $input */
    public function __construct(array $input = [], ?DateTimeImmutable $now = null)
    {
        $this->today = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('America/Sao_Paulo'))->setTime(0, 0);
        $this->start = self::date($input['from'] ?? $this->today->modify('first day of this month')->format('Y-m-d'));
        $this->end = self::date($input['to'] ?? $this->today->format('Y-m-d'));
        if ($this->end < $this->start || (int) $this->start->diff($this->end)->days > 365) {
            throw new InvalidArgumentException('Escolha um período de até 366 dias, com início anterior ou igual ao fim.');
        }
        $this->kind = $input['report'] ?? 'receipts';
        if (!in_array($this->kind, ['receipts', 'open', 'attempts', 'pending', 'refunds'], true)) {
            throw new InvalidArgumentException('Selecione um relatório válido.');
        }
        $this->method = $input['method'] ?? '';
        if (!in_array($this->method, ['', 'pix', 'boleto', 'card', 'unknown'], true)) {
            throw new InvalidArgumentException('Selecione um meio de pagamento válido.');
        }
        $this->status = $input['status'] ?? '';
        if (!array_key_exists($this->status, self::statuses($this->kind))) {
            throw new InvalidArgumentException('A situação não pertence ao relatório escolhido. Limpe o filtro de situação.');
        }
        $this->query = trim($input['q'] ?? '');
        if (strlen($this->query) > 64 || preg_match('/[\x00-\x1f]/', $this->query) === 1 || $this->query === '!invalid') {
            throw new InvalidArgumentException('Informe um nome de cliente com até 64 caracteres.');
        }
        $document = trim($input['document'] ?? '');
        $this->party = $input['party'] ?? 'either';
        if (!in_array($this->party, ['either', 'issued', 'payer'], true)) {
            throw new InvalidArgumentException('Selecione onde pesquisar o documento.');
        }
        if ($document !== '') {
            try {
                if (preg_match('/^[0-9.\/ -]{11,18}$/D', $document) !== 1) {
                    throw new InvalidArgumentException();
                }
                $document = \Pagou\Whmcs\Domain\Document::fromString($document)->digits();
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('Informe um CPF ou CNPJ completo e válido.');
            }
            if ($this->kind !== 'receipts') {
                throw new InvalidArgumentException('A pesquisa por documento está disponível em Recebimentos.');
            }
        }
        $this->document = $document;
        $this->client = self::id($input['client'] ?? '');
        $this->invoice = self::id($input['invoice'] ?? '');
        $this->page = max(1, self::id($input['page'] ?? '1'));
    }

    public static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('America/Sao_Paulo'));
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > '2099-12-31') {
            throw new InvalidArgumentException('Informe datas válidas entre 2000 e 2099.');
        }
        return $date;
    }

    /** @return array{string,string} */
    public function bounds(bool $previous = false): array
    {
        $days = (int) $this->start->diff($this->end)->days + 1;
        $start = $previous ? $this->start->modify('-' . $days . ' days') : $this->start;
        $end = $previous ? $this->start : $this->end->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /** @return array<string,string> */
    public function values(): array
    {
        return [
            'from' => $this->start->format('Y-m-d'), 'to' => $this->end->format('Y-m-d'),
            'document' => $this->document, 'party' => $this->party,
            'q' => $this->query, 'report' => $this->kind, 'method' => $this->method, 'status' => $this->status,
            'client' => $this->client > 0 ? (string) $this->client : '',
            'invoice' => $this->invoice > 0 ? (string) $this->invoice : '', 'page' => (string) $this->page,
        ];
    }

    /** @return array<string,string> */
    public static function statuses(string $kind): array
    {
        return ['' => 'Todas as situações'] + match ($kind) {
            'open' => ['open' => 'A vencer ou vence hoje', 'overdue' => 'Vencida'],
            'pending' => ['open' => 'Aberta', 'pending' => 'Pendente'],
            'refunds' => [
                'refund_done' => 'Devolvida', 'refund_confirmed' => 'Confirmada, registro pendente no WHMCS',
                'refund_progress' => 'Em andamento', 'refund_review' => 'Em conferência', 'refund_rejected' => 'Não aceita',
            ],
            'attempts' => [
                'queued' => 'Na fila', 'pending' => 'Pendente', 'ready' => 'Pronta', 'active' => 'Ativa',
                'awaiting_registration' => 'Registro bancário', 'paid' => 'Paga', 'failed' => 'Falha',
                'cancel_requested' => 'Cancelamento solicitado', 'cancelled' => 'Cancelada',
                'canceled' => 'Cancelada (cartão)', 'superseded' => 'Substituída', 'refunded' => 'Reembolsada',
                'authorized' => 'Autorizada', 'action_required' => 'Autenticação pendente',
                'reversed' => 'Autorização revertida', 'charged_back' => 'Contestada', 'dispatching' => 'Em envio',
                'uncertain' => 'Resultado incerto', 'unavailable' => 'Indisponível',
            ],
            default => ['applied' => 'Conciliado no WHMCS'],
        };
    }

    private static function id(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Informe apenas números positivos nos campos de cliente, fatura e página.');
        }
        return (int) $value;
    }
}
