/**
 * Cascading Selects Handler
 * يدير سلسلة اختيار: المشغل → وحدة التوليد → المولد
 * 
 * Usage:
 * CascadingSelects.init({
 *     prefix: '',                                    // اختياري: prefix لـ IDs
 *     canSelectOperator: true,                       // هل يستطيع المستخدم اختيار المشغل؟
 *     operatorUrl: '/admin/operators/{id}/generation-units-for-logs',
 *     generationUnitUrl: '/admin/generation-units/{id}/generators-for-logs',
 *     tariffUrl: '/admin/operators/{id}/api/tariff-price', // اختياري
 *     onOperatorChange: function(operatorId) {},    // callback
 *     onGenerationUnitChange: function(unitId) {},  // callback
 *     onGeneratorChange: function(generatorId) {},  // callback
 *     useSelect2: true,                              // استخدام Select2
 *     select2Options: { dir: 'rtl', language: 'ar' } // خيارات Select2
 * });
 */

(function($) {
    'use strict';

    window.CascadingSelects = {
        defaults: {
            prefix: '',
            canSelectOperator: true,
            operatorUrl: '/admin/operators/{id}/generation-units-for-logs',
            generationUnitUrl: '/admin/generation-units/{id}/generators-for-logs',
            tariffUrl: null,
            tariffDateField: null,
            tariffPriceField: null,
            useSelect2: true,
            select2Options: {
                dir: 'rtl',
                language: 'ar',
                allowClear: true,
                width: '100%',
                placeholder: function() {
                    return $(this).data('placeholder') || '';
                }
            },
            onOperatorChange: null,
            onGenerationUnitChange: null,
            onGeneratorChange: null,
            labels: {
                operator: 'المشغل',
                generationUnit: 'وحدة التوليد',
                generator: 'المولد',
                selectFirst: 'اختر أولاً',
                loading: 'جاري التحميل...',
                noData: 'لا توجد بيانات',
                error: 'حدث خطأ في التحميل'
            }
        },

        instances: {},

        init: function(options) {
            if (options && options.routes) {
                if (options.routes.generationUnits) {
                    options.operatorUrl = options.routes.generationUnits.replace(/__OPERATOR__/g, '{id}');
                }
                if (options.routes.generators) {
                    options.generationUnitUrl = options.routes.generators.replace(/__UNIT__/g, '{id}');
                }
            }
            const settings = $.extend(true, {}, this.defaults, options);
            const prefix = settings.prefix;
            const instanceId = prefix || 'default';

            // احصل على العناصر
            const $operator = $(`#${prefix}operator_id`);
            const $generationUnit = $(`#${prefix}generation_unit_id`);
            const $generator = $(`#${prefix}generator_id`);

            if (!$operator.length) {
                console.warn('CascadingSelects: Operator select not found');
                return null;
            }

            // تهيئة Select2 إذا كانت مفعلة
            if (settings.useSelect2) {
                this.initSelect2($operator, settings.select2Options);
                if ($generationUnit.length) {
                    this.initSelect2($generationUnit, settings.select2Options);
                }
                if ($generator.length) {
                    this.initSelect2($generator, settings.select2Options);
                }
            }

            // تخزين الـ instance
            const instance = {
                settings: settings,
                elements: {
                    operator: $operator,
                    generationUnit: $generationUnit,
                    generator: $generator
                }
            };

            this.instances[instanceId] = instance;

            // ربط الأحداث حسب نوع المستخدم
            if (settings.canSelectOperator) {
                this.bindSuperAdminEvents(instance);
            } else {
                this.bindAffiliatedUserEvents(instance);
            }

            // تحميل البيانات المبدئية إذا كانت هناك قيم محددة
            this.loadInitialData(instance);

            return instance;
        },

        initSelect2: function($element, options) {
            if (!$element.length) return;

            // تدمير Select2 إذا كان موجوداً
            if ($element.hasClass('select2-hidden-accessible')) {
                $element.select2('destroy');
            }

            // التأكد من وجود placeholder
            const placeholder = $element.data('placeholder') || $element.find('option:first').text();
            const mergedOptions = $.extend({}, options, {
                placeholder: placeholder
            });

            // إذا العنصر داخل modal → نفتح الـ dropdown داخل الـ modal (z-index fix)
            const $modal = $element.closest('.modal');
            if ($modal.length) {
                mergedOptions.dropdownParent = $modal;
            }

            $element.select2(mergedOptions);
        },

        resetSelect: function($element, placeholder, settings) {
            if (!$element.length) return;

            $element.empty().append(`<option value="">${placeholder}</option>`);
            
            if (settings.useSelect2) {
                this.initSelect2($element, settings.select2Options);
            }
            
            $element.prop('disabled', true);
            $element.trigger('change');
        },

        populateSelect: function($element, data, placeholder, settings, labelKey = 'label', valueKey = 'id') {
            if (!$element.length) return;

            $element.empty().append(`<option value="">${placeholder}</option>`);

            if (data && data.length > 0) {
                data.forEach(item => {
                    const label = item[labelKey] || item.name || item.title;
                    const value = item[valueKey];
                    const $option = new Option(label, value, false, false);
                    
                    // إضافة data attributes إضافية
                    if (item.generation_unit_id) {
                        $($option).data('generation-unit-id', item.generation_unit_id);
                    }
                    
                    $element.append($option);
                });
                $element.prop('disabled', false);
            } else {
                $element.append(`<option value="" disabled>${settings.labels.noData}</option>`);
            }

            if (settings.useSelect2) {
                this.initSelect2($element, settings.select2Options);
            }
            
            $element.trigger('change');
        },

        updateHelpText: function(prefix, field, message, type = 'info') {
            const $help = $(`#${prefix}${field}_help`);
            if (!$help.length) return;

            const iconClass = type === 'success' ? 'bi-check-circle text-success' : 
                             type === 'error' ? 'bi-x-circle text-danger' : 
                             type === 'warning' ? 'bi-exclamation-triangle text-warning' : 
                             'bi-info-circle text-muted';

            $help.html(`<i class="bi ${iconClass} me-1"></i>${message}`);
        },

        bindSuperAdminEvents: function(instance) {
            const self = this;
            const settings = instance.settings;
            const { operator: $operator, generationUnit: $generationUnit, generator: $generator } = instance.elements;
            const prefix = settings.prefix;

            // عند تغيير المشغل
            $operator.on('change', async function() {
                const operatorId = $(this).val();

                // إعادة تهيئة وحدات التوليد
                if ($generationUnit.length) {
                    self.resetSelect($generationUnit, `-- اختر ${settings.labels.generationUnit} --`, settings);
                    self.updateHelpText(prefix, 'generation_unit', `اختر ${settings.labels.operator} أولاً`);
                }

                // إعادة تهيئة المولدات
                if ($generator.length) {
                    self.resetSelect($generator, `-- اختر ${settings.labels.generator} --`, settings);
                    self.updateHelpText(prefix, 'generator', `اختر ${settings.labels.generationUnit} أولاً`);
                }

                // callback
                if (typeof settings.onOperatorChange === 'function') {
                    settings.onOperatorChange(operatorId);
                }

                if (!operatorId || operatorId === '0') return;

                // تحميل وحدات التوليد
                try {
                    self.updateHelpText(prefix, 'generation_unit', settings.labels.loading, 'info');
                    
                    const url = settings.operatorUrl.replace('{id}', operatorId);
                    const response = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                        }
                    });

                    if (response.ok) {
                        const data = await response.json();
                        const units = data.generation_units || data.data || data;
                        
                        self.populateSelect(
                            $generationUnit, 
                            units, 
                            `-- اختر ${settings.labels.generationUnit} --`, 
                            settings
                        );
                        
                        if (units.length > 0) {
                            self.updateHelpText(prefix, 'generation_unit', `${units.length} وحدة متاحة`, 'success');
                        } else {
                            self.updateHelpText(prefix, 'generation_unit', settings.labels.noData, 'warning');
                        }
                        // تعبئة القيمة الأولية (مثلاً في صفحة التعديل)
                        if (settings.initialGenerationUnitId && $generationUnit.find('option[value="' + settings.initialGenerationUnitId + '"]').length) {
                            $generationUnit.val(settings.initialGenerationUnitId);
                            if (settings.useSelect2) self.initSelect2($generationUnit, settings.select2Options);
                            $generationUnit.trigger('change');
                            settings.initialGenerationUnitId = null;
                        }
                    } else {
                        self.updateHelpText(prefix, 'generation_unit', settings.labels.error, 'error');
                    }
                } catch (error) {
                    console.error('Error loading generation units:', error);
                    self.updateHelpText(prefix, 'generation_unit', settings.labels.error, 'error');
                }

                // تحميل سعر التعرفة إذا كان مطلوباً
                if (settings.tariffUrl && settings.tariffDateField && settings.tariffPriceField) {
                    self.loadTariffPrice(instance);
                }
            });

            // عند تغيير وحدة التوليد
            if ($generationUnit.length && $generator.length) {
                $generationUnit.on('change', async function() {
                    const generationUnitId = $(this).val();

                    // إعادة تهيئة المولدات
                    self.resetSelect($generator, `-- اختر ${settings.labels.generator} --`, settings);
                    self.updateHelpText(prefix, 'generator', `اختر ${settings.labels.generationUnit} أولاً`);

                    // callback
                    if (typeof settings.onGenerationUnitChange === 'function') {
                        settings.onGenerationUnitChange(generationUnitId);
                    }

                    if (!generationUnitId || generationUnitId === '0') return;

                    // تحميل المولدات
                    try {
                        self.updateHelpText(prefix, 'generator', settings.labels.loading, 'info');
                        
                        const url = settings.generationUnitUrl.replace('{id}', generationUnitId);
                        const response = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                            }
                        });

                        if (response.ok) {
                            const data = await response.json();
                            const generators = data.generators || data.data || data;
                            
                            self.populateSelect(
                                $generator, 
                                generators, 
                                `-- اختر ${settings.labels.generator} --`, 
                                settings
                            );
                            
                            if (generators.length > 0) {
                                self.updateHelpText(prefix, 'generator', `${generators.length} مولد متاح`, 'success');
                            } else {
                                self.updateHelpText(prefix, 'generator', settings.labels.noData, 'warning');
                            }
                            // تعبئة القيمة الأولية (مثلاً في صفحة التعديل)
                            if (settings.initialGeneratorId && $generator.find('option[value="' + settings.initialGeneratorId + '"]').length) {
                                $generator.val(settings.initialGeneratorId);
                                if (settings.useSelect2) self.initSelect2($generator, settings.select2Options);
                                settings.initialGeneratorId = null;
                            }
                        } else {
                            self.updateHelpText(prefix, 'generator', settings.labels.error, 'error');
                        }
                    } catch (error) {
                        console.error('Error loading generators:', error);
                        self.updateHelpText(prefix, 'generator', settings.labels.error, 'error');
                    }
                });
            }

            // عند تغيير المولد
            if ($generator.length) {
                $generator.on('change', function() {
                    const generatorId = $(this).val();
                    
                    if (typeof settings.onGeneratorChange === 'function') {
                        settings.onGeneratorChange(generatorId);
                    }
                });
            }
        },

        bindAffiliatedUserEvents: function(instance) {
            const self = this;
            const settings = instance.settings;
            const { generationUnit: $generationUnit, generator: $generator } = instance.elements;
            const prefix = settings.prefix;

            // عند تغيير وحدة التوليد (تحميل المولدات عبر AJAX)
            if ($generationUnit.length && $generator.length) {
                $generationUnit.on('change', async function() {
                    const generationUnitId = $(this).val();

                    // إعادة تهيئة المولدات
                    self.resetSelect($generator, `-- اختر ${settings.labels.generator} --`, settings);
                    self.updateHelpText(prefix, 'generator', `اختر ${settings.labels.generationUnit} أولاً`);

                    // callback
                    if (typeof settings.onGenerationUnitChange === 'function') {
                        settings.onGenerationUnitChange(generationUnitId);
                    }

                    if (!generationUnitId || generationUnitId === '0') return;

                    // تحميل المولدات عبر AJAX
                    try {
                        self.updateHelpText(prefix, 'generator', settings.labels.loading, 'info');
                        
                        const url = settings.generationUnitUrl.replace('{id}', generationUnitId);
                        const response = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                            }
                        });

                        if (response.ok) {
                            const data = await response.json();
                            const generators = data.generators || data.data || data;
                            
                            self.populateSelect(
                                $generator, 
                                generators, 
                                `-- اختر ${settings.labels.generator} --`, 
                                settings
                            );
                            
                            if (generators.length > 0) {
                                self.updateHelpText(prefix, 'generator', `${generators.length} مولد متاح`, 'success');
                            } else {
                                self.updateHelpText(prefix, 'generator', settings.labels.noData, 'warning');
                            }
                            if (settings.initialGeneratorId && $generator.find('option[value="' + settings.initialGeneratorId + '"]').length) {
                                $generator.val(settings.initialGeneratorId);
                                if (settings.useSelect2) self.initSelect2($generator, settings.select2Options);
                                settings.initialGeneratorId = null;
                            }
                        } else {
                            self.updateHelpText(prefix, 'generator', settings.labels.error, 'error');
                        }
                    } catch (error) {
                        console.error('Error loading generators:', error);
                        self.updateHelpText(prefix, 'generator', settings.labels.error, 'error');
                    }
                });

                // عند تغيير المولد
                $generator.on('change', function() {
                    const generatorId = $(this).val();
                    
                    if (typeof settings.onGeneratorChange === 'function') {
                        settings.onGeneratorChange(generatorId);
                    }
                });
            }
        },

        loadInitialData: function(instance) {
            const { operator: $operator, generationUnit: $generationUnit } = instance.elements;
            
            // إذا كان هناك مشغل محدد مسبقاً
            if ($operator.val()) {
                $operator.trigger('change');
            }
            
            // إذا كان هناك وحدة توليد محددة مسبقاً (للمستخدمين المرتبطين)
            if (!instance.settings.canSelectOperator && $generationUnit.val()) {
                $generationUnit.trigger('change');
            }
        },

        loadTariffPrice: async function(instance) {
            const settings = instance.settings;
            const $dateField = $(settings.tariffDateField);
            const $priceField = $(settings.tariffPriceField);
            const operationDate = $dateField.val();

            // الأسعار عامة لجميع المشغلين - لا نحتاج operatorId
            if (!operationDate || !$priceField.length) return;

            try {
                // استخدام API route الجديد بدون operatorId
                const url = settings.tariffUrl + `?date=${operationDate}`;
                const response = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    if (data.price && !$priceField.val()) {
                        $priceField.val(parseFloat(data.price).toFixed(2)); // خانتان عشريتان
                    }
                }
            } catch (error) {
                console.error('Error loading tariff price:', error);
            }
        },

        // الحصول على instance معين
        getInstance: function(instanceId) {
            return this.instances[instanceId || 'default'];
        },

        // تدمير instance
        destroy: function(instanceId) {
            const id = instanceId || 'default';
            const instance = this.instances[id];
            
            if (instance) {
                const { operator, generationUnit, generator } = instance.elements;
                
                // إزالة Select2
                if (instance.settings.useSelect2) {
                    [operator, generationUnit, generator].forEach($el => {
                        if ($el && $el.hasClass('select2-hidden-accessible')) {
                            $el.select2('destroy');
                        }
                    });
                }

                // إزالة الأحداث
                [operator, generationUnit, generator].forEach($el => {
                    if ($el) $el.off('change');
                });

                delete this.instances[id];
            }
        }
    };

})(jQuery);
