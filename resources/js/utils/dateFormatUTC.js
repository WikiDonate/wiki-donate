export const formatDateUTC = (dateString) => {
    if (!dateString) return ''

    const parsed = Date.parse(dateString + ' UTC')
    if (isNaN(parsed)) return 'Invalid Date'

    const d = new Date(parsed)

    return (
        d.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZone: 'UTC',
        }) + ' (UTC)'
    )
}

export const formatDateUTCLong = (dateString) => {
    if (!dateString) return ''

    const parsed = Date.parse(dateString + ' UTC')
    if (isNaN(parsed)) return 'Invalid Date'

    const d = new Date(parsed)

    return (
        d.toLocaleString('en-US', {
            day: '2-digit',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            timeZone: 'UTC',
        }) + ' (UTC)'
    )
}
