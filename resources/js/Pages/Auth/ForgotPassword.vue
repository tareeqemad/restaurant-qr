<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({ brand:Object, routes:Object, oldIdentifier:{type:String,default:''} });
const page = usePage();
const input = ref(null);
const form = useForm({ identifier:props.oldIdentifier });
const success = computed(() => page.props.flash?.success);
function submit(){form.post(props.routes.submit,{preserveScroll:true,onError:()=>nextTick(()=>input.value?.focus())})}
</script>

<template>
    <Head :title="`استعادة الدخول · ${brand.name}`" />
    <main class="reset-page">
        <section class="reset-card">
            <aside>
                <span class="logo"><img :src="brand.logo" :alt="brand.name"></span>
                <div><small>دخول الموظفين</small><h2>سنساعدك على العودة للعمل.</h2><p>أدخل رقم جوالك أو اسم المستخدم. إذا كان حسابك مرتبطاً بجوال فستصلك كلمة مرور مؤقتة.</p></div>
                <span class="privacy"><i class="bi bi-shield-check"></i> نحافظ على خصوصية الحساب ولا نكشف إن كان الرقم مسجلاً.</span>
            </aside>
            <section class="form-side">
                <header><Link :href="routes.login"><i class="bi bi-arrow-right"></i> تسجيل الدخول</Link><small>استعادة الوصول</small><h1>نسيت كلمة المرور؟</h1><p>استخدم بياناتك المعروفة ثم افحص رسائل الجوال.</p></header>
                <div v-if="success" class="alert success" role="status"><i class="bi bi-check2-circle"></i><span>{{success}}</span></div>
                <div v-else-if="form.errors.identifier" class="alert error" role="alert"><i class="bi bi-exclamation-circle"></i><span>{{form.errors.identifier}}</span></div>
                <form @submit.prevent="submit">
                    <label><span>رقم الجوال أو اسم المستخدم</span><div class="input"><i class="bi bi-person"></i><input ref="input" v-model.trim="form.identifier" type="text" autocomplete="username" placeholder="0592632026 أو username" required autofocus></div><small>رقم الجوال المحلي يتكون من 10 أرقام.</small></label>
                    <button type="submit" :disabled="form.processing"><i :class="form.processing?'bi bi-arrow-repeat spin':'bi bi-send'"></i>{{form.processing?'جاري الإرسال…':'إرسال كلمة مرور مؤقتة'}}</button>
                </form>
                <footer><i class="bi bi-info-circle"></i> إذا لم يصل SMS، اطلب من مدير النظام تحديث رقم جوالك.</footer>
            </section>
        </section>
    </main>
</template>

<style scoped>*{box-sizing:border-box}.reset-page{display:grid;min-height:100dvh;place-items:center;padding:1rem;color:#1c2b22;background:radial-gradient(circle at 15% 15%,#dceee4,transparent 28rem),#f3f7f4}.reset-card{display:grid;width:min(100%,900px);min-height:570px;grid-template-columns:.85fr 1.15fr;overflow:hidden;border:1px solid #d8e3dc;border-radius:24px;background:#fff;box-shadow:0 28px 80px -48px #173d2d}.reset-card>aside{display:flex;flex-direction:column;justify-content:space-between;padding:2.2rem;color:#fff;background:linear-gradient(150deg,#236f54,#123f31)}.logo{display:grid;width:60px;height:60px;place-items:center;padding:.35rem;border-radius:17px;background:#fff}.logo img{width:100%;height:100%;object-fit:contain}.reset-card aside small{color:#d1e8dc;font-size:.65rem;font-weight:800}.reset-card aside h2{margin:.45rem 0;font-size:1.8rem;line-height:1.45}.reset-card aside p{margin:0;color:#ffffffb3;font-size:.72rem;line-height:1.9}.privacy{display:flex;align-items:flex-start;gap:.45rem;padding:.7rem;border:1px solid #ffffff21;border-radius:12px;color:#ffffffc7;background:#00000019;font-size:.59rem;line-height:1.65}.privacy i{color:#f1cf91}.form-side{display:flex;flex-direction:column;justify-content:center;padding:clamp(1.5rem,5vw,3.7rem)}.form-side header>a{display:inline-flex;min-height:40px;align-items:center;gap:.3rem;margin-bottom:1.3rem;color:#547061;font-size:.62rem;font-weight:800;text-decoration:none}.form-side header>small{display:block;color:#1f6b50;font-size:.65rem;font-weight:850}.form-side h1{margin:.3rem 0;font-size:1.65rem}.form-side header p{margin:0;color:#748078;font-size:.71rem}.alert{display:flex;align-items:flex-start;gap:.45rem;margin-top:1rem;padding:.7rem;border:1px solid;border-radius:11px;font-size:.65rem;line-height:1.6}.alert.success{border-color:#b8dcc5;color:#1e6a3c;background:#eff9f2}.alert.error{border-color:#ecc5c8;color:#8b2c34;background:#fff4f5}.form-side form{margin-top:1.1rem}.form-side label{display:grid;gap:.35rem}.form-side label>span{font-size:.67rem;font-weight:850}.input{display:flex;min-height:48px;align-items:center;gap:.45rem;padding:0 .7rem;border:1px solid #d3dfd8;border-radius:11px}.input:focus-within{border-color:#5c9b80;box-shadow:0 0 0 3px #e3f1ea}.input i{color:#789084}.input input{min-width:0;flex:1;border:0;outline:0;background:transparent;font:inherit;font-size:.72rem}.form-side label>small{color:#87938c;font-size:.55rem}.form-side form>button{display:flex;width:100%;min-height:48px;align-items:center;justify-content:center;gap:.4rem;margin-top:1rem;border:0;border-radius:11px;color:#fff;background:#1f6b50;font:inherit;font-size:.7rem;font-weight:850}.form-side form>button:disabled{opacity:.6}.form-side footer{display:flex;align-items:flex-start;gap:.4rem;margin-top:1.1rem;padding-top:1rem;border-top:1px solid #e5ebe7;color:#7b8981;font-size:.56rem;line-height:1.6}.spin{animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:720px){.reset-page{padding:0}.reset-card{display:block;min-height:100dvh;border:0;border-radius:0}.reset-card>aside{min-height:185px;padding:1.2rem}.reset-card aside>div{margin-top:1rem}.reset-card aside h2{font-size:1.25rem}.privacy{display:none}.logo{width:46px;height:46px}.form-side{padding:1.4rem}}</style>
