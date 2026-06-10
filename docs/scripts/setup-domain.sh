#!/bin/bash
# Usage: bash setup-domain.sh yourdomain.com your@email.com
DOMAIN=${1}; EMAIL=${2}; SERVER_IP="13.236.66.72"
[ -z "$DOMAIN" ] || [ -z "$EMAIL" ] && echo "Usage: bash setup-domain.sh domain.com email@x.com" && exit 1
echo "=== Checking DNS ==="
RESOLVED=$(dig +short $DOMAIN A | head -1)
[ "$RESOLVED" != "$SERVER_IP" ] && echo "DNS not ready: $RESOLVED (need $SERVER_IP). Check: https://dnschecker.org/#A/$DOMAIN" && exit 1
echo "DNS OK"
echo "=== Updating Nginx ==="
sudo tee /etc/nginx/sites-available/noodlehaus > /dev/null << NG
server { listen 80; server_name $DOMAIN www.$DOMAIN; return 301 https://\$host\$request_uri; }
server { listen 443 ssl http2; server_name $DOMAIN www.$DOMAIN; root /var/www/html; index index.php index.html; client_max_body_size 20M; add_header X-Frame-Options "SAMEORIGIN" always; location ~* \.(bak|sql|log|env|git)$ { deny all; return 404; } location / { try_files \$uri \$uri/ =404; } location ~ \.php$ { fastcgi_pass unix:/var/run/php/php8.5-fpm.sock; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; include fastcgi_params; fastcgi_read_timeout 3600; fastcgi_buffering off; } ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem; ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem; include /etc/letsencrypt/options-ssl-nginx.conf; ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem; }
NG
sudo nginx -t || exit 1
echo "=== Getting SSL (FREE Let's Encrypt) ==="
sudo certbot --nginx -d $DOMAIN -d www.$DOMAIN --non-interactive --agree-tos --email $EMAIL
echo "=== Done! Live at https://$DOMAIN ==="
