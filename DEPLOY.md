# نشر التطبيق على السيرفر (Ubuntu + nginx + PHP-FPM + MySQL)

> **لكلود كود على السيرفر:** نفّذ هذا الملف من البداية إلى النهاية.
>
> **قبل أي أمر، اسأل المستخدم عن:**
> 1. **اسم النطاق (domain):** مثلاً `stock.exemple.com`. يجب أن يكون سجل DNS من نوع A يشير إلى IP هذا السيرفر.
> 2. **بريد حساب المدير** في اللوحة، واسمه.
>
> **إذا لم يكن السيرفر Ubuntu، أو لم تكن عندك صلاحية root/sudo:** توقّف واشرح للمستخدم ما ينقص. لا ترتجل.
>
> **HTTPS إلزامي:** الوكيل في الفروع يرفض أي عنوان API لا يبدأ بـ `https://`.

القيم المستعملة أدناه:

| المتغير | القيمة |
|---|---|
| `DOMAIN` | النطاق الذي أعطاه المستخدم |
| `APP_DIR` | `/var/www/gescom-stock` |
| PHP | 8.3 أو أحدث (Laravel 13 يشترط `^8.3`). على Ubuntu 24.04 الحزمة الافتراضية هي 8.3 |

---

## 1. الحزم
```bash
sudo apt update
sudo apt install -y nginx mysql-server git unzip curl \
  php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-zip php-intl php-bcmath php-sqlite3
php -v    # يجب أن يكون 8.3 أو أحدث، وإلا ثبّت 8.3/8.4 من ppa:ondrej/php
```

Composer:
```bash
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
```

## 2. قاعدة البيانات
ولّد كلمة سر قوية (مثلاً `openssl rand -base64 24`)، واحفظها لملف `.env` فقط:
```bash
sudo mysql <<'SQL'
CREATE DATABASE gescom_stock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gescom_stock'@'localhost' IDENTIFIED BY '<MOT_DE_PASSE>';
GRANT ALL PRIVILEGES ON gescom_stock.* TO 'gescom_stock'@'localhost';
FLUSH PRIVILEGES;
SQL
```

## 3. الكود
```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <URL_DU_DEPOT> gescom-stock
sudo chown -R $USER:www-data gescom-stock
cd gescom-stock
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

## 4. ملف `.env`
عدّل هذه القيم، واترك الباقي كما في `.env.example`:
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DOMAIN
APP_TIMEZONE=Africa/Algiers
APP_LOCALE=ar

LOG_STACK=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gescom_stock
DB_USERNAME=gescom_stock
DB_PASSWORD=<MOT_DE_PASSE>

SESSION_SECURE_COOKIE=true
```
في `.env.example` أسطر `DB_HOST` و`DB_PORT` و`DB_DATABASE` و`DB_USERNAME` و`DB_PASSWORD` معلّقة بـ `#`. أزل `#` واملأها، وغيّر `DB_CONNECTION=sqlite` إلى `mysql`. **لا ترفع `.env` إلى Git أبداً.**

## 5. الجداول والصلاحيات
```bash
php artisan migrate --force
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 6. اختبار التوافق مع MySQL (مرة واحدة)
الاختبارات مكتوبة على SQLite. هذه الخطوة تشغّلها مرة على MySQL، على قاعدة مؤقتة منفصلة:
```bash
sudo mysql -e "CREATE DATABASE gescom_stock_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON gescom_stock_test.* TO 'gescom_stock'@'localhost';"
composer install            # يحتاج حزم dev للاختبار
php artisan config:clear
DB_CONNECTION=mysql DB_DATABASE=gescom_stock_test DB_USERNAME=gescom_stock DB_PASSWORD='<MOT_DE_PASSE>' php artisan test
sudo mysql -e "DROP DATABASE gescom_stock_test;"
composer install --no-dev --optimize-autoloader
php artisan config:cache
```
**يجب أن تمر كل الاختبارات.** إذا فشل اختبار، توقّف وأبلغ المستخدم بالخطأ كما هو، ولا تعدّل الكود على السيرفر.

## 7. nginx
أنشئ الملف `/etc/nginx/sites-available/gescom-stock`. عدّل `DOMAIN`، وعدّل رقم نسخة PHP في `fastcgi_pass` (مثلاً `php8.3-fpm.sock`):
```nginx
server {
    listen 80;
    server_name DOMAIN;
    root /var/www/gescom-stock/public;
    index index.php;
    charset utf-8;
    client_max_body_size 10m;   # مزامنة فرع = حوالي 350 KB، والهامش للفروع الأكبر

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/gescom-stock /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

## 8. HTTPS (إلزامي)
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d DOMAIN --redirect -m <EMAIL_ADMIN> --agree-tos -n
```

## 9. الجدار الناري
```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
```
لا تفتح منفذ MySQL (3306) للخارج.

## 10. المهام المجدولة
تنظيف يومي للطلبات القديمة وأكواد التركيب المنتهية:
```bash
( sudo crontab -u www-data -l 2>/dev/null; echo "* * * * * cd /var/www/gescom-stock && php artisan schedule:run >> /dev/null 2>&1" ) | sudo crontab -u www-data -
```

## 11. حساب المدير
```bash
php artisan user:create <EMAIL_ADMIN> --name="<NOM>"
```
الأمر يسأل عن كلمة السر (8 أحرف على الأقل). **لا تكتبها في أي ملف.** إذا ولّدتها أنت، أعطها للمستخدم مرة واحدة واطلب منه تغييرها، بإعادة نفس الأمر.

## 12. التحقق
```bash
curl -s -o /dev/null -w "%{http_code}\n" https://DOMAIN/up                 # 200
curl -s https://DOMAIN/api/agent/ping                                      # {"error":"token invalide ..."} = الـAPI يعمل
php artisan agent:install-code                                             # كود التركيب لأول فرع
```

## 13. ما تعطيه للمستخدم في النهاية
- **رابط اللوحة:** `https://DOMAIN`، مع بريد الدخول.
- **عنوان الـAPI للوكلاء:** `https://DOMAIN/api`
- **كود تركيب لأول فرع:** من الأمر `agent:install-code`، أو من اللوحة ← «الفروع» ← «كود تركيب جديد».
- **الخطوة التالية على سيرفر Gescomm في كل فرع:**
  1. انسخ مجلد `agent` من هذا المستودع.
  2. شغّل كأدمين: `powershell -ExecutionPolicy Bypass -File .\install.ps1`
  3. أدخل عنوان الـAPI، واسم الفرع، والكود.

---

## التحديثات لاحقاً
```bash
cd /var/www/gescom-stock
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan up
sudo systemctl reload php8.3-fpm
```
