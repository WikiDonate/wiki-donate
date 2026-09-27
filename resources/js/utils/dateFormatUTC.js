const MONTHS = {
    jan: 0,
    january: 0,
    feb: 1,
    february: 1,
    mar: 2,
    march: 2,
    apr: 3,
    april: 3,
    may: 4,
    jun: 5,
    june: 5,
    jul: 6,
    july: 6,
    aug: 7,
    august: 7,
    sep: 8,
    sept: 8,
    september: 8,
    oct: 9,
    october: 9,
    nov: 10,
    november: 10,
    dec: 11,
    december: 11,
}

/**
 * Parse the datetime shapes this app actually sends into absolute instants:
 * ISO-with-TZ, naive `YYYY-MM-DD HH:MM[:SS]` (= UTC, APP_TIMEZONE=UTC),
 * and written backend forms (`27 September, 2026 [14:30]`).
 * Display converts to the browser's local timezone.
 */
const parseAppDate = (value) => {
    if (value === null || value === undefined) return null
    if (value instanceof Date) return isNaN(value.getTime()) ? null : value
    if (typeof value !== 'string') return null

    const input = value.trim()
    if (!input) return null

    // ISO with explicit timezone/offset — unambiguous, parse directly.
    if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(input)) {
        const parsed = new Date(input)
        return isNaN(parsed.getTime()) ? null : parsed
    }

    // Naive `YYYY-MM-DD HH:MM[:SS]` — backend default, UTC by convention.
    let match = input.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?(\.\d+)?$/)
    if (match) {
        const [, y, mo, d, h, mi, s] = match
        return new Date(Date.UTC(+y, +mo - 1, +d, +h, +mi, +(s || 0)))
    }

    // Written backend forms: `27 September, 2026 [14:30]` / `27 Sep, 2026`.
    match = input.match(/^(\d{1,2})\s+([A-Za-z]+),?\s+(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/)
    if (match) {
        const month = MONTHS[match[2].toLowerCase()]
        if (month !== undefined) {
            return new Date(
                Date.UTC(+match[3], month, +match[1], +(match[4] || 0), +(match[5] || 0)),
            )
        }
    }

    return null
}

const formatUtcDate = (date, long = false) => {
    // Rendered in the browser's local (default) timezone — no timeZone
    // option, so toLocaleString uses the user's own zone. The instants
    // parsed above are absolute, so they convert correctly.
    const options = long
        ? {
              day: '2-digit',
              month: 'long',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          }
        : {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
              hour12: true,
          }

    try {
        return date.toLocaleString('en-US', options)
    } catch {
        return ''
    }
}

/**
 * Project-default datetime display, e.g. "Sep 27, 2026, 6:30 AM".
 * Backend instants (UTC) are shown in the browser's local timezone.
 * Never renders "Invalid Date": empty/unparseable input yields ''.
 */
export const formatDateUTC = (dateString) => {
    const parsed = parseAppDate(dateString)
    if (!parsed) return ''

    return formatUtcDate(parsed, false)
}

export const formatDateUTCLong = (dateString) => {
    const parsed = parseAppDate(dateString)
    if (!parsed) return ''

    return formatUtcDate(parsed, true)
}
