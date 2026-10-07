<?php

declare(strict_types=1);

namespace Pagou\Payments\Tests\UI\Admin;

use Pagou\Payments\Admin\View\AccountPanel;
use Pagou\Payments\Admin\View\Page;
use Pagou\Payments\Admin\View\Pages;
use Pagou\Whmcs\Presentation\PaymentLabels;
use PHPUnit\Framework\TestCase;

final class PagesTest extends TestCase
{
    public function testChargeUnitsAndLegacyReviewAreExplicit(): void
    {
        $pages = new Pages();
        $pix = $pages->render('settings', ['settingsSection' => 'pix', 'settings' => [
            'pix_due_interest_type' => 'percentage', 'pix_due_interest_amount' => '1.00',
        ]], '');
        self::assertStringContainsString('Percentual antigo: selecione a periodicidade', $pix);
        self::assertStringContainsString('percentage_month_calendar_days', $pix);
        self::assertStringContainsString('pix_late_charges_reviewed', $pix);
        $boleto = $pages->render('settings', ['settingsSection' => 'boleto', 'settings' => ['boleto_fine' => '2.00']], '');
        self::assertStringContainsString('Multa (%)', $boleto);
        self::assertStringContainsString('Juros ao mês (%)', $boleto);
        self::assertStringContainsString('boleto_late_charges_reviewed', $boleto);
        self::assertStringNotContainsString('Multa fixa', $boleto);
    }

    public function testCardAdvancedOptionsStayUnavailableAndLegacySettingsNeedReview(): void
    {
        $pages = new Pages();
        $html = $pages->render('settings', ['settingsSection' => 'card', 'settings' => [
            'card_auto_capture' => '0', 'card_max_installments' => '6',
        ]], '');
        self::assertStringContainsString('Revise a configuração anterior', $html);
        self::assertStringContainsString('Pré-autorização e captura posterior não estão disponíveis', $html);
        self::assertStringNotContainsString('Desmarcado cria uma pré-autorização', $html);
        $card = $pages->render('card', ['operations' => [[
            'chargeId' => 'charge-12345678', 'state' => 'authorized',
        ]]], '');
        self::assertStringNotContainsString('value="card-capture"', $card);
        self::assertStringContainsString('card-reverse', $card);
    }

    public function testHistoryNavigationOnlyOffersAnExistingNextPage(): void
    {
        $pages = new Pages();
        foreach (['payments', 'webhooks', 'operations'] as $page) {
            self::assertStringNotContainsString('Próxima', $pages->render($page, ['hasNext' => false], ''));
            $html = $pages->render($page, ['hasNext' => true, 'filters' => ['page' => '2', 'invoice' => '123']], '');
            self::assertStringContainsString('Próxima', $html);
            self::assertStringContainsString('Anterior', $html);
            self::assertStringContainsString('page=3&amp;invoice=123', $html);
        }
        $html = $pages->render('payments', [], '');
        self::assertStringContainsString('<select name="method">', $html);
        self::assertStringContainsString('Aguardando pagamento', $html);
        // An empty list shows its message without an empty table head.
        self::assertStringContainsString('<section class="pagou-card pagou-table-card is-empty">', $html);
        self::assertStringContainsString('Nenhuma cobrança corresponde aos filtros atuais.', $html);
        self::assertStringNotContainsString('<thead>', $html);
        $html = $pages->render('payments', ['payments' => [['#12', 'Cliente', 'Pix', 'R$ 1,00', '06/10/2026 11:41', 'Pago', '5cd50977-c577-45f3-9e2e-9ce2abf8caf8']]], '');
        self::assertStringContainsString('Valor da cobrança', $html);
        self::assertStringContainsString('Atualizada em', $html);
    }

    public function testGroupedPaymentsShowCountsMethodAndCopyablePagouId(): void
    {
        $group = ['invoice' => 406701, 'customer' => 'Studio', 'method' => 'Pix', 'methodKey' => 'pix', 'amount' => 'R$ 89,90', 'createdAt' => '', 'updatedAt' => '07/10/2026 00:51',
            'status' => 'Aguardando pagamento', 'statusKey' => 'ready', 'attemptId' => 'a1', 'remoteId' => 'baf1691f-524f-4df0-87ca-c71cb1b9e93a', 'history' => [['attemptId' => 'a0'], ['attemptId' => 'a-1']]];
        $html = (new Pages())->render('payments', ['grouped' => true, 'payments' => [$group, ['remoteId' => '', 'statusKey' => 'queued', 'history' => []] + $group],
            'statusCounts' => ['' => 36, 'ready' => 5, 'paid' => 30], 'filters' => ['method' => 'pix']], '');

        self::assertStringContainsString('>Todas<span class="pagou-chip-count">36</span></a>', $html);
        self::assertStringContainsString('>Aguardando pagamento<span class="pagou-chip-count">5</span></a>', $html);
        self::assertMatchesRegularExpression('#class="pagou-chip is-zero" href="[^"]*status=failed"[^>]*>Com falha<span class="pagou-chip-count">0</span>#', $html);
        self::assertStringContainsString('<th scope="col">ID Pagou</th>', $html);
        self::assertStringContainsString('data-pagou-copy-value="baf1691f-524f-4df0-87ca-c71cb1b9e93a" aria-label="Copiar ID Pagou"', $html);
        self::assertStringContainsString('<a class="pagou-count-tag" href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=406701" title="Ver as tentativas no histórico da fatura">+2 anteriores</a>', $html);
        self::assertStringContainsString('<span class="pagou-muted-cell">Ainda não emitida</span>', $html);
        self::assertMatchesRegularExpression('#<span class="pagou-method-cell"><svg[^>]*>.*?</svg>Pix</span>#s', $html);
        self::assertStringNotContainsString('pagou-history-table', $html);
    }

    public function testRoutineChecksCollapseUnderTheNewestAndAttentionStandsOut(): void
    {
        $row = static fn (string $at, string $type, string $invoice, string $status): array => [$at, $type, 'Pix', $invoice, $status, '1', '5cd50977-c577-45f3-9e2e-9ce2abf8caf8', ''];
        $html = (new Pages())->render('operations', ['queue' => ['pending' => 2, 'oldest' => '07/10/2026 00:18'], 'operations' => [
            $row('10:10', 'Conciliar pagamento', '#1', 'Concluída'),
            $row('10:09', 'Conciliar pagamento', '#1', 'Concluída'),
            $row('10:08', 'Conciliar pagamento', '#1', 'Concluída'),
            $row('10:07', 'Conciliar pagamento', '#1', 'Falhou'),
            $row('10:06', 'Gerar Pix', '#2', 'Concluída'),
        ]], '');

        self::assertMatchesRegularExpression('#Conciliar pagamento <button type="button" class="pagou-group-toggle" aria-expanded="false" data-pagou-group="(pagou-group-\d+-0)">\+2 consultas iguais</button>#', $html);
        preg_match('#data-pagou-group="(pagou-group-\d+-0)"#', $html, $match);
        self::assertSame(2, substr_count($html, 'data-pagou-group-member="' . $match[1] . '" hidden'));
        // A different result starts a new row and, needing attention, is marked.
        self::assertSame(1, substr_count($html, 'class="is-attention"'));
        self::assertStringContainsString('data-pagou-group-all aria-pressed="false">Mostrar todas as consultas</button>', $html);
        self::assertStringContainsString('<span class="pagou-queue-state is-busy">Fila: 2 aguardando, desde 07/10/2026 00:18</span>', $html);
    }

    public function testNotificationsSummariseTheDayAndFilterByInvoiceAndPeriod(): void
    {
        $html = (new Pages())->render('webhooks', ['summary' => ['today' => 3, 'valid' => 2, 'last' => '07/10/2026 00:21'], 'filters' => ['invoice' => '409547', 'period' => '7d'],
            'webhooks' => [['07/10/2026 00:21', 'Pix devolvido', 'aa6489a5-7d3e-4d11-abfe-da123fe4ff23', 'válida', 'Concluída', '#409547']]], '');

        self::assertStringContainsString('<span>Recebidas hoje</span><strong>3</strong>', $html);
        self::assertStringContainsString('<article class="pagou-metric is-warning"><span>Assinaturas válidas</span><strong>2 de 3</strong>', $html);
        self::assertStringContainsString('name="invoice" value="409547"', $html);
        self::assertStringContainsString('<option value="7d" selected>Últimos 7 dias</option>', $html);
        self::assertMatchesRegularExpression('#<span class="pagou-method-cell"><svg[^>]*><path d="M3 12a9 9 0 0 1 9-9#', $html);
    }

    public function testReconciliationLeadsWithTheNewestCheckOfEachInvoice(): void
    {
        $row = static fn (string $at, string $invoice): array => [$at, 'Conciliar pagamento', 'Pix', $invoice, 'Concluída', '1', '5cd50977-c577-45f3-9e2e-9ce2abf8caf8', ''];
        $html = (new Pages())->render('reconciliation', ['coverage' => ['checked' => 1, 'eligible' => 2], 'divergences' => 0, 'pending' => '0', 'lastReconciliationDisplay' => '07/10/2026 00:18',
            'runs' => [$row('10:10', '#1'), $row('10:09', '#2'), $row('10:08', '#1')]], '');

        self::assertStringContainsString('<span>Cobertura em 24 horas</span><strong>1 de 2</strong>', $html);
        self::assertStringContainsString('+1 anterior</button>', $html);
        // The older check of #1 moves under its newest one, ahead of #2.
        self::assertLessThan(strpos($html, '>10:09<'), strpos($html, '>10:08<'));
    }

    public function testTablesOpenDetailsInAFullRowAndCopyShortenedIdentifiers(): void
    {
        $attempt = '5cd50977-c577-45f3-9e2e-9ce2abf8caf8';
        $html = (new Pages())->render('operations', [
            'operations' => [['07/10/2026 10:10', 'Conciliar pagamento', 'Pix', '#406502', 'Concluída', '1', $attempt, 'Operação: <op-4>']],
        ], '');

        self::assertMatchesRegularExpression('#<tr data-pagou-row-href="addonmodules.php\?module=pagou_payments&amp;view=charge&amp;invoice=406502">#', $html);
        self::assertMatchesRegularExpression('#<button type="button" class="pagou-row-toggle" aria-expanded="false" aria-controls="(pagou-row-details-\d+)">Detalhes#', $html);
        preg_match('#aria-controls="(pagou-row-details-\d+)"#', $html, $match);
        self::assertStringContainsString('<tr class="pagou-row-details" id="' . $match[1] . '" hidden><td colspan="8"><p class="pagou-operation-details">Operação: &lt;op-4&gt;</p></td></tr>', $html);
        self::assertStringContainsString('<th scope="col" class="pagou-col-toggle"><span class="pagou-sr-only">Orientação</span></th>', $html);
        self::assertStringContainsString('data-pagou-copy-value="' . $attempt . '" aria-label="Copiar Tentativa"', $html);
        self::assertStringNotContainsString('<details><summary>Detalhes', $html);
        // Daily actions stay available but no longer compete with the content.
        self::assertStringContainsString('<button class="btn btn-default pagou-button" type="submit">Retomar fila</button>', $html);
    }

    public function testNotificationSelectionPreservesFiltersAndEscapesUnknownTypes(): void
    {
        $html = (new Pages())->render('webhooks', [
            'eventTypes' => ['' => 'Todos os eventos', 'charge.paid' => 'Boleto pago'],
            'filters' => ['type' => 'charge.paid', 'page' => '2'],
            'hasNext' => true,
        ], '');
        self::assertStringContainsString('<select name="type">', $html);
        self::assertStringContainsString('<option value="charge.paid" selected>Boleto pago</option>', $html);
        self::assertStringContainsString('page=3&amp;type=charge.paid', $html);
        $html = (new Pages())->render('webhooks', ['filters' => ['type' => '"><script>bad</script>']], '');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEscapesDynamicValuesInDashboard(): void
    {
        $html = (new Pages())->render('dashboard', [
            'paymentsToday' => '<script>alert(1)</script>',
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('class="btn btn-primary pagou-button"', $html);
        self::assertStringContainsString('class="btn btn-default pagou-button"', $html);
    }

    public function testSettingsNeverRendersTheStoredCredential(): void
    {
        $html = (new Pages())->render('settings', [
            'credential' => 'secret-value',
            'settings' => [
                'cpf_field_id' => '2',
                'cnpj_field_id' => '',
                'worker_max_jobs' => '25',
                'worker_max_seconds' => '20',
                'retention_operational_days' => '90',
            ],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('type="password"', $html);
        self::assertStringNotContainsString('secret-value', $html);
        self::assertStringContainsString('name="action" value="save-settings"', $html);
        self::assertStringContainsString('name="cpf_field_id"', $html);
        self::assertStringContainsString('name="worker_max_jobs"', $html);
        self::assertStringNotContainsString('name="pix_due_enabled"', $html);
        self::assertStringNotContainsString('name="boleto_grace_period"', $html);
    }

    public function testUnknownPageUsesDashboard(): void
    {
        $html = (new Pages())->render('unexpected', [], '');

        self::assertStringContainsString('Ações rápidas', $html);
    }

    public function testDashboardGuidesTheFirstUseAndShowsReadiness(): void
    {
        $html = (new Pages())->render('dashboard', [
            'onboarding' => [
                'state' => 'attention',
                'ready' => 2,
                'total' => 6,
                'next' => ['url' => 'addonmodules.php?module=pagou_payments&view=settings', 'action' => 'Configurar credencial'],
                'steps' => [[
                    'state' => 'attention',
                    'title' => 'Conectar a conta Pagou',
                    'detail' => 'Informe a credencial.',
                    'url' => 'addonmodules.php?module=pagou_payments&view=settings',
                    'action' => 'Configurar credencial',
                ]],
            ],
            'readinessCards' => [[
                'label' => 'Conexão Pagou',
                'value' => 'Pendente',
                'detail' => 'Aguardando configuração.',
                'state' => 'attention',
                'url' => 'addonmodules.php?module=pagou_payments&view=settings',
            ]],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('Conclua a configuração do módulo', $html);
        self::assertStringContainsString('Roteiro de configuração', $html);
        self::assertStringContainsString('2<small>/6</small>', $html);
        self::assertStringContainsString('aria-valuenow="2"', $html);
        self::assertStringContainsString('Conectar a conta Pagou', $html);
        self::assertStringContainsString('OPERAÇÃO DIÁRIA', $html);
        self::assertStringContainsString('class="pagou-card pagou-intro pagou-setup-banner"', $html);
        self::assertStringContainsString('<nav class="pagou-action-bar" aria-label="Ações rápidas">', $html);
        self::assertStringContainsString('<nav class="pagou-health" aria-label="Saúde da operação">', $html);
        self::assertStringContainsString('Ver histórico operacional', $html);
        self::assertStringContainsString('Precisa da sua atenção', $html);
        // Without the merchant projection, module figures are unavailable rather than zero.
        self::assertStringContainsString('<strong>Indisponível</strong>', $html);
        self::assertStringNotContainsString('pagou-readiness-grid', $html);
        self::assertStringNotContainsString('pagou-account', $html);
        // While setup is incomplete it leads the page; daily operation follows.
        self::assertTrue(strpos($html, 'pagou-setup-banner') < strpos($html, 'pagou-action-bar'));
        self::assertTrue(strpos($html, 'pagou-action-bar') < strpos($html, 'pagou-health'));
        self::assertTrue(strpos($html, 'pagou-health') < strpos($html, 'pagou-kpis'));
    }

    public function testOperationsUsesFriendlyHistoryFiltersAndNoRawReferenceColumn(): void
    {
        $html = (new Pages())->render('operations', [
            'filters' => ['invoice' => '123', 'method' => 'boleto', 'status' => 'superseded', 'period' => '30d'],
            'operations' => [['23/08/2026 15:00', 'Gerar boleto', 'Boleto', '#123', 'Substituída']],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('Histórico operacional', $html);
        self::assertStringContainsString('name="invoice" value="123"', $html);
        self::assertStringContainsString('name="method"', $html);
        self::assertStringContainsString('value="boleto" selected', $html);
        self::assertStringContainsString('value="superseded" selected', $html);
        self::assertStringContainsString('value="30d" selected', $html);
        self::assertStringContainsString('<th scope="col">Fatura</th>', $html);
        self::assertStringNotContainsString('<th scope="col">Referência</th>', $html);
    }

    public function testCardPageShowsMerchantAvailabilityAndKeepsTechnicalDetailsInSupport(): void
    {
        $html = (new Pages())->render('card', [
            'cardReady' => true,
            'availability' => 'hidden',
            'events' => [['23/08/2026 15:00', '#123', 'Cobrar cartão', 'Concluída']],
        ], '');

        self::assertStringContainsString('Oculto para clientes', $html);
        self::assertStringContainsString('href="configgateways.php"', $html);
        self::assertStringContainsString('diagnostics#pagou-support-details', $html);
        self::assertStringContainsString('https://app.pagou.com.br/configuracoes/cartao', $html);
        foreach (['Preparação segura', 'tokenização', 'backend', '3DS', 'homologação', 'aprovação pendente'] as $technical) {
            self::assertStringNotContainsString($technical, $html);
        }
        self::assertStringContainsString('#123', $html);
        self::assertStringNotContainsString('attempt-', $html);
        $review = (new Pages())->render('card', ['availability' => 'visible', 'cardReady' => false], '');
        self::assertStringContainsString('Disponível no WHMCS', $review);
        self::assertStringContainsString('configurações da instalação que precisam de revisão', $review);
        $unknown = (new Pages())->render('card', [], '');
        self::assertStringContainsString('Situação indisponível', $unknown);
        self::assertStringNotContainsString('Desativado no WHMCS', $unknown);
    }

    public function testCardPageKeepsSettledRefundsInTheNativeWhmcsTransactionFlow(): void
    {
        $html = (new Pages())->render('card', [
            'cardReady' => true,
            'readinessReason' => 'Pronto.',
            'operations' => [[
                'chargeId' => 'charge-12345678',
                'invoiceId' => 123,
                'state' => 'paid',
                'status' => 'Pago',
                'card' => 'Visa final 4242',
                'amount' => 'R$ 10,00',
                'updatedAt' => '23/08/2026 15:00',
            ]],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('Reembolsos de pagamentos confirmados usam a operação nativa', $html);
        self::assertStringNotContainsString('Reverter cobrança', $html);
        self::assertStringContainsString('Consultar', $html);
    }

    public function testCompletedDashboardKeepsTheFirstUseGuideCompactAndReviewable(): void
    {
        $html = (new Pages())->render('dashboard', [
            'onboarding' => [
                'state' => 'ready',
                'ready' => 6,
                'total' => 6,
                'steps' => [[
                    'state' => 'ready',
                    'title' => 'Conectar a conta Pagou',
                    'detail' => 'Conexão validada.',
                    'url' => '#',
                    'action' => 'Rever',
                ]],
            ],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('Configuração inicial concluída', $html);
        self::assertStringContainsString('Roteiro de configuração (6/6)', $html);
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);
        self::assertSame(1, $xpath->query('//details[@data-pagou-preference="onboarding-complete" and not(@open)]')->length);
        self::assertSame(1, $xpath->query('//details[@data-pagou-preference="onboarding-complete"]//ol[@class="pagou-onboarding-steps" and not(ancestor::details/ancestor::details)]')->length);
        self::assertStringNotContainsString('pagou-setup-banner', $html);
        // A completed setup no longer competes with the daily figures.
        self::assertTrue(strpos($html, 'pagou-kpis') < strpos($html, 'pagou-setup-review'));
    }

    public function testSettingsUsesWhmcsCustomFieldSelectorsAndGatewayLinks(): void
    {
        $html = (new Pages())->render('settings', [
            'credentialConfigured' => true,
            'credentialState' => 'ready',
            'credentialValidatedAt' => '22/08/2026 15:00',
            'credentialFingerprint' => 'sha256:abc123',
            'settings' => [
                'cpf_field_id' => '2',
                'cnpj_field_id' => '',
                'worker_max_jobs' => '25',
                'worker_max_seconds' => '20',
                'retention_operational_days' => '90',
            ],
            'customFields' => [['id' => '2', 'label' => 'Documento CPF/CNPJ']],
            'gatewayStatus' => [[
                'label' => 'Pix', 'active' => true, 'visible' => false,
                'detail' => 'Ativo e oculto para teste controlado.', 'url' => 'configgateways.php',
            ]],
            'worker' => ['state' => 'ready', 'detail' => 'Worker ativo.', 'lastRun' => '22/08/2026 15:00', 'pending' => 0, 'oldest' => 'Nunca'],
            'callback' => ['state' => 'ready', 'url' => 'https://example.test/modules/gateways/callback/pagou.php', 'detail' => 'HTTPS pronto.'],
            'timezone' => ['web' => 'UTC', 'persistence' => 'UTC', 'display' => 'America/Sao_Paulo', 'detail' => 'Conversão explícita.'],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('<select id="pagou-cpf_field_id" name="cpf_field_id">', $html);
        self::assertStringContainsString('Documento CPF/CNPJ (ID 2)', $html);
        self::assertStringContainsString('Ativo e oculto', $html);
        self::assertStringContainsString('Não é necessário cadastrá-lo manualmente.', $html);
        self::assertStringContainsString('class="pagou-technical-value"', $html);
        self::assertStringContainsString('<code>https://example.test/modules/gateways/callback/pagou.php</code>', $html);
        self::assertStringNotContainsString('id="pagou-callback-url"', $html);
        self::assertStringNotContainsString('data-pagou-copy="pagou-callback-url"', $html);
        self::assertStringNotContainsString('telemetria', strtolower($html));
        self::assertStringContainsString('name="replace_credential"', $html);
        self::assertStringContainsString('<header class="pagou-page-head"><div class="pagou-page-head-copy"><h2>Configurações gerais</h2>', $html);
        // The cron command is shown whole, as text, with its copy button.
        self::assertMatchesRegularExpression('#<code id="pagou-worker-command" class="pagou-command">php [^<]+cron\.php</code><button class="btn btn-default pagou-button" type="button" data-pagou-copy="pagou-worker-command">#', $html);
        self::assertStringNotContainsString('<input id="pagou-worker-command"', $html);
        self::assertStringContainsString('data-pagou-reset-open="pagou-reset-general"', $html);
        self::assertStringContainsString('name="action" value="reset-settings"', $html);
        self::assertStringContainsString('Essa ação não remove a credencial Pagou', $html);
        self::assertStringNotContainsString('secret-value', $html);
    }

    public function testReadySettingsOffersAssistedActivationForPixAndBoletoOnly(): void
    {
        $html = (new Pages())->render('settings', [
            'credentialConfigured' => true,
            'credentialState' => 'ready',
            'settings' => [
                'cpf_field_id' => '2',
                'cnpj_field_id' => '',
                'worker_max_jobs' => '25',
                'worker_max_seconds' => '20',
                'retention_operational_days' => '90',
            ],
            'gatewayStatus' => [
                ['id' => 'pagou_pix', 'label' => 'Pix', 'active' => false, 'visible' => false],
                ['id' => 'pagou_boleto', 'label' => 'Boleto', 'active' => false, 'visible' => false],
                ['id' => 'pagou_creditcard', 'label' => 'Cartão de crédito', 'active' => false, 'visible' => false],
            ],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertSame(2, substr_count($html, 'name="action" value="activate-gateway"'));
        self::assertStringContainsString('name="gateway" value="pagou_pix"', $html);
        self::assertStringContainsString('name="gateway" value="pagou_boleto"', $html);
        self::assertStringNotContainsString('name="gateway" value="pagou_creditcard"', $html);
        self::assertStringNotContainsString('name="action" value="activate-recommended-gateways"', $html);
        self::assertStringNotContainsString('Ativar Pix e boleto juntos', $html);
        self::assertStringContainsString('Ative cada meio separadamente.', $html);
        self::assertStringContainsString('pagou-gateway-icon--pix', $html);
        self::assertStringContainsString('pagou-gateway-icon--boleto', $html);
        self::assertStringContainsString('pagou-gateway-icon--card', $html);
        self::assertStringContainsString('Aguardando disponibilidade', $html);
        self::assertStringContainsString('class="pagou-connection-grid"', $html);
        self::assertStringContainsString('pagou-settings-pair--identity', $html);
        self::assertStringContainsString('pagou-settings-pair--operation', $html);
    }

    public function testBulkActivationIsNotRepeatedWhenOnlyOneRecommendedGatewayRemains(): void
    {
        $html = (new Pages())->render('settings', [
            'credentialConfigured' => true,
            'credentialState' => 'ready',
            'settings' => ['cpf_field_id' => '2'],
            'gatewayStatus' => [
                ['id' => 'pagou_pix', 'label' => 'Pix', 'active' => true, 'visible' => false],
                ['id' => 'pagou_boleto', 'label' => 'Boleto', 'active' => false, 'visible' => false],
                ['id' => 'pagou_creditcard', 'label' => 'Cartão de crédito', 'active' => false, 'visible' => false],
            ],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertSame(1, substr_count($html, 'name="action" value="activate-gateway"'));
        self::assertStringNotContainsString('name="action" value="activate-recommended-gateways"', $html);
        self::assertStringNotContainsString('Ativar Pix e boleto juntos', $html);
    }

    public function testGatewayActivationRemainsDisabledUntilCredentialAndDocumentAreReady(): void
    {
        $html = (new Pages())->render('settings', [
            'credentialConfigured' => true,
            'credentialState' => 'attention',
            'settings' => ['cpf_field_id' => ''],
            'gatewayStatus' => [[
                'id' => 'pagou_pix', 'label' => 'Pix', 'active' => false, 'visible' => false,
            ]],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('Concluir preparação', $html);
        self::assertStringNotContainsString('name="action" value="activate-gateway"', $html);
        self::assertStringNotContainsString('name="action" value="activate-recommended-gateways"', $html);
    }

    public function testDiagnosticsAndAboutRemainPublicSafe(): void
    {
        $diagnostics = (new Pages())->render('diagnostics', [
            'overall' => 'attention',
            'generatedAt' => '22/08/2026 15:00',
            'readyCount' => 1,
            'attentionCount' => 1,
            'unavailableCount' => 0,
            'onboarding' => ['ready' => 3, 'total' => 6],
            'supportReport' => 'Sem credenciais e sem payloads.',
            'checks' => [[
                'group' => 'connection', 'state' => 'attention', 'title' => 'Conectividade',
                'detail' => '<token-secreto>', 'action' => 'Configurar',
                'url' => 'addonmodules.php?module=pagou_payments&view=settings',
            ]],
        ], '<input type="hidden" name="token" value="safe">');
        $about = (new Pages())->render('about', [
            'version' => '0.1.0-dev', 'channel' => 'Desenvolvimento', 'publisher' => 'Pagou',
            'license' => 'MIT', 'currentPhp' => '8.2.0', 'currentWhmcs' => '8.13.1',
            'components' => ['Addon central', 'Pix', 'Boleto', 'Cartão de crédito'],
        ], '');

        self::assertStringContainsString('Relatório seguro para suporte', $diagnostics);
        self::assertStringContainsString('class="pagou-diagnostic-grid"', $diagnostics);
        self::assertStringContainsString('class="pagou-diagnostic-support-grid"', $diagnostics);
        self::assertStringContainsString('&lt;token-secreto&gt;', $diagnostics);
        self::assertStringNotContainsString('<token-secreto>', $diagnostics);
        self::assertStringContainsString('Pagou para WHMCS', $about);
        self::assertStringContainsString('Licença', $about);
        self::assertStringContainsString('Privacidade por padrão', $about);
        self::assertStringContainsString('class="pagou-about-stack"', $about);
        self::assertStringContainsString('Componentes instalados', $about);
        self::assertStringNotContainsString('bit' . 'bucket.org', $about);
    }

    public function testDiagnosticsCollapsesSupportDetailsWithoutHidingEnvironmentFailures(): void
    {
        $html = (new Pages())->render('diagnostics', [
            'checks' => [
                ['group' => 'runtime', 'id' => 'extensions', 'state' => 'attention', 'title' => 'Extensões obrigatórias', 'detail' => 'cURL ausente'],
                ['group' => 'notifications', 'id' => 'webhook', 'state' => 'attention', 'title' => 'Recebimento e assinatura',
                    'detail' => '1 assinatura inválida', 'merchantTitle' => 'Recebimento de notificações', 'merchantDetail' => 'Contate o suporte para verificar as notificações.'],
            ],
        ], '');
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);
        self::assertSame(1, $xpath->query('//details[@id="pagou-support-details" and not(@open)]')->length);
        self::assertSame(1, $xpath->query('//details[@id="pagou-support-details"]//p[text()="cURL ausente"]')->length);
        self::assertSame(1, $xpath->query('//details[@id="pagou-support-details"]//p[text()="1 assinatura inválida"]')->length);
        self::assertSame(1, $xpath->query('//div[@role="status" and not(ancestor::details)]/strong[contains(text(), "ambiente")]')->length);
        self::assertSame(1, $xpath->query('//strong[text()="Recebimento de notificações" and not(ancestor::details)]')->length);
    }

    public function testPaymentMethodSettingsAreSeparatedFromNativeGatewayScreens(): void
    {
        $html = (new Pages())->render('settings', [
            'settingsSection' => 'pix',
            'settings' => [
                'pix_due_enabled' => '1',
                'pix_expiration_seconds' => '3600',
                'pix_due_expiration_days' => '30',
                'pix_due_fine_type' => 'percentage',
                'pix_due_fine_amount' => '2.00',
            ],
        ], '<input type="hidden" name="token" value="safe">');

        self::assertStringContainsString('Configurações do Pix', $html);
        self::assertStringContainsString('name="settings_section" value="pix"', $html);
        self::assertStringContainsString('name="pix_due_fine_type"', $html);
        self::assertStringContainsString('name="pix_show_qr"', $html);
        self::assertStringContainsString('name="pix_due_respect_late_fees"', $html);
        self::assertStringContainsString('data-pagou-reset-open="pagou-reset-pix"', $html);
        self::assertStringContainsString('name="action" value="reset-settings"', $html);
        self::assertStringContainsString('<header class="pagou-page-head"><div class="pagou-page-head-copy"><h2>Configurações do Pix</h2>', $html);
        // 3600 seconds read as 1 hour; the stored seconds stay in the form for older pages.
        self::assertStringContainsString('name="pix_expiration_amount" type="number" min="1" step="1" value="1"', $html);
        self::assertStringContainsString('<option value="hours" selected>horas</option>', $html);
        self::assertStringContainsString('<input type="hidden" name="pix_expiration_seconds" value="3600">', $html);
        self::assertStringNotContainsString('<option value="seconds"', $html);
        self::assertStringNotContainsString('Tempo em segundos', $html);
        self::assertStringNotContainsString('name="boleto_grace_period"', $html);
        self::assertSame(4, substr_count($html, 'view=settings&amp;section='));
    }

    public function testOperationalFiltersPreserveSafePaginationAndEscapeInput(): void
    {
        $html = (new Pages())->render('payments', [
            'payments' => [],
            'hasNext' => true,
            'filters' => [
                'invoice' => '42',
                'method' => 'pix',
                'status' => '"><script>alert(1)</script>',
                'page' => '2',
            ],
        ], '');

        self::assertStringContainsString('Página 2', $html);
        self::assertStringContainsString('name="invoice" value="42"', $html);
        self::assertStringContainsString('<option value="pix" selected>Pix</option>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('page=1', $html);
        self::assertStringContainsString('page=3', $html);
    }

    public function testPaymentsShowCustomerStatusShortcutsAndCompactReferences(): void
    {
        $attempt = '5cd50977-c577-45f3-9e2e-9ce2abf8caf8';
        $html = (new Pages())->render('payments', [
            'filters' => ['method' => 'boleto', 'status' => 'ready'],
            'payments' => [['#406689', '<b>Loja Exemplo</b>', 'Boleto', 'R$ 12,00', '06/10/2026 11:41', 'Aguardando pagamento', $attempt]],
        ], '');

        self::assertStringContainsString('<th scope="col">Cliente</th>', $html);
        self::assertStringContainsString('&lt;b&gt;Loja Exemplo&lt;/b&gt;', $html);
        self::assertStringContainsString('<span class="pagou-badge pagou-badge--info">Aguardando pagamento</span>', $html);
        self::assertStringContainsString('title="' . $attempt . '">5cd50977…</code>', $html);
        self::assertStringContainsString('view=charge&amp;invoice=406689#attempt-' . $attempt, $html);
        // Shortcuts keep the other filters and mark the current status.
        self::assertStringContainsString('class="pagou-chip is-active" href="addonmodules.php?module=pagou_payments&amp;view=payments&amp;method=boleto&amp;status=ready"', $html);
        self::assertStringContainsString('view=payments&amp;method=boleto&amp;status=paid', $html);
        self::assertStringContainsString('Página 1', $html);
    }

    public function testFindingsKeepFiltersAndExportOnTheirOwnTab(): void
    {
        $finding = ['invoice' => 406701, 'customer' => '<b>Studio</b>', 'client' => 337, 'method' => 'pix', 'amount' => 8990, 'status' => 'open',
            'type' => 'amount_mismatch', 'severity' => 'high', 'guidance' => 'Compare o valor recebido.', 'date' => '2026-10-07T03:53:00+00:00', 'days' => 2];
        $report = static fn (array $filter, int $total): array => ['report' => [
            'filter' => $filter + ['report' => 'pending', 'from' => '2026-09-01', 'to' => '2026-09-17', 'q' => '', 'method' => '', 'status' => '', 'client' => '', 'invoice' => '', 'page' => '1'],
            'summary' => ['count' => $total, 'amount' => 0, 'unknown' => 0, 'invoiceCount' => 0, 'invoiceAmount' => 0, 'methods' => [], 'buckets' => [], 'average' => null, 'types' => []],
            'previous' => null, 'rows' => $total > 0 ? [$finding] : [], 'total' => $total, 'pages' => 1, 'firstAt' => null, 'asOf' => '2026-09-17T12:00:00+00:00',
        ]];
        $html = (new Pages())->render('findings', $report(['method' => 'pix'], 1), '');

        self::assertStringNotContainsString('pagou-report-tabs', $html);
        self::assertStringNotContainsString('<h2>Relatórios</h2>', $html);
        self::assertStringContainsString('<input type="hidden" name="view" value="findings">', $html);
        self::assertStringContainsString('action="addonmodules.php?module=pagou_payments&amp;view=findings', $html);
        self::assertStringContainsString('Reverificar pendências', $html);
        // Each open finding is a card with what to check and where to act.
        self::assertStringContainsString('<li class="pagou-finding is-danger">', $html);
        self::assertStringContainsString('Compare o valor recebido.', $html);
        self::assertStringContainsString('Fatura #406701, &lt;b&gt;Studio&lt;/b&gt;', $html);
        self::assertStringContainsString('href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=406701">Ver histórico</a>', $html);
        self::assertStringContainsString('href="invoices.php?action=edit&amp;id=406701">', $html);
        self::assertStringContainsString(', há 2 dia(s)', $html);
        $none = (new Pages())->render('findings', $report(['method' => 'pix'], 0), '');
        self::assertStringContainsString('Nenhum registro corresponde a estes filtros.', $none);
        self::assertStringContainsString('pagou-report-filters', $none);
        // Nothing open and nothing filtered: one clear state instead of empty filters, figures and tables.
        $clear = (new Pages())->render('findings', $report([], 0), '');
        self::assertStringContainsString('<section class="pagou-card pagou-all-clear">', $clear);
        self::assertStringContainsString('Nenhuma pendência aberta.', $clear);
        self::assertStringContainsString('Reverificar pendências', $clear);
        foreach (['Baixar CSV completo', 'pagou-report-filters', 'Histórico de pendências'] as $hidden) {
            self::assertStringNotContainsString($hidden, $clear);
        }
    }

    public function testReportsOfferOneClickPeriodsThatKeepTheOtherFilters(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo'));
        $from = $today->modify('first day of this month')->format('Y-m-d');
        $html = (new Pages())->render('reports', ['reportFilters' => [
            'report' => 'receipts', 'from' => $from, 'to' => $today->format('Y-m-d'), 'method' => 'pix',
        ], 'reportError' => 'Indisponível para o teste.'], '');

        self::assertStringContainsString('aria-label="Períodos rápidos"', $html);
        self::assertStringContainsString('class="pagou-chip is-active"', $html);
        self::assertStringContainsString('>Este mês</a>', $html);
        self::assertStringContainsString('&amp;method=pix', $html);
        self::assertStringNotContainsString('data-pagou-period', $html);
    }

    public function testAccountPanelSeparatesAccountValuesAndNeverInventsZero(): void
    {
        $panel = new AccountPanel();
        $ready = $panel->render([
            'configured' => true,
            'balance' => ['value' => ['available' => 137735, 'held' => 2000], 'asOf' => 1789671600],
            'summary' => ['value' => ['today_amount' => 15000, 'today_count' => 2, 'month_amount' => 85000, 'month_count' => 10]],
        ]);
        self::assertStringContainsString('R$ 1.377,35', $ready);
        self::assertStringContainsString('Valor retido: R$ 20,00', $ready);
        self::assertStringContainsString('R$ 850,00', $ready);
        self::assertStringContainsString('Toda a conta Pagou', $ready);
        self::assertStringContainsString('data-pagou-account-refresh', $ready);
        self::assertStringNotContainsString('data-pagou-account-pending', $ready);

        $pending = $panel->render(['configured' => true, 'balance' => ['pending' => true], 'summary' => ['pending' => true]]);
        self::assertStringContainsString('data-pagou-account-pending', $pending);
        self::assertStringContainsString('data-pagou-account-src="addonmodules.php?module=pagou_payments&amp;view=dashboard&amp;fragment=account"', $pending);

        foreach ([['unavailable' => true], ['configured' => true, 'balance' => ['error' => true], 'summary' => ['error' => true]]] as $state) {
            $html = $panel->render($state);
            self::assertStringNotContainsString('R$ 0,00', $html);
        }
        $missing = $panel->render(['configured' => false, 'balance' => [], 'summary' => []]);
        self::assertStringContainsString('Configure a credencial', $missing);
        self::assertStringNotContainsString('data-pagou-account-refresh', $missing);
    }

    public function testDashboardShowsTheAccountCardOnlyWhenTheEntrypointProvidesIt(): void
    {
        $html = (new Pages())->render('dashboard', ['account' => ['configured' => true, 'balance' => ['pending' => true], 'summary' => ['pending' => true]]], '');

        self::assertStringContainsString('data-pagou-account-pending', $html);
        self::assertTrue(strpos($html, 'pagou-account') < strpos($html, 'Precisa da sua atenção'));
    }

    public function testStatusTonesNeverDefaultToSuccess(): void
    {
        self::assertSame('success', Page::tone('Conciliado no WHMCS'));
        self::assertSame('danger', Page::tone('inválida'));
        self::assertSame('warning', Page::tone('Aguardando conciliação'));
        self::assertSame('neutral', Page::tone('Substituída'));
        self::assertSame('neutral', Page::tone('Um estado novo da API'));
        // States from the attempt lifecycle are translated before they reach a badge.
        self::assertSame('Cancelamento solicitado', PaymentLabels::label('cancel_requested'));
        self::assertSame('Reembolso parcial', PaymentLabels::label('partially_refunded'));
        self::assertSame('info', Page::tone(PaymentLabels::label('cancel_requested')));
    }
}
