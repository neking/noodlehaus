#!/bin/bash
cd /var/www/html

echo "🔄 [1/3] Adding latest changes from AWS..."
sudo git add .

echo "💬 [2/3] Committing changes..."
sudo git commit -m "Automated update from AWS Server: $(date)"

echo "🚀 [3/3] Pushing to GitHub..."
sudo git push origin main --force

echo "🎉 Done! AWS files are now updated on GitHub."
