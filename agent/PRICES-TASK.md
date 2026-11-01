# مهمة مفتوحة: الوكيل يرسل سعر البيع

**لمن:** جلسة Claude (أو المطوّر) على جهاز Windows فيه Gescomm وقاعدته، لأن أسماء أعمدة الأسعار لا تُعرف إلا من القاعدة نفسها.
**عند الانتهاء:** احذف هذا الملف، واحذف السطر الذي يشير إليه في `CLAUDE.md`، في نفس الـcommit.

## ما هو جاهز في اللوحة

- `POST /agent/sync` و `POST /agent/result` يقبلان حقلاً اختيارياً `price` في كل عنصر من `items`، ويُحفظ في `branch_stock.price`. بدونه يبقى `null`.
- **الفرع الرئيسي:** يحدّده المدير في صفحة «الفروع». سعره يظهر في سطر كل صنف في البحث، والمعاينة تعرض سعر كل فرع وتُبرز السعر المختلف عن الرئيسي.
- **ترتيب النشر لا يهم:** اللوحة القديمة تتجاهل `price` إن وصلها، والوكيل القديم لا يرسله.

**الناقص:** الوكيل `agent/agent.ps1` لا يقرأ السعر بعد، لأن اسم العمود في جدول `GC003` غير معروف.

## الخطوات

### 1. معرفة أعمدة الجدول (قراءة فقط)
لا تكتب أي شيء في قاعدة Gescomm. كل سكربت isql يبدأ بـ `SET TRANSACTION READ ONLY`.

```bat
"C:\Program Files (x86)\Borland\InterBase\bin\isql.exe" -user SYSDBA -password masterkey "localhost:D:\Gescomm\LABASEN.IB"
```

```sql
SET TRANSACTION READ ONLY;
SET LIST ON;
SELECT RDB$FIELD_NAME FROM RDB$RELATION_FIELDS WHERE RDB$RELATION_NAME = 'GC003' ORDER BY RDB$FIELD_POSITION;
SELECT * FROM GC003 WHERE CODEA = '820AC';
```

المسار وكلمة السر موجودان في `C:\ProgramData\GescomAgent\config.json` إذا كان الوكيل مركّباً على هذا الجهاز.

### 2. اختيار العمود مع المستخدم
- قارن قيم منتج معروف (الاستعلام الثاني) بما تعرضه شاشة المنتج في Gescomm.
- **المطلوب:** سعر البيع الذي يغيّره المدير ويدفعه الزبون. إن وُجد أكثر من سعر بيع (HT و TTC، تجزئة وجملة…)، **اسأل المستخدم** أيّها يريد.
- **لا تُرسل سعر الشراء** إلا إذا طلبه المستخدم صراحة: كل حسابات اللوحة، ومنها العمّال، ترى السعر في البحث.
- سجّل العمود المختار ومعناه في `agent/README.md`.

### 3. تعديل `agent/agent.ps1`
- **عمود السعر اختياري:** لا تضفه إلى `$Fields`، لأن `Test-Schema` يوقف المزامنة كلها إذا غاب عمود مطلوب، وقد تختلف نسخة Gescomm بين الفروع. افحص وجوده مرة واحدة، وإن غاب: أرسل المخزون بدون سعر، وسجّل ذلك في الجريدة مرة واحدة.
- أضف العمود إلى الاستعلامين: المزامنة الكاملة، و«تحديث الآن» (`Get-StockByCode`).
- **في `ConvertTo-StockItem`:** أضف `price`، وحوّله بـ `[decimal]::Parse($v, [Globalization.NumberStyles]::Float, $Invariant)`، لأن isql قد يطبع أعمدة `DOUBLE PRECISION` بصيغة الأس مثل `4.500000000000000e+01`. قيمة `<null>` تصبح `$null`.
- غيّر `$AgentVersion` إلى `'1.0.5'`.
- **قواعد لا تُكسر:**
  - الملف ASCII فقط، لأن PowerShell 5.1 يقرأ الملف بدون BOM كـ ANSI.
  - قراءة فقط.
  - لا تغيير آخر في عقد الـAPI.

### 4. الاختبار على الجهاز
```bat
powershell -ExecutionPolicy Bypass -File agent\agent.ps1 -ConfigPath <config.json> -SelfTest -SkipApi
powershell -ExecutionPolicy Bypass -File agent\agent.ps1 -ConfigPath <config.json> -TestCode 820AC
powershell -ExecutionPolicy Bypass -File agent\agent.ps1 -ConfigPath <config.json> -Once -DryRun
```
- **`-TestCode`:** يجب أن يعرض عمود `price`.
- **`-Once -DryRun`:** يكتب ملف JSON في مجلد `outbox\`، وتأكد أن فيه `"price"`.
- **اختياري:** `python agent\tools\mock_api.py`، ثم agent بـ `api_url` = `http://127.0.0.1:8085/api` في config تجريبي. سطر `[sync]` والمعاينة يعرضان السعر.

### 5. التوثيق
- **`agent/README.md`:** أسطر SQL في «ما يقرؤه الوكيل»، واحذف جملة «مهمة مفتوحة».
- **`README.md`:** سطر «يقرأ `GC003` (الكود، التسمية، الكمية)».
- **`agent/guide/guide.html`:** سطر «بدون أي سعر» في جدول «ما يُقرأ من القاعدة». ثم أعد بناء الدليل PDF بـ `agent\guide\build-pdf.ps1`، وهذا يحتاج Edge، فهو متاح على Windows.

### 6. الرفع والنشر
- commit و push، مع حذف هذا الملف والسطر الذي يشير إليه في `CLAUDE.md`.
- **السيرفر:** قسم «التحديثات لاحقاً» في `DEPLOY.md`. فيه `migrate` لعمودَي `price` و`is_main`.
- **كل فرع:** نزّل الوكيل من صفحة «الفروع»، ثم `install.ps1 -UpdateOnly` كمدير.
- **في اللوحة:** صفحة «الفروع»، ثم «اجعله رئيسياً» على الفرع المرجع.
