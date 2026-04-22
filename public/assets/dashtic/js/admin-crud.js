/**
 * Admin CRUD - نظام CRUD احترافي للوحة التحكم
 * 
 * @version 2.1.0
 * @author Rased Platform
 * 
 * نظام متكامل للتعامل مع عمليات CRUD (Create, Read, Update, Delete)
 * يدعم AJAX requests، form handling، validation، error handling، pagination مع filters
 */

(function($, window) {
    'use strict';

    /**
     * AdminCRUD - Class رئيسي للتعامل مع عمليات CRUD
     */
    class AdminCRUD {
        /**
         * Constructor
         * @param {object} config - Configuration object
         */
        constructor(config = {}) {
            this.config = {
                csrfToken: null,
                defaultMethod: 'POST',
                timeout: 60000, // زيادة timeout إلى 60 ثانية
                retryAttempts: 2, // إضافة retry تلقائي
                retryDelay: 1000,
                csrfRefreshInterval: 10 * 60 * 1000, // تحديث CSRF كل 10 دقائق
                ...config
            };

            // Store active list instances
            this.activeLists = new Map();
            
            // CSRF refresh timer
            this.csrfRefreshTimer = null;

            this._init();
        }

        /**
         * Initialize AdminCRUD
         * @private
         */
        _init() {
            this._setupCSRF();
            this._setupDefaultAjax();
            this._startCSRFRefresh();
        }

        /**
         * Setup CSRF Token
         * @private
         */
        _setupCSRF() {
            if (!this.config.csrfToken) {
                this.config.csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
            }
        }

        /**
         * Setup default AJAX configuration
         * @private
         */
        _setupDefaultAjax() {
            const self = this;
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': this.config.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                timeout: this.config.timeout
            });
            
            // معالجة أخطاء CSRF تلقائياً
            $(document).ajaxError(function(event, xhr, settings, thrownError) {
                if (xhr.status === 419 && !settings._csrfRetried) {
                    // CSRF token expired - تحديثه تلقائياً
                    settings._csrfRetried = true; // منع الحلقات اللا نهائية
                    self._refreshCSRFToken().then((success) => {
                        if (success) {
                            // تحديث CSRF token في الطلب
                            settings.headers = settings.headers || {};
                            settings.headers['X-CSRF-TOKEN'] = self.config.csrfToken;
                            // إعادة المحاولة تلقائياً
                            if (settings.retryOnCSRF !== false) {
                                $.ajax(settings);
                            }
                        } else {
                            // إذا فشل التحديث، إعادة تحميل الصفحة
                            if (window.showToast) {
                                window.showToast('انتهت الجلسة، سيتم إعادة تحميل الصفحة...', 'warning', 3000);
                            }
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        }
                    }).catch(() => {
                        // إذا فشل التحديث، إعادة تحميل الصفحة
                        if (window.showToast) {
                            window.showToast('انتهت الجلسة، سيتم إعادة تحميل الصفحة...', 'warning', 3000);
                        }
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    });
                }
            });
        }

        /**
         * Get CSRF Token
         * @returns {string} CSRF Token
         */
        getCSRFToken() {
            return this.config.csrfToken || $('meta[name="csrf-token"]').attr('content') || '';
        }

        /**
         * Refresh CSRF Token
         * @private
         */
        async _refreshCSRFToken() {
            try {
                const response = await fetch(window.location.href, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newToken = doc.querySelector('meta[name="csrf-token"]');
                
                if (newToken) {
                    const token = newToken.getAttribute('content');
                    this.config.csrfToken = token;
                    $('meta[name="csrf-token"]').attr('content', token);
                    $.ajaxSetup({
                        headers: {
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    });
                    return true;
                }
                return false;
            } catch (error) {
                console.error('Error refreshing CSRF token:', error);
                return false;
            }
        }

        /**
         * Start CSRF token refresh timer
         * @private
         */
        _startCSRFRefresh() {
            if (this.csrfRefreshTimer) {
                clearInterval(this.csrfRefreshTimer);
            }
            
            this.csrfRefreshTimer = setInterval(() => {
                this._refreshCSRFToken().then(success => {
                    if (success) {
                        console.log('✓ CSRF token refreshed successfully');
                    }
                });
            }, this.config.csrfRefreshInterval);
        }

        /**
         * Stop CSRF token refresh timer
         */
        stopCSRFRefresh() {
            if (this.csrfRefreshTimer) {
                clearInterval(this.csrfRefreshTimer);
                this.csrfRefreshTimer = null;
            }
        }

        /**
         * Show notification/toast message
         * @param {string} type - Type: success, error, warning, info
         * @param {string} message - Message text
         * @param {object} options - Additional options
         * @returns {AdminCRUD} For chaining
         */
        notify(type, message, options = {}) {
            const notificationType = this._normalizeNotificationType(type);
            
            if (window.adminNotifications && typeof window.adminNotifications[notificationType] === 'function') {
                window.adminNotifications[notificationType](message);
            } else {
                // Fallback to console
                const consoleMethod = notificationType === 'error' || notificationType === 'danger' 
                    ? 'error' 
                    : 'log';
                console[consoleMethod](`[AdminCRUD ${notificationType.toUpperCase()}]`, message);
            }

            // Trigger custom event
            $(document).trigger('admincrud:notify', [notificationType, message, options]);

            return this;
        }

        /**
         * Normalize notification type
         * @private
         */
        _normalizeNotificationType(type) {
            const typeMap = {
                'danger': 'error',
                'success': 'success',
                'warning': 'warning',
                'info': 'info',
                'error': 'error'
            };
            return typeMap[type.toLowerCase()] || 'info';
        }

        /**
         * Set loading state
         * @param {jQuery|string|HTMLElement} container - Container element (tbody, div, etc)
         * @param {boolean} show - Show or hide loading
         * @param {object} options - Loading options
         * @returns {AdminCRUD} For chaining
         */
        setLoading(container, show, options = {}) {
            const $container = $(container);
            if (!$container.length) return this;

            const {
                loadingHtml = '<tr><td colspan="100%" class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">جاري التحميل...</span></div></td></tr>',
                emptyHtml = null
            } = options;

            // Check if container is tbody or regular div
            const isTableBody = $container.is('tbody');
            const loadingSelector = isTableBody ? '.admincrud-loading-row' : '.admincrud-loading-overlay';

            if (show) {
                // Remove existing loading
                $container.find(loadingSelector).remove();

                if (isTableBody) {
                    // For tbody, add loading row
                    $container.prepend($(loadingHtml));
                } else {
                    // For div, add overlay
                    const overlay = $('<div class="admincrud-loading-overlay" style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.9);z-index:9999;display:flex;align-items:center;justify-content:center;border-radius:inherit;">')
                        .html('<div class="spinner-border text-primary" role="status"><span class="visually-hidden">جاري التحميل...</span></div>');
                    $container.css('position', 'relative').append(overlay);
                }
            } else {
                $container.find(loadingSelector).remove();
            }

            // Trigger custom event
            $(document).trigger('admincrud:loading', [container, show, options]);

            return this;
        }

        /**
         * Debounce function
         * @param {Function} func - Function to debounce
         * @param {number} wait - Wait time in milliseconds
         * @param {boolean} immediate - Execute immediately
         * @returns {Function} Debounced function
         */
        debounce(func, wait, immediate = false) {
            let timeout;
            return function() {
                const context = this;
                const args = arguments;
                const later = function() {
                    timeout = null;
                    if (!immediate) func.apply(context, args);
                };
                const callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(context, args);
            };
        }

        /**
         * Throttle function
         * @param {Function} func - Function to throttle
         * @param {number} limit - Time limit in milliseconds
         * @returns {Function} Throttled function
         */
        throttle(func, limit) {
            let inThrottle;
            return function() {
                const args = arguments;
                const context = this;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        }

        /**
         * Escape HTML
         * @param {string} str - String to escape
         * @returns {string} Escaped string
         */
        escapeHtml(str) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(str || '').replace(/[&<>"']/g, m => map[m]);
        }

        /**
         * Delete item
         * @param {object} options - Delete options
         * @returns {Promise} Promise that resolves/rejects with response
         */
        async delete(options) {
            const {
                url,
                id,
                confirmMessage = 'هل أنت متأكد من الحذف؟',
                confirmTitle = 'تأكيد الحذف',
                showConfirm = true,
                onSuccess = null,
                onError = null,
                onBeforeDelete = null,
                onAfterDelete = null,
                loadingContainer = null,
                customData = {},
                ...ajaxOptions
            } = options;

            // Show confirmation
            if (showConfirm && !confirm(confirmMessage)) {
                return Promise.reject({ cancelled: true });
            }

            // Before delete hook
            if (onBeforeDelete) {
                const result = await onBeforeDelete(id);
                if (result === false) {
                    return Promise.reject({ cancelled: true });
                }
            }

            const deleteUrl = url.replace('__ID__', id);
            const $loadingContainer = loadingContainer ? $(loadingContainer) : null;

            if ($loadingContainer) {
                this.setLoading($loadingContainer, true);
            }

            try {
                const response = await this._ajaxRequest({
                    url: deleteUrl,
                    method: 'DELETE',
                    data: customData,
                    ...ajaxOptions
                });

                if (response.success) {
                    this.notify('success', response.message || 'تم الحذف بنجاح');
                    if (onSuccess) onSuccess(response, id);
                    $(document).trigger('admincrud:deleted', [id, response]);
                } else {
                    this.notify('error', response.message || 'فشل الحذف');
                    if (onError) onError(response, id);
                    throw response;
                }

                if (onAfterDelete) {
                    await onAfterDelete(response, id);
                }

                return response;
            } catch (error) {
                const errorMsg = this._getErrorMessage(error, 'حدث خطأ أثناء الحذف');
                this.notify('error', errorMsg);
                if (onError) onError(error, id);
                $(document).trigger('admincrud:delete-error', [id, error]);
                throw error;
            } finally {
                if ($loadingContainer) {
                    this.setLoading($loadingContainer, false);
                }
            }
        }

        /**
         * Initialize List with Filters and Pagination
         * @param {object} options - List options
         * @returns {object} List controller object
         */
        initList(options) {
            const {
                url,
                container, // tbody - سيتم استبدال محتواه مباشرة
                filters = {}, // { searchInput: '#search', statusFilter: '#status', etc }
                searchButton = null, // Search button selector
                clearButton = null, // Clear filters button selector
                paginationContainer = null, // Where to render pagination
                countElement = null, // Element to show total count
                onSuccess = null,
                onError = null,
                pageParam = 'page',
                perPage = 100,
                listId = 'default' // Unique ID for this list instance
            } = options;

            const $container = $(container);
            if (!$container.length) {
                console.warn('[AdminCRUD] Container not found');
                return null;
            }

            // State management
            const state = {
                page: 1,
                filters: {},
                loading: false
            };

            // Get current filters from DOM
            const getFilters = () => {
                const currentFilters = {};
                $.each(filters, (key, selector) => {
                    const $element = $(selector);
                    if ($element.length) {
                        const value = $element.val() || $element.text() || '';
                        if (value) {
                            currentFilters[key] = value.trim();
                        }
                    }
                });
                return currentFilters;
            };

            // Load list function - يجلب HTML من Laravel ويستبدل tbody
            const loadList = async (page = state.page) => {
                if (state.loading) return;

                state.loading = true;
                state.page = page;

                // Get current filters
                state.filters = getFilters();

                // Prepare params for Laravel
                const params = {
                    ...state.filters,
                    [pageParam]: page,
                    per_page: perPage,
                    ajax: 1 // علامة أن الطلب AJAX
                };

                // Show loading on tbody - صف loading بسيط
                const $loadingRow = $('<tr class="admincrud-loading-row"><td colspan="100%" class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">جاري التحميل...</span></div></td></tr>');
                $container.html($loadingRow);

                try {
                    // AJAX request لجلب HTML من Laravel
                    const response = await this._ajaxRequest({
                        url: url,
                        method: 'GET',
                        data: params
                    });

                    if (response.success) {
                        // استبدال محتوى tbody بالHTML القادم من Laravel مباشرة
                        if (response.html) {
                            $container.html(response.html); // استبدال مباشر - HTML من Laravel
                        } else {
                            // إذا لم يكن هناك HTML، عرض رسالة فارغة
                            $container.html('<tr><td colspan="100%" class="text-center py-5 text-muted">لا توجد نتائج</td></tr>');
                        }

                        // Update pagination إذا كانت موجودة
                        if (response.pagination && paginationContainer) {
                            const $paginationContainer = $(paginationContainer);
                            $paginationContainer.html(response.pagination);
                            
                            // Handle pagination links - عندما ينقر على صفحة جديدة
                            $paginationContainer.off('click.admincrud').on('click.admincrud', 'a[data-page]', function(e) {
                                e.preventDefault();
                                const newPage = parseInt($(this).data('page'));
                                if (newPage && newPage !== state.page) {
                                    loadList(newPage); // جلب الصفحة الجديدة
                                }
                            });
                        }

                        // Update count إذا كان موجود
                        if (response.count !== undefined && countElement) {
                            $(countElement).text(response.count);
                        }

                        if (onSuccess) onSuccess(response, state);
                        $(document).trigger('admincrud:list-loaded', [response, state, listId]);
                    } else {
                        $container.html('<tr><td colspan="100%" class="text-center py-5 text-muted">' + (response.message || 'لا توجد نتائج') + '</td></tr>');
                        if (onError) onError(response, state);
                    }
                } catch (error) {
                    const errorMsg = this._getErrorMessage(error, 'حدث خطأ أثناء تحميل البيانات');
                    this.notify('error', errorMsg);
                    $container.html('<tr><td colspan="100%" class="text-center py-5 text-danger">' + errorMsg + '</td></tr>');
                    if (onError) onError(error, state);
                    $(document).trigger('admincrud:list-error', [error, state, listId]);
                } finally {
                    state.loading = false;
                }
            };

            // Search button handler
            if (searchButton) {
                $(searchButton).off('click.admincrud').on('click.admincrud', function(e) {
                    e.preventDefault();
                    loadList(1); // Reset to page 1 when searching
                });
            }

            // Clear filters button handler
            if (clearButton) {
                $(clearButton).off('click.admincrud').on('click.admincrud', function(e) {
                    e.preventDefault();
                    // Clear all filter inputs
                    $.each(filters, (key, selector) => {
                        const $element = $(selector);
                        if ($element.is('input, textarea, select')) {
                            $element.val('');
                        }
                    });
                    loadList(1); // Reset to page 1
                });
            }

            // Enter key on search input
            if (filters.search || filters.q) {
                const searchSelector = filters.search || filters.q;
                $(searchSelector).off('keypress.admincrud').on('keypress.admincrud', function(e) {
                    if (e.which === 13) {
                        e.preventDefault();
                        loadList(1);
                    }
                });
            }

            // Store list controller
            const listController = {
                load: loadList,
                getState: () => ({ ...state }),
                setPage: (page) => loadList(page),
                refresh: () => loadList(state.page),
                reset: () => {
                    state.page = 1;
                    state.filters = {};
                    loadList(1);
                }
            };

            this.activeLists.set(listId, listController);

            // Initial load
            loadList(1);

            return listController;
        }

        /**
         * Load list/data (backward compatibility - simplified version)
         * @param {object} options - Load options
         * @returns {Promise} Promise that resolves/rejects with response
         */
        async loadList(options) {
            const {
                url,
                params = {},
                listContainer,
                countElement = null,
                paginationElement = null,
                onSuccess = null,
                onError = null,
                onBeforeLoad = null,
                onAfterLoad = null,
                loadingContainer = null,
                append = false,
                ...ajaxOptions
            } = options;

            const $listContainer = $(listContainer);
            if (!$listContainer.length) {
                console.warn('[AdminCRUD] List container not found');
                return Promise.reject({ error: 'Container not found' });
            }

            const $countElement = countElement ? $(countElement) : null;
            const $paginationElement = paginationElement ? $(paginationElement) : null;
            const $loadingContainer = loadingContainer ? $(loadingContainer) : $listContainer;

            // Before load hook
            if (onBeforeLoad) {
                const result = await onBeforeLoad(params);
                if (result === false) {
                    return Promise.reject({ cancelled: true });
                }
            }

            this.setLoading($loadingContainer, true);

            try {
                const requestParams = { ...params, ajax: 1 };
                const response = await this._ajaxRequest({
                    url: url,
                    method: 'GET',
                    data: requestParams,
                    ...ajaxOptions
                });

                if (response.success && response.html) {
                    if (append) {
                        $listContainer.append(response.html);
                    } else {
                        $listContainer.html(response.html);
                    }

                    if (response.count !== undefined && $countElement) {
                        $countElement.text(response.count);
                    }

                    if (response.pagination && $paginationElement) {
                        $paginationElement.html(response.pagination);
                    }

                    if (onSuccess) onSuccess(response);
                    $(document).trigger('admincrud:list-loaded', [response]);
                } else {
                    if (!append) {
                        $listContainer.html('<div class="text-center text-muted py-5">' + (response.message || 'لا توجد نتائج') + '</div>');
                    }
                    if (onError) onError(response);
                }

                if (onAfterLoad) {
                    await onAfterLoad(response);
                }

                return response;
            } catch (error) {
                const errorMsg = this._getErrorMessage(error, 'حدث خطأ أثناء تحميل البيانات');
                this.notify('error', errorMsg);
                if (onError) onError(error);
                $(document).trigger('admincrud:list-error', [error]);
                throw error;
            } finally {
                this.setLoading($loadingContainer, false);
            }
        }

        /**
         * Submit form (Create/Update)
         * @param {object} options - Form submit options
         * @returns {Promise} Promise that resolves/rejects with response
         */
        async submitForm(options) {
            const {
                form,
                url = null,
                method = null,
                onSuccess = null,
                onError = null,
                onBeforeSubmit = null,
                onAfterSubmit = null,
                onValidate = null,
                submitButton = null,
                resetOnSuccess = false,
                redirectOnSuccess = null,
                transformData = null,
                ...ajaxOptions
            } = options;

            const $form = $(form);
            if (!$form.length) {
                console.warn('[AdminCRUD] Form not found');
                return Promise.reject({ error: 'Form not found' });
            }

            const $submitButton = submitButton ? $(submitButton) : $form.find('button[type="submit"]').first();
            const formUrl = url || $form.attr('action');
            const formMethod = method || $form.attr('method') || this.config.defaultMethod;

            return new Promise((resolve, reject) => {
                $form.off('submit.admincrud').on('submit.admincrud', async (e) => {
                    e.preventDefault();

                    // Custom validation
                    if (onValidate) {
                        const validationResult = await onValidate($form);
                        if (validationResult !== true) {
                            if (typeof validationResult === 'string') {
                                this.notify('error', validationResult);
                            }
                            return reject({ validation: false, message: validationResult });
                        }
                    }

                    // HTML5 validation
                    if (!$form[0].checkValidity()) {
                        $form[0].reportValidity();
                        return reject({ validation: false });
                    }

                    // Before submit hook
                    if (onBeforeSubmit) {
                        const result = await onBeforeSubmit($form);
                        if (result === false) {
                            return reject({ cancelled: true });
                        }
                    }

                    const originalText = $submitButton.html();
                    const originalDisabled = $submitButton.prop('disabled');
                    $submitButton.prop('disabled', true);
                    $submitButton.html('<span class="spinner-border spinner-border-sm me-2"></span>جاري الحفظ...');

                    // Prepare form data
                    const hasFileInputs = $form.find('input[type="file"]').length > 0;
                    const isMultipart = $form.attr('enctype') === 'multipart/form-data';
                    let formData;

                    if (hasFileInputs || isMultipart) {
                        formData = new FormData($form[0]);
                    } else {
                        formData = $form.serialize();
                    }

                    // Transform data if needed
                    if (transformData && typeof transformData === 'function') {
                        formData = await transformData(formData, $form);
                    }

                    try {
                        const response = await this._ajaxRequest({
                            url: formUrl,
                            method: formMethod,
                            data: formData,
                            processData: !(formData instanceof FormData),
                            contentType: formData instanceof FormData ? false : 'application/x-www-form-urlencoded; charset=UTF-8',
                            ...ajaxOptions
                        });

                        if (response.success) {
                            this.notify('success', response.message || 'تم الحفظ بنجاح');
                            
                            // تحديث فوري للإشعارات والرسائل
                            this.refreshNotificationsAndMessages();
                            
                            if (resetOnSuccess) {
                                $form[0].reset();
                                $form.find('.is-invalid').removeClass('is-invalid');
                                $form.find('.invalid-feedback').remove();
                            }

                            if (redirectOnSuccess) {
                                setTimeout(() => {
                                    window.location.href = typeof redirectOnSuccess === 'function' 
                                        ? redirectOnSuccess(response) 
                                        : redirectOnSuccess;
                                }, 500);
                            }

                            if (onSuccess) await onSuccess(response, $form);
                            $(document).trigger('admincrud:form-submitted', [response, $form]);
                            resolve(response);
                        } else {
                            this.notify('error', response.message || 'فشل الحفظ');
                            if (onError) await onError(response, $form);
                            reject(response);
                        }

                        if (onAfterSubmit) {
                            await onAfterSubmit(response, $form);
                        }
                    } catch (error) {
                        this._handleFormError(error, $form);
                        if (onError) await onError(error, $form);
                        reject(error);
                    } finally {
                        $submitButton.prop('disabled', originalDisabled);
                        $submitButton.html(originalText);
                    }
                });
            });
        }

        /**
         * Handle form validation errors
         * @private
         */
        _handleFormError(error, $form) {
            if (error.status === 422) {
                const errors = error.responseJSON?.errors || {};
                $form.find('.is-invalid').removeClass('is-invalid');
                $form.find('.invalid-feedback').remove();

                $.each(errors, (field, messages) => {
                    const $field = $form.find(`[name="${field}"], [name="${field}[]"]`).first();
                    if ($field.length) {
                        $field.addClass('is-invalid');
                        const errorMsg = Array.isArray(messages) ? messages[0] : messages;
                        $field.closest('.form-group, .mb-3, .col-md-6, .col-12').append(
                            `<div class="invalid-feedback d-block">${this.escapeHtml(errorMsg)}</div>`
                        );
                        // Scroll to first error
                        if ($form.find('.is-invalid').length === 1) {
                            $('html, body').animate({
                                scrollTop: $field.offset().top - 100
                            }, 500);
                        }
                    }
                });

                const firstError = Object.values(errors).flat()[0];
                this.notify('error', firstError || 'يرجى التحقق من الحقول المطلوبة');
            } else {
                const errorMsg = this._getErrorMessage(error, 'حدث خطأ أثناء الحفظ');
                this.notify('error', errorMsg);
            }
        }

        /**
         * Get error message from error object
         * @private
         */
        _getErrorMessage(error, defaultMessage) {
            if (error.responseJSON?.message) {
                return error.responseJSON.message;
            } else if (error.message) {
                return error.message;
            } else if (error.status === 403) {
                return 'ليس لديك صلاحية للقيام بهذه العملية';
            } else if (error.status === 404) {
                return 'العنصر المطلوب غير موجود';
            } else if (error.status === 422) {
                return 'يرجى التحقق من البيانات المدخلة';
            } else if (error.status === 500) {
                return 'حدث خطأ في الخادم، يرجى المحاولة لاحقاً';
            } else if (error.status === 419) {
                return 'انتهت الجلسة، سيتم تحديثها تلقائياً...';
            } else if (error.status === 0 || error.statusText === 'timeout') {
                return 'انتهت مهلة الاتصال، يرجى المحاولة مرة أخرى';
            }
            return defaultMessage;
        }

        /**
         * Make AJAX request with retry support
         * @private
         */
        async _ajaxRequest(options, attempt = 0) {
            try {
                // تحديث CSRF token قبل كل طلب
                const currentToken = this.getCSRFToken();
                if (currentToken) {
                    options.headers = options.headers || {};
                    options.headers['X-CSRF-TOKEN'] = currentToken;
                }
                
                const response = await $.ajax(options);
                return response;
            } catch (error) {
                // إذا كان خطأ CSRF، حاول تحديثه أولاً
                if (error.status === 419 && attempt === 0) {
                    const refreshed = await this._refreshCSRFToken();
                    if (refreshed) {
                        // إعادة المحاولة مع token جديد
                        return this._ajaxRequest(options, attempt + 1);
                    }
                }
                
                if (attempt < this.config.retryAttempts && this._shouldRetry(error)) {
                    await this._delay(this.config.retryDelay * (attempt + 1));
                    return this._ajaxRequest(options, attempt + 1);
                }
                throw error;
            }
        }

        /**
         * تحديث فوري للإشعارات والرسائل
         */
        refreshNotificationsAndMessages() {
            // تحديث الإشعارات
            if (window.notificationPanel && typeof window.notificationPanel.loadNotifications === 'function') {
                window.notificationPanel.loadNotifications();
            }
            
            // تحديث الرسائل
            if (window.MessagesPanel && typeof window.MessagesPanel.loadUnreadCount === 'function') {
                window.MessagesPanel.loadUnreadCount();
                window.MessagesPanel.loadRecentMessages();
            }
        }

        /**
         * Check if error should trigger retry
         * @private
         */
        _shouldRetry(error) {
            // إضافة 419 (CSRF expired) للـ retry
            return error.status === 0 || error.status === 419 || error.status === 500 || error.status === 502 || error.status === 503;
        }

        /**
         * Delay helper
         * @private
         */
        _delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    }

    // Create singleton instance
    const instance = new AdminCRUD();

    // Make instance available globally
    window.AdminCRUD = instance;

    // Also expose class for advanced usage
    window.AdminCRUDClass = AdminCRUD;

    // Initialize on DOM ready
    $(document).ready(function() {
        $(document).trigger('admincrud:ready', [instance]);
    });

})(jQuery, window);
