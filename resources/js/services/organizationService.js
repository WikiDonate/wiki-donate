import api from '../config/apiConfig'

/**
 * Donor/editor-facing organization autocomplete.
 *
 * Backed by the admin-curated `organizations` registry only (no external
 * charity API — that is admin-only). `source: local` rows are returned with
 * their registry identity and live verification state.
 */
const searchOrganizations = (query, signal) => {
    return api.get('/organizations/search', { params: { q: query }, signal })
}

export const organizationService = {
    searchOrganizations,
}
