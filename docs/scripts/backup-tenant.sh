#!/bin/bash
TENANT_ID=${1:-1}
DATE=$(date +%Y-%m-%d_%H%M)
DIR="/var/backups/noodlehaus/tenants"
mkdir -p $DIR
OUT="$DIR/tenant_${TENANT_ID}_${DATE}.sql"
TENANT_NAME=$(mysql -uroot -pGGttgg123! noodlehaus -sN -e "SELECT name FROM tenants WHERE id=$TENANT_ID;" 2>/dev/null)
echo "Backing up: $TENANT_NAME (tenant_id=$TENANT_ID)"
for TABLE in orders menu_items loyalty_cards stock_log delivery_tracking expenses schedules; do
  mysqldump -uroot -pGGttgg123! --no-create-info --skip-triggers --where="tenant_id=$TENANT_ID" noodlehaus $TABLE 2>/dev/null >> $OUT
done
mysqldump -uroot -pGGttgg123! --no-create-info --skip-triggers --where="order_id IN (SELECT id FROM orders WHERE tenant_id=$TENANT_ID)" noodlehaus order_items 2>/dev/null >> $OUT
mysqldump -uroot -pGGttgg123! --no-create-info --skip-triggers --where="id=$TENANT_ID" noodlehaus tenants billing 2>/dev/null >> $OUT
gzip $OUT
echo "Done: ${OUT}.gz ($(du -sh ${OUT}.gz | cut -f1))"
