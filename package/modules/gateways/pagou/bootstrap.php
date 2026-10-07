<?php

/**
 * Runtime autoloader for the Pagou WHMCS module.
 *
 * WHMCS installations receive only the modules directory. Composer is used
 * during development and must never be required by the installed module.
 */

declare(strict_types=1);

if (!defined('PAGOU_WHMCS_AUTOLOADER_REGISTERED')) {
    define('PAGOU_WHMCS_AUTOLOADER_REGISTERED', true);

    spl_autoload_register(
        static function (string $class): void {
            $prefixes = [
                'Pagou\\Whmcs\\Vendor\\Fpdi\\' => __DIR__ . '/vendor/fpdi/src/',
                'Pagou\\Whmcs\\' => __DIR__ . '/src/',
                'Pagou\\Payments\\Admin\\' => dirname(__DIR__, 2) . '/addons/pagou_payments/admin/',
            ];

            foreach ($prefixes as $prefix => $baseDirectory) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relativeClass = substr($class, strlen($prefix));
                $file = $baseDirectory . str_replace('\\', '/', $relativeClass) . '.php';

                if (is_file($file)) {
                    require_once $file;
                }

                return;
            }
        },
    );
}

if (!function_exists('pagou_gateway_settings_notice')) {
    /** @return array{FriendlyName: string, Type: string, Description: string} */
    function pagou_gateway_settings_notice(string $section, string $label, string $summary): array
    {
        $url = 'addonmodules.php?module=pagou_payments&amp;view=settings&amp;section=' . rawurlencode($section);
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSummary = htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [
            'FriendlyName' => 'Configurações avançadas',
            'Type' => '',
            'Description' => '<div role="note" style="max-width:760px;padding:12px 14px;border:1px solid #d7dce3;'
                . 'border-left:3px solid #2856d9;border-radius:4px;background:#f8fafc">'
                . '<strong style="display:block;margin-bottom:4px;color:#1f2937">Opções do ' . $safeLabel . '</strong>'
                . '<span style="display:block;color:#647084;line-height:1.45">' . $safeSummary
                . ' ficam centralizados no addon Pagou para WHMCS.</span>'
                . '<a class="btn btn-primary btn-sm" style="margin-top:9px" href="' . $url . '">Configurar '
                . $safeLabel . ' no addon Pagou</a></div>',
        ];
    }
}
