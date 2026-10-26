/**
 * Return `candidate` only when it is a same-site absolute path.
 *
 * A plain `startsWith('/')` check is not enough: browsers interpret `//evil.test`
 * as protocol-relative and `/\evil.test` as an origin escape, which would turn a
 * `?back=` query param into an open redirect.
 */
export function safeInternalPath(candidate, fallback = '/') {
    if (typeof candidate !== 'string' || !candidate.startsWith('/')) return fallback
    if (candidate.startsWith('//') || candidate.includes('\\')) return fallback

    return candidate
}
