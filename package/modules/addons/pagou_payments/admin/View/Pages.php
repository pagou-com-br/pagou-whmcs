<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;
use Pagou\Whmcs\Payment\LateChargeRules;

final class Pages
{
    /** @param array<string, mixed> $data */
    public function render(string $page, array $data, string $csrfField): string
    {
        $errors = '';
        foreach ($data['filterErrors'] ?? [] as $error) {
            $errors .= '<p class="alert alert-danger" role="alert">' . Html::e((string) $error) . '</p>';
        }
        return $errors . match ($page) {
            'reports' => (new MerchantReports())->report($data, $csrfField),
            'charge' => (new MerchantReports())->detail($data),
            'search' => (new SearchResults())->render($data),
            'payments' => $this->payments($data),
            'operations' => $this->operations($data, $csrfField),
            'webhooks' => $this->webhooks($data),
            'reconciliation' => $this->reconciliation($data, $csrfField),
            'findings' => $this->findings($data, $csrfField),
            'card' => $this->card($data, $csrfField),
            'settings' => $this->settings($data, $csrfField),
            'diagnostics' => $this->diagnostics($data, $csrfField),
            'about' => $this->about($data),
            default => $this->dashboard($data, $csrfField),
        };
    }

    /** @param array<string, mixed> $data */
    private function dashboard(array $data, string $csrf): string
    {
        return (new Dashboard())->render($data, $csrf);
    }

    /** @param array<string, mixed> $data */
    private function payments(array $data): string
    {
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $hasNext = (bool) ($data['hasNext'] ?? false);
        $intro = Page::header('Pagamentos', 'Cobranças do módulo e a situação de cada fatura.');
        return $intro . $this->filterForm('payments', $filters, [
            'invoice' => 'Fatura', 'method' => 'Método', 'status' => 'Situação',
        ])
            . $this->statusShortcuts($filters, [
                '' => 'Todas', 'ready' => 'Aguardando pagamento', 'paid' => 'Pagas', 'uncertain' => 'Aguardando conciliação',
                'failed' => 'Com falha', 'cancelled' => 'Canceladas', 'superseded' => 'Substituídas',
            ], is_array($data['statusCounts'] ?? null) ? $data['statusCounts'] : null)
            . (($data['grouped'] ?? false) === true ? $this->paymentGroups($data['payments'] ?? [], $filters, $hasNext) : Page::table(
                'Cobranças e tentativas',
                ['Fatura', 'Cliente', 'Método', 'Valor da cobrança', 'Atualizada em', 'Situação', 'Tentativa'],
                $data['payments'] ?? [],
                'Nenhuma cobrança corresponde aos filtros atuais.',
                true,
                $this->pager('payments', $filters, $hasNext, 'O valor da cobrança não representa necessariamente um recebimento confirmado. Substituições e cancelamentos permanecem no histórico.')
                    . '<p class="pagou-table-switch"><a href="' . $this->paymentsUrl($filters, '') . '">Agrupar por fatura</a></p>',
            ));
    }

    /**
     * @param mixed $groups
     * @param array<string, mixed> $filters
     */
    private function paymentGroups(mixed $groups, array $filters, bool $hasNext): string
    {
        $body = '';
        foreach (is_array($groups) ? $groups : [] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $invoice = (int) ($group['invoice'] ?? 0);
            $history = is_array($group['history'] ?? null) ? $group['history'] : [];
            $remote = (string) ($group['remoteId'] ?? '');
            $reference = $remote !== ''
                ? '<span class="pagou-id-cell">' . Page::shortId($remote) . Page::copyButton($remote, 'ID Pagou') . '</span>'
                : '<span class="pagou-muted-cell">' . (in_array((string) ($group['statusKey'] ?? ''), ['queued', 'pending'], true) ? 'Ainda não emitida' : 'Sem ID Pagou') . '</span>';
            $older = $history === [] ? '' : '<a class="pagou-count-tag" href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $invoice . '" title="Ver as tentativas no histórico da fatura">+'
                . count($history) . ' anterior' . (count($history) > 1 ? 'es' : '') . '</a>';
            $icon = match ((string) ($group['methodKey'] ?? '')) {
                'pix' => Html::icon('qr-code', 14), 'boleto' => Html::icon('barcode', 14), 'card' => Html::icon('card', 14), default => '',
            };
            $body .= '<tr data-pagou-row-href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $invoice . '"><td><a class="pagou-invoice-link" href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $invoice . '">#' . $invoice . '</a></td>'
                . '<td>' . Html::e((string) $group['customer']) . '</td><td><span class="pagou-method-cell">' . $icon . Html::e((string) $group['method']) . '</span></td>'
                . '<td class="pagou-amount">' . Html::e((string) $group['amount']) . '</td><td>' . Html::e((string) $group['updatedAt']) . '</td>'
                . '<td>' . Page::status((string) $group['status']) . '</td><td><span class="pagou-reference-cell">' . $reference . $older . '</span></td></tr>';
        }
        if ($body === '') {
            return Page::emptyCard('Cobranças por fatura', 'Nenhuma cobrança corresponde aos filtros atuais.', $this->pager('payments', $filters, $hasNext));
        }

        return '<section class="pagou-card pagou-table-card"><div class="pagou-card-title"><div><h2>Cobranças por fatura</h2>'
            . '<p>Uma linha por fatura com a cobrança atual. As tentativas anteriores ficam no histórico da fatura.</p></div>'
            . '<a class="pagou-link" href="' . $this->paymentsUrl($filters, 'all') . '">Ver todas as tentativas' . Html::icon('chevron-right', 14) . '</a></div>'
            . '<div class="pagou-table-wrap"><table class="pagou-table"><thead><tr><th scope="col">Fatura</th><th scope="col">Cliente</th><th scope="col">Método</th>'
            . '<th scope="col">Valor da cobrança</th><th scope="col">Atualizada em</th><th scope="col">Situação atual</th><th scope="col">ID Pagou</th></tr></thead><tbody>'
            . $body . '</tbody></table></div>'
            . $this->pager('payments', $filters, $hasNext, 'Os filtros selecionam faturas com alguma tentativa correspondente. O valor da cobrança não representa necessariamente um recebimento confirmado.')
            . '</section>';
    }

    /** @param array<string, mixed> $filters */
    private function paymentsUrl(array $filters, string $group): string
    {
        $query = ['module' => 'pagou_payments', 'view' => 'payments'];
        foreach (['invoice', 'method', 'status'] as $key) {
            if (is_scalar($filters[$key] ?? null) && trim((string) $filters[$key]) !== '') {
                $query[$key] = trim((string) $filters[$key]);
            }
        }
        if ($group !== '') {
            $query['group'] = $group;
        }

        return Html::e('addonmodules.php?' . http_build_query($query));
    }

    /** @param array<string, mixed> $data */
    private function operations(array $data, string $csrf): string
    {
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $hasNext = (bool) ($data['hasNext'] ?? false);
        $queue = is_array($data['queue'] ?? null) ? $data['queue'] : null;
        $state = $queue === null ? '' : '<span class="pagou-queue-state' . ((int) $queue['pending'] > 0 ? ' is-busy' : '') . '">'
            . ((int) $queue['pending'] > 0 ? 'Fila: ' . (int) $queue['pending'] . ' aguardando' . ($queue['oldest'] !== '' ? ', desde ' . Html::e((string) $queue['oldest']) : '') : 'Fila vazia') . '</span>';
        $buttons = $state . Page::action('Retomar fila', 'resume-queue', 'operations', $csrf, true, true, [], 'default')
            . Page::action('Atualizar pendências', 'refresh-pending', 'operations', $csrf, true, true, [], 'default');
        $actions = Page::header('Operações', 'Tudo o que o módulo executou junto à Pagou. Use as ações somente após conferir a fatura.', $buttons);
        return $actions . $this->operationFilterForm($filters)
            . Page::table(
                'Histórico operacional',
                ['Quando', 'Operação', 'Meio', 'Fatura', 'Resultado', 'Execuções', 'Tentativa', 'Orientação'],
                $data['operations'] ?? [],
                'Nenhuma operação corresponde aos filtros atuais.',
                true,
                $this->pager('operations', $filters, $hasNext),
                self::routineChecks(),
            );
    }

    /**
     * Repeated automatic payment checks of one invoice with the same result
     * collapse under the newest one.
     * @return array{key:callable(list<string>):?string,column:string,label:callable(int):string,all:string}
     */
    private static function routineChecks(): array
    {
        return [
            'key' => static fn (array $row): ?string => ($row[1] ?? '') === 'Conciliar pagamento' ? ($row[3] ?? '') . '|' . ($row[4] ?? '') : null,
            'column' => 'Operação',
            'label' => static fn (int $count): string => $count === 1 ? '+1 consulta igual' : '+' . $count . ' consultas iguais',
            'all' => 'Mostrar todas as consultas',
        ];
    }

    /** @param array<string, mixed> $data */
    private function webhooks(array $data): string
    {
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $hasNext = (bool) ($data['hasNext'] ?? false);
        $intro = Page::header('Notificações', 'Avisos recebidos da Pagou e o resultado do processamento de cada um.');
        $hidden = (int) ($data['hiddenUnknown'] ?? 0);
        $unknown = ($filters['type'] ?? '') === 'unknown';
        $note = $hidden > 0
            ? '<p class="pagou-report-note pagou-list-note">' . $hidden . ' notificação(ões) com tipo não reconhecido não aparecem nesta lista. '
                . 'Normalmente são registros anteriores ao formato atual de notificação e continuam guardados para auditoria. '
                . '<a href="addonmodules.php?module=pagou_payments&amp;view=webhooks&amp;type=unknown">Ver essas notificações</a></p>'
            : ($unknown ? '<p class="pagou-report-note pagou-list-note">Notificações com tipo não reconhecido. Normalmente são registros anteriores ao formato atual '
                . 'e não alteram pagamentos por si só. <a href="addonmodules.php?module=pagou_payments&amp;view=webhooks">Voltar à lista padrão</a></p>' : '');
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : null;
        $today = $summary === null ? '' : Page::metrics([
            ['label' => 'Recebidas hoje', 'value' => (string) (int) $summary['today'], 'detail' => 'Desde 00:00, no horário de São Paulo'],
            ['label' => 'Assinaturas válidas', 'value' => (int) $summary['today'] === 0 ? 'Sem envios hoje' : (int) $summary['valid'] . ' de ' . (int) $summary['today'],
                'detail' => 'Só notificações válidas alteram pagamentos', 'tone' => (int) $summary['today'] === 0 ? '' : ((int) $summary['valid'] < (int) $summary['today'] ? 'warning' : 'success')],
            ['label' => 'Última recebida', 'value' => (string) ($summary['last'] !== '' ? $summary['last'] : 'Nenhuma'), 'detail' => 'Qualquer tipo reconhecido'],
        ]);
        return $intro . $today . $this->filterForm('webhooks', $filters, [
            'type' => 'Tipo do evento', 'invoice' => 'Fatura', 'period' => 'Período',
        ], $data['eventTypes'] ?? ['' => 'Todos os eventos'])
            . $note
            . Page::table(
                'Notificações recebidas',
                ['Recebida em', 'Tipo recebido', 'Identidade do recurso', 'Assinatura', 'Conciliação', 'Fatura'],
                $data['webhooks'] ?? [],
                'Nenhuma notificação recebida ainda.',
                true,
                $this->pager('webhooks', $filters, $hasNext, 'Toda notificação tem a assinatura validada antes de qualquer atualização financeira.'),
            );
    }

    /** @param array<string, mixed> $data */
    private function reconciliation(array $data, string $csrf): string
    {
        $coverage = is_array($data['coverage'] ?? null) ? $data['coverage'] : [];
        $checked = (int) ($coverage['checked'] ?? 0);
        $eligible = (int) ($coverage['eligible'] ?? 0);
        $percent = $eligible > 0 ? round(min($checked, $eligible) / $eligible * 100, 1) : 0;
        $divergences = (int) ($data['divergences'] ?? 0);
        $run = Page::action('Conciliar agora', 'run-reconciliation', 'reconciliation', $csrf);
        $summary = Page::header('Conciliação', 'Conferência automática dos recebimentos com a Pagou. Pode dar baixa nas faturas, mas nunca cria cobranças.', $run);
        $complete = $eligible === 0 || $checked >= $eligible;
        $tiles = Page::metrics([
            ['label' => 'Cobertura em 24 horas', 'value' => $checked . ' de ' . $eligible, 'detail' => $eligible === 0 ? 'Nenhuma cobrança em acompanhamento' : 'Cobranças consultadas com sucesso (' . number_format($percent, 0, ',', '.') . '%)', 'tone' => $complete ? 'success' : 'warning'],
            ['label' => 'Última conciliação', 'value' => (string) ($data['lastReconciliationDisplay'] ?? 'Nunca'), 'detail' => 'Consulta concluída mais recente'],
            ['label' => 'Aguardando consulta', 'value' => (string) ($data['pending'] ?? '0'), 'detail' => 'Cobranças na fila de conferência'],
            ['label' => 'Divergências', 'value' => (string) $divergences, 'detail' => $divergences > 0 ? 'Confira em Pendências' : 'Nada para conferir', 'tone' => $divergences > 0 ? 'warning' : 'success'],
        ]);
        if ($divergences > 0) {
            $tiles .= '<p class="pagou-help-strip"><a href="addonmodules.php?module=pagou_payments&amp;view=findings">' . $divergences . ' divergência(s) para conferir em Pendências</a>. Nenhuma baixa automática é feita nesses casos.</p>';
        }
        // The newest check of each invoice leads; its earlier checks stay one click away.
        $runs = is_array($data['runs'] ?? null) ? $data['runs'] : [];
        $byInvoice = [];
        foreach ($runs as $run) {
            $byInvoice[(string) ($run[3] ?? '')][] = $run;
        }
        $runs = $byInvoice === [] ? [] : array_merge(...array_values($byInvoice));
        return $summary . $tiles
            . Page::table('Consultas recentes por fatura', ['Atualizada em', 'Operação', 'Meio', 'Fatura', 'Resultado', 'Execuções', 'Tentativa', 'Orientação'], $runs, 'Nenhuma consulta registrada.', true, '', [
                'key' => static fn (array $row): string => (string) ($row[3] ?? ''),
                'column' => 'Operação',
                'label' => static fn (int $count): string => $count === 1 ? '+1 anterior' : '+' . $count . ' anteriores',
                'all' => 'Mostrar todas as consultas',
            ]);
    }

    /** @param array<string, mixed> $data */
    private function findings(array $data, string $csrf): string
    {
        $refresh = Page::action('Reverificar pendências', 'refresh-pending', 'findings', $csrf, true, true, [], 'default');
        $help = Page::header('Pendências', 'Registros ambíguos não geram crédito automaticamente. Confira cada item antes de uma baixa ou ação manual.', $refresh);
        $history = $data['findings'] ?? [];

        return $help . (new MerchantReports())->report($data, $csrf, 'findings')
            . ($history === [] ? '' : Page::table('Histórico de pendências (100 mais recentes)', ['Criada em', 'Tipo', 'Fatura', 'Gravidade', 'Estado'], $history, 'Nenhuma pendência registrada.', true));
    }

    /** @param array<string, mixed> $data */
    private function card(array $data, string $csrf): string
    {
        [$label, $tone, $description, $action, $url] = match ($data['availability'] ?? 'unknown') {
            'inactive' => ['Desativado no WHMCS', 'neutral', 'O cartão ainda não está ativado nesta instalação. Quando decidir utilizá-lo, acesse os meios de pagamento nas configurações do módulo.', 'Ver configurações', 'addonmodules.php?module=pagou_payments&view=settings#pagou-gateways'],
            'hidden' => ['Oculto para clientes', 'info', 'O cartão está ativado, mas não aparece entre as opções para novas seleções de pagamento. A visibilidade é controlada nos meios de pagamento do WHMCS.', 'Gerenciar disponibilidade', 'configgateways.php'],
            'visible' => ['Disponível no WHMCS', 'success', 'O cartão está ativado e configurado para aparecer entre as formas de pagamento dos clientes elegíveis.', 'Gerenciar disponibilidade', 'configgateways.php'],
            default => ['Situação indisponível', 'warning', 'Não foi possível consultar a configuração do cartão no WHMCS. Tente novamente ou consulte o diagnóstico para obter ajuda.', 'Ver diagnóstico', 'addonmodules.php?module=pagou_payments&view=diagnostics'],
        };
        $review = ($data['cardReady'] ?? false) || ($data['availability'] ?? 'unknown') === 'unknown' ? ''
            : '<p class="pagou-help-strip">Há configurações da instalação que precisam de revisão. '
                . '<a href="addonmodules.php?module=pagou_payments&amp;view=diagnostics">Consulte o diagnóstico</a> antes de disponibilizar novos pagamentos.</p>';
        $operations = is_array($data['operations'] ?? null) ? $data['operations'] : [];
        $events = is_array($data['events'] ?? null) ? $data['events'] : [];
        // Until the card is used, the activity tables would only repeat that nothing happened.
        $activity = $operations === [] && $events === [] && ($data['availability'] ?? '') === 'inactive'
            ? ''
            : $this->cardOperations($operations, $csrf)
                . Page::table('Eventos de cartão', ['Quando', 'Fatura', 'Evento', 'Estado'], $events, 'Nenhum processamento de cartão foi iniciado.');
        return Page::header('Cartão de crédito', 'Disponibilidade do cartão no WHMCS e acompanhamento das cobranças.', Html::badge($label, $tone))
            . '<section class="pagou-card"><div class="pagou-card-title"><div><h2>Disponibilidade no WHMCS</h2><p>' . Html::e($description)
            . '</p></div><a class="btn btn-default pagou-button" href="' . Html::e($url) . '">' . Html::e($action) . '</a></div>' . $review
            . '<dl class="pagou-details"><dt>Pagamento</dt><dd>' . Html::e((string) ($data['installments'] ?? 'Consulte as configurações')) . '</dd></dl>'
            . '<p class="pagou-card-caption">A disponibilidade acima se refere a este WHMCS. Consulte a habilitação da sua conta no '
            . '<a href="https://app.pagou.com.br/configuracoes/cartao" target="_blank" rel="noopener noreferrer">aplicativo Pagou</a>.</p></section>'
            . $activity
            . '<p class="pagou-card-caption">Precisa de ajuda? O <a href="addonmodules.php?module=pagou_payments&amp;view=diagnostics#pagou-support-details">relatório para suporte</a> reúne os detalhes da instalação.</p>';
    }

    /** @param mixed $operations */
    private function cardOperations(mixed $operations, string $csrf): string
    {
        $operations = is_array($operations) ? $operations : [];
        $body = '';
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $chargeId = (string) ($operation['chargeId'] ?? '');
            $state = (string) ($operation['state'] ?? 'unknown');
            $actions = [Page::action('Consultar', 'card-reconcile', 'card', $csrf, $chargeId !== '', false, ['charge_id' => $chargeId])];
            if ($state === 'authorized') {
                $actions[] = Page::action('Reverter autorização', 'card-reverse', 'card', $csrf, true, true, ['charge_id' => $chargeId]);
            } elseif (in_array($state, ['pending', 'action_required'], true)) {
                array_unshift($actions, Page::action('Cancelar pendência', 'card-cancel', 'card', $csrf, true, true, ['charge_id' => $chargeId]));
            } elseif ($state === 'failed') {
                array_unshift($actions, Page::action('Tentar novamente', 'card-retry', 'card', $csrf, true, true, ['charge_id' => $chargeId]));
            }
            $body .= '<tr><td>' . Html::e((string) ($operation['updatedAt'] ?? '')) . '</td>'
                . '<td>#' . Html::e((string) ($operation['invoiceId'] ?? '')) . '</td>'
                . '<td>' . Html::e((string) ($operation['card'] ?? 'Cartão mascarado')) . '</td>'
                . '<td>' . Html::e((string) ($operation['amount'] ?? '')) . '</td>'
                . '<td>' . Html::e((string) ($operation['status'] ?? $state)) . '</td>'
                . '<td><div class="pagou-actions">' . implode('', $actions) . '</div></td></tr>';
        }
        if ($body === '') {
            return Page::emptyCard('Operações financeiras', 'Nenhuma cobrança de cartão foi iniciada. Reembolsos de pagamentos confirmados usam a operação nativa da transação no WHMCS.');
        }

        return '<section class="pagou-card"><div class="pagou-card-title"><div><h2>Operações financeiras</h2>'
            . '<p>A captura é automática. Cancele pendências, reverta autorizações e consulte o estado sem criar uma nova cobrança. Reembolsos de pagamentos confirmados usam a operação nativa da transação no WHMCS.</p>'
            . '</div></div><div class="pagou-table-wrap"><table class="pagou-table"><thead><tr>'
            . '<th>Atualizada em</th><th>Fatura</th><th>Cartão</th><th>Valor</th><th>Estado</th><th>Ações</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table></div></section>';
    }

    /** @param array<string, mixed> $data */
    private function settings(array $data, string $csrf): string
    {
        $credentialConfigured = (bool) ($data['credentialConfigured'] ?? false);
        $credentialReady = ($data['credentialState'] ?? '') === 'ready';
        $credentialStatus = $credentialReady
            ? Html::badge('Conexão validada', 'success')
            : Html::badge($credentialConfigured ? 'Requer validação' : 'Não configurada', 'warning');
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $customFields = is_array($data['customFields'] ?? null) ? $data['customFields'] : [];
        $callback = is_array($data['callback'] ?? null) ? $data['callback'] : [];
        $worker = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        $timezone = is_array($data['timezone'] ?? null) ? $data['timezone'] : [];
        $validation = (string) ($data['credentialValidatedAt'] ?? 'Nunca');
        $fingerprint = (string) ($data['credentialFingerprint'] ?? '');
        $documentReady = trim((string) ($settings['cpf_field_id'] ?? '')) !== '';
        $activationReady = $credentialReady && $documentReady;
        $cardReady = (bool) ($data['cardReady'] ?? false);
        $section = is_scalar($data['settingsSection'] ?? null) ? (string) $data['settingsSection'] : 'general';
        $section = in_array($section, ['general', 'pix', 'boleto', 'card'], true) ? $section : 'general';
        $subnav = $this->settingsSubnav($section);
        if ($section !== 'general') {
            return $subnav . $this->paymentMethodSettings($section, $settings, $csrf);
        }

        $diagnosticsLink = '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view=diagnostics">Ver diagnóstico</a>';
        $intro = Page::header('Configurações gerais', 'Valem para toda a integração. As escolhas de Pix, boleto e cartão ficam nas abas acima.', $diagnosticsLink);

        $credential = '<section id="pagou-connection" class="pagou-card pagou-anchor"><div class="pagou-card-title">'
            . '<div><span class="pagou-eyebrow">CONEXÃO PAGOU</span><h2>Credencial única e protegida</h2><p>Usada por Pix, boleto e cartão. A credencial atual nunca volta a ser exibida.</p></div>'
            . $credentialStatus . '</div><div class="pagou-connection-grid"><div><div class="pagou-assurances">'
            . '<span>Uma única credencial</span><span>Protegida pelo WHMCS</span><span>URL oficial fixa</span></div>'
            . '<dl class="pagou-details"><dt>Última validação</dt><dd>' . Html::e($validation) . '</dd>'
            . '<dt>Identificação segura</dt><dd>' . Html::e($fingerprint !== '' ? $fingerprint : 'Disponível depois da primeira validação') . '</dd>'
            . '<dt>Ambiente do produto</dt><dd>Produção, sem seletor de ambiente</dd></dl>'
            . ($credentialConfigured
                ? '<div class="pagou-actions pagou-spacing-top">'
                    . Page::action('Testar conexão agora', 'test-credential', 'settings', $csrf, true, false)
                    . '</div>'
                : '')
            . '</div><div class="pagou-connection-form"><form method="post" class="pagou-form" data-pagou-loading-overlay'
            . ($credentialConfigured ? ' data-pagou-confirm="Substituir a credencial Pagou atual"' : '') . '>' . $csrf
            . '<input type="hidden" name="view" value="settings">'
            . '<input type="hidden" name="action" value="validate-credential">'
            . '<label for="pagou-credential">' . ($credentialConfigured ? 'Nova credencial Pagou' : 'Credencial Pagou') . '</label>'
            . '<input id="pagou-credential" name="credential" type="password" '
            . 'autocomplete="new-password" value="" placeholder="' . ($credentialConfigured ? 'Informe somente para substituir' : 'Informe a credencial fornecida pela Pagou') . '">'
            . ($credentialConfigured
                ? '<label class="pagou-confirm-check"><input name="replace_credential" type="checkbox" value="1"> Confirmo que desejo substituir a credencial protegida atual.</label>'
                : '')
            . '<p class="pagou-help">O módulo valida a conexão antes de armazenar qualquer alteração.</p>'
            . '<button class="btn btn-primary pagou-button" type="submit">'
            . ($credentialConfigured ? 'Validar e substituir' : 'Salvar e validar')
            . '</button></form></div></div></section>';

        $identification = '<section id="pagou-identification" class="pagou-card pagou-anchor"><div class="pagou-card-title"><div>'
            . '<span class="pagou-eyebrow">IDENTIFICAÇÃO DO CLIENTE</span><h2>Campos de CPF e CNPJ</h2>'
            . '<p>Escolha os campos existentes no WHMCS. O módulo não exibe os documentos nesta tela.</p>'
            . '</div></div><div class="pagou-settings-grid">'
            . $this->settingSelect($settings, 'cpf_field_id', 'Campo principal de CPF ou CNPJ', 'Obrigatório para criar cobranças.', $customFields)
            . $this->settingSelect($settings, 'cnpj_field_id', 'Campo alternativo de CNPJ', 'Opcional. O campo principal continua tendo prioridade.', $customFields)
            . '</div>' . $this->settingToggle($settings, 'prefer_cnpj', 'Priorizar o campo alternativo de CNPJ', 'Use somente quando CPF e CNPJ estiverem em campos separados. Selecionar o mesmo campo nas duas opções é seguro e não duplica a leitura.')
            . '</section>';

        $processing = '<section id="pagou-processing" class="pagou-card pagou-anchor"><div class="pagou-card-title"><div>'
            . '<span class="pagou-eyebrow">PROCESSAMENTO EM SEGUNDO PLANO</span><h2>Fila protegida do cron diário</h2>'
            . '<p>' . Html::e((string) ($worker['detail'] ?? 'Aguardando a primeira execução do worker.')) . '</p></div>'
            . Html::badge(($worker['state'] ?? '') === 'ready' ? 'Ativo' : 'Requer atenção', ($worker['state'] ?? '') === 'ready' ? 'success' : 'warning')
            . '</div><dl class="pagou-details"><dt>Última execução</dt><dd>' . Html::e((string) ($worker['lastRun'] ?? 'Nunca')) . '</dd>'
            . '<dt>Operações aguardando</dt><dd>' . Html::e((string) ($worker['pending'] ?? '0')) . '</dd>'
            . '<dt>Operação mais antiga</dt><dd>' . Html::e((string) ($worker['oldest'] ?? 'Nunca')) . '</dd></dl>'
            . '<label class="pagou-spacing-top" for="pagou-worker-command">Comando recomendado, executar a cada minuto</label>'
            . '<div class="pagou-copy-control pagou-command-control"><code id="pagou-worker-command" class="pagou-command">'
            . Html::e((string) ($worker['command'] ?? 'php /caminho/do/whmcs/modules/addons/pagou_payments/cron.php')) . '</code>'
            . '<button class="btn btn-default pagou-button" type="button" data-pagou-copy="pagou-worker-command">'
            . Html::icon('copy') . 'Copiar comando</button></div>'
            . $this->settingToggle($settings, 'admin_alerts_enabled', 'Avisar os administradores por e-mail', 'Envia um aviso do sistema WHMCS quando o worker parar, surgir uma pendência financeira ou uma devolução entrar em conferência. O aviso não inclui dados de clientes.')
            . '<details class="pagou-advanced"><summary>Configurações avançadas do worker</summary><div class="pagou-settings-grid">'
            . $this->settingField(
                $settings,
                'worker_max_jobs',
                'Operações por execução',
                'Quantidade processada em cada passagem do worker, entre 1 e 200.',
                1,
                200,
            )
            . $this->settingField(
                $settings,
                'worker_max_seconds',
                'Orçamento do worker',
                'Tempo máximo por execução, entre 10 e 300 segundos.',
                10,
                300,
            )
            . $this->settingField(
                $settings,
                'retention_operational_days',
                'Retenção operacional',
                'Retenção de payloads descartáveis, entre 30 e 730 dias.',
                30,
                730,
            )
            . '</div><p class="pagou-help">Os valores padrão são recomendados para a maioria das instalações.</p></details></section>';

        $notifications = '<section id="pagou-notifications" class="pagou-card pagou-anchor"><div class="pagou-card-title"><div>'
            . '<span class="pagou-eyebrow">NOTIFICAÇÕES E SEGURANÇA</span><h2>Retorno automático da Pagou</h2><p>'
            . Html::e((string) ($callback['detail'] ?? 'Aguardando configuração.')) . '</p></div>'
            . Html::badge(($callback['state'] ?? '') === 'ready' ? 'HTTPS pronto' : 'Revisar', ($callback['state'] ?? '') === 'ready' ? 'success' : 'warning')
            . '</div><div class="pagou-technical-value">'
            . '<span class="pagou-technical-value-icon">' . Html::icon('external', 16) . '</span><div>'
            . '<span>Endereço utilizado automaticamente</span><code>'
            . Html::e((string) ($callback['url'] ?? 'Indisponível')) . '</code></div></div>'
            . '<p class="pagou-help">O módulo envia este endereço automaticamente ao criar cada cobrança. Não é necessário cadastrá-lo manualmente. '
            . 'As notificações recebidas são validadas antes de qualquer atualização financeira.</p></section>';

        $timezoneCard = '<section id="pagou-timezone" class="pagou-card pagou-anchor"><div class="pagou-card-title"><div>'
            . '<span class="pagou-eyebrow">HORÁRIOS</span><h2>Datas financeiras previsíveis</h2><p>'
            . Html::e((string) ($timezone['detail'] ?? 'Persistência UTC e exibição em São Paulo.')) . '</p></div></div>'
            . '<dl class="pagou-details"><dt>PHP da área administrativa</dt><dd>' . Html::e((string) ($timezone['web'] ?? 'Indisponível')) . '</dd>'
            . '<dt>Persistência técnica</dt><dd>' . Html::e((string) ($timezone['persistence'] ?? 'UTC')) . '</dd>'
            . '<dt>Exibição financeira</dt><dd>' . Html::e((string) ($timezone['display'] ?? 'America/Sao_Paulo')) . '</dd></dl>'
            . '</section>';

        $general = '<form id="pagou-settings-general" method="post" class="pagou-settings-form">' . $csrf
            . '<input type="hidden" name="view" value="settings">'
            . '<input type="hidden" name="action" value="save-settings">'
            . '<input type="hidden" name="settings_section" value="general">'
            . '<div class="pagou-settings-pair pagou-settings-pair--identity">'
            . $identification . $timezoneCard . '</div>'
            . '<div class="pagou-settings-pair pagou-settings-pair--operation">'
            . $processing . $notifications . '</div>'
            . '</form><div class="pagou-form-footer"><button class="btn btn-primary pagou-button" type="submit" form="pagou-settings-general">Salvar configurações gerais</button>'
            . $this->resetControl(
                'general',
                'configurações gerais',
                'Os campos de CPF/CNPJ, a prioridade e os ajustes avançados do worker voltarão ao padrão.',
                $csrf,
            )
            . '<span>Os gateways só são ativados quando você confirma a ação nos cards acima.</span></div>';

        return $subnav . $intro . $credential
            . $this->gatewaySummary(
                is_array($data['gatewayStatus'] ?? null) ? $data['gatewayStatus'] : [],
                $csrf,
                $activationReady,
                $cardReady,
            )
            . $general;
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<array{id:string,label:string}> $fields
     */
    private function settingSelect(array $settings, string $key, string $label, string $help, array $fields): string
    {
        $selected = is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';
        $known = false;
        $options = '<option value="">Selecione um campo do WHMCS</option>';
        foreach ($fields as $field) {
            $id = $field['id'];
            if ($id === '') {
                continue;
            }
            $known = $known || hash_equals($selected, $id);
            $options .= '<option value="' . Html::e($id) . '"' . (hash_equals($selected, $id) ? ' selected' : '') . '>'
                . Html::e($field['label'] . ' (ID ' . $id . ')') . '</option>';
        }
        if ($selected !== '' && !$known) {
            $options .= '<option value="' . Html::e($selected) . '" selected>Campo configurado anteriormente (ID '
                . Html::e($selected) . ')</option>';
        }

        return '<label class="pagou-setting" for="pagou-' . Html::e($key) . '"><span>'
            . Html::e($label) . '</span><select id="pagou-' . Html::e($key) . '" name="' . Html::e($key) . '">'
            . $options . '</select><small>' . Html::e($help) . '</small></label>';
    }

    private function settingsSubnav(string $active): string
    {
        $items = [
            'general' => ['Geral', 'settings'],
            'pix' => ['Pix', 'qr-code'],
            'boleto' => ['Boleto', 'barcode'],
            'card' => ['Cartão de crédito', 'card'],
        ];
        $html = '<nav class="pagou-tabs pagou-subtabs" aria-label="Seções da configuração">';
        foreach ($items as $key => [$label, $icon]) {
            $html .= '<a class="pagou-tab pagou-subtab' . ($key === $active ? ' is-active' : '')
                . '" href="addonmodules.php?module=pagou_payments&amp;view=settings&amp;section=' . Html::e($key) . '"'
                . ($key === $active ? ' aria-current="page"' : '') . '>' . Html::icon($icon, 15) . Html::e($label) . '</a>';
        }

        return $html . '</nav>';
    }

    /** @param array<string, mixed> $settings */
    private function paymentMethodSettings(string $section, array $settings, string $csrf): string
    {
        $definitions = [
            'pix' => [
                'title' => 'Configurações do Pix',
                'description' => 'Controle emissão, vencimento, limites, acréscimos e apresentação do Pix.',
                'icon' => 'qr-code',
            ],
            'boleto' => [
                'title' => 'Configurações do boleto',
                'description' => 'Controle registro, multa, juros, limites, PDF, e-mail e apresentação do boleto.',
                'icon' => 'barcode',
            ],
            'card' => [
                'title' => 'Configurações do cartão de crédito',
                'description' => 'Controle parcelamento, captura, identificação e limites compatíveis com o contrato Pagou.',
                'icon' => 'card',
            ],
        ];
        $definition = $definitions[$section];
        $diagnostics = '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view=diagnostics">Ver diagnóstico</a>';
        $intro = Page::header($definition['title'], $definition['description'], $diagnostics);

        $cards = match ($section) {
            'pix' => $this->pixSettingsCards($settings),
            'boleto' => $this->boletoSettingsCards($settings),
            default => $this->cardSettingsCards($settings),
        };
        $label = match ($section) {
            'pix' => 'Salvar configurações do Pix',
            'boleto' => 'Salvar configurações do boleto',
            default => 'Salvar configurações do cartão',
        };
        $formId = 'pagou-settings-' . $section;
        $resetDescription = match ($section) {
            'pix' => 'Emissão, vencimento, multa, juros, limites, acréscimos, e-mail e apresentação voltarão ao padrão recomendado.',
            'boleto' => 'Prazo, multa, juros, limites, acréscimos, PDF, e-mail e apresentação voltarão ao padrão recomendado.',
            default => 'Parcelamento, captura, identificação, limites e acréscimos voltarão ao padrão recomendado.',
        };
        $resetLabel = match ($section) {
            'pix' => 'configurações do Pix',
            'boleto' => 'configurações do boleto',
            default => 'configurações do cartão',
        };

        return $intro . '<form id="' . Html::e($formId) . '" method="post" class="pagou-settings-form">' . $csrf
            . '<input type="hidden" name="view" value="settings"><input type="hidden" name="action" value="save-settings">'
            . '<input type="hidden" name="settings_section" value="' . Html::e($section) . '">'
            . '<div class="pagou-method-settings-grid">' . $cards . '</div>'
            . '</form><div class="pagou-form-footer"><button class="btn btn-primary pagou-button" type="submit" form="' . Html::e($formId) . '">'
            . Html::e($label) . '</button>'
            . $this->resetControl($section, $resetLabel, $resetDescription, $csrf)
            . '<span>Regras de emissão valem para novas cobranças. Opções de exibição também afetam cobranças existentes.</span></div>'
            . (new ClientPreview())->render($section, $settings, $formId);
    }

    /** @param array<string, mixed> $settings */
    private function pixSettingsCards(array $settings): string
    {
        $issuance = $this->configCard(
            'Emissão e vencimento',
            'Defina a modalidade e a validade da cobrança.',
            $this->settingToggle($settings, 'pix_due_enabled', 'Pix com vencimento', 'Usa a data de vencimento da fatura. Desmarcado gera Pix imediato.')
            . '<div class="pagou-settings-grid">'
            . $this->pixValidityField($settings)
            . $this->settingNumber($settings, 'pix_due_expiration_days', 'Validade após o vencimento', 'Quantidade de dias, entre 1 e 365.', 1, 365)
            . '</div>'
        );
        $interestOptions = LateChargeRules::interestOptions();
        if (($settings['pix_due_interest_type'] ?? '') === 'percentage') {
            $interestOptions['percentage'] = 'Percentual antigo: selecione a periodicidade';
        }
        $late = $this->configCard(
            'Multa e juros do Pix com vencimento',
            'Aplicados pela Pagou e pelo banco após o vencimento. O módulo não calcula dias de atraso.',
            $this->chargeRulesNotice($settings, 'pix') . '<div class="pagou-settings-grid">'
            . $this->settingChoice($settings, 'pix_due_fine_type', 'Tipo de multa', ['none' => 'Não aplicar', 'fixed' => 'Valor fixo', 'percentage' => 'Percentual'], 'Aplicada somente ao Pix com vencimento.')
            . $this->settingDecimal($settings, 'pix_due_fine_amount', 'Valor da multa', 'Use reais para valor fixo ou percentual para porcentagem.', false)
            . $this->settingChoice($settings, 'pix_due_interest_type', 'Tipo de juros', $interestOptions, 'Escolha a unidade e a periodicidade. Dias corridos incluem fins de semana.')
            . $this->settingDecimal($settings, 'pix_due_interest_amount', 'Valor dos juros', 'Informe R$ por dia ou % ao dia/ao mês conforme a opção. Zero desativa.', false)
            . '</div>'
            . $this->settingToggle($settings, 'pix_due_respect_late_fees', 'Respeitar “Aplicar taxas por atraso”', 'Quando marcado, multa e juros não são enviados se essa opção estiver desativada para o cliente no WHMCS.')
        );
        $financial = $this->financialSettingsCard('Pix', 'pix', $settings);
        $presentation = $this->configCard(
            'Fatura e e-mail',
            'Escolha quais informações o cliente verá.',
            '<div class="pagou-toggle-grid">'
            . $this->settingToggle($settings, 'pix_email_details', 'Incluir informações nos e-mails', 'Disponibiliza os dados do Pix nos e-mails de fatura.')
            . $this->settingToggle($settings, 'pix_hide_on_error', 'Ocultar detalhes em caso de erro', 'Exibe uma mensagem segura sem detalhes técnicos.')
            . $this->settingToggle($settings, 'pix_show_qr', 'Exibir QR Code', 'Mostra a imagem do QR Code na fatura e nos e-mails.')
            . $this->settingToggle($settings, 'pix_show_copy_paste', 'Exibir Pix copia e cola', 'Mostra o código completo para cópia.')
            . $this->settingToggle($settings, 'pix_show_notes', 'Exibir observações', 'Mostra o texto configurado abaixo da cobrança.')
            . '</div>' . $this->settingTextarea($settings, 'pix_notes', 'Observações', 'Texto simples, até 2.000 caracteres.')
        );

        return $issuance . $late . $financial . $presentation;
    }

    /** @param array<string, mixed> $settings */
    private function boletoSettingsCards(array $settings): string
    {
        $issuance = $this->configCard(
            'Emissão e vencimento',
            'Defina o prazo bancário e os encargos percentuais enviados à Pagou.',
            $this->chargeRulesNotice($settings, 'boleto') . '<div class="pagou-settings-grid">'
            . $this->settingNumber($settings, 'boleto_grace_period', 'Prazo de pagamento após o vencimento', 'Entre 1 e 30 dias.', 1, 30)
            . $this->settingDecimal($settings, 'boleto_fine', 'Multa (%)', 'Percentual único após o vencimento. Zero desativa; de 0,10 a 100.', false)
            . $this->settingDecimal($settings, 'boleto_interest', 'Juros ao mês (%)', 'Percentual mensal proporcional aos dias de atraso (base 30 dias). Zero desativa; de 0,10 a 100.', false)
            . '</div>'
            . $this->settingToggle($settings, 'boleto_respect_late_fees', 'Respeitar “Aplicar taxas por atraso”', 'Quando marcado, multa e juros não são enviados se essa opção estiver desativada para o cliente no WHMCS.')
        );
        $financial = $this->financialSettingsCard('Boleto', 'boleto', $settings);
        $delivery = $this->configCard(
            'E-mail e PDF',
            'Controle o envio assíncrono depois que o boleto estiver pronto.',
            '<div class="pagou-settings-grid">'
            . $this->settingChoice($settings, 'boleto_email_pdf_mode', 'PDF no e-mail', ['attach' => 'Anexar PDF', 'link' => 'Enviar somente o link', 'none' => 'Não incluir PDF'], 'O e-mail aguarda apenas quando o modo selecionado exige o PDF.')
            . $this->settingText($settings, 'boleto_email_template', 'Template do WHMCS', 'Nome do template reenviado quando a cobrança estiver pronta. Em branco, usa Invoice Created.', 100)
            . '</div><div class="pagou-toggle-grid">'
            . $this->settingToggle($settings, 'boleto_email_details', 'Incluir informações nos e-mails', 'Inclui a linha digitável e o link do boleto no e-mail.')
            . $this->settingToggle($settings, 'boleto_hide_on_error', 'Ocultar detalhes em caso de erro', 'Exibe uma mensagem segura sem detalhes técnicos.')
            . '</div>'
        );
        $presentation = $this->configCard(
            'Fatura do cliente',
            'Escolha quais artefatos da mesma cobrança serão exibidos.',
            '<div class="pagou-toggle-grid">'
            . $this->settingToggle($settings, 'boleto_show_line', 'Exibir linha digitável', 'Mostra a linha digitável do boleto.')
            . $this->settingToggle($settings, 'boleto_show_pdf', 'Exibir download do PDF', 'Mostra o link quando o PDF estiver pronto.')
            . $this->settingToggle($settings, 'boleto_show_qr', 'Exibir QR Code Pix', 'Mostra o QR Code que já pertence ao boleto.')
            . $this->settingToggle($settings, 'boleto_show_notes', 'Exibir observações', 'Mostra o texto configurado abaixo da cobrança.')
            . '</div>' . $this->settingTextarea($settings, 'boleto_notes', 'Observações', 'Texto simples, até 2.000 caracteres.')
        );

        return $issuance . $financial . $delivery . $presentation;
    }

    /** @param array<string, mixed> $settings */
    private function chargeRulesNotice(array $settings, string $method): string
    {
        $notice = '<p>Use somente uma origem de multa por atraso: WHMCS ou Pagou. Novas emissões com multa Pagou serão bloqueadas se houver multa nativa aplicável. Juros e acréscimo comercial são configurações separadas.</p>';
        if (!LateChargeRules::needsReview($settings, $method)) {
            return $notice;
        }
        return '<div class="alert alert-warning"><strong>Revise os encargos configurados anteriormente.</strong>'
            . '<p>Os números foram preservados. Confirme as unidades e a periodicidade abaixo. Novas emissões com esses encargos aguardam sua revisão; cobranças já emitidas não serão alteradas.</p>'
            . $this->settingToggle([], $method . '_late_charges_reviewed', 'Revisei os valores, as unidades e a periodicidade', 'Marque somente após conferir os campos e salve as configurações.')
            . '</div>' . $notice;
    }

    /** @param array<string, mixed> $settings */
    private function cardSettingsCards(array $settings): string
    {
        $legacy = (string) ($settings['card_max_installments'] ?? '1') !== '1'
            || (string) ($settings['card_auto_capture'] ?? '1') !== '1';
        $review = $legacy
            ? '<div class="pagou-callout"><strong>Revise a configuração anterior</strong><p>Novas cobranças estão bloqueadas por opções ainda não disponíveis. Ao salvar esta seção com uma parcela, o cartão passará a usar pagamento à vista e captura automática.</p></div>'
            : '';
        $behavior = $this->configCard(
            'Processamento do cartão',
            'Opções suportadas pelo contrato atual da Pagou.',
            '<div class="pagou-settings-grid">'
            . $this->settingNumber($settings, 'card_max_installments', 'Máximo de parcelas', 'Esta versão aceita pagamento à vista. Parcelamento adicional permanece indisponível.', 1, 1)
            . $this->settingText($settings, 'card_soft_descriptor', 'Nome na fatura do cartão', 'Até 22 letras, números, espaços, pontos ou hífens.', 22)
            . '</div>'
            . '<input type="hidden" name="card_auto_capture" value="1"><p>Captura automática. Pré-autorização e captura posterior não estão disponíveis nesta versão.</p>'
        );
        $financial = $this->financialSettingsCard('Cartão de crédito', 'card', $settings);
        $security = $this->configCard(
            'Segurança e disponibilidade',
            'O módulo nunca armazena número completo nem código de segurança.',
            '<div class="pagou-callout"><strong>Tokenização e 3DS</strong><p>Os dados sensíveis entram somente pelo fluxo remoto da Pagou. A autenticação adicional do emissor depende de homologação do fluxo nesta instalação.</p></div>'
            . '<div class="pagou-callout"><strong>Split no cartão</strong><p>Não está disponível nesta versão do módulo. Isso não impede a homologação de pagamentos comuns com cartão.</p></div>'
        );

        return $review . $behavior . $financial . $security;
    }

    /** @param array<string, mixed> $settings */
    private function financialSettingsCard(string $label, string $prefix, array $settings): string
    {
        $minimumHelp = $prefix === 'boleto'
            ? 'O boleto exige no mínimo R$ 5,00. Deixe vazio para não aplicar um limite comercial adicional.'
            : 'Deixe vazio para não limitar.';

        return $this->configCard(
            'Limites e acréscimos',
            'Aplicados pelo módulo antes da criação da cobrança de ' . $label . '.',
            '<div class="pagou-settings-grid">'
            . $this->settingDecimal($settings, $prefix . '_min_amount', 'Limite mínimo', $minimumHelp, true)
            . $this->settingDecimal($settings, $prefix . '_max_amount', 'Limite máximo', 'Deixe vazio para não limitar.', true)
            . $this->settingDecimal($settings, $prefix . '_fee_percent', 'Acréscimo percentual', 'Percentual somado ao valor fixo. Zero desativa.', false)
            . $this->settingDecimal($settings, $prefix . '_fee_fixed', 'Acréscimo fixo', 'Valor em reais somado ao percentual. Zero desativa.', false)
            . $this->settingDecimal($settings, $prefix . '_fee_exempt_at', 'Isentar a partir de', 'Deixe vazio para nunca isentar pelo valor da fatura.', true)
            . '</div>'
            . $this->settingToggle($settings, $prefix . '_fee_respect_late_fees', 'Respeitar “Aplicar taxas por atraso”', 'Quando marcado, o acréscimo não é aplicado se essa opção estiver desativada para o cliente no WHMCS.')
        );
    }

    private function configCard(string $title, string $description, string $content): string
    {
        return '<section class="pagou-card pagou-config-card"><div class="pagou-card-title"><div><h2>'
            . Html::e($title) . '</h2><p>' . Html::e($description) . '</p></div></div>' . $content . '</section>';
    }

    private function resetControl(string $section, string $label, string $description, string $csrf): string
    {
        $id = 'pagou-reset-' . $section;

        return '<button class="btn btn-default pagou-button" type="button" data-pagou-reset-open="' . Html::e($id) . '">Restaurar padrões</button>'
            . '<section id="' . Html::e($id) . '" class="pagou-reset-confirmation" hidden aria-live="polite">'
            . '<div><strong>Restaurar ' . Html::e($label) . '?</strong><p>' . Html::e($description)
            . ' Essa ação não remove a credencial Pagou, não ativa ou exibe gateways e não modifica cobranças já emitidas.</p></div>'
            . '<div class="pagou-reset-actions"><form method="post">' . $csrf
            . '<input type="hidden" name="view" value="settings"><input type="hidden" name="action" value="reset-settings">'
            . '<input type="hidden" name="settings_section" value="' . Html::e($section) . '">'
            . '<button class="btn btn-danger pagou-button" type="submit">Confirmar restauração</button></form>'
            . '<button class="btn btn-default pagou-button" type="button" data-pagou-reset-close="' . Html::e($id) . '">Cancelar</button></div>'
            . '</section>';
    }

    /** @param array<string, mixed> $settings */
    private function settingToggle(array $settings, string $key, string $label, string $help): string
    {
        $checked = in_array(strtolower((string) ($settings[$key] ?? '')), ['1', 'on', 'yes', 'true'], true);
        return '<label class="pagou-toggle" for="pagou-' . Html::e($key) . '"><input id="pagou-'
            . Html::e($key) . '" name="' . Html::e($key) . '" type="checkbox" value="1"'
            . ($checked ? ' checked' : '') . '><span><strong>' . Html::e($label) . '</strong><small>'
            . Html::e($help) . '</small></span></label>';
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, string> $options
     */
    private function settingChoice(array $settings, string $key, string $label, array $options, string $help): string
    {
        $selected = (string) ($settings[$key] ?? '');
        $html = '<label class="pagou-setting" for="pagou-' . Html::e($key) . '"><span>' . Html::e($label)
            . '</span><select id="pagou-' . Html::e($key) . '" name="' . Html::e($key) . '">';
        foreach ($options as $value => $optionLabel) {
            $html .= '<option value="' . Html::e($value) . '"' . ($selected === $value ? ' selected' : '') . '>'
                . Html::e($optionLabel) . '</option>';
        }
        return $html . '</select><small>' . Html::e($help) . '</small></label>';
    }

    /** @param array<string, mixed> $settings */
    private function settingNumber(array $settings, string $key, string $label, string $help, int $minimum, int $maximum): string
    {
        return $this->settingField($settings, $key, $label, $help, $minimum, $maximum);
    }

    /**
     * Validity typed as an amount and a unit. The stored seconds stay in the form,
     * so a page rendered before this field still saves the current value.
     * @param array<string, mixed> $settings
     */
    private function pixValidityField(array $settings): string
    {
        $seconds = is_scalar($settings['pix_expiration_seconds'] ?? null) ? (int) $settings['pix_expiration_seconds'] : \Pagou\Whmcs\Configuration\PixValidity::MAXIMUM;
        $shown = \Pagou\Whmcs\Configuration\PixValidity::display($seconds);
        $units = ['minutes' => 'minutos', 'hours' => 'horas', 'days' => 'dias'];
        if ($shown['unit'] === 'seconds') {
            $units = ['seconds' => 'segundos'] + $units;
        }
        $options = '';
        foreach ($units as $unit => $label) {
            $options .= '<option value="' . $unit . '"' . ($unit === $shown['unit'] ? ' selected' : '') . '>' . $label . '</option>';
        }

        return '<label class="pagou-setting pagou-setting--validity" for="pagou-pix_expiration_amount"><span>Validade do Pix imediato</span>'
            . '<input type="hidden" name="pix_expiration_seconds" value="' . $seconds . '">'
            . '<span class="pagou-input-group"><input id="pagou-pix_expiration_amount" name="pix_expiration_amount" type="number" min="1" step="1" value="' . $shown['amount'] . '" inputmode="numeric">'
            . '<select name="pix_expiration_unit" aria-label="Unidade da validade">' . $options . '</select></span>'
            . '<small>Entre 1 minuto e 30 dias. Depois disso, o módulo gera um Pix novo quando o cliente abrir a fatura.</small></label>';
    }

    /** @param array<string, mixed> $settings */
    private function settingDecimal(array $settings, string $key, string $label, string $help, bool $optional): string
    {
        $value = is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';
        return '<label class="pagou-setting" for="pagou-' . Html::e($key) . '"><span>' . Html::e($label)
            . '</span><input id="pagou-' . Html::e($key) . '" name="' . Html::e($key)
            . '" type="number" min="0" step="0.01" value="' . Html::e($value) . '"'
            . ($optional ? ' placeholder="Sem limite"' : '') . '><small>' . Html::e($help) . '</small></label>';
    }

    /** @param array<string, mixed> $settings */
    private function settingText(array $settings, string $key, string $label, string $help, int $maxlength): string
    {
        $value = is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';
        return '<label class="pagou-setting" for="pagou-' . Html::e($key) . '"><span>' . Html::e($label)
            . '</span><input id="pagou-' . Html::e($key) . '" name="' . Html::e($key)
            . '" type="text" maxlength="' . $maxlength . '" value="' . Html::e($value) . '"><small>'
            . Html::e($help) . '</small></label>';
    }

    /** @param array<string, mixed> $settings */
    private function settingTextarea(array $settings, string $key, string $label, string $help): string
    {
        $value = is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';
        return '<label class="pagou-setting pagou-setting--wide" for="pagou-' . Html::e($key) . '"><span>'
            . Html::e($label) . '</span><textarea id="pagou-' . Html::e($key) . '" name="' . Html::e($key)
            . '" maxlength="2000" rows="4">' . Html::e($value) . '</textarea><small>' . Html::e($help) . '</small></label>';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function settingField(
        array $settings,
        string $key,
        string $label,
        string $help,
        int $minimum,
        ?int $maximum = null,
    ): string {
        $value = is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';
        $maximumAttribute = $maximum === null ? '' : ' max="' . $maximum . '"';

        return '<label class="pagou-setting" for="pagou-' . Html::e($key) . '"><span>'
            . Html::e($label) . '</span><input id="pagou-' . Html::e($key) . '" name="'
            . Html::e($key) . '" type="number" min="' . $minimum . '"' . $maximumAttribute
            . ' step="1" value="' . Html::e($value) . '"><small>' . Html::e($help) . '</small></label>';
    }

    /** @param array<string,mixed> $state */
    private function pdfIntegration(array $state, string $csrf): string
    {
        $installed = ($state['installed'] ?? false) === true;
        $available = ($state['available'] ?? false) === true;
        $message = (string) ($state['message'] ?? 'Confira o tema ativo para integrar os PDFs de pagamento.');
        $actions = '';
        if ($available) {
            // Undoing is an occasional maintenance step, not the main action of the card.
            $actions = Page::action(
                $installed ? 'Desfazer integração neste tema' : 'Integrar PDFs ao tema',
                $installed ? 'remove-pdf-template' : 'install-pdf-template',
                'diagnostics',
                $csrf,
                ($state['writable'] ?? false) === true,
                true,
                ['theme' => (string) ($state['theme'] ?? ''), 'template_hash' => (string) ($state['hash'] ?? '')],
                $installed ? 'default' : 'primary',
            );
        }
        $snippet = \Pagou\Whmcs\InvoicePdf\TemplateIntegration::snippet();
        $pdfSetting = $available && ($state['nativeAttachments'] ?? false) !== true
            ? '<p>O WHMCS está com o anexo nativo de faturas desativado. Integrar o tema não altera essa configuração. Ative o anexo de faturas no WHMCS para enviar o PDF gerado pelo tema.</p>' : '';
        return '<section class="pagou-card" id="pagou-pdf-integration"><div class="pagou-card-title"><div>'
            . '<h2>PDFs de Pix e boleto</h2><p>' . Html::e($message) . '</p></div><span class="pagou-badge '
            . ($installed ? 'pagou-badge--success' : 'pagou-badge--warning') . '">' . ($installed ? 'Integrado' : 'Não integrado') . '</span></div>'
            . ($available ? '<p>Tema ativo: <strong>' . Html::e((string) $state['theme']) . '</strong></p>' : '')
            . '<p>Acrescenta somente a chamada da Pagou ao PDF do tema, com cópia de segurança privada. '
            . 'Os outros gateways continuam usando o código existente. Nenhum meio de pagamento será ativado ou trocado.</p>'
            . '<p>Com a integração, o PDF de pagamento disponível substitui o PDF da fatura nos downloads e nos e-mails que já recebem o anexo nativo. '
            . 'A substituição respeita as opções de informações por e-mail e o modo de PDF do boleto. Se o pagamento não estiver pronto ou não puder ser exibido, permanece o PDF atual do tema.</p>'
            . $pdfSetting . '<div class="pagou-actions">' . $actions . '</div>'
            . '<details style="margin-top:16px"><summary>Ver alteração e instruções manuais</summary>'
            . '<p>Faça uma cópia do invoicepdf.tpl do seu tema. Dentro do primeiro bloco PHP, antes da lógica de geração '
            . 'e depois de eventual declare(strict_types=1), acrescente o trecho abaixo uma única vez. '
            . 'Não substitua o arquivo inteiro. Se houver estrutura diferente ou personalizações complexas, solicite revisão técnica.</p>'
            . '<textarea class="pagou-support-report" readonly aria-label="Trecho de integração do PDF" rows="12">'
            . Html::e($snippet) . '</textarea>'
            . '<p>Para desfazer manualmente, remova apenas o bloco entre BEGIN PAGOU INVOICE PDF BRIDGE e END PAGOU INVOICE PDF BRIDGE. '
            . 'Após trocar ou atualizar o tema, confira novamente este diagnóstico. A remoção do módulo não apaga o template.</p></details></section>';
    }

    /** @param array<string, mixed> $data */
    private function diagnostics(array $data, string $csrf): string
    {
        $overall = ($data['overall'] ?? '') === 'ready';
        $checks = is_array($data['checks'] ?? null) ? $data['checks'] : [];
        $onboarding = is_array($data['onboarding'] ?? null) ? $data['onboarding'] : [];
        $report = (string) ($data['supportReport'] ?? 'Relatório indisponível.');
        $run = Page::action('Executar diagnóstico agora', 'run-diagnostics', 'diagnostics', $csrf, true, false);
        $hero = Page::header('Diagnóstico', 'Conexão, processamento e notificações do módulo. A consulta não cria cobranças nem altera pagamentos.', $run);
        $ready = (int) ($data['readyCount'] ?? 0);
        $attention = (int) ($data['attentionCount'] ?? 0);
        $unconfirmed = (int) ($data['unavailableCount'] ?? 0);
        // One verdict first; the counts explain it.
        $summary = '<section class="pagou-card pagou-health-banner' . ($overall ? ' is-ok' : ' is-attention') . '" role="status">'
            . Html::icon($overall ? 'check-circle' : 'alert', 22) . '<div><h2>' . ($overall ? 'Tudo funcionando' : 'Há verificações que pedem atenção') . '</h2>'
            . '<p>' . $ready . ' verificação(ões) pronta(s)' . ($attention > 0 ? ', ' . $attention . ' pedem atenção' : '') . ($unconfirmed > 0 ? ', ' . $unconfirmed . ' sem confirmação' : '')
            . '. Resumo gerado em ' . Html::e((string) ($data['generatedAt'] ?? 'agora')) . '.</p></div></section>';

        $groups = [
            'runtime' => 'Ambiente e horários',
            'storage' => 'Banco e armazenamento',
            'connection' => 'Conexão com a Pagou',
            'processing' => 'Processamento automático',
            'notifications' => 'Notificações',
            'reconciliation' => 'Conciliação',
            'gateways' => 'Meios de pagamento',
        ];
        $grouped = [];
        $technicalChecks = [];
        $technicalAttention = [];
        foreach ($checks as $check) {
            if (is_array($check)) {
                if (in_array($check['group'] ?? '', ['runtime', 'storage'], true)) {
                    if (($check['state'] ?? '') !== 'ready') {
                        $technicalAttention[] = (string) ($check['title'] ?? 'Ambiente da instalação');
                    }
                }
                if (($check['id'] ?? '') === 'webhook') {
                    $technicalChecks[] = $check;
                    $check['title'] = $check['merchantTitle'] ?? $check['title'];
                    $check['detail'] = $check['merchantDetail'] ?? $check['detail'];
                }
                $grouped[(string) ($check['group'] ?? 'runtime')][] = $check;
            }
        }
        $sections = [];
        foreach ($groups as $id => $label) {
            if (!isset($grouped[$id])) {
                continue;
            }
            $sections[$id] = $this->diagnosticSection($label, $grouped[$id]);
        }

        $readiness = '<section class="pagou-card"><div class="pagou-card-title"><div><h2>Prontidão da instalação</h2><p>'
            . (int) ($onboarding['ready'] ?? 0) . ' de ' . (int) ($onboarding['total'] ?? 0)
            . ' etapas concluídas no roteiro de configuração.</p></div><a class="btn btn-default pagou-button" '
            . 'href="addonmodules.php?module=pagou_payments&amp;view=dashboard">Ver roteiro</a></div></section>';
        $support = '<section class="pagou-card"><div class="pagou-card-title"><div><h2>Relatório seguro para suporte</h2>'
            . '<p>Copie este resumo se o atendimento solicitar. Ele não contém credenciais nem dados pessoais ou de pagamento.</p></div>'
            . '<button class="btn btn-default pagou-button" type="button" data-pagou-copy="pagou-support-report">'
            . Html::icon('copy') . 'Copiar relatório</button></div><textarea id="pagou-support-report" class="pagou-support-report" readonly>'
            . Html::e($report) . '</textarea></section>';
        $glossary = '<section class="pagou-card pagou-glossary"><h2>Como interpretar</h2><dl>'
            . '<dt>Pronto</dt><dd>A verificação foi realizada e não há alerta conhecido.</dd>'
            . '<dt>Atenção</dt><dd>Existe uma configuração ou ocorrência que precisa ser revisada.</dd>'
            . '<dt>Sem confirmação</dt><dd>A verificação ainda não tem resultado. Consulte a orientação do item.</dd>'
            . '</dl></section>';

        $sections['readiness'] = $readiness;
        $diagnosticGrid = '<div class="pagou-diagnostic-grid pagou-diagnostic-grid--main">';
        foreach (['connection', 'processing', 'notifications', 'reconciliation', 'gateways', 'readiness'] as $id) {
            $diagnosticGrid .= $sections[$id] ?? '';
        }
        $diagnosticGrid .= '</div>';

        $environmentAlert = $technicalAttention === [] ? '' : '<div class="pagou-notice pagou-notice--warning" role="status">'
            . '<strong>Há verificações do ambiente que precisam de revisão.</strong> ' . Html::e(implode(', ', $technicalAttention))
            . '. Consulte os detalhes para suporte abaixo e, se necessário, encaminhe o relatório à sua hospedagem ou ao suporte Pagou.</div>';
        $cardNotice = ($data['cardInUse'] ?? false) !== true ? '' : '<p class="pagou-help-strip">'
            . 'Para consultar a habilitação da sua conta para receber por cartão, acesse Configurações &gt; Cartão de crédito no '
            . $this->externalLink('https://app.pagou.com.br/configuracoes/cartao', 'aplicativo Pagou') . '. Este diagnóstico verifica somente a instalação e a conexão técnica.</p>';
        $technical = '<details class="pagou-card pagou-diagnostic-details" id="pagou-support-details"><summary>Detalhes para suporte</summary>'
            . '<p>Versões, requisitos do servidor e verificações de segurança para auxiliar o atendimento.</p>'
            . '<div class="pagou-diagnostic-grid">' . ($sections['runtime'] ?? '') . ($sections['storage'] ?? '')
            . ($technicalChecks === [] ? '' : $this->diagnosticSection('Verificação de segurança das notificações', $technicalChecks)) . '</div>'
            . '<div class="pagou-diagnostic-support-grid">' . $support . $glossary . '</div></details>';

        return $hero . $summary . $environmentAlert . $diagnosticGrid . $cardNotice
            . $this->pdfIntegration(is_array($data['pdfIntegration'] ?? null) ? $data['pdfIntegration'] : [], $csrf)
            . $technical;
    }

    /** @param list<array<string, mixed>> $checks */
    private function diagnosticSection(string $title, array $checks): string
    {
        $rows = '';
        foreach ($checks as $check) {
            $state = in_array(($check['state'] ?? ''), ['ready', 'attention', 'unavailable'], true)
                ? (string) $check['state'] : 'unavailable';
            $label = match ($state) {
                'ready' => 'Pronto',
                'attention' => 'Atenção',
                default => 'Sem confirmação',
            };
            // A ready check reads as a ticked line; only what needs attention keeps a button.
            $rows .= '<li class="is-' . Html::e($state) . '"><span class="pagou-diagnostic-state is-' . Html::e($state) . '" title="' . Html::e($label) . '">'
                . Html::icon($state === 'ready' ? 'check' : 'alert', 14) . '<span class="pagou-sr-only">' . Html::e($label) . '</span>'
                . '</span><div><strong>' . Html::e((string) ($check['title'] ?? 'Verificação')) . '</strong><p>'
                . Html::e((string) ($check['detail'] ?? '')) . '</p></div>'
                . '<a class="' . ($state === 'ready' ? 'pagou-link' : 'btn btn-default pagou-button') . '" href="' . Html::e((string) ($check['url'] ?? '#')) . '">'
                . Html::e((string) ($check['action'] ?? 'Abrir')) . '</a></li>';
        }

        return '<section class="pagou-card pagou-diagnostic-section"><div class="pagou-card-title"><h2>'
            . Html::e($title) . '</h2></div><ul class="pagou-diagnostic-list">' . $rows . '</ul></section>';
    }

    /** @param array<string, mixed> $data */
    private function about(array $data): string
    {
        $components = '';
        foreach ((array) ($data['components'] ?? []) as $component) {
            if (is_scalar($component)) {
                $components .= '<li>' . Html::icon('check') . Html::e((string) $component) . '</li>';
            }
        }
        $badges = '<span class="pagou-about-badges">' . Html::badge('Versão ' . (string) ($data['version'] ?? 'Indisponível'), 'info')
            . Html::badge((string) ($data['channel'] ?? 'Desenvolvimento'), 'neutral') . '</span>';
        $hero = Page::header('Pagou para WHMCS', 'Integração oficial para receber e acompanhar pagamentos Pagou no WHMCS.', $badges);
        $installation = '<section class="pagou-card"><div class="pagou-card-title"><div><h2>Esta instalação</h2>'
            . '<p>Informações não sensíveis para confirmar o pacote e sua compatibilidade.</p></div></div><dl class="pagou-about-details">'
            . $this->aboutDetail('Versão', $data['version'] ?? 'Indisponível')
            . $this->aboutDetail('Canal', $data['channel'] ?? 'Indisponível')
            . $this->aboutDetail('Publicador', $data['publisher'] ?? 'Pagou')
            . $this->aboutDetail('Licença', $data['license'] ?? 'MIT')
            . $this->aboutDetail('PHP mínimo', $data['minimumPhp'] ?? '8.1')
            . $this->aboutDetail('PHP atual', $data['currentPhp'] ?? PHP_VERSION)
            . $this->aboutDetail('WHMCS mínimo', $data['minimumWhmcs'] ?? '8.6')
            . $this->aboutDetail('WHMCS atual', $data['currentWhmcs'] ?? 'Indisponível')
            . $this->aboutDetail('Moeda', $data['currency'] ?? 'BRL')
            . $this->aboutDetail('Idioma', $data['language'] ?? 'Português do Brasil')
            . '</dl></section>';
        $resources = '<section class="pagou-card"><div class="pagou-card-title"><div><h2>Recursos oficiais</h2>'
            . '<p>Documentação, atendimento e informações públicas da Pagou.</p></div></div><div class="pagou-actions">'
            . $this->externalLink('https://pagou.com.br', 'Site da Pagou')
            . $this->externalLink('https://docs.pagou.com.br', 'Documentação')
            . $this->externalLink('https://suporte.pagou.com.br', 'Central de suporte')
            . $this->externalLink('mailto:suporte@pagou.com.br', 'Contatar suporte', false)
            . '</div></section>';
        $package = '<section class="pagou-card"><div class="pagou-card-title"><div><h2>Componentes instalados</h2>'
            . '<p>Componentes incluídos no pacote. A instalação não significa que os meios de pagamento estejam ativos para os clientes.</p></div></div><ul class="pagou-component-list">'
            . $components . '</ul></section>';
        $privacy = '<div class="pagou-help-strip"><strong>Privacidade por padrão.</strong> Esta tela não exibe credenciais, dados de clientes, identificadores financeiros, dados de cartão, payloads ou detalhes internos da infraestrutura Pagou.</div>';

        return $hero . '<div class="pagou-about-grid">' . $installation
            . '<div class="pagou-about-stack">' . $resources . $package . '</div></div>' . $privacy;
    }

    private function aboutDetail(string $label, mixed $value): string
    {
        return '<div><dt>' . Html::e($label) . '</dt><dd>' . Html::e($value) . '</dd></div>';
    }

    private function externalLink(string $url, string $label, bool $newTab = true): string
    {
        return '<a class="btn btn-default pagou-button" href="' . Html::e($url) . '"'
            . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
            . Html::icon('external') . Html::e($label) . '</a>';
    }

    /** @param list<array<string, mixed>> $gateways */
    private function gatewaySummary(array $gateways, string $csrf, bool $activationReady, bool $cardReady): string
    {
        $cards = '';
        foreach ($gateways as $gateway) {
            $active = (bool) ($gateway['active'] ?? false);
            $id = (string) ($gateway['id'] ?? '');
            $label = (string) ($gateway['label'] ?? 'Gateway');
            $icon = match ($id) {
                'pagou_pix' => 'qr-code',
                'pagou_boleto' => 'barcode',
                'pagou_creditcard' => 'card',
                default => 'wallet',
            };
            $iconModifier = match ($id) {
                'pagou_pix' => 'pix',
                'pagou_boleto' => 'boleto',
                'pagou_creditcard' => 'card',
                default => 'gateway',
            };
            $assisted = in_array($id, ['pagou_pix', 'pagou_boleto'], true)
                || ($id === 'pagou_creditcard' && $cardReady);
            if ($active) {
                $methodSection = match ($id) {
                    'pagou_pix' => 'pix',
                    'pagou_boleto' => 'boleto',
                    default => 'card',
                };
                $action = '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view=settings&amp;section='
                    . Html::e($methodSection) . '">Configurar opções</a>';
            } elseif (!$assisted) {
                $action = '<button class="btn btn-default pagou-button" type="button" disabled aria-disabled="true">'
                    . 'Aguardando disponibilidade</button>';
            } elseif (!$activationReady) {
                $action = '<button class="btn btn-default pagou-button" type="button" disabled aria-disabled="true">'
                    . 'Concluir preparação</button>';
            } else {
                $action = '<form method="post" class="pagou-inline-form" data-pagou-confirm="Ativar '
                    . Html::e($label) . ' e manter oculto para teste">' . $csrf
                    . '<input type="hidden" name="view" value="settings">'
                    . '<input type="hidden" name="action" value="activate-gateway">'
                    . '<input type="hidden" name="gateway" value="' . Html::e($id) . '">'
                    . '<button class="btn btn-primary pagou-button" type="submit">Ativar '
                    . Html::e($label) . '</button></form>';
            }
            $cards .= '<article class="pagou-gateway-card"><div class="pagou-gateway-card-header">'
                . '<div class="pagou-gateway-title"><span class="pagou-gateway-icon pagou-gateway-icon--'
                . Html::e($iconModifier) . '">' . Html::icon($icon, 18) . '</span><h3>'
                . Html::e((string) ($gateway['label'] ?? 'Gateway')) . '</h3></div>'
                . Html::badge($active ? ((bool) ($gateway['visible'] ?? false) ? 'Ativo e visível' : 'Ativo e oculto') : 'Inativo', $active ? 'success' : 'warning')
                . '</div><p>' . Html::e((string) ($gateway['detail'] ?? '')) . '</p>' . $action . '</article>';
        }

        return '<section id="pagou-gateways" class="pagou-card pagou-anchor"><div class="pagou-card-title"><div>'
            . '<span class="pagou-eyebrow">MEIOS DE PAGAMENTO</span><h2>Estado dos gateways</h2>'
            . '<p>Ative cada meio separadamente. As opções específicas ficam nos submenus desta página.</p></div></div>'
            . '<div class="pagou-gateway-grid">' . $cards . '</div></section>';
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, string> $fields
     * @param array<string, string> $eventTypes
     */
    private function filterForm(string $view, array $filters, array $fields, array $eventTypes = []): string
    {
        $inputs = '';
        foreach ($fields as $name => $label) {
            $value = is_scalar($filters[$name] ?? null) ? (string) $filters[$name] : '';
            $options = match ($name) {
                'type' => $eventTypes,
                'method' => ['' => 'Todos', 'pix' => 'Pix', 'boleto' => 'Boleto', 'card' => 'Cartão de crédito'],
                'period' => ['' => 'Todo o histórico', 'today' => 'Hoje', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', '90d' => 'Últimos 90 dias'],
                'status' => [
                    '' => 'Todas', 'queued' => 'Na fila', 'pending' => 'Pendente',
                    'ready' => 'Aguardando pagamento', 'awaiting_registration' => 'Aguardando registro',
                    'authorized' => 'Autorizado', 'action_required' => 'Requer autenticação',
                    'paid' => 'Pago', 'settled' => 'Liquidado', 'cancelled' => 'Cancelado',
                    'superseded' => 'Substituída', 'refunded' => 'Reembolsado', 'reversed' => 'Revertido',
                    'charged_back' => 'Contestado', 'failed' => 'Falhou', 'uncertain' => 'Aguardando conciliação',
                    'unknown' => 'Estado desconhecido',
                ],
                default => [],
            };
            if ($options !== [] && $value !== '' && !array_key_exists($value, $options)) {
                $options[$value] = $value;
            }
            $inputs .= '<label>' . Html::e($label) . ($options === []
                ? '<input ' . ($name === 'invoice' ? 'type="number" min="1" max="2147483647" step="1" placeholder="Ex.: 12345"' : 'type="text"') . ' name="' . Html::e($name) . '" value="' . Html::e($value) . '">'
                : $this->filterSelect($name, $value, $options)) . '</label>';
        }

        return '<section class="pagou-card pagou-filter-card"><form method="get" class="pagou-filter-form" aria-label="Filtros">'
            . '<input type="hidden" name="module" value="pagou_payments"><input type="hidden" name="view" value="'
            . Html::e($view) . '">' . (($filters['group'] ?? '') === 'all' ? '<input type="hidden" name="group" value="all">' : '') . $inputs
            . '<div class="pagou-actions"><button class="btn btn-primary pagou-button" type="submit">Filtrar</button>'
            . '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view='
            . Html::e($view) . '">Limpar</a></div></form></section>';
    }

    /**
     * One-click status filters over the same validated status parameter.
     * @param array<string, mixed> $filters
     * @param array<string, string> $options
     * @param array<string, int>|null $counts Matching invoices per status, under the other filters.
     */
    private function statusShortcuts(array $filters, array $options, ?array $counts = null): string
    {
        $current = is_scalar($filters['status'] ?? null) ? (string) $filters['status'] : '';
        $html = '<nav class="pagou-chips" aria-label="Filtrar por situação">';
        foreach ($options as $value => $label) {
            $query = ['module' => 'pagou_payments', 'view' => 'payments'];
            foreach (['invoice', 'method', 'group'] as $key) {
                if (is_scalar($filters[$key] ?? null) && trim((string) $filters[$key]) !== '') {
                    $query[$key] = trim((string) $filters[$key]);
                }
            }
            if ($value !== '') {
                $query['status'] = $value;
            }
            $active = $current === $value;
            $count = $counts === null ? null : (int) ($counts[$value] ?? 0);
            $html .= '<a class="pagou-chip' . ($active ? ' is-active' : '') . ($count === 0 && !$active ? ' is-zero' : '') . '" href="addonmodules.php?'
                . Html::e(http_build_query($query)) . '"' . ($active ? ' aria-current="true"' : '') . '>' . Html::e($label)
                . ($count === null ? '' : '<span class="pagou-chip-count">' . $count . '</span>') . '</a>';
        }

        return $html . '</nav>';
    }

    /** @param array<string, mixed> $filters */
    private function pager(string $view, array $filters, bool $hasNext, string $note = ''): string
    {
        $page = is_scalar($filters['page'] ?? null) && preg_match('/^[1-9]\d{0,3}$/', (string) $filters['page']) === 1
            ? (int) $filters['page']
            : 1;
        $links = ($page > 1 ? $this->pageLink($view, $filters, $page - 1, 'Anterior') : '')
            . ($hasNext ? $this->pageLink($view, $filters, $page + 1, 'Próxima') : '');

        return '<div class="pagou-table-footer"><span>' . ($note !== '' ? Html::e($note) . ' ' : '') . 'Página ' . $page
            . ', até 50 registros por página.</span>' . ($links !== '' ? '<nav class="pagou-actions" aria-label="Páginas">' . $links . '</nav>' : '') . '</div>';
    }

    /** @param array<string, mixed> $filters */
    private function operationFilterForm(array $filters): string
    {
        $invoice = is_scalar($filters['invoice'] ?? null) ? (string) $filters['invoice'] : '';
        $type = is_scalar($filters['type'] ?? null) ? (string) $filters['type'] : '';
        $method = is_scalar($filters['method'] ?? null) ? (string) $filters['method'] : '';
        $status = is_scalar($filters['status'] ?? null) ? (string) $filters['status'] : '';
        $period = is_scalar($filters['period'] ?? null) ? (string) $filters['period'] : '';

        return '<section class="pagou-card pagou-filter-card">'
            . '<form method="get" class="pagou-filter-form pagou-filter-form--operations" aria-label="Filtros do histórico">'
            . '<input type="hidden" name="module" value="pagou_payments"><input type="hidden" name="view" value="operations">'
            . '<label>Fatura<input type="number" min="1" max="2147483647" step="1" name="invoice" value="' . Html::e($invoice) . '" placeholder="Ex.: 12345"></label>'
            . '<label>Situação' . $this->filterSelect('status', $status, [
                '' => 'Todas', 'queued' => 'Na fila', 'pending' => 'Pendente', 'leased' => 'Em processamento',
                'retrying' => 'Nova tentativa', 'uncertain' => 'Aguardando conciliação',
                'succeeded' => 'Concluída', 'superseded' => 'Substituída', 'failed' => 'Falhou',
            ]) . '</label>'
            . '<label>Período' . $this->filterSelect('period', $period, [
                '' => 'Todo o histórico', 'today' => 'Hoje', '7d' => 'Últimos 7 dias',
                '30d' => 'Últimos 30 dias', '90d' => 'Últimos 90 dias',
            ]) . '</label>'
            . '<label class="is-secondary">Meio de pagamento' . $this->filterSelect('method', $method, [
                '' => 'Todos', 'pix' => 'Pix', 'boleto' => 'Boleto', 'card' => 'Cartão de crédito',
            ]) . '</label>'
            . '<label class="is-secondary">Tipo de operação' . $this->filterSelect('type', $type, [
                '' => 'Todas', 'issue_pix' => 'Gerar Pix', 'cancel_pix' => 'Cancelar Pix',
                'issue_boleto' => 'Gerar boleto', 'fetch_boleto_pdf' => 'Obter PDF do boleto',
                'deliver_invoice_email' => 'Enviar fatura por e-mail', 'cancel_boleto' => 'Cancelar boleto',
                'replace_boleto' => 'Confirmar cancelamento do boleto', 'reconcile_payment' => 'Conciliar pagamento',
                'reconcile_uncertain_operation' => 'Conciliar operação incerta',
                'card.customer.create' => 'Cadastrar cliente do cartão',
                'card.card.create' => 'Tokenizar cartão', 'card.charge.create' => 'Cobrar cartão',
                'card.charge.capture' => 'Capturar cartão', 'card.charge.reverse' => 'Estornar cartão',
                'card.charge.cancel' => 'Cancelar cartão', 'card.charge.retry' => 'Tentar cartão novamente',
                'card.charge.refund' => 'Reembolsar cartão',
                'card.charge.recurring' => 'Renovação automática no cartão',
            ]) . '</label>'
            . '<div class="pagou-actions"><button class="btn btn-primary pagou-button" type="submit">Filtrar</button>'
            . '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view=operations">Limpar</a>'
            . '</div></form></section>';
    }

    /** @param array<string, string> $options */
    private function filterSelect(string $name, string $selected, array $options): string
    {
        $html = '<select name="' . Html::e($name) . '">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . Html::e($value) . '"' . ($selected === $value ? ' selected' : '') . '>'
                . Html::e($label) . '</option>';
        }

        return $html . '</select>';
    }

    /** @param array<string, mixed> $filters */
    private function pageLink(string $view, array $filters, int $page, string $label): string
    {
        $query = ['module' => 'pagou_payments', 'view' => $view, 'page' => (string) $page];
        foreach (['invoice', 'method', 'status', 'type', 'period', 'group'] as $key) {
            if (is_scalar($filters[$key] ?? null) && trim((string) $filters[$key]) !== '') {
                $query[$key] = trim((string) $filters[$key]);
            }
        }

        return '<a class="btn btn-default pagou-button" href="addonmodules.php?'
            . Html::e(http_build_query($query)) . '">' . Html::e($label) . '</a>';
    }
}
