# Nexus IT Deployment Checklist

## 1. Server Requirements
- PHP 8.1+ with `pdo_mysql`, `fileinfo`, and `openssl`
- MySQL or MariaDB
- Apache or Nginx
- HTTPS certificate

## 2. Upload Project
- Copy the project to the server, for example: `/var/www/nexusss`
- Ensure the web server user can read the app files
- If you want the simplest long-running temporary host, you can skip manual Apache/Nginx setup and use `docker compose -f deploy/docker-compose.yml up -d --build`

## 3. Database Setup
- Create the database:

```sql
CREATE DATABASE IF NOT EXISTS nexus_it CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

- Create a database user with a strong password
- Grant that user access only to `nexus_it`

## 4. Environment Variables
- Use [.env.production.example](/home/project_101/Desktop/Nexusss/.env.production.example:1) as your reference
- For Docker quick hosting, start from [deploy/.env.docker.example](/home/project_101/Desktop/Nexusss/deploy/.env.docker.example:1)
- Set at minimum:
  - `DB_HOST`
  - `DB_NAME`
  - `DB_USER`
  - `DB_PASS`
  - `SMTP_HOST`
  - `SMTP_PORT`
  - `SMTP_USERNAME`
  - `SMTP_PASSWORD`
  - `SMTP_ENCRYPTION`
  - `MAIL_FROM_EMAIL`
  - `MAIL_FROM_NAME`
  - `AUTO_SCHEMA_MIGRATE=0`
  - `ENABLE_ADMIN_BOOTSTRAP=0`
  - `SESSION_SECURE_COOKIE=1`
  - `SESSION_LIFETIME_SECONDS=604800`
  - `APP_SITE_ADDRESS=:80` for temporary HTTP hosting, or `APP_SITE_ADDRESS=your-domain.com` for automatic HTTPS with Caddy

## 5. First-Time Admin Setup
- Preferred: create the first admin directly in the database
- Alternative: temporarily set:
  - `ENABLE_ADMIN_BOOTSTRAP=1`
  - `ADMIN_STAFF_ID=your_admin_id`
  - `ADMIN_PASSWORD_PLAIN=your_strong_password`
  - `ADMIN_FULL_NAME=Your Name`
- Open the app once, confirm the admin is created, then set `ENABLE_ADMIN_BOOTSTRAP=0`

## 6. Web Server Setup
- Apache: use [apache-vhost.conf](/home/project_101/Desktop/Nexusss/deploy/apache-vhost.conf:1)
- Nginx: use [nginx-site.conf](/home/project_101/Desktop/Nexusss/deploy/nginx-site.conf:1)
- Docker quick host: use [docker-compose.yml](/home/project_101/Desktop/Nexusss/deploy/docker-compose.yml:1), [Dockerfile](/home/project_101/Desktop/Nexusss/deploy/Dockerfile:1), [Caddyfile](/home/project_101/Desktop/Nexusss/deploy/Caddyfile:1), and [php.ini](/home/project_101/Desktop/Nexusss/deploy/php.ini:1)
- Replace:
  - domain names
  - file paths
  - certificate paths
  - DB and SMTP values

## 7. File Permissions
- Keep project files readable by the web server
- Allow write access only where needed
- Recommended writable paths:
  - `uploads/`
  - `data/`

## 8. Production Safety Checks
- Confirm HTTPS is working
- Confirm direct access to `/data/` is blocked
- Confirm direct access to `/uploads/` is blocked
- Confirm attachment downloads only work after login
- Confirm `SESSION_SECURE_COOKIE=1`

## 9. Production Test Flow
- Admin login
- Create employee account
- Employee first login
- Email verification OTP
- Complaint submission
- Attachment download
- IT complaint acceptance and completion
- Password reset flow

## 10. Go-Live Notes
- Keep backups of the database
- Rotate passwords if test credentials were used at any point
- Monitor web server and PHP logs after launch
- If you use Docker, confirm the named database volume exists and keep your `.env` file backed up with the server
