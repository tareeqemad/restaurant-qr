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
                <span class="logo"><img :src="brand.logo || '/brand/dar-al-sarw-mark.svg'" :alt="brand.name || 'مطعم دار السرو'"></span>
                <div><small>{{ brand.name || 'مطعم دار السرو' }}</small></div>
                <span class="privacy"><i class="bi bi-shield-check"></i> نحافظ على خصوصية الحساب ولا نكشف إن كان الرقم مسجلاً.</span>
            </aside>
            <section class="form-side">
                <header><Link :href="routes.login"><i class="bi bi-arrow-right"></i> تسجيل الدخول</Link><small>استعادة الوصول</small><h1>نسيت كلمة المرور؟</h1><p>ستصلك كلمة مرور مؤقتة على جوالك.</p></header>
                <div v-if="success" class="alert success" role="status"><i class="bi bi-check2-circle"></i><span>{{success}}</span></div>
                <div v-else-if="form.errors.identifier" class="alert error" role="alert"><i class="bi bi-exclamation-circle"></i><span>{{form.errors.identifier}}</span></div>
                <form @submit.prevent="submit">
                    <label><span>رقم الجوال أو اسم المستخدم</span><div class="input"><i class="bi bi-person"></i><input ref="input" v-model.trim="form.identifier" type="text" autocomplete="username" placeholder="رقم الجوال أو اسم المستخدم" required autofocus></div><small>رقم الجوال المحلي يتكون من 10 أرقام.</small></label>
                    <button type="submit" :disabled="form.processing"><i :class="form.processing?'bi bi-arrow-repeat spin':'bi bi-send'"></i>{{form.processing?'جاري الإرسال…':'إرسال كلمة مرور مؤقتة'}}</button>
                </form>
                <footer><i class="bi bi-info-circle"></i> إذا لم يصل SMS، اطلب من مدير النظام تحديث رقم جوالك.</footer>
            </section>
        </section>
    </main>
</template>

<style scoped>*{box-sizing:border-box}.reset-page{display:grid;min-height:100dvh;place-items:center;padding:1rem;color:var(--ink);background:var(--canvas)}.reset-card{display:grid;width:min(100%,900px);min-height:570px;grid-template-columns:.85fr 1.15fr;overflow:hidden;border:1px solid var(--line);border-radius:12px;background:var(--surface);box-shadow:none}.reset-card>aside{display:flex;flex-direction:column;justify-content:space-between;padding:2.2rem;color:#FFFDFA;background:var(--brand-dark)}.logo{display:grid;width:60px;height:60px;place-items:center;padding:.35rem;border-radius:12px;background:var(--surface)}.logo img{width:100%;height:100%;object-fit:contain}.reset-card aside small{color:var(--brand-soft);font-size:.875rem;font-weight:800}.reset-card aside h2{margin:.45rem 0;font-size:2rem;line-height:1.45}.reset-card aside p{margin:0;color:#ffffffb3;font-size:.875rem;line-height:1.9}.privacy{display:flex;align-items:flex-start;gap:.45rem;padding:.9rem 0 0;border:0;border-radius:0;color:#ffffffc7;background:transparent;font-size:.875rem;line-height:1.65;border-top:1px solid rgb(255 253 250 / 20%)}.privacy i{color:#F8F0E2}.form-side{display:flex;flex-direction:column;justify-content:center;padding:clamp(1.5rem,5vw,3.7rem)}.form-side header>a{display:inline-flex;min-height:40px;align-items:center;gap:.3rem;margin-bottom:1.3rem;color:var(--brand);font-size:.875rem;font-weight:800;text-decoration:none}.form-side header>small{display:block;color:var(--brand);font-size:.875rem;font-weight:800}.form-side h1{margin:.3rem 0;font-size:1.65rem}.form-side header p{margin:0;color:var(--muted);font-size:.875rem}.alert{display:flex;align-items:flex-start;gap:.45rem;margin-top:1rem;padding:.7rem;border:1px solid;border-radius:11px;font-size:.875rem;line-height:1.6}.alert.success{border-color:var(--line);color:var(--ink);background:var(--canvas)}.alert.error{border-color:var(--line);color:#8b2c34;background:var(--canvas)}.form-side form{margin-top:1.1rem}.form-side label{display:grid;gap:.35rem}.form-side label>span{font-size:.875rem;font-weight:800}.input{display:flex;min-height:48px;align-items:center;gap:.45rem;padding:0 .7rem;border:1px solid var(--line);border-radius:8px}.input:focus-within{border-color:var(--brand);box-shadow:0 0 0 3px var(--surface-muted)}.input i{color:var(--brand)}.input input{min-width:0;flex:1;border:0;outline:0;background:transparent;font:inherit;font-size:.875rem}.form-side label>small{color:var(--muted);font-size:.875rem}.form-side form>button{display:flex;width:100%;min-height:48px;align-items:center;justify-content:center;gap:.4rem;margin-top:1rem;border:0;border-radius:8px;color:#FFFDFA;background:var(--brand);font:inherit;font-size:.875rem;font-weight:800;cursor:pointer}.form-side form>button:disabled{opacity:.6}.form-side footer{display:flex;align-items:flex-start;gap:.4rem;margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--line);color:var(--muted);font-size:.875rem;line-height:1.6}.spin{animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:720px){.reset-page{padding:0}.reset-card{display:block;min-height:100dvh;border:0;border-radius:0}.reset-card>aside{min-height:185px;padding:1.2rem}.reset-card aside>div{margin-top:1rem}.reset-card aside h2{font-size:1.25rem}.privacy{display:none}.logo{width:46px;height:46px}.form-side{padding:1.4rem}}.form-side form>button:hover:not(:disabled){background:var(--brand-hover)}
/* Operational layout and responsive hierarchy. */
.reset-page{padding:28px 16px}.reset-card{display:flex;flex-direction:column;width:min(100%,480px);min-height:0;border-radius:12px;border:1px solid var(--line)}.reset-card>aside{padding:22px 26px;display:flex;flex-direction:row;align-items:center;justify-content:flex-start;gap:14px;background:var(--surface);color:var(--ink);border-bottom:1px solid var(--line)}.reset-card aside small{font-size:19px;color:var(--ink)}.logo{width:50px;height:50px;padding:0;background:transparent;border:0;border-radius:0}.privacy{display:none}.form-side{padding:26px}.form-side header>small{display:none}.form-side h1{font-size:25px}.form-side header>a{margin-bottom:20px}.input{min-height:52px;background:var(--canvas)}.form-side form>button{min-height:52px}.form-side form{margin-top:24px}@media(max-width:720px){.reset-page{padding:20px 14px}.reset-card{min-height:0;border:1px solid var(--line);border-radius:12px}.reset-card>aside{min-height:0}.reset-card aside>div{margin:0}}
</style>
