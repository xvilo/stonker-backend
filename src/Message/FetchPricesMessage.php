<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Trigger a refresh of all instrument prices. Dispatched on a schedule and
 * handled asynchronously; also runnable on demand via `app:prices:fetch`.
 */
final class FetchPricesMessage
{
}
