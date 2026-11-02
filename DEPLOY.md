# نشر التطبيق على السيرفر — stockallal.souftech.com (HestiaCP)

> **لكلود كود على السيرفر:** نفّذ هذا الملف خطوة بخطوة، **كـ root**، وتحقّق من نتيجة كل خطوة قبل التي بعدها.
>
> **السيرفر:** `srv955079`، تديره لوحة **HestiaCP**: nginx كـ proxy أمام apache2، وPHP-FPM. النطاق موجود مسبقاً:
> ```
> /home/user/web/stockallal.souftech.com/public_html   (المالك user:www-data)
> ```
>
> **قواعد:**
> - لا تعدّل كود التطبيق على السيرفر. إذا فشل شيء، توقّف وأبلغ المستخدم بالخطأ كما هو.
> - كل الملفات تبقى ملكاً للمستخدم `user`، لأن PHP-FPM في Hestia يشتغل باسمه. **لا `chmod 777`.**
> - أوامر Hestia في `/usr/local/hestia/bin`، فنفّذ أولاً `export PATH=$PATH:/usr/local/hestia/bin`.
> - **HTTPS إلزامي:** الوكلاء في الفروع يرفضون أي عنوان API لا يبدأ بـ `https://`.
> - **حساب المدير:** اطلب من المستخدم في المحادثة **اسم المستخدم وكلمة السر**. لا تكتبهما في أي ملف ولا في Git، لأن **المستودع عام**. المستخدم يغيّرهما لاحقاً من صفحة «حسابي».

## الوضع الحالي (منشور يوم 01/11/2026)

| العنصر | الحالة |
|---|---|
| الكود | في `public_html`، وهو نسخة Git من `main` |
| PHP | **8.4**: الـbackend للنطاق هو `PHP-8_4`، والأوامر تستعمل `/usr/bin/php8.4`، و`php8.4-fpm` مفعّل عند الإقلاع |
| القوالب | `laravel-public` لـ apache (الـtpl) **و** لـ nginx (الـproxy-tpl)، وكلاهما يخدم `public_html/public` |
| قاعدة البيانات | `user_stock`. كلمة سرها في `/root/.stockallal-db-pass.txt`، للـroot فقط |
| HTTPS | Let's Encrypt، مع تحويل http إلى https |
| المهمة المجدولة | `schedule:run` كل دقيقة للمستخدم `user` |
| صفحة Hestia الافتراضية | نُقلت إلى `/root/stockallal-index.html.bak` |

**لتحديث سيرفر منشور، اذهب مباشرة إلى قسم «التحديثات لاحقاً» في آخر الملف.**

القيم المستعملة أدناه:

| المتغير | القيمة |
|---|---|
| `DOMAIN` | `stockallal.souftech.com` |
| `WEB` | `/home/user/web/stockallal.souftech.com` |
| `APP` | `$WEB/public_html` (الكود يُستنسخ هنا، والـdocroot يصبح `public_html/public`) |
| `PHP` | `/usr/bin/php8.4`. **8.4 إلزامية:** `composer.lock` فيه حزم Symfony 8 تشترط PHP ≥ 8.4.1، وعلى 8.3 يفشل `composer install` |
| `COMPOSER` | `sudo -u user env COMPOSER_HOME=/home/user/.composer /usr/bin/php8.4 $(which composer)` |

---

## 0. فحص البيئة
```bash
export PATH=$PATH:/usr/local/hestia/bin
v-list-web-domain user stockallal.souftech.com          # TPL و PROXY و BACKEND و SSL
v-list-sys-config | grep -Ei 'WEB_SYSTEM|PROXY_SYSTEM|WEB_BACKEND'   # nginx وحده، أو nginx + apache2
ls /usr/bin/php8.* ; v-list-web-templates-backend
```
- **PHP 8.4 غير مثبّتة:** ثبّتها من `ppa:ondrej/php` (Hestia يستعمله أصلاً)، ثم أضفها لـ Hestia بـ `v-add-web-php 8.4`.
- **اجعل الـbackend للنطاق 8.4، وفعّل الخدمة عند الإقلاع:**
  ```bash
  v-change-web-domain-backend-tpl user stockallal.souftech.com PHP-8_4
  systemctl enable --now php8.4-fpm        # بدون هذا يتوقف الموقع بعد إعادة تشغيل السيرفر
  apt install -y php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath php8.4-sqlite3
  ```

## 1. Composer
```bash
which composer || v-add-user-composer user
```

## 2. قاعدة البيانات (عبر Hestia)
```bash
openssl rand -base64 24 | tr -d '/+=' > /root/.stockallal-db-pass.txt && chmod 600 /root/.stockallal-db-pass.txt
v-add-database user stock stock "$(cat /root/.stockallal-db-pass.txt)" mysql
v-list-databases user        # الاسم الفعلي يصبح user_stock والمستخدم user_stock
```

## 3. الكود في `public_html`
```bash
cd /home/user/web/stockallal.souftech.com
mv public_html/index.html /root/stockallal-index.html.bak        # الصفحة الافتراضية
sudo -u user git clone https://github.com/Mouawia12/stockAllal.git public_html
cd public_html
sudo -u user env COMPOSER_HOME=/home/user/.composer /usr/bin/php8.4 $(which composer) install --no-dev --optimize-autoloader
sudo -u user cp .env.example .env
sudo -u user /usr/bin/php8.4 artisan key:generate
```
إذا رفض `git clone` لأن المجلد غير فارغ، تأكّد أنه لم يبقَ فيه إلا ملفات Hestia الافتراضية، وانقلها جانباً.

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
DB_PASSWORD=<محتوى /root/.stockallal-db-pass.txt>

SESSION_SECURE_COOKIE=true
```
- **أسطر قاعدة البيانات:** في `.env.example` أسطر `DB_*` معلّقة بـ `#`. أزل `#` واملأها، وغيّر `DB_CONNECTION=sqlite` إلى `mysql`.
- **صلاحيات `.env`:** `chmod 640 .env`.

```bash
sudo -u user /usr/bin/php8.4 artisan migrate --force
sudo -u user /usr/bin/php8.4 artisan user:create <ADMIN_USERNAME> --name="Admin" --admin   # يسأل عن كلمة السر بشكل مخفي
```

## 5. جعل `public_html/public` هو الـdocroot (حاسم للأمان)
الكود كله، ومعه `.env`، موجود في `public_html`. **يجب ألا يُخدم إلا مجلد `public`.**

**مع nginx أمام apache2 (حالة هذا السيرفر)، يجب تغيير القالبين معاً:**
- **قالب apache:** للطلبات التي تمر إلى PHP.
- **قالب nginx (الـproxy):** nginx يخدم الملفات الثابتة بنفسه من الـdocroot. إذا تغيّر قالب apache وحده، يبقى nginx يقرأ من `public_html` مباشرة.

1. **قالب `laravel-public` لـ apache:**
   - انسخ `default.tpl` و`default.stpl` من `/usr/local/hestia/data/templates/web/apache2/php-fpm/` إلى `laravel-public.tpl` و`laravel-public.stpl` في نفس المجلد.
   - في النسختين: غيّر `%docroot%` إلى `%docroot%/public` في `DocumentRoot` وفي `<Directory ...>`.
   - طبّقه: `v-change-web-domain-tpl user stockallal.souftech.com laravel-public`
2. **قالب `laravel-public` لـ nginx (الـproxy):**
   - انسخ `default.tpl` و`default.stpl` من `/usr/local/hestia/data/templates/web/nginx/` إلى `laravel-public.*`.
   - غيّر كل `root %docroot%` إلى `root %docroot%/public`. لا تلمس أسطر `alias` الخاصة بصفحات الأخطاء.
   - طبّقه: `v-change-web-domain-proxy-tpl user stockallal.souftech.com laravel-public`
3. **إذا كان nginx وحده (بدون apache):**
   - قالب واحد فقط من `/usr/local/hestia/data/templates/web/nginx/php-fpm/`، فيه `root %docroot%/public;` و`try_files $uri $uri/ /index.php?$query_string;`.
   - يُطبَّق بـ `v-change-web-domain-tpl`.
4. **حد حجم الطلب:** مزامنة فرع تأخذ حوالي 350 KB. إذا ظهر خطأ `413`، ارفع `client_max_body_size` إلى `10m`.

**تحقّق إلزامي:** إذا أعاد أي سطر من هذه `200`، فالإعداد خاطئ. **توقّف وأصلحه قبل أي شيء آخر:**
```bash
for p in .env composer.json artisan storage/logs/laravel.log; do
  echo "$p -> $(curl -s -o /dev/null -w '%{http_code}' https://stockallal.souftech.com/$p)"     # يجب ألا يكون 200
done
curl -s -o /dev/null -w "login -> %{http_code}\n" https://stockallal.souftech.com/login          # 200
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
sudo -u user /usr/bin/php8.4 artisan config:cache && sudo -u user /usr/bin/php8.4 artisan route:cache && sudo -u user /usr/bin/php8.4 artisan view:cache
```

## 8. اختبار التوافق مع MySQL (مرة واحدة)
الاختبارات مكتوبة على SQLite. هنا نشغّلها مرة على قاعدة MySQL مؤقتة:
```bash
v-add-database user stocktest stocktest '<DB_PASS_TEST>' mysql
sudo -u user env COMPOSER_HOME=/home/user/.composer /usr/bin/php8.4 $(which composer) install      # حزم dev للاختبار
sudo -u user /usr/bin/php8.4 artisan config:clear
sudo -u user env DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=user_stocktest DB_USERNAME=user_stocktest DB_PASSWORD='<DB_PASS_TEST>' /usr/bin/php8.4 artisan test
v-delete-database user user_stocktest
sudo -u user env COMPOSER_HOME=/home/user/.composer /usr/bin/php8.4 $(which composer) install --no-dev --optimize-autoloader
sudo -u user /usr/bin/php8.4 artisan config:cache
```
**يجب أن تمر كل الاختبارات** (17 في أول نشر). إذا فشل اختبار، توقّف وأبلغ المستخدم.

## 9. المهمة المجدولة
```bash
v-add-cron-job user '*' '*' '*' '*' '*' "cd /home/user/web/stockallal.souftech.com/public_html && /usr/bin/php8.4 artisan schedule:run >/dev/null 2>&1"
```

## 10. التحقق النهائي
```bash
curl -s -o /dev/null -w "%{http_code}\n" https://stockallal.souftech.com/up       # 200
curl -s https://stockallal.souftech.com/api/agent/ping                            # {"error":"token invalide ..."} = الـAPI يعمل
sudo -u user /usr/bin/php8.4 artisan schedule:list                                # مهمة التنظيف اليومية تظهر
sudo -u user /usr/bin/php8.4 artisan agent:install-code                           # كود تركيب لأول فرع
```

## 11. ما تعطيه للمستخدم في النهاية
- **اللوحة:** https://stockallal.souftech.com، مع اسم المستخدم الذي أعطاه. ذكّره بتغيير كلمة السر من «حسابي»، خاصة إذا كانت تساوي اسم المستخدم.
- **عنوان الـAPI للوكلاء:** `https://stockallal.souftech.com/api`. هذا هو العنوان الافتراضي في المثبّت وفي `agent/install.ps1`.
- **كود تركيب لأول فرع:** من الخطوة 10، أو من اللوحة ← «الفروع» ← «كود تركيب جديد».
- **على سيرفر Gescomm في كل فرع:**
  1. من اللوحة ← «الفروع» ← «تنزيل الوكيل»، وفكّ ضغط الملف كاملاً.
  2. شغّل `GescomAgent-Setup.exe` واقبل صلاحيات المسؤول.
  3. اكتب اسم الفرع والكود، ثم «تركيب». المثبّت يجد قاعدة Gescomm وحده.

**المثبّت `agent/GescomAgent-Setup.exe` يأتي من Git كما هو:** السيرفر لا يبنيه، فلا تحاول بناءه هنا. إذا فشل `php artisan test` في `AgentSetupTest`، فالـexe لم يُعَد بناؤه بعد تعديل `agent/setup/`. أبلغ المستخدم: يجب بناؤه على جهاز Windows ورفعه.

---

## التحديثات لاحقاً
```bash
cd /home/user/web/stockallal.souftech.com/public_html
sudo -u user /usr/bin/php8.4 artisan down --retry=15   # صفحة «تحديث قصير» تُعاد تلقائياً، والوكلاء يعيدون المحاولة
sudo -u user git pull
sudo -u user env COMPOSER_HOME=/home/user/.composer /usr/bin/php8.4 $(which composer) install --no-dev --optimize-autoloader
sudo -u user /usr/bin/php8.4 artisan migrate --force
sudo -u user /usr/bin/php8.4 artisan optimize
sudo -u user /usr/bin/php8.4 artisan up
```

- **إذا فشل أمر في الوسط:** الموقع يبقى في وضع الصيانة. أصلح السبب ثم نفّذ `artisan up`، ولا تتركه هكذا.
- **مدة التوقف:** عادةً أقل من دقيقة. المستخدم يرى «تحديث قصير للنظام»، وتعاد الصفحة وحدها كل 15 ثانية.

## «الموقع غير متوفر» أحياناً: الشبكة وليس السيرفر
قياس من شبكة المكتب في 2026-11-01:
- **فشل الاتصال:** نحو نصف اتصالات TCP الجديدة يفشل، نحو السيرفر وأيضاً نحو Cloudflare وGitHub وHostinger. Google لا يفشل، لأن خوادمه داخل الجزائر.
- **السيرفر سليم:** الـping بدون فقدان، وكل طلب وصل أجاب خلال 0.2 ثانية.
- **السبب:** خط الإنترنت أو مزوّده، لا الكود ولا المزامنة.

قياس ثانٍ في نفس الليلة:
- **عنوان الخط:** المكتب وفرع stockArtisana يخرجان من نفس العنوان `154.244.255.12`، فتوقّف وكيل الفرع من نفس الخط.
- **HTTP/3 (UDP) لا يحسّن:** يسقط هو أيضاً.
- **Cloudflare أفضل:** عبر Marseille نجح 10 إلى 11 اتصالاً من 12، مقابل 3 إلى 7 من 12 نحو السيرفر.

**ما يخفّف الأثر:**
- **في الكود، الطلبات داخل الصفحة:** صفحة البحث والفروع تعيد المحاولة وحدها، وبدون تكرار الطلبات، عبر `fetchRetry` و `newId` في `layouts/app.blade.php`.
- **في الكود، فتح الصفحات:** `public/sw.js` (service worker) يعيد طلب الصفحة حتى 3 مرات. إن بقي الخط مقطوعاً، تظهر `public/offline.html` بدل «This site can't be reached»، وتفتح الصفحة وحدها عند عودة الاتصال.
- **في nginx:** اجعل `keepalive_timeout` على الأقل `300s` في قالب Hestia للنطاق. هكذا يعيد المتصفح استعمال الاتصال المفتوح بدل فتح اتصال جديد قد يفشل.
- **Cloudflare (قرار المالك):** نقل DNS النطاق `souftech.com` إلى Cloudflare مع تفعيل البروكسي (السحابة البرتقالية) لـ `stockallal`. المتصفحات والوكلاء يتصلون بـ Marseille، وهو المسار الأفضل حسب القياس، وCloudflare يكمل الطريق إلى السيرفر عبر شبكة مستقرة. يجب نقل كل سجلات DNS الحالية، ومنها البريد، قبل تغيير الـnameservers.
- **عند المزوّد:** إن استمر المشكل، قارن من شبكة الهاتف (4G). وإن كانت أفضل، فالمشكل عند مزوّد الخط.
