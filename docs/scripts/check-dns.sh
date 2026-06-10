#!/bin/bash
DOMAIN=$1; SERVER_IP="13.236.66.72"
RESOLVED=$(dig +short $DOMAIN A | head -1)
[ "$RESOLVED" = "$SERVER_IP" ] && echo "✅ DNS ready — run: bash setup-domain.sh $DOMAIN your@email.com" || echo "❌ DNS not ready ($RESOLVED) — need $SERVER_IP — check: https://dnschecker.org/#A/$DOMAIN"
