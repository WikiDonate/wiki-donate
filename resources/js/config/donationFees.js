/**
 * Display-only cost figures (nothing here is a recorded fee).
 *
 * `operational_cost_percent` is the donor-facing platform cost shown in the
 * donate modal. It comes from `config/donations.php` via
 * `GET /v1/payment-methods` (`operational_cost_percent`); the constant below is
 * only the offline fallback for the first paint / a registry outage.
 *
 * `payout_fee_estimate_percent` is a pre-send ESTIMATE for the admin Pay modal
 * only. The recorded payout fee is PayPal's actual `payout_item_fee` (the
 * receiver pays it, `fees_payer=RECEIVER`) and is stored on the payout row
 * after the transfer.
 */
export const DEFAULT_OPERATIONAL_COST_PERCENT = 5
export const DEFAULT_PAYOUT_FEE_ESTIMATE_PERCENT = 2

/** Human-readable percentage for the label (5 → 5, 0.1 → 0.1). */
export const OPERATIONAL_COST_PERCENT = DEFAULT_OPERATIONAL_COST_PERCENT

/**
 * Round half-up to cents.
 *
 * @param {number} value
 * @returns {number}
 */
export function roundHalfUp(value) {
    return Math.round((value + Number.EPSILON) * 100) / 100
}

function percentageCost(amount, percent, fallbackPercent) {
    const value = Number(amount)
    const rate = Number(percent ?? fallbackPercent)

    if (!Number.isFinite(value) || value <= 0) {
        return null
    }
    if (!Number.isFinite(rate) || rate < 0) {
        return null
    }

    return Math.min(roundHalfUp((value * rate) / 100), roundHalfUp(value))
}

/**
 * Donor-facing operational cost for the typed amount.
 *
 * Returns `null` for empty/zero/non-numeric input so the caller can hide the
 * row entirely instead of rendering a meaningless `$0.00`.
 *
 * @param {number|string} amount
 * @param {number} [percent]
 * @returns {number|null}
 */
export function calculateOperationalCost(amount, percent = DEFAULT_OPERATIONAL_COST_PERCENT) {
    return percentageCost(amount, percent, DEFAULT_OPERATIONAL_COST_PERCENT)
}

/**
 * Estimated provider fee shown in the admin Pay modal before sending.
 *
 * @param {number|string} amount
 * @param {number} [percent]
 * @returns {number|null}
 */
export function calculatePayoutFeeEstimate(amount, percent = DEFAULT_PAYOUT_FEE_ESTIMATE_PERCENT) {
    return percentageCost(amount, percent, DEFAULT_PAYOUT_FEE_ESTIMATE_PERCENT)
}
