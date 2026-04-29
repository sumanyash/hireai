#!/bin/bash
# Debug: WA message not sending + resume upload issue
echo "=== 1. WhatsApp config ==="
grep -n "send_whatsapp\|WA_\|WHATSAPP\|whatsapp" /var/www/hire/includes/config.php | head -20
grep -n "send_whatsapp\|wa_url\|wa_token\|wa_api" /var/www/hire/includes/functions.php | head -20

echo ""
echo "=== 2. send_whatsapp function ==="
grep -A 30 "function send_whatsapp" /var/www/hire/includes/functions.php

echo ""
echo "=== 3. Resume upload dir permissions ==="
ls -la /var/www/hire/uploads/ 2>/dev/null || echo "uploads/ dir missing!"
ls -la /var/www/hire/uploads/resumes/ 2>/dev/null || echo "resumes/ subdir missing!"

echo ""
echo "=== 4. PHP upload limits ==="
php -r "echo 'upload_max_filesize: '.ini_get('upload_max_filesize').PHP_EOL; echo 'post_max_size: '.ini_get('post_max_size').PHP_EOL; echo 'memory_limit: '.ini_get('memory_limit').PHP_EOL; echo 'max_execution_time: '.ini_get('max_execution_time').PHP_EOL;"

echo ""
echo "=== 5. Last error in apply API ==="
tail -30 /var/log/php*.log 2>/dev/null || tail -30 /var/log/apache2/error.log 2>/dev/null || tail -30 /var/log/nginx/error.log 2>/dev/null

echo ""
echo "=== 6. api/apply.php - resume handling ==="
grep -n "resume\|base64\|file_put_contents\|uploads" /var/www/hire/api/apply.php | head -20

echo ""
echo "=== 7. Last candidate in DB (to check what was saved) ==="
mysql -u$(grep DB_USER /var/www/hire/includes/config.php | grep -oP "'[^']+'" | tail -1 | tr -d "'") \
      -p$(grep DB_PASS /var/www/hire/includes/config.php | grep -oP "'[^']+'" | tail -1 | tr -d "'") \
      -D$(grep DB_NAME /var/www/hire/includes/config.php | grep -oP "'[^']+'" | tail -1 | tr -d "'") \
      -e "SELECT id, name, email, resume_path, status, created_at FROM candidates ORDER BY id DESC LIMIT 3\G" 2>/dev/null

echo ""
echo "=== 8. WA outreach log ==="
mysql -u$(grep DB_USER /var/www/hire/includes/config.php | grep -oP "'[^']+'" | tail -1 | tr -d "'") \
      -p$(grep DB_PASS /var/www/hire/includes/config.php | grep -oP "'[^']+'" | tail -1 | tr -d "'") \
      -D$(grep DB_NAME /var/www/hire/includes/config.php | grep -oP "'[^']+'" | tail -1 | tr -d "'") \
      -e "SELECT * FROM outreach_log ORDER BY id DESC LIMIT 5\G" 2>/dev/null
