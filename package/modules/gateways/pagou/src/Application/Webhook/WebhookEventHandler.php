<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Webhook;

interface WebhookEventHandler
{
    /**
     * The handler must be idempotent with the supplied delivery key. Unknown
     * events are intentionally delivered so they can be recorded/reconciled.
     */
    public function handle(WebhookEvent $event): void;
}
