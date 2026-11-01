# نشر التطبيق على السيرفر — stockallal.souftech.com (HestiaCP)

> **لكلود كود على السيرفر:** نفّذ هذا الملف خطوة بخطوة، **كـ root**، وتحقّق من نتيجة كل خطوة قبل التي بعدها.
>
> **السيرفر:** `srv955079`، تديره لوحة **HestiaCP**. النطاق موجود مسبقاً:
> ```
> /home/user/web/stockallal.souftech.com/public_html   (المالك user:www-data، فيه index.html فقط)
> ```
>
> **قواعد:**
> - لا تعدّل كود التطبيق على السيرفر. إذا فشل شيء، توقّف وأبلغ المستخدم بالخطأ كما هو.
> - كل الملفات تبقى ملكاً للمستخدم `user`، لأن PHP-FPM في Hestia يشتغل باسمه. **لا `chmod 777`.**
> - أوامر Hestia في `/usr/local/hestia/bin`، فنفّذ أولاً `export PATH=$PATH:/usr/local/hestia/bin`.
> - **HTTPS إلزامي:** الوكلاء في الفروع يرفضون أي عنوان API لا يبدأ بـ `https://`.
> - **حساب المدير:** اطلب من المستخدم في المحادثة **اسم المستخدم وكلمة السر**. لا تكتبهما في أي ملف ولا في Git، لأن **المستودع عام**. المستخدم يغيّرهما لاحقاً من صفحة «حسابي».

القيم المستعملة أدناه:

| المتغير | القيمة |
|---|---|
| `DOMAIN` | `stockallal.souftech.com` |
| `HUSER` | `user` (مالك النطاق في Hestia) |
| `WEB` | `/home/user/web/stockallal.souftech.com` |
| `APP` | `$WEB/public_html` (الكود يُستنسخ هنا، والـdocroot يصبح `public_html/public`) |
| `PHP` | `php8.3` أو أحدث (Laravel 13 يشترط `^8.3`) |

---

## 0. فحص البيئة
```bash
export PATH=$PATH:/usr/local/hestia/bin
v-list-web-domain user stockallal.souftech.com          # يجب أن يظهر النطاق: TPL و BACKEND و SSL
v-list-sys-config | grep -Ei 'WEB_SYSTEM|PROXY_SYSTEM|WEB_BACKEND'   # nginx وحده، أو nginx + apache2
ls /usr/bin/php8.* ; v-list-web-templates-backend      # نسخ PHP المتوفرة
```
- **الـbackend الحالي أقل من 8.3:** إذا كانت النسخة 8.3 أو 8.4 مثبّتة، غيّره إلى إحداهما:
  ```bash
  v-change-web-domain-backend-tpl user stockallal.souftech.com PHP-8_3
  ```
  - **إذا لم تكن 8.3 ولا 8.4 مثبّتة:** ثبّت 8.3 من `ppa:ondrej/php` (Hestia يستعمله أصلاً): `apt install php8.3-fpm`. ثم أضفها لـ Hestia من لوحة Server ← Configure ← PHP، أو بالأمر `v-add-web-php 8.3`.
- **الإضافات اللازمة لنسخة PHP المختارة:**
  ```bash
  apt install -y php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath php8.3-sqlite3
  ```

**في كل ما يلي:** `PHP` = المسار الكامل لنسخة PHP المختارة (مثلاً `/usr/bin/php8.3`)، لا الأمر `php` الافتراضي.

## 1. Composer
```bash
which composer || v-add-user-composer user      # أو التثبيت الرسمي في /usr/local/bin/composer
```

## 2. قاعدة البيانات (عبر Hestia)
ولّد كلمة سر قوية، مثلاً `openssl rand -base64 24 | tr -d '/+='`.
```bash
v-add-database user stock stock '<DB_PASS>' mysql
v-list-databases user        # الاسم الفعلي يصبح user_stock والمستخدم user_stock
```

## 3. الكود في `public_html`
```bash
cd /home/user/web/stockallal.souftech.com
mv public_html/index.html /root/stockallal-index.html.bak        # الصفحة الافتراضية
sudo -u user git clone https://github.com/Mouawia12/stockAllal.git public_html
```
- **إذا كان المستودع خاصاً:** اطلب من المستخدم Deploy key أو توكن GitHub للقراءة فقط. لا تخمّن.
- **إذا رفض `git clone` لأن المجلد غير فارغ:** تأكّد أنه لم يبقَ فيه إلا ملفات Hestia الافتراضية، وانقلها جانباً.
```bash
cd public_html
sudo -u user PHP $(which composer) install --no-dev --optimize-autoloader
sudo -u user cp .env.example .env
sudo -u user PHP artisan key:generate
```

## 4. ملف `.env`
عدّل هذه القيم في `public_html/.env`، واترك الباقي:
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://stockallal.souftech.com
APP_TIMEZONE=Africa/Algiers
APP_LOCALE=ar

LOG_STACK=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=user_stock
DB_USERNAME=user_stock
DB_PASSWORD=<DB_PASS>

SESSION_SECURE_COOKIE=true
```
- **أسطر قاعدة البيانات:** في `.env.example` أسطر `DB_*` معلّقة بـ `#`. أزل `#` واملأها، وغيّر `DB_CONNECTION=sqlite` إلى `mysql`.
- **صلاحيات `.env`:** `chmod 640 .env`.
- **Git:** `.env` لا يُرفع إليه أبداً.

```bash
sudo -u user PHP artisan migrate --force
sudo -u user PHP artisan user:create <ADMIN_USERNAME> --name="Admin"   # يسأل عن كلمة السر: أدخل التي أعطاها المستخدم
```

## 5. جعل `public_html/public` هو الـdocroot (حاسم للأمان)
الكود كله، ومعه `.env`، موجود في `public_html`. **يجب ألا يُخدم إلا مجلد `public`.**

1. **إذا كان في Hestia قالب `laravel`، طبّقه:**
   ```bash
   ls /usr/local/hestia/data/templates/web/nginx/php-fpm/ | grep -i laravel     # إذا كان nginx وحده
   ls /usr/local/hestia/data/templates/web/apache2/php-fpm/ | grep -i laravel   # إذا كان apache2 خلف nginx
   v-change-web-domain-tpl user stockallal.souftech.com laravel                 # نوع الـweb (apache2 أو nginx وحده)
   ```
2. **إذا لم يوجد قالب `laravel`:**
   - انسخ القالب الحالي للنطاق (`default.tpl` و`default.stpl`) إلى قالب جديد اسمه `laravel-public` في نفس المجلد.
   - في النسختين: استبدل `%docroot%` بـ `%docroot%/public` في سطر `root` (nginx) أو `DocumentRoot` (apache).
   - مع nginx: تأكّد أن `location /` فيه `try_files $uri $uri/ /index.php?$query_string;`.
   - مع apache: ملف `public/.htaccess` في المستودع يتكفّل بذلك.
   - ثم طبّقه بـ `v-change-web-domain-tpl user stockallal.souftech.com laravel-public`.
3. **حد حجم الطلب:** مزامنة فرع تأخذ حوالي 350 KB. حد nginx الافتراضي في Hestia أكبر من ذلك عادة. إذا ظهر خطأ `413`، ارفع `client_max_body_size` إلى `10m`.

**تحقّق إلزامي:** إذا أعاد أي سطر من هذه `200`، فالإعداد خاطئ. **توقّف وأصلحه قبل أي شيء آخر:**
```bash
curl -s -o /dev/null -w "%{http_code}\n" https://stockallal.souftech.com/.env           # يجب ألا يكون 200
curl -s -o /dev/null -w "%{http_code}\n" https://stockallal.souftech.com/composer.json  # يجب ألا يكون 200
curl -s -o /dev/null -w "%{http_code}\n" https://stockallal.souftech.com/login          # 200
```

## 6. HTTPS
```bash
v-list-web-domain-ssl user stockallal.souftech.com || v-add-letsencrypt-domain user stockallal.souftech.com
v-add-web-domain-ssl-force user stockallal.souftech.com          # تحويل http إلى https
```
إذا فشل Let's Encrypt، فسجل DNS للنطاق لا يشير إلى هذا السيرفر. أبلغ المستخدم.

## 7. الكاش والصلاحيات
```bash
cd /home/user/web/stockallal.souftech.com/public_html
chown -R user:user storage bootstrap/cache && chmod -R u+rwX storage bootstrap/cache
sudo -u user PHP artisan config:cache && sudo -u user PHP artisan route:cache && sudo -u user PHP artisan view:cache
```

## 8. اختبار التوافق مع MySQL (مرة واحدة)
الاختبارات مكتوبة على SQLite. هنا نشغّلها مرة على قاعدة MySQL مؤقتة:
```bash
v-add-database user stocktest stocktest '<DB_PASS_TEST>' mysql
sudo -u user PHP $(which composer) install                 # حزم dev للاختبار
sudo -u user PHP artisan config:clear
sudo -u user env DB_CONNECTION=mysql DB_DATABASE=user_stocktest DB_USERNAME=user_stocktest DB_PASSWORD='<DB_PASS_TEST>' PHP artisan test
v-delete-database user user_stocktest
sudo -u user PHP $(which composer) install --no-dev --optimize-autoloader
sudo -u user PHP artisan config:cache
```
**يجب أن تمر كل الاختبارات.** إذا فشل اختبار، توقّف وأبلغ المستخدم.

## 9. المهمة المجدولة
تنظيف يومي للطلبات القديمة وأكواد التركيب المنتهية:
```bash
v-add-cron-job user '*' '*' '*' '*' '*' "cd /home/user/web/stockallal.souftech.com/public_html && PHP artisan schedule:run >/dev/null 2>&1"
```
استبدل `PHP` بالمسار الكامل، مثلاً `/usr/bin/php8.3`.

## 10. التحقق النهائي
```bash
curl -s -o /dev/null -w "%{http_code}\n" https://stockallal.souftech.com/up       # 200
curl -s https://stockallal.souftech.com/api/agent/ping                            # {"error":"token invalide ..."} = الـAPI يعمل
sudo -u user PHP artisan agent:install-code                                       # كود تركيب لأول فرع
```

## 11. ما تعطيه للمستخدم في النهاية
- **اللوحة:** https://stockallal.souftech.com، مع اسم المستخدم الذي أعطاه. ذكّره بتغيير كلمة السر من «حسابي»، خاصة إذا كانت تساوي اسم المستخدم.
- **عنوان الـAPI للوكلاء:** `https://stockallal.souftech.com/api`. هذا هو العنوان الافتراضي في `agent/install.ps1`.
- **كود تركيب لأول فرع:** من الخطوة 10، أو من اللوحة ← «الفروع» ← «كود تركيب جديد».
- **على سيرفر Gescomm في كل فرع:**
  1. انسخ مجلد `agent`.
  2. شغّل كأدمين: `powershell -ExecutionPolicy Bypass -File .\install.ps1`
  3. اضغط Enter لقبول عنوان الـAPI، ثم أدخل اسم الفرع والكود.

---

## التحديثات لاحقاً
```bash
cd /home/user/web/stockallal.souftech.com/public_html
sudo -u user PHP artisan down
sudo -u user git pull
sudo -u user PHP $(which composer) install --no-dev --optimize-autoloader
sudo -u user PHP artisan migrate --force
sudo -u user PHP artisan optimize
sudo -u user PHP artisan up
```
