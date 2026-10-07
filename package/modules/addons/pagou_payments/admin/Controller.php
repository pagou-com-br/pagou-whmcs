<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin;

use Pagou\Payments\Admin\Http\AdminRequest;
use Pagou\Payments\Admin\Security\Authorization;
use Pagou\Payments\Admin\Security\Csrf;
use Pagou\Payments\Admin\View\Layout;
use Pagou\Payments\Admin\View\Pages;
use Pagou\Whmcs\Configuration\CentralSettingsStore;

/**
 * Presentation controller. Services are injected by the addon entrypoint once
 * persistence and queue adapters are available; empty data is safe by default.
 */
final class Controller
{
    /** @var callable(string, array<string, string>): array{notice?:string,noticeTone?:string,data?:array<string,mixed>}|null */
    private $command;
    /** @var callable(string): array<string,mixed>|null */
    private $dataProvider;
    /** @var callable(): array<string,int>|null */
    private $navigation;
    private bool $redirectAfterPost;

    /**
     * @param callable(string, array<string, string>): array{notice?:string,noticeTone?:string,data?:array<string,mixed>}|null $command
     * @param callable(string): array<string,mixed>|null $dataProvider
     * @param callable(): array<string,int>|null $navigation Counts displayed beside the tabs.
     */
    public function __construct(?callable $command = null, ?callable $dataProvider = null, bool $redirectAfterPost = false, ?callable $navigation = null)
    {
        $this->redirectAfterPost = $redirectAfterPost;
        $this->command = $command;
        $this->dataProvider = $dataProvider;
        $this->navigation = $navigation;
    }

    public function handle(?AdminRequest $request = null): string
    {
        $request ??= AdminRequest::fromGlobals();
        $page = $request->page();
        $csrf = new Csrf();
        $context = ['page' => $page, 'title' => $this->title($page), 'searchTerm' => $page === 'search' ? (string) $request->queryString('q') : ''];
        $flashKey = $request->queryString('result') ?? '';
        if (!$request->isPost() && preg_match('/^[a-f0-9]{24}$/D', $flashKey) === 1 && isset($_SESSION['pagou_notices'][$flashKey])) {
            $context = array_replace($context, $_SESSION['pagou_notices'][$flashKey]);
            unset($_SESSION['pagou_notices'][$flashKey]);
        }
        $section = $request->postString('settings_section') ?? $request->queryString('section') ?? 'general';
        $settingsSection = in_array($section, CentralSettingsStore::sections(), true) ? $section : 'general';
        // Page data is read once, and only when this response renders the page.
        $data = null;

        if ($request->isPost()) {
            $action = null;
            try {
                (new Authorization())->assertCanOperate();
                $csrf->assertValid($request->postString('token'));
                $action = $request->action();
                if ($action === null) {
                    throw new \RuntimeException('Ação administrativa inválida.');
                }
                $result = $this->command === null
                    ? ['notice' => 'Ação recebida. O serviço operacional ainda será conectado.', 'noticeTone' => 'info']
                    : ($this->command)($action, $this->input($request, $action));
                $context['notice'] = $result['notice'] ?? 'Ação concluída.';
                $context['noticeTone'] = $result['noticeTone'] ?? 'success';
                if ($this->redirectAfterPost) {
                    $key = bin2hex(random_bytes(12));
                    $_SESSION['pagou_notices'][$key] = ['notice' => $context['notice'], 'noticeTone' => $context['noticeTone']];
                    $_SESSION['pagou_notices'] = array_slice($_SESSION['pagou_notices'], -10, null, true);
                    $query = ['module' => 'pagou_payments', 'view' => $page, 'result' => $key];
                    foreach (['invoice','method','status','type','period','page','from','to','client','report','q','document','party','group'] as $filter) {
                        $value = $request->queryString($filter);
                        if ($value !== null && strlen($value) <= 64) {
                            $query[$filter] = $value;
                        }
                    }
                    if ($page === 'settings') {
                        $query['section'] = $settingsSection;
                    }
                    $context['redirect'] = 'addonmodules.php?' . http_build_query($query);
                    if (!headers_sent()) {
                        header('Location: ' . $context['redirect'], true, 303);
                    }
                    // The browser follows the redirect; reading and rendering the page here is wasted work.
                    return (new Layout())->render('', $context);
                }
                // Command responses contain complete top-level projections. A
                // recursive merge could preserve stale rows when a refreshed
                // list becomes shorter after an administrative operation.
                $data = array_replace($this->dataProvider === null ? [] : ($this->dataProvider)($page), $result['data'] ?? []);
            } catch (\Throwable $exception) {
                if (function_exists('logActivity')) {
                    logActivity('Pagou Payments: ação administrativa recusada. ' . $exception::class);
                }
                $context['notice'] = in_array($action, ['save-settings', 'reset-settings'], true)
                    && $exception instanceof \InvalidArgumentException
                    ? $exception->getMessage()
                    : 'Não foi possível concluir a ação. Consulte o diagnóstico e o log administrativo.';
                $context['noticeTone'] = 'danger';
            }
        }

        $data ??= $this->dataProvider === null ? [] : ($this->dataProvider)($page);
        if ($page === 'settings') {
            $data['settingsSection'] = $settingsSection;
        }
        if ($this->navigation !== null) {
            try {
                // Read after any command, so a resolved finding leaves the tab count at once.
                $context['badges'] = ($this->navigation)();
            } catch (\Throwable) {
                $context['badges'] = [];
            }
        }
        $content = (new Pages())->render($page, $data, $csrf->field());
        return (new Layout())->render($content, $context);
    }

    /** @return array<string, string> */
    private function input(AdminRequest $request, string $action): array
    {
        if (in_array($action, ['install-pdf-template', 'remove-pdf-template'], true)) {
            return ['theme' => $request->postString('theme') ?? '', 'template_hash' => $request->postString('template_hash') ?? ''];
        }
        $credential = $request->postString('credential');
        $input = $credential === null ? [] : ['credential' => $credential];
        if ($action === 'validate-credential') {
            $input['replace_credential'] = $request->postString('replace_credential') === null ? '0' : '1';
        }
        $invoiceId = $request->postInt('invoice_id');
        if ($invoiceId !== null) {
            $input['invoice_id'] = (string) $invoiceId;
        }
        $reason = $request->postString('reason');
        if ($reason !== null) {
            $input['reason'] = $reason;
        }
        $gateway = $request->postString('gateway');
        if ($gateway !== null) {
            $input['gateway'] = $gateway;
        }
        $chargeId = $request->postString('charge_id');
        if ($chargeId !== null) {
            $input['charge_id'] = $chargeId;
        }
        if ($action === 'save-settings') {
            $section = $request->postString('settings_section') ?? 'general';
            $input['settings_section'] = in_array($section, CentralSettingsStore::sections(), true) ? $section : 'general';
            foreach (CentralSettingsStore::keysForSection($input['settings_section']) as $key) {
                $input[$key] = $request->postString($key) ?? '';
            }
            // The Pix validity is typed as an amount and a unit; only its seconds are stored.
            $amount = $request->postString('pix_expiration_amount');
            if ($input['settings_section'] === 'pix' && $amount !== null) {
                $input['pix_expiration_seconds'] = \Pagou\Whmcs\Configuration\PixValidity::seconds($amount, $request->postString('pix_expiration_unit') ?? '');
            }
        }
        if ($action === 'reset-settings') {
            $section = $request->postString('settings_section');
            if ($section === null || !in_array($section, CentralSettingsStore::sections(), true)) {
                throw new \InvalidArgumentException('A seção de configurações informada é inválida.');
            }
            $input = ['settings_section' => $section];
        }

        return $input;
    }

    private function title(string $page): string
    {
        return match ($page) {
            'reports' => 'Relatórios', 'charge' => 'Detalhe da fatura', 'search' => 'Busca', 'payments' => 'Pagamentos', 'operations' => 'Operações', 'webhooks' => 'Notificações',
            'reconciliation' => 'Conciliação', 'findings' => 'Pendências', 'card' => 'Cartão de crédito',
            'settings' => 'Configurações', 'diagnostics' => 'Diagnóstico', 'about' => 'Sobre', default => 'Visão geral',
        };
    }
}
