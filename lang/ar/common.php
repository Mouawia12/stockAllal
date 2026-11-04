<?php

// Textes communs : barre du haut, ecran des applications, theme, boutons. Memes cles dans lang/fr et lang/en.
return [
    'app_name' => 'مخزون الفروع',
    'language' => 'اللغة',
    'apps_launcher' => 'التطبيقات',
    // une cle par application de config/apps.php
    'apps' => [
        'stock' => 'المخزون',
        'sales' => 'المبيعات',
        'purchases' => 'المشتريات',
        'suppliers' => 'الموردون',
        'customers' => 'الزبائن',
        'settings' => 'الإعدادات',
    ],
    'soon' => 'قريباً',
    'available' => 'متاح',
    'in_development' => ':app: قيد التطوير',
    'hello' => 'مرحباً :name',
    'choose_app' => 'اختر التطبيق',

    'nav' => [
        'search' => 'البحث',
        'merge' => 'دمج الأصناف',
        'branches' => 'الفروع',
        'users' => 'المستخدمون',
        'activity' => 'السجل',
    ],
    'account' => 'حسابي',
    'logout' => 'خروج',
    'weak_password' => 'كلمة السر الحالية تساوي اسم المستخدم. غيّرها من :link.',
    'no_response' => 'لا رد من السيرفر',
    'page_expired' => 'انتهت صلاحية الصفحة لأنها بقيت مفتوحة مدة طويلة. أعد المحاولة.',
    'request_failed' => 'تعذّر إرسال الطلب (:error)',

    'save' => 'حفظ',
    'saved' => 'تم الحفظ',
    'delete' => 'حذف',
    'edit' => 'تعديل',
    'back' => 'رجوع',
    'show' => 'إظهار',
    'hide' => 'إخفاء',

    'theme' => [
        'change' => 'تغيير المظهر',
        'title' => 'المظهر: :theme — اضغط للتغيير',
        'auto' => 'تلقائي',
        'light' => 'نهاري',
        'dark' => 'ليلي',
    ],

    'scope' => [
        'title' => 'الفروع المعروضة: كل فروعك أو فرع واحد',
        'all' => 'كل فروعي',
        'count' => ':count',
    ],
    'palette' => [
        'title' => 'البحث الشامل',
        'button' => 'بحث',
        'placeholder' => 'كود صنف أو تسميته، أو اسم صفحة…',
        'products' => 'الأصناف',
        'pages' => 'الصفحات',
        'shortcuts' => 'الاختصارات',
        'searching' => 'بحث…',
        'nothing' => 'لا شيء يطابق «:q».',
        'failed' => 'الاتصال ضعيف. اكتب حرفاً آخر لإعادة المحاولة.',
        'key_palette' => 'البحث الشامل، من أي صفحة',
        'key_search' => 'خانة البحث في صفحة المخزون',
        'key_move' => 'التنقل بين النتائج',
        'key_open' => 'فتح',
        'key_close' => 'إغلاق',
    ],
    'keys' => [
        'enter' => 'Entrée',
        'esc' => 'Échap',
    ],
];
