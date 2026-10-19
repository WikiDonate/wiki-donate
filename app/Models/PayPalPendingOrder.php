<?php

namespace App\Models;

/**
 * @deprecated Phase 3 of the pluggable payment-method refactor renamed this to
 * App\Models\PaymentPendingOrder (table `payment_pending_orders`). Kept as a
 * thin subclass for one migration window so external code / tooling that
 * references the old class name keeps working; new code must use
 * PaymentPendingOrder.
 */
class PayPalPendingOrder extends PaymentPendingOrder
{
}
