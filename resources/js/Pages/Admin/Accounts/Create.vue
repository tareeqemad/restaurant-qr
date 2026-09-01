<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountForm from '../../../Components/Accounts/AccountForm.vue'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    account: { type: Object, default: null },
    prefilledParent: { type: Object, default: null },
    parentOptions: { type: Array, default: () => [] },
    typeOptions: { type: Array, default: () => [] },
    balanceOptions: { type: Array, default: () => [] },
    balanceByType: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
})

const form = useForm({
    code: '',
    name: '',
    description: '',
    type: props.prefilledParent?.type ?? 'asset',
    normal_balance: props.prefilledParent?.normalBalance ?? 'debit',
    display_order: 0,
    parent_account_id: props.prefilledParent?.id ?? '',
    is_active: true,
})

function submit() {
    form.post(props.urls.store)
}
</script>

<template>
    <Head title="حساب جديد" />
    <PageHeader
        title="حساب جديد"
        icon="bi-plus-circle-fill"
        subtitle="أضف الحساب في مكانه الصحيح؛ القيود الجديدة فقط ستتمكن من استخدامه"
        :crumbs="[{ label: 'شجرة الحسابات', url: urls.index }]"
    />
    <AccountingNav :urls="urls.workspace" active="accounts" />

    <form class="account-page-form" @submit.prevent="submit">
        <AccountForm
            :form="form"
            variant="page"
            :parent-options="parentOptions"
            :type-options="typeOptions"
            :balance-options="balanceOptions"
            :balance-by-type="balanceByType"
            :prefilled-parent="prefilledParent"
        />
        <footer>
            <Link :href="urls.index" class="btn btn-light" preserve-scroll>إلغاء</Link>
            <button class="btn btn-primary" :disabled="form.processing">
                <i class="bi bi-check2-circle"></i> حفظ الحساب
            </button>
        </footer>
    </form>
</template>

<style scoped>
.account-page-form {
    display: grid;
    max-width: 980px;
    gap: 11px;
    margin-inline: auto;
}

.account-page-form > footer {
    position: sticky;
    z-index: 5;
    bottom: 10px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 10px;
    border: 1px solid #dce6df;
    border-radius: 13px;
    background: rgba(255, 255, 255, .96);
    box-shadow: 0 10px 28px rgba(21, 52, 31, .08);
    backdrop-filter: blur(10px);
}

.account-page-form .btn {
    min-height: 44px;
}
</style>
