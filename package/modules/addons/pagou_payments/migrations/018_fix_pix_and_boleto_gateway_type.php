<?php

declare(strict_types=1);

use Pagou\Whmcs\Infrastructure\Persistence\GatewayTypeMigration;

return new GatewayTypeMigration('018', 'Stop the daily WHMCS card charge task on Pix and boleto invoices', [
    'pagou_pix',
    'pagou_boleto',
]);
