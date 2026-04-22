/**
 * General Helpers - وظائف مساعدة عامة
 * يمكن استخدامها في أي مكان في المشروع
 */

(function(window) {
    'use strict';

    /**
     * GeneralHelpers - كائن يحتوي على الوظائف المساعدة
     */
    const GeneralHelpers = {
        /**
         * الحصول على المدن حسب المحافظة
         * 
         * @param {number|string} governorateId - ID المحافظة
         * @param {string} governorateCode - كود المحافظة (بديل)
         * @param {object} options - خيارات إضافية
         * @param {function} options.onSuccess - callback عند النجاح
         * @param {function} options.onError - callback عند الخطأ
         * @returns {Promise} Promise يحتوي على بيانات المدن
         */
        getCitiesByGovernorate: function(governorateId, governorateCode, options = {}) {
            const {
                onSuccess = null,
                onError = null
            } = options;

            // بناء URL
            const url = '/admin/constant-details/cities-by-governorate';
            const params = new URLSearchParams();
            if (governorateId) {
                params.append('governorate_id', governorateId);
            } else if (governorateCode) {
                params.append('governorate_code', governorateCode);
            } else {
                const error = new Error('يجب تحديد المحافظة (ID أو Code)');
                if (onError) {
                    onError(error);
                }
                return Promise.reject(error);
            }
            const fullUrl = `${url}?${params.toString()}`;

            // إرسال الطلب
            return fetch(fullUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    // محاولة قراءة JSON من الاستجابة
                    return response.json().then(data => {
                        const errorMessage = data.message || `حدث خطأ أثناء جلب المدن (${response.status})`;
                        throw new Error(errorMessage);
                    }).catch(() => {
                        // إذا فشل قراءة JSON، استخدم رسالة خطأ عامة
                        throw new Error(`حدث خطأ أثناء جلب المدن (${response.status})`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.data) {
                    if (onSuccess) {
                        onSuccess(data.data);
                    }
                    return data.data;
                } else {
                    throw new Error(data.message || 'لم يتم العثور على مدن');
                }
            })
            .catch(error => {
                if (onError) {
                    onError(error);
                }
                throw error;
            });
        },

        /**
         * تحديث select المدن بناءً على المحافظة المختارة
         * 
         * @param {string|HTMLElement} governorateSelect - select المحافظة (selector أو element)
         * @param {string|HTMLElement} citySelect - select المدينة (selector أو element)
         * @param {object} options - خيارات إضافية
         * @param {string} options.placeholder - النص الافتراضي (افتراضي: 'اختر المدينة')
         * @param {string|number} options.selectedValue - قيمة محددة مسبقاً
         */
        updateCitiesSelect: function(governorateSelect, citySelect, options = {}) {
            const {
                placeholder = 'اختر المدينة',
                selectedValue = null
            } = options;

            const governorateEl = typeof governorateSelect === 'string' 
                ? document.querySelector(governorateSelect) 
                : governorateSelect;
            const cityEl = typeof citySelect === 'string' 
                ? document.querySelector(citySelect) 
                : citySelect;

            if (!governorateEl || !cityEl) {
                console.error('GeneralHelpers.updateCitiesSelect: عناصر select غير موجودة');
                return;
            }

            // إعادة تعيين select المدينة
            cityEl.innerHTML = `<option value="">${placeholder}</option>`;
            cityEl.disabled = true;

            const governorateValue = governorateEl.value;
            if (!governorateValue || governorateValue === '') {
                return;
            }

            // الحصول على ID المحافظة من option المختار
            const selectedOption = governorateEl.options[governorateEl.selectedIndex];
            // استخدام value مباشرة كـ ID (لأن value يحتوي على ID المحافظة)
            // يمكن أيضاً استخدام dataset.governorateId إذا كان موجوداً
            const governorateId = selectedOption?.dataset?.governorateId || governorateValue;
            
            // التحقق من أن governorateId صحيح (رقم)
            if (!governorateId || isNaN(parseInt(governorateId))) {
                console.error('GeneralHelpers.updateCitiesSelect: ID المحافظة غير صحيح:', governorateId);
                cityEl.innerHTML = `<option value="">${placeholder}</option>`;
                return;
            }

            // جلب المدن
            GeneralHelpers.getCitiesByGovernorate(governorateId, null, {
                onSuccess: function(cities) {
                    cityEl.innerHTML = `<option value="">${placeholder}</option>`;
                    cities.forEach(function(city) {
                        const option = document.createElement('option');
                        option.value = city.id || city.code || city.value;
                        option.textContent = city.label;
                        if (selectedValue && (option.value == selectedValue || city.code == selectedValue || city.value == selectedValue)) {
                            option.selected = true;
                        }
                        cityEl.appendChild(option);
                    });
                    cityEl.disabled = false;
                },
                onError: function(error) {
                    console.error('خطأ في جلب المدن:', error);
                    cityEl.innerHTML = `<option value="">${placeholder}</option>`;
                }
            });
        },
        /**
         * الحصول على المشغلين التابعين لمحافظة معينة
         * 
         * @param {number} governorateValue - رقم المحافظة (10, 20, 30, 40)
         * @param {object} options - خيارات إضافية
         * @param {boolean} options.activeOnly - إذا كان true، يرجع فقط المشغلين النشطين (افتراضي: true)
         * @param {function} options.onSuccess - callback عند النجاح
         * @param {function} options.onError - callback عند الخطأ
         * @returns {Promise} Promise يحتوي على بيانات المشغلين
         */
        getOperatorsByGovernorate: function(governorateValue, options = {}) {
            const {
                activeOnly = true,
                onSuccess = null,
                onError = null
            } = options;

            // التحقق من صحة رقم المحافظة
            const validGovernorates = [10, 20, 30, 40];
            if (!validGovernorates.includes(governorateValue)) {
                const error = new Error('رقم المحافظة غير صحيح. يجب أن يكون 10, 20, 30, أو 40');
                if (onError) {
                    onError(error);
                }
                return Promise.reject(error);
            }

            // بناء URL
            const url = `/admin/operators/by-governorate/${governorateValue}`;
            const params = new URLSearchParams();
            if (activeOnly) {
                params.append('active_only', '1');
            }
            const fullUrl = params.toString() ? `${url}?${params.toString()}` : url;

            // إرسال الطلب
            return fetch(fullUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.data) {
                    if (onSuccess) {
                        onSuccess(data.data);
                    }
                    return data.data;
                } else {
                    throw new Error(data.message || 'حدث خطأ في جلب البيانات');
                }
            })
            .catch(error => {
                console.error('Error fetching operators by governorate:', error);
                if (onError) {
                    onError(error);
                }
                throw error;
            });
        },

        /**
         * ملء select dropdown بالمشغلين حسب المحافظة
         * 
         * @param {number} governorateValue - رقم المحافظة
         * @param {string|HTMLElement} selectSelector - selector أو element للـ select
         * @param {object} options - خيارات إضافية
         * @param {boolean} options.activeOnly - إذا كان true، يرجع فقط المشغلين النشطين
         * @param {string} options.placeholder - نص الخيار الافتراضي
         * @param {function} options.onSuccess - callback عند النجاح
         * @param {function} options.onError - callback عند الخطأ
         */
        fillOperatorsSelect: function(governorateValue, selectSelector, options = {}) {
            const {
                activeOnly = true,
                placeholder = 'اختر المشغل',
                onSuccess = null,
                onError = null
            } = options;

            // الحصول على element الـ select
            const selectElement = typeof selectSelector === 'string' 
                ? document.querySelector(selectSelector) 
                : selectSelector;

            if (!selectElement) {
                const error = new Error('عنصر الـ select غير موجود');
                if (onError) {
                    onError(error);
                }
                return Promise.reject(error);
            }

            // إظهار حالة التحميل
            const originalHTML = selectElement.innerHTML;
            selectElement.innerHTML = `<option value="">جاري التحميل...</option>`;
            selectElement.disabled = true;

            // جلب البيانات
            return this.getOperatorsByGovernorate(governorateValue, {
                activeOnly,
                onSuccess: (operators) => {
                    // مسح الخيارات القديمة
                    selectElement.innerHTML = '';

                    // إضافة الخيار الافتراضي
                    const defaultOption = document.createElement('option');
                    defaultOption.value = '';
                    defaultOption.textContent = placeholder;
                    selectElement.appendChild(defaultOption);

                    // إضافة المشغلين
                    operators.forEach(operator => {
                        const option = document.createElement('option');
                        option.value = operator.id;
                        option.textContent = operator.name + (operator.city ? ` - ${operator.city}` : '');
                        option.dataset.city = operator.city || '';
                        option.dataset.unitNumber = operator.unit_number || '';
                        selectElement.appendChild(option);
                    });

                    // تفعيل الـ select
                    selectElement.disabled = false;

                    // trigger change event
                    selectElement.dispatchEvent(new Event('change', { bubbles: true }));

                    if (onSuccess) {
                        onSuccess(operators);
                    }
                },
                onError: (error) => {
                    // استعادة الحالة الأصلية
                    selectElement.innerHTML = originalHTML;
                    selectElement.disabled = false;

                    // إضافة رسالة خطأ
                    const errorOption = document.createElement('option');
                    errorOption.value = '';
                    errorOption.textContent = 'حدث خطأ في جلب البيانات';
                    selectElement.innerHTML = '';
                    selectElement.appendChild(errorOption);

                    if (onError) {
                        onError(error);
                    }
                }
            });
        }
    };

    // جعل GeneralHelpers متاحاً بشكل عام
    window.GeneralHelpers = GeneralHelpers;

    // دعم jQuery إذا كان موجوداً
    if (typeof jQuery !== 'undefined') {
        jQuery.fn.fillOperatorsByGovernorate = function(governorateValue, options = {}) {
            const selectElement = this[0];
            if (!selectElement || selectElement.tagName !== 'SELECT') {
                console.error('Element must be a SELECT element');
                return this;
            }

            GeneralHelpers.fillOperatorsSelect(governorateValue, selectElement, options);
            return this;
        };
    }

})(window);






