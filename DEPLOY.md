# HireAI Deployment Runbook

## Pull latest code

```bash
cd /var/www/hire
git pull origin main
```

## Required environment file

Create `/var/www/hire/.env` from `.env.example` and fill production values:

```bash
cd /var/www/hire
cp .env.example .env
nano .env
chown root:www-data .env
chmod 640 .env
```

Do not commit `.env`.

## Apply database changes

```bash
cd /var/www/hire
mysql -u root -p < schema.sql
```

## Permissions

```bash
cd /var/www/hire
chown -R www-data:www-data uploads
chmod -R 775 uploads
```

## Restart services

```bash
nginx -t
systemctl reload nginx
systemctl restart php8.2-fpm
```

## Reminder cron

```bash
crontab -e
```

Add:

```cron
*/30 * * * * curl -s "https://hire.clouddialer.in/api/reminders.php?action=send_due" >/dev/null 2>&1
```

## Debug HTTP 500

```bash
tail -n 120 /var/log/nginx/error.log
tail -n 120 /var/log/php8.2-fpm.log
cd /var/www/hire && php -d display_errors=1 index.php
```

Most 500s after this update are caused by missing `.env`, wrong DB credentials, or schema not applied.
