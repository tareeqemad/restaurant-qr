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
                    <span class="logo-shell"><img :src="brand.logo" :alt="brand.name"></span>
                    <div>
                        <span>مساحة الموظفين</span>
                        <strong>{{ brand.name }}</strong>
                    </div>
                </div>

                <div class="identity-copy">
                    <span class="eyebrow"><i class="bi bi-shield-check"></i> دخول آمن ومباشر</span>
                    <h2>ابدأ عملك من المكان الصحيح.</h2>
                    <p>الطلبات والطاولات والتحصيل والحسابات تظهر لك حسب دورك وصلاحياتك فقط.</p>
                </div>

                <div class="identity-note">
                    <i class="bi bi-person-check"></i>
                    <p><strong>استخدم حسابك الشخصي</strong><span>حتى تُنسب التحصيلات والإجراءات إليك بشكل صحيح.</span></p>
                </div>
            </aside>

            <section class="form-panel">
                <header class="form-heading">
                    <span class="mobile-brand"><img :src="brand.logo" alt=""><b>{{ brand.name }}</b></span>
                    <span class="form-kicker">مرحباً بعودتك</span>
                    <h1 id="login-title">تسجيل دخول الموظفين</h1>
                    <p>أدخل رقم جوالك أو اسم المستخدم ثم كلمة المرور.</p>
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
                                placeholder="0592632026 أو username"
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
            </section>
        </section>
    </main>
</template>

<style scoped>
.login-page {
    --login-primary: rgb(var(--primary-rgb, 31 107 80));
    --login-dark: rgb(var(--dark-rgb, 18 63 49));
    display: grid;
    min-height: 100dvh;
    box-sizing: border-box;
    place-items: center;
    padding: clamp(1rem, 4vw, 3rem);
    color: #1d2b22;
    background:
        radial-gradient(circle at 12% 16%, rgba(var(--accent-rgb, 185 120 24), .1), transparent 28rem),
        linear-gradient(145deg, #f3f7f4 0%, #fbfcfb 52%, #eef4f0 100%);
}

.login-frame {
    display: grid;
    width: min(100%, 980px);
    min-height: min(680px, calc(100dvh - 2rem));
    grid-template-columns: minmax(0, .9fr) minmax(390px, 1.1fr);
    overflow: hidden;
    border: 1px solid rgba(31, 65, 45, .12);
    border-radius: 28px;
    background: #fff;
    box-shadow: 0 28px 80px -42px rgba(20, 55, 35, .44);
}

.identity-panel {
    position: relative;
    display: flex;
    min-width: 0;
    flex-direction: column;
    justify-content: space-between;
    padding: clamp(2rem, 4vw, 3.2rem);
    overflow: hidden;
    color: #fff;
    background:
        linear-gradient(155deg, rgba(255, 255, 255, .08), transparent 42%),
        linear-gradient(145deg, var(--login-primary), var(--login-dark));
}
.identity-panel::before,
.identity-panel::after { position: absolute; border: 1px solid rgba(255, 255, 255, .1); border-radius: 999px; content: ''; pointer-events: none; }
.identity-panel::before { width: 330px; height: 330px; inset: -150px auto auto -155px; }
.identity-panel::after { width: 230px; height: 230px; inset: auto -110px -125px auto; }

.identity-brand { position: relative; z-index: 1; display: flex; align-items: center; gap: .8rem; }
.logo-shell { display: grid; width: 62px; height: 62px; flex: 0 0 auto; place-items: center; padding: .35rem; border: 1px solid rgba(255, 255, 255, .2); border-radius: 18px; background: rgba(255, 255, 255, .94); box-shadow: 0 12px 28px -18px rgba(0, 0, 0, .5); }
.logo-shell img { width: 100%; height: 100%; object-fit: contain; }
.identity-brand > div { display: flex; flex-direction: column; }
.identity-brand span { color: rgba(255, 255, 255, .7); font-size: .74rem; font-weight: 700; }
.identity-brand strong { margin-top: .08rem; font-size: 1.18rem; }

.identity-copy { position: relative; z-index: 1; max-width: 370px; margin-block: 2rem; }
.eyebrow { display: inline-flex; align-items: center; gap: .4rem; padding: .38rem .65rem; border: 1px solid rgba(255, 255, 255, .16); border-radius: 999px; color: rgba(255, 255, 255, .86); background: rgba(255, 255, 255, .08); font-size: .69rem; font-weight: 750; }
.identity-copy h2 { max-width: 330px; margin: 1rem 0 .65rem; font-size: clamp(1.7rem, 3.2vw, 2.45rem); line-height: 1.3; }
.identity-copy p { max-width: 350px; margin: 0; color: rgba(255, 255, 255, .73); font-size: .9rem; line-height: 1.9; }

.identity-note { position: relative; z-index: 1; display: flex; align-items: center; gap: .7rem; padding: .8rem; border: 1px solid rgba(255, 255, 255, .14); border-radius: 16px; background: rgba(5, 31, 18, .18); backdrop-filter: blur(8px); }
.identity-note > i { display: grid; width: 38px; height: 38px; flex: 0 0 auto; place-items: center; border-radius: 11px; color: #f7d59b; background: rgba(255, 255, 255, .1); }
.identity-note p { display: flex; margin: 0; flex-direction: column; }
.identity-note strong { font-size: .76rem; }
.identity-note span { margin-top: .14rem; color: rgba(255, 255, 255, .67); font-size: .64rem; line-height: 1.6; }

.form-panel { display: flex; min-width: 0; flex-direction: column; justify-content: center; padding: clamp(2rem, 5vw, 4.25rem); background: #fff; }
.mobile-brand { display: none; }
.form-kicker { color: var(--login-primary); font-size: .75rem; font-weight: 850; }
.form-heading h1 { margin: .35rem 0 .35rem; color: #18271e; font-size: clamp(1.55rem, 2.8vw, 2rem); }
.form-heading p { margin: 0; color: #748078; font-size: .82rem; line-height: 1.7; }

.login-alert { display: flex; align-items: flex-start; gap: .5rem; margin-top: 1.1rem; padding: .72rem .8rem; border: 1px solid; border-radius: 12px; font-size: .74rem; font-weight: 700; line-height: 1.6; }
.login-alert i { margin-top: .1rem; }
.login-alert.is-error { border-color: #edc3c7; color: #8e2731; background: #fff4f5; }
.login-alert.is-success { border-color: #b9ddc4; color: #1d6b39; background: #eff9f2; }

.login-form { margin-top: 1.45rem; }
.field { display: block; margin-bottom: 1rem; }
.field-label { display: block; margin-bottom: .42rem; color: #34453a; font-size: .75rem; font-weight: 800; }
.input-shell { position: relative; display: flex; min-height: 52px; align-items: center; border: 1px solid #dce4df; border-radius: 13px; background: #fbfcfb; transition: border-color .16s, box-shadow .16s, background .16s; }
.input-shell:focus-within { border-color: rgba(var(--primary-rgb, 31 107 80), .62); background: #fff; box-shadow: 0 0 0 4px rgba(var(--primary-rgb, 31 107 80), .09); }
.input-shell.has-error { border-color: #d95a64; background: #fffafa; }
.input-shell > i { position: absolute; inset-inline-start: .9rem; color: var(--login-primary); font-size: .95rem; }
.input-shell input { width: 100%; min-width: 0; min-height: 50px; box-sizing: border-box; padding: .65rem 2.7rem; border: 0; outline: 0; color: #1f2c24; background: transparent; font: inherit; font-size: .9rem; text-align: left; }
.input-shell input.identity-input,
.input-shell input.password-input { direction: ltr; text-align: left; }
.input-shell input::placeholder { color: #a0aaa3; }
.password-toggle { position: absolute; inset-inline-end: .3rem; display: grid; width: 44px; height: 44px; place-items: center; border: 0; border-radius: 10px; color: #607067; background: transparent; font-size: .95rem; }
.password-toggle:hover { color: var(--login-primary); background: rgba(var(--primary-rgb, 31 107 80), .07); }
.caps-warning { display: flex; align-items: center; gap: .35rem; margin-top: .42rem; color: #98620c; font-size: .68rem; font-weight: 750; }

.form-options { display: flex; min-height: 52px; align-items: center; justify-content: space-between; gap: .7rem; }
.remember-option { display: flex; align-items: center; gap: .5rem; cursor: pointer; user-select: none; }
.remember-option input { width: 18px; height: 18px; margin: 0; accent-color: var(--login-primary); }
.remember-option span { display: flex; flex-direction: column; }
.remember-option b { color: #33443a; font-size: .73rem; }
.remember-option small { margin-top: .05rem; color: #8a958e; font-size: .6rem; }
.form-options > a { min-height: 44px; display: inline-flex; align-items: center; color: var(--login-primary); border-radius: 9px; font-size: .7rem; font-weight: 800; text-decoration: none; }
.form-options > a:hover { text-decoration: underline; }
.shared-device-note { display: flex; align-items: flex-start; gap: .35rem; margin: .2rem 0 .7rem; padding: .52rem .6rem; border-radius: 9px; color: #73520d; background: #fff8e7; font-size: .64rem; line-height: 1.6; }

.submit-button { display: flex; width: 100%; min-height: 52px; align-items: center; justify-content: center; gap: .5rem; margin-top: .6rem; border: 0; border-radius: 13px; color: #fff; background: var(--login-primary); box-shadow: 0 12px 26px -17px rgba(18, 78, 47, .9); font: inherit; font-size: .86rem; font-weight: 850; cursor: pointer; transition: transform .12s, background .16s, opacity .16s; }
.submit-button:hover:not(:disabled) { background: var(--login-dark); transform: translateY(-1px); }
.submit-button:active:not(:disabled) { transform: translateY(0); }
.submit-button:disabled { opacity: .52; cursor: not-allowed; box-shadow: none; }
.submit-button.is-processing { opacity: .72; cursor: wait; }
.spinning { animation: spin .75s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.form-footer { display: flex; align-items: center; justify-content: center; gap: .35rem; margin-top: 1.3rem; color: #919b94; font-size: .61rem; text-align: center; }
.form-footer i { color: #7c9383; }

@media (max-width: 760px) {
    .login-page { display: block; padding: 0; background: #fff; }
    .login-frame { display: block; min-height: 100dvh; border: 0; border-radius: 0; box-shadow: none; }
    .identity-panel { display: none; }
    .form-panel { min-height: 100dvh; box-sizing: border-box; justify-content: center; padding: max(1.5rem, env(safe-area-inset-top)) 1.25rem max(1.25rem, env(safe-area-inset-bottom)); }
    .mobile-brand { display: flex; align-items: center; gap: .55rem; margin-bottom: 1.8rem; }
    .mobile-brand img { width: 46px; height: 46px; object-fit: contain; padding: .25rem; border: 1px solid #e1e8e3; border-radius: 13px; background: #fff; }
    .mobile-brand b { color: #22342a; font-size: .9rem; }
}

@media (max-width: 390px) {
    .form-options { align-items: flex-start; flex-direction: column; gap: 0; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; animation-duration: .01ms !important; }
}
</style>
