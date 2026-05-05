# Nexus IT

Functional PHP + MySQL login system with an admin-only account creation flow.

## Setup
1. Create a MySQL database named `nexus_it`.
2. Set database and mail credentials with environment variables.
3. Start a PHP server in this folder:

```bash
php -S localhost:8000
```

Then open `http://localhost:8000`.

## Quick Temporary Hosting
If you want a hosting setup that is simple, fairly fast, and can stay up longer, use the Docker stack in `deploy/`:

```bash
cp deploy/.env.docker.example .env
docker compose -f deploy/docker-compose.yml up -d --build
```

- The stack uses `Caddy + PHP-FPM + MySQL`
- Containers use `restart: unless-stopped` so they come back after reboots
- MySQL data is stored in a named Docker volume so the app survives container recreation
- Sessions now default to `SESSION_LIFETIME_SECONDS=604800` (7 days), which helps users stay signed in longer

For a bare temporary host with no domain yet, set `APP_SITE_ADDRESS=:80` in `.env`.
For a real domain with automatic HTTPS, set `APP_SITE_ADDRESS=your-domain.com`.

## Railway Hosting
If you want the app reachable from any device even when your PC is off, deploy it to Railway.

This repository now includes a Railway-ready root `Dockerfile`, `railway.json`, and `health.php`.

Recommended setup:

1. Create a new Railway project
2. Add a `MySQL` service
3. Deploy this repo as a web service
4. Set these app variables on the web service:
   - `DB_HOST=${{MySQL.MYSQLHOST}}`
   - `DB_NAME=${{MySQL.MYSQLDATABASE}}`
   - `DB_USER=${{MySQL.MYSQLUSER}}`
   - `DB_PASS=${{MySQL.MYSQLPASSWORD}}`
   - `RESEND_API_KEY=...` for Railway Free/Hobby/Trial, or use SMTP on Pro
   - `SMTP_HOST=...`
   - `SMTP_PORT=587`
   - `SMTP_USERNAME=...`
   - `SMTP_PASSWORD=...`
   - `SMTP_ENCRYPTION=tls`
   - `MAIL_FROM_EMAIL=...`
   - `MAIL_FROM_NAME=Nexus IT`
   - `ADMIN_RESET_SECRET=change_this_to_a_long_random_secret`
   - `AUTO_SCHEMA_MIGRATE=0`
   - `ENABLE_ADMIN_BOOTSTRAP=0`
   - `SESSION_SECURE_COOKIE=1`
   - `SESSION_LIFETIME_SECONDS=604800`
5. Add a Railway volume to the web service at `/var/www/html/uploads`
6. Add a second Railway volume to the web service at `/var/www/html/data`
7. In `Networking`, click `Generate Domain`

After that, Railway gives you a public `*.up.railway.app` link.

If you use Railway on a non-Pro plan, prefer Resend's HTTPS API instead of SMTP. Railway's outbound networking docs state SMTP is only available on Pro and above.

## Production Notes
- Do not store real passwords or SMTP secrets in `config.php`.
- For HTTPS deployments, set `SESSION_SECURE_COOKIE=1`.
- Adjust `SESSION_LIFETIME_SECONDS` if you want shorter or longer login sessions.
- `AUTO_SCHEMA_MIGRATE=1` is convenient for local development. After the database is ready, set `AUTO_SCHEMA_MIGRATE=0` in production.
- Admin bootstrap is disabled by default. If you need a one-time automatic admin creation on a fresh database, set `ENABLE_ADMIN_BOOTSTRAP=1` together with `ADMIN_STAFF_ID`, `ADMIN_PASSWORD_PLAIN`, and `ADMIN_FULL_NAME`, then turn it back off after the first admin exists.
- Production deployment files are included in `deploy/`.
- Use [.env.production.example](/home/project_101/Desktop/Nexusss/.env.production.example:1) for the required environment variables.
- Use [deploy/.env.docker.example](/home/project_101/Desktop/Nexusss/deploy/.env.docker.example:1) for the Docker quick-host path.
- See [DEPLOYMENT_CHECKLIST.md](/home/project_101/Desktop/Nexusss/deploy/DEPLOYMENT_CHECKLIST.md:1) before going live.
