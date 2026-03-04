# AGENTS.md

## Cursor Cloud specific instructions

This is a **Next.js 16** (App Router, TypeScript, React 19) single-page civic-complaint tool ("Karen Bot — Guyancourt"). No database, Docker, or auxiliary local services are needed.

### Quick reference

| Action | Command |
|--------|---------|
| Install deps | `npm install` |
| Dev server | `npm run dev` (Turbopack, port 3000) |
| Production build | `npm run build` |
| Lint | `npm run lint` — **non-functional** on Next.js 16 (see note below) |

### Key caveats

- **`next lint` was removed in Next.js 16.** The `lint` script in `package.json` (`next lint`) exits with an error. ESLint is not installed as a dependency and there is no `.eslintrc` or `eslint.config.*`. If linting is needed, install ESLint and configure it separately.
- **Auth is required to view the main page.** The middleware redirects unauthenticated visitors to `/login`. To access the app, either:
  - Visit `http://localhost:3000?key=<KEY_TO_ACCESS_THE_SCRIPT>` (sets an httpOnly cookie, then redirects), or
  - Use the `/login` form to enter the key.
- **External API keys (OpenRouter, Postmark) are required for full functionality** (letter generation and email sending). Without valid keys the UI works but API calls fail gracefully with an alert. For dev/test, dummy values in `.env.local` are sufficient to start the server and interact with the map/form.
- **Environment variables** are documented in `.env.local.example`. Copy it to `.env.local` and fill in values. At minimum, `KEY_TO_ACCESS_THE_SCRIPT` must be set for the app to allow access.
