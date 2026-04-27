#!/bin/bash
cd /var/www/hire
git add -A
git commit -m "Deploy: $(date '+%Y-%m-%d %H:%M')"
git push origin main
echo "Done."
