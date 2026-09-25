# Deploying the CRM

The CRM needs four things running on the server:

| Piece                                                                      | Why                                                                           |
| -------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| **Web server** (Nginx + PHP 8.4) with **HTTPS**                            | The website, and Meta's webhooks (Meta only sends to HTTPS)                   |
| **Queue worker**: `php artisan horizon` (Redis) or `queue:work` (database) | Sends messages, processes webhooks, runs blasts                               |
| **Reverb**: `php artisan reverb:start`                                     | Real-time inbox updates (optional; without it the inbox refreshes every 10 s) |
| **Scheduler** cron: `php artisan schedule:run` every minute                | Starts scheduled blasts                                                       |

A small VPS (2 GB RAM, e.g. DigitalOcean, Hetzner, Vultr or Linode, about USD 6–12/month) is plenty for a travel agency team.

---

## Option A: Laravel Forge + VPS (recommended)

[Laravel Forge](https://forge.laravel.com) sets up and manages the server for you.

1. **Create a server** in Forge (App server, PHP 8.4, MySQL 8). Redis is installed automatically.
2. **Create a site**, e.g. `crm.holidaygogogo.com`, and point your domain's DNS **A record** to the server IP.
3. **Connect the repository** (`addmondz/holidaygogogo-crm-automation`, branch `main`). Don't tick "Install Composer dependencies" yet if you still need to set `.env`.
4. **Environment:** edit `.env` in Forge (see [Production `.env`](#production-env) below).
5. **SSL:** Site → SSL → Let's Encrypt.
6. **Deploy script:** replace it with:

    ```bash
    cd $FORGE_SITE_PATH
    git pull origin $FORGE_SITE_BRANCH

    $FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    npm ci
    npm run build

    $FORGE_PHP artisan migrate --force
    $FORGE_PHP artisan optimize
    $FORGE_PHP artisan horizon:terminate
    $FORGE_PHP artisan reverb:restart
    ```

7. **Queue:** Site → Application → turn on **Laravel Horizon**, or add a daemon with `php artisan horizon`.
8. **Real-time:** Site → Application → turn on **Laravel Reverb**. Forge adds the Nginx proxy and the daemon. Use the host/port it shows in `.env` (see below).
9. **Scheduler:** Server → Scheduler → add `php /home/forge/crm.holidaygogogo.com/artisan schedule:run`, every minute.
10. Click **Deploy now**, then create your admin over SSH:

    ```bash
    cd /home/forge/crm.holidaygogogo.com
    php artisan crm:create-admin
    ```

11. Continue with [META_SETUP.md](META_SETUP.md).

---

## Production `.env`

```env
APP_NAME="HolidayGoGoGo CRM"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://crm.holidaygogogo.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=crm
DB_USERNAME=forge
DB_PASSWORD=...

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis

# Real-time (Reverb). The browser and the app both connect through Nginx on 443.
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...           # from "php artisan reverb:install" (or Forge)
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=crm.holidaygogogo.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Meta (see META_SETUP.md)
META_APP_ID=...
META_APP_SECRET=...
META_WEBHOOK_VERIFY_TOKEN=some-long-random-text
META_FAKE=false

CRM_DEFAULT_COUNTRY_CODE=60
CRM_BROADCAST_PER_MINUTE=300

# Password reset emails (any SMTP provider)
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="crm@holidaygogogo.com"
```

> `VITE_*` values are built into the JavaScript. After changing them, run `npm run build` (the deploy script does this).

---

## Option B: Any Ubuntu VPS, set up by hand

Tested with Ubuntu 24.04.

```bash
# PHP 8.4 + extensions, MySQL, Redis, Nginx, Supervisor, Node 22
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y nginx mysql-server redis-server supervisor git unzip \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-intl php8.4-gd php8.4-bcmath
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# Code
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/addmondz/holidaygogogo-crm-automation.git crm
sudo chown -R www-data:www-data crm && cd crm
sudo -u www-data cp .env.example .env        # then edit it (see above)
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan reverb:install  # adds Reverb keys; then set the production REVERB_* values
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
sudo -u www-data php artisan crm:create-admin
```

### Nginx (`/etc/nginx/sites-available/crm`)

```nginx
server {
    listen 80;
    server_name crm.holidaygogogo.com;
    root /var/www/crm/public;
    index index.php;
    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Reverb: WebSocket connections (/app) and the publish API (/apps)
    location ~ ^/apps?/ {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_pass http://127.0.0.1:8080;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/crm /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d crm.holidaygogogo.com
```

### Supervisor (`/etc/supervisor/conf.d/crm.conf`)

```ini
[program:crm-horizon]
command=php /var/www/crm/artisan horizon
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/crm/storage/logs/horizon.log
stopwaitsecs=3600

[program:crm-reverb]
command=php /var/www/crm/artisan reverb:start --host=127.0.0.1 --port=8080
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/crm/storage/logs/reverb.log
minfds=10000
```

If you use the **database queue** instead of Redis, replace the Horizon program's command with:

```ini
command=php /var/www/crm/artisan queue:work --queue=webhooks,messages,broadcasts,default --tries=3 --max-time=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

### Scheduler (cron)

```bash
sudo crontab -u www-data -e
# add:
* * * * * cd /var/www/crm && php artisan schedule:run >> /dev/null 2>&1
```

### Updating to a new version

```bash
cd /var/www/crm
sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
sudo -u www-data php artisan horizon:terminate    # Supervisor restarts it with the new code
sudo -u www-data php artisan reverb:restart
```

---

## Option C: Laravel Cloud

[Laravel Cloud](https://cloud.laravel.com) runs the app without managing a server: connect the repository, add a MySQL database and a Redis (KV) store, enable a queue worker and WebSockets (Reverb), and set the `.env` values above. It is usage-priced and needs the least maintenance.

## Shared hosting (cPanel), if you really must

It can work with limits:

- Set `QUEUE_CONNECTION=database` and `BROADCAST_CONNECTION=log`. The inbox then refreshes every 10 seconds instead of instantly.
- Add two cron jobs, each running every minute:
    - `cd ~/crm && php artisan schedule:run`
    - `cd ~/crm && php artisan queue:work --queue=webhooks,messages,broadcasts,default --stop-when-empty --max-time=55`
- Replies and blasts can then take up to about a minute to go out.
- Point the domain's document root to the `public/` folder, and build the assets on your computer (`npm run build`) before uploading.

---

## Backups

Back up **both**:

- the **MySQL database** (Forge: Server → Backups, or a daily `mysqldump`)
- **`storage/app/private/`**, which holds customer photos/files and quick-reply attachments

## Security checklist

- `APP_DEBUG=false` and `APP_ENV=production`
- `META_APP_SECRET` set (webhooks without a valid Meta signature are rejected)
- Admins should turn on **two-factor authentication** (Settings → Security)
- Deactivate agents who leave (Admin → Agents): they are signed out the next time they click anything
- Keep the server updated (`sudo apt upgrade`), or let Forge handle it
