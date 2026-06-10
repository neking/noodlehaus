#!/bin/bash
# migrate-tenant.sh
# Usage: migrate-tenant.sh <tenant_id> <new_server_ip> <new_server_user>
# Example: migrate-tenant.sh 3 54.123.456.789 ubuntu

TENANT_ID=${1}
NEW_SERVER=${2}
NEW_USER=${3:-ubuntu}
DB_PASS="GGttgg123!"
DATE=$(date +%Y-%m-%d_%H%M)
BACKUP_FILE="/var/backups/noodlehaus/tenants/migrate_${TENANT_ID}_${DATE}.sql"

if [ -z "$TENANT_ID" ] || [ -z "$NEW_SERVER" ]; then
  echo "Usage: migrate-tenant.sh <tenant_id> <new_server_ip> [user]"
  exit 1
fi

TENANT_NAME=$(mysql -uroot -p${DB_PASS} noodlehaus -sN \
  -e "SELECT name FROM tenants WHERE id=$TENANT_ID;" 2>/dev/null)

echo "============================================"
echo "NoodleHaus Tenant Migration"
echo "Tenant: $TENANT_NAME (ID: $TENANT_ID)"
echo "Destination: $NEW_USER@$NEW_SERVER"
echo "============================================"

# Step 1: Schema only
echo "[1/6] Exporting database schema..."
mysqldump -uroot -p${DB_PASS} --no-data noodlehaus > /tmp/schema_${TENANT_ID}.sql 2>/dev/null

# Step 2: Tenant data
echo "[2/6] Exporting tenant data..."
echo "SET FOREIGN_KEY_CHECKS=0;" > $BACKUP_FILE
for TABLE in orders menu_items loyalty_cards stock_log delivery_tracking expenses schedules kds_queue; do
  mysqldump -uroot -p${DB_PASS} --no-create-info --skip-triggers \
    --where="tenant_id=$TENANT_ID" noodlehaus $TABLE 2>/dev/null >> $BACKUP_FILE
done
mysqldump -uroot -p${DB_PASS} --no-create-info --skip-triggers \
  --where="order_id IN (SELECT id FROM orders WHERE tenant_id=$TENANT_ID)" \
  noodlehaus order_items 2>/dev/null >> $BACKUP_FILE
mysqldump -uroot -p${DB_PASS} --no-create-info --skip-triggers \
  --where="id=$TENANT_ID" noodlehaus tenants billing 2>/dev/null >> $BACKUP_FILE
mysqldump -uroot -p${DB_PASS} --no-create-info --skip-triggers \
  noodlehaus site_settings saas_plans 2>/dev/null >> $BACKUP_FILE
echo "SET FOREIGN_KEY_CHECKS=1;" >> $BACKUP_FILE
gzip $BACKUP_FILE
echo "   Done: ${BACKUP_FILE}.gz ($(du -sh ${BACKUP_FILE}.gz | cut -f1))"

# Step 3: Copy to new server
echo "[3/6] Copying files to new server..."
scp /tmp/schema_${TENANT_ID}.sql ${NEW_USER}@${NEW_SERVER}:/tmp/
scp ${BACKUP_FILE}.gz ${NEW_USER}@${NEW_SERVER}:/tmp/

# Step 4: Remote setup
echo "[4/6] Setting up database on new server..."
ssh ${NEW_USER}@${NEW_SERVER} bash << REMOTE
  mysql -uroot -p${DB_PASS} -e "CREATE DATABASE IF NOT EXISTS noodlehaus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
  mysql -uroot -p${DB_PASS} noodlehaus < /tmp/schema_${TENANT_ID}.sql 2>/dev/null
  zcat /tmp/migrate_${TENANT_ID}_${DATE}.sql.gz | mysql -uroot -p${DB_PASS} noodlehaus 2>/dev/null
  # Reset tenant_id to 1 on new server (single tenant)
  mysql -uroot -p${DB_PASS} noodlehaus -e "
    UPDATE orders SET tenant_id=1;
    UPDATE menu_items SET tenant_id=1;
    UPDATE loyalty_cards SET tenant_id=1;
    UPDATE stock_log SET tenant_id=1;
    UPDATE delivery_tracking SET tenant_id=1;
    UPDATE expenses SET tenant_id=1;
    UPDATE schedules SET tenant_id=1;
    UPDATE kds_queue SET tenant_id=1;
    UPDATE tenants SET id=1, slug='main' WHERE id=$TENANT_ID;
  " 2>/dev/null
  echo "Database ready"
REMOTE

# Step 5: Deploy app code
echo "[5/6] Deploying app code..."
ssh ${NEW_USER}@${NEW_SERVER} bash << REMOTE
  if [ ! -d "/var/www/html/.git" ]; then
    sudo git clone https://github.com/neking/noodlehaus /var/www/html
    sudo chown -R www-data:www-data /var/www/html
  else
    cd /var/www/html && sudo git pull
  fi
  sudo chmod 640 /var/www/html/.env
  sudo chown www-data:www-data /var/www/html/.env
REMOTE

echo "[6/6] Migration complete!"
echo ""
echo "Next steps:"
echo "  1. Point DNS → $NEW_SERVER"
echo "  2. Run: sudo certbot --nginx -d yourdomain.com"
echo "  3. Verify: curl https://yourdomain.com/health.php"
echo "  4. Disable on shared DB: UPDATE tenants SET is_active=0 WHERE id=$TENANT_ID;"
echo "============================================"
