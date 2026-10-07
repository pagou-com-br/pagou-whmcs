<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reporting;

use PDO;

final class AdminReportProvider
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    public function admin(string $page, array $filters): array
    {
        try {
            $model = new MerchantReadModel($this->pdo);
            return match ($page) {
                'reports' => ['report' => $model->report($filters)],
                'findings' => ['report' => $model->report(array_replace($filters, ['report' => 'pending']))],
                'charge' => ['detail' => $model->detail((new ReportFilter(['invoice' => $filters['invoice'] ?? '']))->invoice)],
                default => ['merchant' => $model->overview()],
            };
        } catch (\Throwable $exception) {
            return ['reportError' => self::error($exception), 'reportFilters' => $filters];
        }
    }

    public static function error(\Throwable $exception): string
    {
        if ($exception instanceof \InvalidArgumentException || $exception instanceof \LengthException) {
            return $exception->getMessage();
        }
        if (function_exists('logActivity')) {
            logActivity('Pagou Payments: relatório indisponível. ' . $exception::class);
        }
        return 'Não foi possível consultar os dados. Tente novamente ou consulte o diagnóstico. Nenhum valor foi presumido.';
    }
}
