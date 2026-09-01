<?php

namespace App\Support;

/**
 * Stable accounting workflow copy for the accountant-facing guide.
 *
 * The entries reference posting roles instead of account codes so the guide
 * always reflects a restaurant's current mappings without rewriting UI copy.
 */
final class AccountingGuide
{
    public static function postingGroups(bool $taxEnabled): array
    {
        $invoiceCredits = [
            self::line('إيراد المبيعات قبل الخصم', 'sales_revenue'),
            self::line('رسوم الخدمة إن وُجدت', 'service_revenue', 'عند وجود رسم خدمة على الفاتورة'),
        ];

        if ($taxEnabled) {
            $invoiceCredits[] = self::line('ضريبة فاتورة الزبون', 'output_vat', 'حسب النسبة وتاريخ السريان');
        }

        $invoiceCredits[] = self::line('إكراميات الموظفين إن وُجدت', 'tips_payable');

        return [
            [
                'key' => 'sales',
                'label' => 'المبيعات والتحصيل',
                'description' => 'من إصدار الفاتورة حتى القبض أو الاسترداد أو شطب الدين.',
                'icon' => 'bi-receipt-cutoff',
                'entries' => [
                    self::entry(
                        'invoice_issued',
                        'إصدار فاتورة الزبون',
                        'عند إصدار الفاتورة من الكاشير بعد اكتمال الطلب.',
                        [
                            self::line('إجمالي الفاتورة على ذمة الزبون', 'accounts_receivable'),
                            self::line('الخصومات والعروض إن وُجدت', 'sales_discounts'),
                        ],
                        $invoiceCredits,
                        'الضريبة لا تظهر ولا تُرحّل ما دامت متوقفة. رسوم الخدمة إيراد مستقل وليست ضريبة.',
                    ),
                    self::entry(
                        'payment_received',
                        'تحصيل فاتورة',
                        'عند تأكيد الكاشير للدفع نقداً أو للبنك أو للمحفظة.',
                        [self::line('حساب وسيلة الدفع الفعلي', null, 'صندوق أو بنك أو محفظة')],
                        [self::line('ذمم الزبائن', 'accounts_receivable')],
                        'التحويل المباشر والفيزا يصلان إلى البنك فوراً؛ PalPay وJawwal Pay يبقيان في المحفظة حتى التحويل اليدوي.',
                    ),
                    self::entry(
                        'customer_advance_deposited',
                        'استلام رصيد مقدم من زبون',
                        'عندما يستلم الكاشير مبلغاً ليبقى محفوظاً باسم رقم جوال الزبون.',
                        [self::line('حساب وسيلة الاستلام الفعلية', null, 'صندوق أو بنك أو محفظة إلكترونية')],
                        [self::line('أرصدة الزبائن المقدمة', 'customer_advances')],
                        'الرصيد المقدم التزام على المطعم، وليس مبيعات أو إيراداً. يبقى متاحاً للزبون في جميع الفروع حتى يستخدمه.',
                    ),
                    self::entry(
                        'customer_advance_redeemed',
                        'استخدام رصيد مقدم في فاتورة',
                        'عندما يختار الكاشير رصيد الزبون كوسيلة دفع لفاتورة مرتبطة بالرقم نفسه.',
                        [self::line('أرصدة الزبائن المقدمة', 'customer_advances')],
                        [self::line('ذمم الزبائن', 'accounts_receivable')],
                        'يمكن استخدام جزء من الرصيد ودمجه مع النقد أو البنك لتسديد الباقي. تسجيل الفاتورة كاملة كدين لا ينشئ قيداً إضافياً لأن الذمة ثُبتت عند إصدارها.',
                    ),
                    self::entry(
                        'credit_note_issued',
                        'إشعار دائن أو تخفيض دين',
                        'عند اعتماد مرتجع أو تصحيح مبلغ على فاتورة صادرة.',
                        [
                            self::line('مردودات المبيعات', 'sales_returns'),
                            self::line('عكس الرسوم والضريبة والإكرامية بنسبتها', null, 'فقط للمبالغ الموجودة أصلاً'),
                        ],
                        [self::line('ذمم الزبائن', 'accounts_receivable')],
                        'الإشعار الدائن يخفض قيمة البيع والذمة. إذا كانت الفاتورة مدفوعة ينشأ للزبون رصيد مستحق حتى يُصرف الاسترداد.',
                    ),
                    self::entry(
                        'refund_completed',
                        'استرداد مبلغ للزبون',
                        'عند تنفيذ الاسترداد فعلياً من وسيلة الدفع.',
                        [self::line('ذمم الزبائن', 'accounts_receivable')],
                        [self::line('حساب وسيلة الاسترداد', null)],
                        'يفصل النظام تصحيح البيع في الإشعار الدائن عن صرف المال، ويمكن تقسيم الصرف على طرق الدفع الأصلية أو إضافته لرصيد الزبون.',
                    ),
                    self::entry(
                        'debt_writeoff_posted',
                        'شطب دين غير قابل للتحصيل',
                        'بعد قرار محاسبي موثق بشطب رصيد فاتورة.',
                        [self::line('مصروف ديون معدومة', 'bad_debt_expense')],
                        [self::line('ذمم الزبائن', 'accounts_receivable')],
                        'الشطب ليس خصماً ولا دفعة؛ يبقى سببه ومستخدمه ظاهرين في اليومية.',
                    ),
                ],
            ],
            [
                'key' => 'purchasing_inventory',
                'label' => 'الشراء والمخزون',
                'description' => 'الاستلام، فاتورة المورد، تكلفة الوصفة، الهدر والجرد والتحويلات.',
                'icon' => 'bi-box-seam-fill',
                'entries' => [
                    self::entry(
                        'inventory_goods_received',
                        'استلام بضاعة من أمر شراء',
                        'عند اعتماد استلام الكمية فعلياً في مخزن الفرع.',
                        [self::line('المخزون', 'inventory')],
                        [self::line('استلامات غير مفوترة', 'grni')],
                        'يثبت المخزون فور الاستلام حتى لو لم تصل فاتورة المورد بعد.',
                    ),
                    self::entry(
                        'supplier_invoice_created',
                        'إثبات فاتورة مورد',
                        'عند حفظ فاتورة المورد وربط سطورها بالاستلام أو المصروف.',
                        [
                            self::line('تسوية الاستلام غير المفوتر', 'grni', 'للأصناف المستلمة من أمر شراء'),
                            self::line('المخزون', 'inventory', 'للشراء المخزني المباشر'),
                            self::line('المصروف التشغيلي', 'operating_expenses', 'للسطر غير المخزني'),
                            self::line('ضريبة فاتورة المورد إن وُجدت', 'input_vat'),
                            self::line('فرق سعر الشراء عند وجوده', 'purchase_price_variance'),
                        ],
                        [self::line('ذمم الموردين', 'accounts_payable')],
                        'ضريبة المورد مستقلة عن ضريبة فاتورة الزبون، وتُدخل من مستند المورد نفسه.',
                    ),
                    self::entry(
                        'supplier_payment_recorded',
                        'سداد المورد',
                        'عند تسجيل دفعة فعلية على فاتورة المورد.',
                        [self::line('ذمم الموردين', 'accounts_payable')],
                        [self::line('حساب وسيلة الدفع', null, 'صندوق أو بنك أو محفظة')],
                        'في العملات الأجنبية يحتفظ القيد بالمبلغ الأصلي وسعر الصرف، ويظهر فرق العملة تلقائياً عند الحاجة.',
                    ),
                    self::entry(
                        'staff_meal_charged',
                        'تجاوز بدل وجبة موظف',
                        'عند تسليم الوجبة وكان استهلاك الموظف أعلى من المتبقي في بدله الشهري.',
                        [self::line('مستحقات وجبات الموظفين', 'staff_meal_receivable')],
                        [self::line('استرداد تكلفة وجبات الموظفين', 'staff_meal_recovery_revenue')],
                        'القيد يثبت الجزء المتجاوز فقط؛ الجزء المغطى لا ينشئ ديناً ولا إيراداً داخلياً.',
                    ),
                    self::entry(
                        'inventory_staff_meal_consumed',
                        'صرف مكونات وجبة موظف',
                        'عند بدء تحضير صنف مرتبط بموظف وخصم مكوناته الفعلية من المخزون.',
                        [self::line('تكلفة وجبات ومنافع الموظفين', 'staff_meal_benefit_expense')],
                        [self::line('المخزون', 'inventory')],
                        'لا تُسجل كتكلفة مبيعات لأنها منفعة موظف وليست فاتورة زبون. الجزء المتجاوز من البدل يُثبت مستقلاً كذمة على الموظف.',
                    ),
                    self::entry(
                        'inventory_cogs_recognized',
                        'بدء تحضير صنف مباع',
                        'عند بدء المطبخ أو البار تحضير الصنف وخصم مكونات الوصفة.',
                        [self::line('تكلفة البضاعة المباعة', 'cost_of_goods_sold')],
                        [self::line('المخزون', 'inventory')],
                        'المكوّن المستبعد من الصنف لا يُحجز ولا يُخصم ولا يدخل في التكلفة.',
                    ),
                    self::entry(
                        'inventory_cogs_reversed',
                        'إلغاء صنف وإرجاع مكوناته',
                        'عندما يقرر الجرسون أن المواد قابلة للإرجاع بعد الإلغاء.',
                        [self::line('المخزون', 'inventory')],
                        [self::line('تكلفة البضاعة المباعة', 'cost_of_goods_sold')],
                        'إذا بدأت المواد وأصبحت هدراً لا تُعاد للمخزون؛ تُعاد تصنيف التكلفة كهدر بدلاً من ذلك.',
                    ),
                    self::entry(
                        'inventory_waste_recognized',
                        'هدر أو تالف',
                        'عند اعتماد سجل الهدر أو اختيار الهدر بعد إلغاء التحضير.',
                        [self::line('مصروف الهدر والتالف', 'waste_expense')],
                        [self::line('المخزون أو تكلفة المبيعات', null, 'حسب هل خُصمت المواد سابقاً')],
                        'النظام يمنع خصم المخزون مرتين؛ بعد بدء التحضير يعيد تصنيف التكلفة من تكلفة المبيعات إلى الهدر.',
                    ),
                    self::entry(
                        'inventory_adjustment_posted',
                        'اعتماد الجرد',
                        'عند اعتماد فرق الكمية المعدودة عن رصيد النظام.',
                        [
                            self::line('المخزون عند الزيادة', 'inventory'),
                            self::line('عجز المخزون عند النقص', 'inventory_shrinkage_expense'),
                        ],
                        [
                            self::line('فرق جرد دائن عند الزيادة', 'inventory_adjustment_gain'),
                            self::line('المخزون عند النقص', 'inventory'),
                        ],
                        'كل فرق يُرحّل حسب اتجاهه، وتبقى ورقة العد والمستخدم والفرع مرجعاً للقيد.',
                    ),
                    self::entry(
                        'inventory_transfer_sent',
                        'تحويل مخزون بين الفروع',
                        'عند الإرسال ثم عند تأكيد الاستلام في الفرع الآخر.',
                        [
                            self::line('مخزون بالطريق عند الإرسال', 'inventory_in_transit'),
                            self::line('مخزون فرع الوجهة عند الاستلام', 'inventory'),
                        ],
                        [
                            self::line('مخزون فرع المصدر', 'inventory'),
                            self::line('الحساب الجاري بين الفروع', 'interbranch_current'),
                        ],
                        'دفاتر الفروع تبقى منفصلة، والحساب الجاري يتعادل فقط في العرض المجمع للمطعم.',
                    ),
                ],
            ],
            [
                'key' => 'operations_assets',
                'label' => 'المصروفات والأصول',
                'description' => 'المصروف اليومي، المحافظ، شراء الأصل وإهلاكه.',
                'icon' => 'bi-building-gear',
                'entries' => [
                    self::entry(
                        'expense_approved',
                        'اعتماد مصروف تشغيلي',
                        'عند اعتماد مصروف كهرباء أو إيجار أو راتب أو غيره.',
                        [self::line('حساب فئة المصروف', 'operating_expenses', 'أو الحساب المخصص للفئة')],
                        [self::line('حساب وسيلة الدفع', null)],
                        'يُسجل القيد من شاشة المصروف، وليس بقيد يدوي مكرر.',
                    ),
                    self::entry(
                        'wallet_to_bank',
                        'تحويل المحفظة إلى البنك',
                        'عندما ينقل المحاسب الرصيد فعلياً من PalPay أو Jawwal Pay.',
                        [self::line('البنك', 'bank_account')],
                        [self::line('المحفظة المختارة', null)],
                        'لا توجد مقاصة: الرصيد يبقى في المحفظة حتى هذا الإجراء، ولا يسمح النظام بتحويل أكبر من الرصيد.',
                    ),
                    self::entry(
                        'fixed_asset_acquired',
                        'شراء أصل ثابت',
                        'عند تسجيل فرن أو ثلاجة أو أثاث كأصل قابل للإهلاك.',
                        [self::line('الأصول الثابتة', 'fixed_assets')],
                        [self::line('الصندوق أو البنك أو ذمم المورد', null, 'حسب طريقة التمويل')],
                        'المعدات طويلة الاستخدام تُرسمل ولا تُحمّل كلها كمصروف في يوم الشراء.',
                    ),
                    self::entry(
                        'fixed_asset_depreciation',
                        'إهلاك الأصل',
                        'عند تشغيل إهلاك الشهر للأصل أو لكل الأصول النشطة.',
                        [self::line('مصروف الإهلاك', 'depreciation_expense')],
                        [self::line('مجمع الإهلاك', 'accumulated_depreciation')],
                        'يمنع النظام ترحيل الشهر نفسه مرتين للأصل نفسه.',
                    ),
                ],
            ],
            [
                'key' => 'opening_closing',
                'label' => 'الافتتاح والتصحيح والإقفال',
                'description' => 'ما يملكه المحاسب يدوياً مع بقاء أثر المراجعة كاملاً.',
                'icon' => 'bi-calendar2-check-fill',
                'entries' => [
                    self::entry(
                        'opening_balance',
                        'الأرصدة الافتتاحية للحسابات',
                        'مرة واحدة عند بدء استخدام النظام للفرع.',
                        [self::line('حسابات الميزانية ذات الرصيد المدين', null)],
                        [self::line('حسابات الميزانية ذات الرصيد الدائن', null)],
                        'يعادل النظام الفرق مع حساب الأرصدة الافتتاحية. الإيرادات والمصروفات لا تبدأ برصيد افتتاحي.',
                        false,
                    ),
                    self::entry(
                        'customer_opening_debt',
                        'ديون الزبائن والموردين الافتتاحية',
                        'من تبويبي الذمم داخل شاشة الرصيد الافتتاحي.',
                        [
                            self::line('ذمم الزبائن', 'accounts_receivable'),
                            self::line('حساب الأرصدة الافتتاحية لدين المورد', 'opening_balance_equity'),
                        ],
                        [
                            self::line('حساب الأرصدة الافتتاحية لدين الزبون', 'opening_balance_equity'),
                            self::line('ذمم الموردين', 'accounts_payable'),
                        ],
                        'تُنشأ كمستندات ذمم مستقلة حتى تعمل التحصيلات والسداد وأعمار الديون لاحقاً.',
                        false,
                    ),
                    self::entry(
                        'customer_advance_opening',
                        'رصيد مقدم افتتاحي لزبون',
                        'من تبويب رصيد الزبائن المقدم داخل شاشة الأرصدة الافتتاحية.',
                        [self::line('حساب الأرصدة الافتتاحية', 'opening_balance_equity')],
                        [self::line('أرصدة الزبائن المقدمة', 'customer_advances')],
                        'يستخدم فقط لرصيد كان موجوداً قبل تشغيل النظام، ويرتبط باسم الزبون ورقم جواله ليظهر للكاشير مباشرة.',
                        false,
                    ),
                    self::entry(
                        'manual_journal',
                        'قيد يدوي أو تصحيح',
                        'لحركة مستقلة لا يملك النظام مستنداً تشغيلياً لها، أو لعكس وتصحيح خطأ موثق.',
                        [self::line('الحسابات المدينة التي يحددها المحاسب', null)],
                        [self::line('الحسابات الدائنة التي يحددها المحاسب', null)],
                        'لا يُحذف القيد المرحّل: يُعكس ثم يُنشأ القيد الصحيح، فيبقى سجل التدقيق كاملاً.',
                        false,
                    ),
                    self::entry(
                        'fiscal_year_closing',
                        'إقفال السنة المالية',
                        'بعد إقفال الشهور واجتياز قائمة المراجعة.',
                        [self::line('حسابات الإيراد ذات الرصيد المدين عند الإقفال', null)],
                        [self::line('الأرباح المحتجزة أو حسابات المصروف ذات الرصيد الدائن', 'retained_earnings')],
                        'إقفال الشهر يمنع الترحيل فقط. تصفير الإيرادات والمصروفات يحدث مرة واحدة عند إقفال السنة.',
                        false,
                    ),
                ],
            ],
        ];
    }

    private static function entry(
        string $eventType,
        string $title,
        string $trigger,
        array $debits,
        array $credits,
        string $note,
        bool $automatic = true,
    ): array {
        return compact('eventType', 'title', 'trigger', 'debits', 'credits', 'note', 'automatic');
    }

    private static function line(string $label, ?string $role = null, ?string $condition = null): array
    {
        return compact('label', 'role', 'condition');
    }
}
