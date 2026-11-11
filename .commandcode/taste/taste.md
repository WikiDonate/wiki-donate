# Taste

- When a UI feature/filter is removed, prefers the matching backend/API code removed too — no dead or unused-endpoint code left behind "for safety." Confidence: 0.65
- Payment gateway credentials are owned by the database (`payment_methods` table), never by `.env`/config env vars — don't propose env-based key injection. Confidence: 0.65
- Refers to UI specs visually as screenshot files dropped in `~/Downloads` (given by filename, e.g. `2026-11-04_21-45.png`) and expects them actually opened/read before implementing, not inferred from prose alone. Confidence: 0.55
- Expects test runs to be fully isolated from the dev database — running the suite must never migrate/refresh/reset the local development data (e.g. pin the test connection to in-memory sqlite so shell-exported DB env vars can't redirect it). Confidence: 0.7
