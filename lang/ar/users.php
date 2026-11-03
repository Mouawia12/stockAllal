<?php

// Page des comptes (administrateurs) et page "حسابي".
return [
    'title' => 'المستخدمون',
    'new' => 'حساب جديد',
    'create' => 'إنشاء الحساب',
    'name' => 'الاسم',
    'username' => 'اسم المستخدم',
    'role' => 'الصلاحية',
    'created' => 'أُنشئ',
    'you' => '(أنت)',
    'admin' => 'مدير',
    'worker' => 'مستخدم',
    'no_perms' => 'البحث فقط',
    'confirm_delete' => 'حذف حساب «:username»؟ لن يستطيع الدخول بعد ذلك.',
    // HTML (<b>) : affiche avec {!! !!}
    'help' => '<b>المستخدم</b> يبحث في المخزون ويغيّر اسمه وكلمة سره من «حسابي»، ويفعل ما تؤشّر عليه من صلاحيات. <b>المدير</b> عنده كل الصلاحيات، ويدير الحسابات.
        لا يمكنك حذف حسابك ولا إزالة صلاحية المدير عنه، حتى يبقى دائماً مدير واحد على الأقل.',

    // page d'un compte : types de compte et permissions (cles = App\Support\Permissions)
    'section_account' => 'معلومات الدخول',
    'section_role' => 'نوع الحساب',
    'role_user' => 'مستخدم',
    'role_user_help' => 'يبحث في المخزون، ويفعل فقط ما تؤشّر عليه في «الصلاحيات».',
    'role_admin' => 'مدير',
    'role_admin_help' => 'كل الصلاحيات، وإدارة الحسابات والصلاحيات.',
    'section_perms' => 'الصلاحيات',
    'perms_preset_default' => 'الافتراضي',
    'perms_all' => 'الكل',
    'perms_none' => 'لا شيء',
    'perms_always' => 'مسموح دائماً: البحث في المخزون ومعاينة الكميات، وتغيير الاسم وكلمة السر من «حسابي».',
    'perms_admin_note' => 'المدير عنده كل الصلاحيات تلقائياً.',
    'perm_groups' => [
        'stock' => 'المخزون والأسعار',
        'branches' => 'الفروع والوكيل',
        'products' => 'الأصناف',
    ],
    'perms' => [
        'stock.refresh' => ['title' => 'تحديث الآن', 'short' => 'تحديث الآن', 'help' => 'يطلب الكمية اللحظية لمنتج من كل الفروع.'],
        'prices.sale' => ['title' => 'أسعار البيع', 'short' => 'أسعار البيع', 'help' => 'يرى سعر التجزئة وسعر الجملة في البحث والمعاينة.'],
        'prices.buy' => ['title' => 'سعر الشراء', 'short' => 'سعر الشراء', 'help' => 'يرى سعر الشراء (Pu Achat). معلومة حساسة.'],
        'branches.view' => ['title' => 'رؤية الفروع', 'short' => 'الفروع', 'help' => 'يفتح صفحة الفروع: الحالة، وآخر مزامنة، وعدد السلع.'],
        'branches.sync' => ['title' => 'مزامنة الفروع', 'short' => 'المزامنة', 'help' => 'زر «مزامنة الآن» ليرسل الفرع مخزونه كاملاً فوراً.'],
        'branches.manage' => ['title' => 'إدارة الفروع', 'short' => 'إدارة الفروع', 'help' => 'تغيير الأسماء، والتعطيل، والحذف، والفرع الرئيسي، وأكواد التركيب.'],
        'agent.download' => ['title' => 'تنزيل الوكيل', 'short' => 'تنزيل الوكيل', 'help' => 'تنزيل الوكيل ودليل التركيب لتركيبه في فرع جديد.'],
        'merge.manage' => ['title' => 'دمج الأصناف', 'short' => 'دمج الأصناف', 'help' => 'دمج الأكواد التي هي نفس المنتج في فروع مختلفة.'],
    ],
    'permission_invalid' => 'صلاحية غير معروفة',

    // page حسابي : ce que le compte peut faire (lecture seule)
    'your_perms' => 'صلاحياتك',
    'your_perms_admin' => 'أنت مدير: عندك كل الصلاحيات.',
    'your_perms_help' => 'يحددها المدير من صفحة «المستخدمون».',

    'edit_title' => 'تعديل :username',
    'edit_heading' => 'تعديل الحساب',
    'own_account' => 'هذا حسابك: لا يمكن إزالة صلاحية المدير عنه.',

    'display_name' => 'الاسم الظاهر',
    'login_username' => 'اسم المستخدم (للدخول)',
    'password_keep' => 'كلمة سر جديدة (اتركها فارغة للإبقاء على الحالية)',
    'password_new' => 'كلمة السر (8 أحرف على الأقل)',
    'show_password' => 'إظهار كلمة السر',

    // page حسابي
    'new_password' => 'كلمة السر الجديدة',
    'leave_empty' => '(اتركها فارغة إذا لا تريد تغييرها)',
    'confirm_password' => 'تأكيد كلمة السر الجديدة',
    'current_password' => 'كلمة السر الحالية',
    'required_to_save' => '(مطلوبة لحفظ أي تغيير)',

    'name_required' => 'الاسم مطلوب',
    'username_required' => 'اسم المستخدم مطلوب',
    'username_taken' => 'اسم المستخدم مستعمل من حساب آخر',
    'password_required' => 'كلمة السر مطلوبة',
    'password_min' => 'كلمة السر: 8 أحرف على الأقل',
    'new_password_min' => 'كلمة السر الجديدة: 8 أحرف على الأقل',
    'password_mismatch' => 'تأكيد كلمة السر الجديدة غير مطابق',
    'current_password_required' => 'أدخل كلمة السر الحالية لتأكيد التغيير',
    'current_password_wrong' => 'كلمة السر الحالية غير صحيحة',
    'cannot_demote_self' => 'لا يمكنك إزالة صلاحية المدير عن حسابك أنت.',
    'cannot_delete_self' => 'لا يمكنك حذف حسابك أنت.',
    'created_flash' => 'تم إنشاء الحساب «:username»',
    'saved_flash' => 'تم حفظ الحساب «:username»',
    'deleted_flash' => 'تم حذف الحساب «:username»',
    'account_saved' => 'تم حفظ الحساب',
];
