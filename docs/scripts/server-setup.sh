#!/bin/bash
# server-setup.sh — NoodleHaus new server one-command setup
# Run on NEW server: bash server-setup.sh
# Tested on Ubuntu 22.04/24.04

set -e
echo "============================================"
echo "NoodleHaus Server Setup"
echo "============================================"

DB_PASS=${1:-"GGttgg123!"}
DOMAIN=${2:-""}
REPO="https://github.com/neking/noodlehaus.git"

# Step 1: System packages
echo "[1/7] Installing packages..."
sudo apt-get update -qq
sudo apt-get install -y -qq \
  nginx php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip mysql-server certbot python3-certbot-nginx \
  git unzip curl 2>/dev/null

# Step 2: MySQL setup
echo "[2/7] Configuring MySQL..."
sudo systemctl start mysql
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';" 2>/dev/null || true
sudo mysql -uroot -p${DB_PASS} -e "CREATE DATABASE IF NOT EXISTS noodlehaus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null

# Step 3: Deploy app
echo "[3/7] Deploying application..."
sudo rm -rf /var/www/html
sudo git clone $REPO /var/www/html
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html

# Step 4: .env file
echo "[4/7] Creating .env..."
sudo bash -c "cat > /var/www/html/.env << ENVEOF
DB_HOST=localhost
DB_PORT=3306
DB_NAME=noodlehaus
DB_USER=root
DB_PASS=${DB_PASS}
DB_CHARSET=utf8mb4
ENVEOF"
sudo chown www-data:www-data /var/www/html/.env
sudo chmod 640 /var/www/html/.env

# Step 5: Nginx config
echo "[5/7] Configuring Nginx..."
sudo bash -c "cat > /etc/nginx/sites-available/noodlehaus << NGEOF
server {
    listen 80;
    server_name ${DOMAIN:-_};
    root /var/www/html;
    index index.php index.html;
    client_max_body_size 20M;

    location ~* \\.(bak|sql|log|env|git)\$ { deny all; return 404; }
    location / { try_files \\\$uri \\\$uri/ =404; }
    location ~ \\.php\$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \\\$document_root\\\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 3600;
        fastcgi_buffering off;
    }
}
NGEOF"
sudo ln -sf /etc/nginx/sites-available/noodlehaus /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl restart nginx

# Step 6: Create uploads dir
echo "[6/7] Setting up directories..."
sudo mkdir -p /var/www/html/uploads/menu
sudo mkdir -p /var/backups/noodlehaus/tenants
sudo chown -R www-data:www-data /var/www/html/uploads
sudo chmod -R 775 /var/www/html/uploads

# Step 7: SSL (if domain provided)
echo "[7/7] SSL Certificate..."
if [ -n "$DOMAIN" ]; then
  sudo certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m admin@${DOMAIN} 2>/dev/null
  echo "SSL configured for $DOMAIN"
else
  echo "No domain provided - skipping SSL (run: certbot --nginx -d yourdomain.com)"
fi

# Copy backup script
sudo cp /var/www/html/backup-tenant.sh /usr/local/bin/ 2>/dev/null || true
sudo chmod +x /usr/local/bin/backup-tenant.sh 2>/dev/null || true

# Setup daily backup cron
(sudo crontab -l 2>/dev/null; echo "0 2 * * * /usr/local/bin/noodlehaus-backup.sh >> /var/log/noodlehaus-backup.log 2>&1") | sudo crontab -

echo "============================================"
echo "Setup Complete!"
echo "Test: curl http://$(curl -s ifconfig.me)/health.php"
if [ -n "$DOMAIN" ]; then
echo "Live: https://${DOMAIN}"
fi
echo ""
echo "Next: Import tenant data with migrate-tenant.sh"
echo "============================================"
