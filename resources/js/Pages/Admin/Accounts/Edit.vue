<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountForm from '../../../Components/Accounts/AccountForm.vue'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    account: { type: Object, required: true },
    prefilledParent: { type: Object, default: null },
    parentOptions: { type: Array, default: () => [] },
    typeOptions: { type: Array, default: () => [] },
    balanceOptions: { type: Array, default: () => [] },
    balanceByType: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
})

const form = useForm({
    code: props.account.code,
    name: props.account.name,
    description: props.account.description ?? '',
    type: props.account.type,
    normal_balance: props.account.normalBalance,
    display_order: props.account.displayOrder,
    parent_account_id: props.account.parentAccountId ?? '',
    is_active: props.account.isActive,
})

function submit() {
    form.patch(props.urls.update)
}
</script>

<template>
    <Head :title="`تعديل ${account.name}`" />
    <PageHeader
        :title="`تعديل ${account.code} — ${account.name}`"
        icon="bi-pencil-square"
        subtitle="غيّر الوصف والتنظيم بأمان؛ الحقول المؤثرة في التاريخ تُقفل تلقائياً"
        :crumbs="[{ label: 'شجرة الحسابات', url: urls.index }]"
    />
    <AccountingNav :urls="urls.workspace" active="accounts" />

    <form class="account-page-form" @submit.prevent="submit">
        <section v-if="account.parentLabel" class="parent-line">
            <i class="bi bi-diagram-3-fill"></i>
            <span><small>تابع للحساب</small><strong>{{ account.parentLabel }}</strong></span>
        </section>
        <AccountForm
            :form="form"
            variant="page"
            :parent-options="parentOptions"
            :type-options="typeOptions"
            :balance-options="balanceOptions"
            :balance-by-type="balanceByType"
            :is-system="account.isSystem"
            :has-journal-lines="account.hasJournalLines"
            :prefilled-parent="prefilledParent"
        />
        <footer>
            <Link :href="urls.index" class="btn btn-light" preserve-scroll>إلغاء</Link>
            <button class="btn btn-primary" :disabled="form.processing">
                <i class="bi bi-check2-circle"></i> حفظ التعديل
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

.parent-line {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 12px;
    border: 1px solid #bdd9c6;
    border-radius: 12px;
    color: #28543a;
    background: #f0f8f3;
}

.parent-line > span {
    display: grid;
}

.parent-line small {
    color: #7b8981;
    font-size: .56rem;
}

.parent-line strong {
    font-size: .68rem;
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
