<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    routes: { type: Object, required: true },
    oldUsername: { type: String, default: '' },
});

const page = usePage();
const usernameInput = ref(null);
const passwordInput = ref(null);
const showPassword = ref(false);
const capsLock = ref(false);

const form = useForm({
    username: props.oldUsername,
    password: '',
    remember: false,
});

const alertMessage = computed(() => (
    form.errors.username
    || form.errors.password
    || page.props.flash?.error
    || page.props.flash?.success
    || null
));
const alertType = computed(() => page.props.flash?.success && !form.hasErrors ? 'success' : 'error');
const canSubmit = computed(() => (
    form.username.trim().length > 0
    && form.password.length > 0
    && ! form.processing
));

function togglePassword() {
    showPassword.value = !showPassword.value;
    nextTick(() => passwordInput.value?.focus());
}

function detectCapsLock(event) {
    capsLock.value = Boolean(event.getModifierState?.('CapsLock'));
}

function submit() {
    if (form.processing) return;
    if (! form.username.trim()) {
        usernameInput.value?.focus();
        return;
    }
    if (! form.password) {
        passwordInput.value?.focus();
        return;
    }

    form.clearErrors();
    form.post(props.routes.login, {
        preserveScroll: true,
        onError: (errors) => nextTick(() => {
            (errors.username ? usernameInput : passwordInput).value?.focus();
        }),
        onFinish: () => {
            form.reset('password');
            capsLock.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`تسجيل الدخول · ${brand.name}`" />

    <main class="login-page">
        <section class="login-frame" aria-labelledby="login-title">
            <aside class="identity-panel">
                <div class="identity-brand">
                    <span class="logo-shell"><img :src="brand.logo || '/brand/dar-al-sarw-mark.svg'" :alt="brand.name || 'مطعم دار السرو'"></span>
                    <div>
                        <span>مساحة الموظفين</span>
                        <strong>{{ brand.name || 'مطعم دار السرو' }}</strong>
                    </div>
                </div>


            </aside>

            <section class="form-panel">
                <header class="form-heading">
                    <span class="mobile-brand"><img :src="brand.logo || '/brand/dar-al-sarw-mark.svg'" alt=""><b>{{ brand.name || 'مطعم دار السرو' }}</b></span>
                    <span class="form-kicker">مرحباً بعودتك</span>
                    <h1 id="login-title">تسجيل الدخول</h1>

                </header>

                <div
                    v-if="alertMessage"
                    class="login-alert"
                    :class="`is-${alertType}`"
                    :role="alertType === 'error' ? 'alert' : 'status'"
                >
                    <i :class="alertType === 'error' ? 'bi bi-exclamation-circle' : 'bi bi-check-circle'"></i>
                    <span>{{ alertMessage }}</span>
                </div>

                <form class="login-form" novalidate :aria-busy="form.processing" @submit.prevent="submit">
                    <label class="field" for="login-username">
                        <span class="field-label">رقم الجوال أو اسم المستخدم</span>
                        <span class="input-shell" :class="{ 'has-error': form.errors.username }">
                            <i class="bi bi-person"></i>
                            <input
                                id="login-username"
                                ref="usernameInput"
                                v-model.trim="form.username"
                                type="text"
                                name="username"
                                class="identity-input"
                                dir="ltr"

                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                                enterkeyhint="next"
                                maxlength="190"
                                placeholder="رقم الجوال أو اسم المستخدم"
                                :aria-invalid="Boolean(form.errors.username)"
                                autofocus
                                required
                                @input="form.clearErrors('username')"
                                @keydown.enter.prevent="passwordInput?.focus()"
                            >
                        </span>
                    </label>

                    <label class="field" for="login-password">
                        <span class="field-label">كلمة المرور</span>
                        <span class="input-shell" :class="{ 'has-error': form.errors.password }">
                            <i class="bi bi-lock"></i>
                            <input
                                id="login-password"
                                ref="passwordInput"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                name="password"
                                class="password-input"
                                dir="ltr"

                                autocomplete="current-password"
                                enterkeyhint="go"
                                maxlength="255"
                                :aria-invalid="Boolean(form.errors.password)"
                                required
                                @input="form.clearErrors('password')"
                                @keydown="detectCapsLock"
                                @keyup="detectCapsLock"
                                @blur="capsLock = false"
                            >
                            <button
                                type="button"
                                class="password-toggle"
                                :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'"
                                :aria-pressed="showPassword"
                                @click="togglePassword"
                            ><i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'"></i></button>
                        </span>
                        <span v-if="capsLock" class="caps-warning" role="status">
                            <i class="bi bi-capslock"></i> مفتاح Caps Lock مفعّل
                        </span>
                    </label>

                    <div class="form-options">
                        <label class="remember-option" for="login-remember">
                            <input id="login-remember" v-model="form.remember" type="checkbox" name="remember">
                            <span><b>ابقني مسجلاً</b><small>على هذا الجهاز فقط</small></span>
                        </label>
                        <a :href="routes.forgotPassword">نسيت كلمة المرور؟</a>
                    </div>

                    <p v-if="form.remember" class="shared-device-note">
                        <i class="bi bi-info-circle"></i> لا تستخدم هذا الخيار على جهاز مشترك بين الموظفين.
                    </p>

                    <button
                        class="submit-button"
                        :class="{ 'is-processing': form.processing }"
                        type="submit"
                        :disabled="!canSubmit"
                    >
                        <i :class="form.processing ? 'bi bi-arrow-repeat spinning' : 'bi bi-box-arrow-in-left'"></i>
                        <span>{{ form.processing ? 'جاري التحقق…' : 'دخول إلى النظام' }}</span>
                    </button>
                </form>

                <footer class="form-footer">
                    <i class="bi bi-lock"></i>
                    <span>اتصال محمي · لن نطلب كلمة مرورك خارج هذه الصفحة</span>
                </footer>
                <p v-if="page.props.restaurantBrand?.demoNotice" class="demo-note">نسخة تجريبية لمطعم وهمي</p>
            </section>
        </section>
    </main>
</template>

<style scoped>
.login-page {
    --login-primary: var(--brand);
    --login-dark: var(--brand-hover);
    display: grid;
    min-height: 100dvh;
    box-sizing: border-box;
    place-items: center;
    padding: clamp(1rem, 4vw, 3rem);
    color: var(--ink);
    background:
        var(--canvas);
}

.login-frame {
    display: grid;
    width: min(100%, 980px);
    min-height: min(680px, calc(100dvh - 2rem));
    grid-template-columns: minmax(0, .9fr) minmax(390px, 1.1fr);
    overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--brand) 12%, transparent);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: none;
    border-color: var(--line);
}

.identity-panel {
    position: relative;
    display: flex;
    min-width: 0;
    flex-direction: column;
    justify-content: space-between;
    padding: clamp(2rem, 4vw, 3.2rem);
    overflow: hidden;
    color: #FFFDFA;
    background:
        var(--brand-dark);
}
.identity-panel::after { position: absolute; width: 160px; height: 270px; inset-inline-end: -70px; top: 35%; border: 1px solid var(--accent); border-radius: 12px 12px 0 0; opacity: .24; content: ''; pointer-events: none; }

.identity-brand { position: relative; z-index: 1; display: flex; align-items: center; gap: .8rem; }
.logo-shell { display: grid; width: 62px; height: 62px; flex: 0 0 auto; place-items: center; padding: .35rem; border: 1px solid rgba(255, 255, 255, .2); border-radius: 12px; background: rgba(255, 255, 255, .94); box-shadow: none; }
.logo-shell img { width: 100%; height: 100%; object-fit: contain; }
.identity-brand > div { display: flex; flex-direction: column; }
.identity-brand span { color: rgba(255, 255, 255, .7); font-size: .875rem; font-weight: 700; }
.identity-brand strong { margin-top: .08rem; font-size: 1.18rem; }

.identity-copy { position: relative; z-index: 1; max-width: 370px; margin-block: 2rem; }
.eyebrow { display: block; padding-inline-start: .8rem; border-inline-start: 3px solid var(--accent); color: #E9D9BF; font-size: .875rem; font-weight: 700; }
.identity-copy h2 { max-width: 330px; margin: 1rem 0 .65rem; font-size: clamp(1.7rem, 3.2vw, 2.45rem); line-height: 1.5; }
.identity-copy p { max-width: 350px; margin: 0; color: rgba(255, 255, 255, .73); font-size: .9rem; line-height: 1.9; }

.identity-note { position: relative; z-index: 1; display: flex; align-items: center; gap: .7rem; padding-top: 1.2rem; border-top: 1px solid rgb(255 253 250 / 20%); }
.identity-note > i { display: grid; width: 38px; height: 38px; flex: 0 0 auto; place-items: center; border-radius: 11px; color: #F8F0E2; background: rgba(255, 255, 255, .1); }
.identity-note p { display: flex; margin: 0; flex-direction: column; }
.identity-note strong { font-size: .875rem; }
.identity-note span { margin-top: .14rem; color: rgba(255, 255, 255, .67); font-size: .875rem; line-height: 1.6; }

.form-panel { display: flex; min-width: 0; flex-direction: column; justify-content: center; padding: clamp(2rem, 5vw, 4.25rem); background: var(--surface); }
.mobile-brand { display: none; }
.form-kicker { color: var(--login-primary); font-size: .875rem; font-weight: 800; }
.form-heading h1 { margin: .35rem 0 .35rem; color: var(--ink); font-size: clamp(1.55rem, 2.8vw, 2rem); }
.form-heading p { margin: 0; color: var(--muted); font-size: .875rem; line-height: 1.7; }

.login-alert { display: flex; align-items: flex-start; gap: .5rem; margin-top: 1.1rem; padding: .72rem .8rem; border: 1px solid; border-radius: 12px; font-size: .875rem; font-weight: 700; line-height: 1.6; }
.login-alert i { margin-top: .1rem; }
.login-alert.is-error { border-color: #edc3c7; color: #8e2731; background: var(--canvas); }
.login-alert.is-success { border-color: var(--line); color: var(--ink); background: var(--canvas); }

.login-form { margin-top: 1.45rem; }
.field { display: block; margin-bottom: 1rem; }
.field-label { display: block; margin-bottom: .42rem; color: var(--ink); font-size: .875rem; font-weight: 700; }
.input-shell { position: relative; display: flex; min-height: 52px; align-items: center; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); transition: border-color .16s, box-shadow .16s, background .16s; }
.input-shell:focus-within { border-color: color-mix(in srgb, var(--brand) 62%, transparent); background: var(--surface); box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 9%, transparent); }
.input-shell.has-error { border-color: #d95a64; background: var(--surface); }
.input-shell > i { position: absolute; inset-inline-start: .9rem; color: var(--login-primary); font-size: .95rem; }
.input-shell input { width: 100%; min-width: 0; min-height: 50px; box-sizing: border-box; padding: .65rem 2.7rem; border: 0; outline: 0; color: var(--ink); background: transparent; font: inherit; font-size: .9rem; text-align: left; }
.input-shell input.identity-input,
.input-shell input.password-input { direction: ltr; text-align: left; }
.input-shell input::placeholder { color: var(--muted); }
.password-toggle { position: absolute; inset-inline-end: .3rem; display: grid; width: 44px; height: 44px; place-items: center; border: 0; border-radius: 8px; color: var(--muted); background: transparent; font-size: .95rem; }
.password-toggle:hover { color: var(--login-primary); background: color-mix(in srgb, var(--brand) 7%, transparent); }
.caps-warning { display: flex; align-items: center; gap: .35rem; margin-top: .42rem; color: #98620c; font-size: .875rem; font-weight: 750; }

.form-options { display: flex; min-height: 52px; align-items: center; justify-content: space-between; gap: .7rem; }
.remember-option { display: flex; align-items: center; gap: .5rem; cursor: pointer; user-select: none; }
.remember-option input { width: 18px; height: 18px; margin: 0; accent-color: var(--login-primary); }
.remember-option span { display: flex; flex-direction: column; }
.remember-option b { color: var(--ink); font-size: .875rem; }
.remember-option small { margin-top: .05rem; color: var(--muted); font-size: .875rem; }
.form-options > a { min-height: 44px; display: inline-flex; align-items: center; color: var(--login-primary); border-radius: 9px; font-size: .875rem; font-weight: 800; text-decoration: none; }
.form-options > a:hover { text-decoration: underline; }
.shared-device-note { display: flex; align-items: flex-start; gap: .35rem; margin: .2rem 0 .7rem; padding: .52rem .6rem; border-radius: 9px; color: #73520d; background: var(--canvas); font-size: .875rem; line-height: 1.6; }

.submit-button { display: flex; width: 100%; min-height: 52px; align-items: center; justify-content: center; gap: .5rem; margin-top: .6rem; border: 0; border-radius: 8px; color: #FFFDFA; background: var(--login-primary); box-shadow: none; font: inherit; font-size: .875rem; font-weight: 800; cursor: pointer; transition: transform .12s, background .16s, opacity .16s; }
.submit-button:hover:not(:disabled) { background: var(--login-dark); transform: translateY(-1px); }
.submit-button:active:not(:disabled) { transform: translateY(0); }
.submit-button:disabled { opacity: .52; cursor: not-allowed; box-shadow: none; }
.submit-button.is-processing { opacity: .72; cursor: wait; }
.spinning { animation: spin .75s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.form-footer { display: flex; align-items: center; justify-content: center; gap: .35rem; margin-top: 1.3rem; color: var(--muted); font-size: .875rem; text-align: center; line-height: 1.7; }
.form-footer i { color: var(--brand); }
.demo-note { margin: .65rem 0 0; color: var(--muted); font-size: .875rem; text-align: center; line-height: 1.7; }

@media (max-width: 760px) {
    .login-page { display: block; padding: 0; background: var(--surface); }
    .login-frame { display: block; min-height: 100dvh; border: 0; border-radius: 0; box-shadow: none; }
    .identity-panel { display: none; }
    .form-panel { min-height: 100dvh; box-sizing: border-box; justify-content: center; padding: max(1.5rem, env(safe-area-inset-top)) 1.25rem max(1.25rem, env(safe-area-inset-bottom)); }
    .mobile-brand { display: flex; align-items: center; gap: .55rem; margin-bottom: 1.8rem; }
    .mobile-brand img { width: 46px; height: 46px; object-fit: contain; padding: .25rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
    .mobile-brand b { color: var(--brand); font-size: .9rem; }
}

@media (max-width: 390px) {
    .form-options { align-items: flex-start; flex-direction: column; gap: 0; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; animation-duration: .01ms !important; }
}

/* Task-focused workspace layout. */
.login-page{display:grid;place-items:center;padding:28px 16px;background:var(--canvas)}.login-frame{display:flex;flex-direction:column;width:min(100%,480px);min-height:0;border:1px solid var(--line);border-radius:12px;overflow:hidden;box-shadow:none}.identity-panel{padding:24px 28px;background:var(--surface);color:var(--ink);border-bottom:1px solid var(--line)}.identity-panel::before,.identity-panel::after{display:none}.identity-brand{justify-content:flex-start;gap:14px}.identity-brand strong{font-size:20px;color:var(--ink)}.identity-brand>div>span{font-size:14px;color:var(--muted)}.logo-shell{width:56px;height:56px;border-radius:0;background:transparent;border:0;padding:0}.form-panel{padding:28px;background:var(--surface)}.mobile-brand,.form-kicker{display:none}.form-heading{margin-bottom:24px}.form-heading h1{font-size:25px;text-align:start}.login-form{gap:20px}.input-shell{min-height:52px;border-radius:8px;background:var(--canvas)}.login-submit{min-height:52px;margin-top:6px}.form-footer{justify-content:flex-start;text-align:start;margin-top:22px;padding-top:18px;border-top:1px solid var(--line)}.demo-note{text-align:start}.field-label{font-size:14px}@media(max-width:560px){.login-page{padding:20px 14px;align-items:center}.login-frame{border:1px solid var(--line);min-height:0}.identity-panel{display:block;padding:22px}.form-panel{padding:22px}.login-options{gap:12px;flex-wrap:wrap}}
</style>
