/**
 * Transaction-cost figures for the donor payment modal.
 *
 * The rate is owned by the SERVER: `config/donations.php` →
 * `donations.transaction_cost_percent`, surfaced by
 * `GET /v1/payment-methods` as `transaction_cost_percent`. The constant below
 * is only the offline fallback for the first paint / a registry outage, kept
 * identical to the config default so no UI ever shows a different rate.
 *
 * The same server rate drives the enforced payout deduction
 * (OrganizationPayoutService), so donor-facing and payout-facing math can
 * never drift apart.
 */
export const DEFAULT_TRANSACTION_FEE_PERCENT = 5

/** Fallback rate as a fraction (0.05) for legacy import paths. */
export const TRANSACTION_FEE_RATE = DEFAULT_TRANSACTION_FEE_PERCENT / 100

/** Human-readable percentage for the label (5 → 5, 0.1 → 0.1). */
export const TRANSACTION_FEE_PERCENT = DEFAULT_TRANSACTION_FEE_PERCENT

/**
 * Round half-up to cents, matching OrganizationPayoutService::splitTransactionCost.
 *
 * @param {number} value
 * @returns {number}
 */
export function roundHalfUp(value) {
    return Math.round((value + Number.EPSILON) * 100) / 100
}

/**
 * Cost shown under the amount field, rounded to cents.
 *
 * Returns `null` for empty/zero/non-numeric input so the caller can hide the
 * row entirely instead of rendering a meaningless `$0.00`.
 *
 * @param {number|string} amount Donation amount as typed in the modal.
 * @param {number} [percent] Rate in percent; defaults to the config fallback.
 * @returns {number|null}
 */
export function calculateTransactionCost(amount, percent = DEFAULT_TRANSACTION_FEE_PERCENT) {
    const value = Number(amount)
    const rate = Number(percent)

    if (!Number.isFinite(value) || value <= 0) {
        return null
    }
    if (!Number.isFinite(rate) || rate < 0) {
        return null
    }

    return Math.min(roundHalfUp((value * rate) / 100), roundHalfUp(value))
}
