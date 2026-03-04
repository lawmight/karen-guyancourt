# Deploy on Vercel

This project runs entirely on Vercel (no PHP needed).

## Setup

1. **Install and run locally**
   ```bash
   npm install
   cp .env.local.example .env.local
   # Edit .env.local with your keys (you can copy values from legacy .local.env)
   npm run dev
   ```
   Open **http://localhost:3000?key=YOUR_KEY** (must match `KEY_TO_ACCESS_THE_SCRIPT` exactly).
   If `key` is missing/invalid, the app returns a 404 page (same behavior as `council.php`).

2. **Deploy to Vercel**
   - Push the repo to GitHub (or connect your repo in Vercel).
   - In [Vercel](https://vercel.com): **New Project** → import this repo.
   - In **Settings → Environment Variables**, add all variables from `.env.local` (see `.env.local.example`).
   - Deploy. Your app will be at **https://your-project.vercel.app**.
   - **First visit:** open **https://your-project.vercel.app?key=YOUR_KEY** once. The app sets an httpOnly cookie and redirects to `/`.
   - **Next visits:** you can open **https://your-project.vercel.app** (no key in the URL); the cookie loads the app automatically.

## What's included

- **One page**: form + map (Leaflet), same behaviour as the PHP version.
- **API routes** (protected by the same key):
  - `POST /api/expand` — OpenRouter to turn your report into a formal letter (FR + EN, separated with `===ENGLISH===`).
  - `POST /api/send` — Postmark to send the email (with optional image attachments, resized with Sharp).

## Required environment variables

All of these are required in `.env.local` for local dev and in Vercel environment settings, except `CC_EMAILS` (optional):

- `KEY_TO_ACCESS_THE_SCRIPT` (used by `/?key=...` for first-time auth; then stored in an httpOnly cookie so `/` loads without the key. API routes accept the key via cookie, header `x-access-key`, or query.)
- `OPENROUTER_API_KEY` (used by `/api/expand`)
- `POSTMARK_API_KEY` (used by `/api/send`)
- `FROM_YOUR_EMAIL`
- `TO_COUNCIL_EMAIL`
- `CC_EMAILS` (optional)
- `YOUR_NAME` (signature used by `/api/expand`)

You can remove `council.php` and keep only this Next.js app if you want the project to consist only of this.
