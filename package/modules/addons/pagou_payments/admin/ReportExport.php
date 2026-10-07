<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin;

use PDO;
use Pagou\Payments\Admin\Http\AdminRequest;
use Pagou\Payments\Admin\Security\Authorization;
use Pagou\Payments\Admin\Security\Csrf;
use Pagou\Payments\Admin\Support\ReportFormat as F;
use Pagou\Whmcs\Application\Reporting\MerchantReadModel;
use Pagou\Whmcs\Application\Reporting\ReportFilter;

/** Called only inside the addon output callback, after WHMCS native addon ACL. */
final class ReportExport
{
    /** @var resource */
    private $stream;
    private string $filename;

    /** @param array<string,string> $filters */
    public function __construct(PDO $pdo, array $filters, AdminRequest $request)
    {
        (new Authorization())->assertCanOperate();
        if (!$request->isPost() || $request->action() !== 'export-report') {
            throw new \RuntimeException('Exportação administrativa inválida.');
        }
        (new Csrf())->assertValid($request->postString('token'));
        $filter = new ReportFilter($filters);
        $rows = (new MerchantReadModel($pdo))->rowsFor($filter);
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Não foi possível preparar o arquivo.');
        }
        $this->stream = $stream;
        $this->filename = 'pagou-' . $filter->kind . '-' . $filter->today->format('Y-m-d') . '.csv';
        fwrite($this->stream, "\xEF\xBB\xBF");
        $this->line(['Data (São Paulo)', 'Fatura', 'ID cliente', 'Cliente', 'Meio', 'Situação', 'Valor (BRL)', 'Base do valor', 'Referência', 'Dias', 'Tipo de pendência', 'Orientação', 'Consulta (São Paulo)', 'Período inicial', 'Período final', 'Emitida para', 'CPF/CNPJ da emissão', 'Pago por (Pix)', 'CPF/CNPJ do pagador Pix', 'ID Pagou', 'E2E Pix']);
        $consulted = F::date(gmdate('Y-m-d H:i:s'));
        $periodic = in_array($filter->kind, ['receipts', 'attempts', 'refunds'], true);
        foreach ($rows as $row) {
            $this->line([
                F::date($row['date']), $row['invoice'] > 0 ? (string) $row['invoice'] : '',
                $row['client'] > 0 ? (string) $row['client'] : '', $row['customer'], F::label($row['method']),
                F::label($row['status']), $row['amount'] === null ? '' : F::decimal($row['amount']),
                $row['basis'], $row['reference'], isset($row['days']) ? (string) $row['days'] : '',
                isset($row['type']) ? F::label($row['type']) : '', $row['guidance'] ?? '', $consulted,
                $periodic ? $filter->start->format('d/m/Y') : 'Saldo/estado atual',
                $periodic ? $filter->end->format('d/m/Y') : 'Saldo/estado atual',
                $row['issuedName'] ?? '', $row['issuedDocument'] ?? '', $row['payerName'] ?? '',
                $row['payerDocument'] ?? '', $row['pagouId'] ?? '', $row['e2e'] ?? '',
            ]);
        }
        rewind($this->stream);
    }

    public function __destruct()
    {
        fclose($this->stream);
    }

    public function contents(): string
    {
        rewind($this->stream);
        $contents = stream_get_contents($this->stream);
        if ($contents === false) {
            throw new \RuntimeException('Não foi possível ler o relatório.');
        }
        return $contents;
    }

    public function send(): void
    {
        // WHMCS captures addon output before rendering its template. Do not append a
        // download to an already-sent page, or emit a partial CSV after a query error.
        if (headers_sent()) {
            throw new \RuntimeException('Os cabeçalhos da resposta já foram enviados.');
        }
        while (ob_get_level() > 0) {
            if (!ob_end_clean()) {
                throw new \RuntimeException('O buffer da resposta não pode ser encerrado.');
            }
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $this->filename . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        rewind($this->stream);
        fpassthru($this->stream);
    }

    /** @param list<string> $values */
    private function line(array $values): void
    {
        $safe = array_map(static function (string $value): string {
            // Protect spreadsheet formulas, including formulas hidden after whitespace.
            return preg_match('/^[\s\x00-\x1f]*[=+@-]|^[\t\r\n]/u', $value) === 1 ? "'" . $value : $value;
        }, $values);
        if (fputcsv($this->stream, $safe, ';', '"', '', "\r\n") === false) {
            throw new \RuntimeException('Não foi possível escrever o relatório.');
        }
    }
}
