/**
 * Donation fee configuration.
 *
 * Single source of truth for the transaction-cost rate shown in the donation
 * payment modal. Display-only: no backend fee storage or capture logic reads
 * this value. Change the rate here (e.g. 0.001 for 0.1%) and the modal follows.
 */
export const TRANSACTION_FEE_RATE = 0.05

/**
 * Transaction cost for a given donation amount, rounded to cents.
 *
 * @param {number|string} amount Donation amount in USD.
 * @returns {number|null} Cost in USD, or null when the amount is not positive/finite.
 */
export function calculateTransactionCost(amount) {
    const value = Number(amount)
    if (!Number.isFinite(value) || value <= 0) {
        return null
    }
    return Math.round(value * TRANSACTION_FEE_RATE * 100) / 100
}

/**
 * Rate as a percentage number suitable for display (0.05 -> 5).
 */
export const TRANSACTION_FEE_PERCENT = Number((TRANSACTION_FEE_RATE * 100).toFixed(4))
