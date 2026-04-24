#!/bin/bash
cd /var/www/hire || { echo "Directory not found!"; exit 1; }

echo "🔄 Staging changes..."
git add -A

if git diff --cached --quiet; then
    echo "✅ Nothing to commit."
else
    git commit -m "Deploy: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "🚀 Pushing to main..."
    git push origin main
fi

echo "✅ Deploy completed successfully!"
