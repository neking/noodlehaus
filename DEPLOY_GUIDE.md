# NoodleHaus Landing Page — Deploy Guide
**noodlehaus-pos.duckdns.org** သို့ landing page တင်ဖို့ step-by-step

---

## နည်းလမ်း ၂ ခု — ဘာသုံးမလဲ?

| နည်းလမ်း | ကြာချိန် | ဘယ်သူအတွက် |
|-----------|----------|-------------|
| **A. Manual SSH** (ချက်ချင်းတင်) | 5 မိနစ် | ဒါပဲ တင်မယ် |
| **B. GitHub Actions** (auto-deploy) | 20 မိနစ် setup | push လုပ်တိုင်း auto |

---

## နည်းလမ်း A — Manual SSH (အမြန်ဆုံး)

### Step 1 — SSH ဝင်

```bash
ssh your-username@noodlehaus-pos.duckdns.org
```

### Step 2 — Landing folder ဆောက်

```bash
# Web root ကိုသွား (nginx/apache ပေါ်မူတည်)
cd /var/www/html

# landing folder ဆောက်
mkdir -p landing
```

### Step 3 — File တင်

**ကိုယ်ကွန်ပျူတာကနေ** (terminal အသစ်ဖွင့်ပြီး) ။

```bash
# landing-page.html ကို server ကို copy
scp landing-page.html your-username@noodlehaus-pos.duckdns.org:/var/www/html/landing/index.html
```

### Step 4 — စစ်ကြည့်

Browser မှာ သွားကြည့်:
```
https://noodlehaus-pos.duckdns.org/landing/
```

---

## နည်းလမ်း B — GitHub Actions (Auto-deploy)

### Step 1 — GitHub Repo မှာ Secrets ထည့်

GitHub → `neking/noodlehaus` → **Settings** → **Secrets and variables** → **Actions**

ဒီ secrets ၄ ခု ထည့်ပါ:

| Secret Name | Value |
|-------------|-------|
| `SERVER_HOST` | `noodlehaus-pos.duckdns.org` |
| `SERVER_USER` | SSH username (e.g. `ubuntu` or `root`) |
| `SERVER_PORT` | `22` (ပုံမှန်) |
| `SERVER_SSH_KEY` | Private key (ပြီးရင် ကြည့်) |

### Step 2 — SSH Key ဆောက် (မရှိသေးရင်)

```bash
# Local machine မှာ run
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/noodlehaus_deploy

# Public key ကို server ကို ထည့်
ssh-copy-id -i ~/.ssh/noodlehaus_deploy.pub your-username@noodlehaus-pos.duckdns.org

# Private key ကို copy (GitHub Secret မှာ ထည့်မယ်)
cat ~/.ssh/noodlehaus_deploy
```

Private key (-----BEGIN ... -----END ... အပါ) ကို `SERVER_SSH_KEY` secret မှာ ထည့်ပါ။

### Step 3 — Repo ကို files ထည့်

```bash
# Local repo ထဲ
mkdir -p landing
cp landing-page.html landing/index.html

mkdir -p .github/workflows
cp deploy.yml .github/workflows/deploy.yml

git add .
git commit -m "feat: add landing page + auto-deploy"
git push origin main
```

### Step 4 — GitHub မှာ စစ်

`Actions` tab → deploy run တာ မြင်မယ် → ✅ green ဖြစ်ရင် ပြီးပြီ

---

## Server Directory Structure (recommended)

```
/var/www/html/
├── index.php          ← လက်ရှိ app (မပြောင်းဘဲထား)
├── landing/
│   └── index.html     ← Marketing site (ဒါတင်မယ်)
├── pos.php            ← POS terminal
├── admin.php          ← Admin panel
└── kds.php            ← Kitchen display
```

URL အနေနဲ့:
- `noodlehaus-pos.duckdns.org/`         → App (unchanged)
- `noodlehaus-pos.duckdns.org/landing/` → Marketing landing page

---

## Nginx Config (landing page ကို root ပြောင်းချင်ရင်)

Landing page ကို root (`/`) မှာ ထားချင်ရင် — nginx config ပြင်ရမယ်:

```nginx
server {
    listen 443 ssl;
    server_name noodlehaus-pos.duckdns.org;
    root /var/www/html;

    # Landing page = root
    location = / {
        try_files /landing/index.html =404;
    }

    # App routes
    location /pos { try_files $uri $uri/ /pos.php?$query_string; }
    location /admin { try_files $uri $uri/ /admin.php?$query_string; }

    # PHP
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## Troubleshoot

**Permission error:**
```bash
sudo chown -R www-data:www-data /var/www/html/landing
sudo chmod 644 /var/www/html/landing/index.html
```

**Nginx reload:**
```bash
sudo nginx -t && sudo systemctl reload nginx
```

**File မတင်ရသေးဘူး စစ်:**
```bash
ls -la /var/www/html/landing/
```
