<?php

namespace App\Observers;

use App\Models\PaymentMethod;
use App\Models\TransactionLog;
use Illuminate\Support\Facades\Auth;

/**
 * Audit trail for the payment-method registry.
 *
 * `payment_methods` is the single source of truth for which gateways can take
 * money in / pay money out, so every registry change (enabling a method,
 * switching mode, editing currencies) must be attributable. Credential values
 * are never recorded — only the fact that they changed.
 */
class PaymentMethodObserver
{
    private const AUDITED = ['label', 'type', 'active', 'config', 'sort_order'];

    public function created(PaymentMethod $method): void
    {
        $this->write('payment_method.created', $method, [], $this->snapshot($method), sprintf(
            'Payment method %s (%s) registered',
            $method->code,
            $method->label
        ));
    }

    public function updated(PaymentMethod $method): void
    {
        $before = [];
        $after = [];

        foreach (self::AUDITED as $attribute) {
            if (! $method->wasChanged($attribute)) {
                continue;
            }

            $before[$attribute] = $this->sanitise($method->getOriginal($attribute));
            $after[$attribute] = $this->sanitise($method->getAttribute($attribute));
        }

        // Credential rotations are deliberately NOT audited here: they are
        // secret material, and recording even a "changed" marker would put a
        // permanent row in the admin feed for every key rotation.
        if ($before === []) {
            return;
        }

        $this->write(
            'payment_method.updated',
            $method,
            $before,
            $after,
            sprintf('Payment method %s updated', $method->code)
        );
    }

    public function deleted(PaymentMethod $method): void
    {
        $this->write('payment_method.deleted', $method, $this->snapshot($method), [], sprintf(
            'Payment method %s removed',
            $method->code
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PaymentMethod $method): array
    {
        return $this->sanitise($method->only(self::AUDITED));
    }

    /**
     * Belt-and-braces: `config` is documented as non-secret, but redact
     * anything that looks like a credential rather than trust the convention.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function sanitise($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            if (preg_match('/(secret|password|token|private[_-]?key|client[_-]?secret)/i', (string) $key)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = $this->sanitise($item);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function write(string $event, PaymentMethod $method, array $before, array $after, string $message): void
    {
        $user = Auth::user();

        TransactionLog::record($event, [
            'subject_type' => PaymentMethod::class,
            'subject_id' => $method->id,
            'actor_id' => $user?->id,
            'actor_role' => $user?->getRoleNames()->first(),
            'message' => $message,
            'before' => $before,
            'after' => $after,
            'meta' => ['payment_method' => $method->code],
        ]);
    }
}
