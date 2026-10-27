/**
 * Display-only transaction-cost figures for the donor payment modal.
 *
 * The rate lives here and nowhere else: changing 5% to another value is a
 * one-line edit of `TRANSACTION_FEE_RATE`. Nothing is sent to the server — the
 * backend capture/payout logic is untouched and no fee is stored anywhere.
 */
export const TRANSACTION_FEE_RATE = 0.05

/** Human-readable percentage for the label (0.05 → 5, 0.001 → 0.1). */
export const TRANSACTION_FEE_PERCENT = Number((TRANSACTION_FEE_RATE * 100).toFixed(4))

/**
 * Cost shown under the amount field, rounded to cents.
 *
 * Returns `null` for empty/zero/non-numeric input so the caller can hide the
 * row entirely instead of rendering a meaningless `$0.00`.
 *
 * @param {number|string} amount Donation amount as typed in the modal.
 * @returns {number|null}
 */
export function calculateTransactionCost(amount) {
    const value = Number(amount)

    if (!Number.isFinite(value) || value <= 0) {
        return null
    }

    return Math.round(value * TRANSACTION_FEE_RATE * 100) / 100
}
