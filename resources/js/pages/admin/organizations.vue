<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.organizations')"
                :subtitle="t('admin.organizationsSubtitle')"
                :card="true"
            />

            <div class="px-4 sm:px-6 py-3 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row gap-3">
                    <AdminSearchInput
                        v-model="query"
                        :placeholder="t('admin.searchOrganizationsPlaceholder')"
                        @enter="loadPage(1)"
                        @clear="loadPage(1)"
                    />
                    <select
                        v-model="statusFilter"
                        class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    >
                        <option value="">{{ t('admin.allStatuses') }}</option>
                        <option value="verified">{{ t('admin.verified') }}</option>
                        <option value="unverified">{{ t('admin.unverified') }}</option>
                    </select>
                    <Button
                        variant="primary"
                        :text="t('common.apply')"
                        width="auto"
                        @click="loadPage(1)"
                    />
                </div>
            </div>

            <LoadingSpinner v-if="loading" :text="t('admin.loadingOrganizations')" />
            <template v-else>
                <div class="px-4 sm:px-6 py-4">
                    <AdminTable :columns="columns" :rows="organizations" row-key="id">
                        <template #cell-name="{ row }">
                            <div>
                                <p class="text-sm font-medium text-gray-800">{{ row.name }}</p>
                                <p v-if="row.ein" class="text-xs text-gray-400">
                                    EIN: {{ row.ein }}
                                </p>
                            </div>
                        </template>
                        <template #cell-location="{ row }">
                            <span class="text-sm text-gray-600">
                                {{
                                    [row.city, row.state, row.country].filter(Boolean).join(', ') ||
                                    '—'
                                }}
                            </span>
                        </template>
                        <template #cell-website="{ row }">
                            <a
                                v-if="row.website"
                                :href="row.website"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-sm text-indigo-600 hover:text-indigo-800 hover:underline"
                                :title="row.website"
                            >
                                {{ t('admin.visitSite') }}
                            </a>
                            <span v-else class="text-sm text-gray-400">—</span>
                        </template>
                        <template #cell-paypal_email="{ row }">
                            <span class="text-sm text-gray-600">{{ row.paypal_email || '—' }}</span>
                        </template>
                        <template #cell-status="{ row }">
                            <AdminBadge
                                :variant="row.payout_status === 'verified' ? 'success' : 'danger'"
                                :text="
                                    row.payout_status === 'verified'
                                        ? t('admin.verified')
                                        : t('admin.unverified')
                                "
                            />
                        </template>
                        <template #cell-created_at="{ row }">
                            <span class="text-xs text-gray-500">{{
                                formatDateUTC(row.created_at) || '—'
                            }}</span>
                        </template>
                        <template #cell-verified_at="{ row }">
                            <span class="text-xs text-gray-500">{{
                                formatDateUTC(row.verified_at) || '—'
                            }}</span>
                        </template>
                        <template #cell-actions="{ row }">
                            <div class="flex items-center gap-2 justify-end">
                                <button
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors"
                                    @click="openEdit(row)"
                                >
                                    {{ t('common.edit') }}
                                </button>
                                <button
                                    v-if="row.payout_status !== 'verified'"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors disabled:opacity-40"
                                    :disabled="!row.paypal_email"
                                    @click="verify(row)"
                                >
                                    {{ t('admin.verify') }}
                                </button>
                                <button
                                    v-else
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-amber-300 text-amber-700 hover:bg-amber-50 transition-colors"
                                    @click="unverify(row)"
                                >
                                    {{ t('admin.unverify') }}
                                </button>
                            </div>
                        </template>
                    </AdminTable>

                    <div
                        v-if="meta.lastPage > 1"
                        class="px-4 py-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2"
                    >
                        <span class="text-sm text-gray-500 order-2 sm:order-1">
                            {{
                                t('report.pageOf', {
                                    current: meta.currentPage,
                                    last: meta.lastPage,
                                    total: meta.total,
                                })
                            }}
                        </span>
                        <Pagination
                            :current-page="meta.currentPage"
                            :total-pages="meta.lastPage"
                            @page-change="loadPage"
                        />
                    </div>
                </div>
            </template>
        </div>

        <!-- Edit modal -->
        <!-- Edit modal -->
        <AdminModal
            :model-value="!!editTarget"
            :title="t('admin.editOrganization')"
            @close="editTarget = null"
        >
            <template v-if="editTarget">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        {{ t('admin.paypalEmailLabel') }}
                        <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="editPaypalEmail"
                        v-bind="editPaypalEmailProps"
                        type="email"
                        :class="[
                            'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                            orgFieldErrors.paypal_email
                                ? 'border-red-500 focus:ring-red-500 focus:border-red-500'
                                : 'border-gray-300',
                        ]"
                    />
                    <p v-if="orgFieldErrors.paypal_email" class="text-xs text-red-600 mt-1">
                        {{ orgFieldErrors.paypal_email }}
                    </p>
                    <p v-else class="text-xs text-gray-500 mt-1">
                        {{ t('admin.paypalEmailHint') }}
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            {{ t('admin.cityLabel') }}
                        </label>
                        <input
                            v-model="editCity"
                            v-bind="editCityProps"
                            type="text"
                            :class="[
                                'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                                orgFieldErrors.city
                                    ? 'border-red-500 focus:ring-red-500 focus:border-red-500'
                                    : 'border-gray-300',
                            ]"
                        />
                        <p v-if="orgFieldErrors.city" class="text-xs text-red-600 mt-1">
                            {{ orgFieldErrors.city }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                            {{ t('admin.stateLabel') }}
                        </label>
                        <input
                            v-model="editState"
                            v-bind="editStateProps"
                            type="text"
                            :class="[
                                'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                                orgFieldErrors.state
                                    ? 'border-red-500 focus:ring-red-500 focus:border-red-500'
                                    : 'border-gray-300',
                            ]"
                        />
                        <p v-if="orgFieldErrors.state" class="text-xs text-red-600 mt-1">
                            {{ orgFieldErrors.state }}
                        </p>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        {{ t('admin.countryLabel') }}
                    </label>
                    <input
                        v-model="editCountry"
                        v-bind="editCountryProps"
                        type="text"
                        :class="[
                            'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                            orgFieldErrors.country
                                ? 'border-red-500 focus:ring-red-500 focus:border-red-500'
                                : 'border-gray-300',
                        ]"
                    />
                    <p v-if="orgFieldErrors.country" class="text-xs text-red-600 mt-1">
                        {{ orgFieldErrors.country }}
                    </p>
                </div>
                <p v-if="editError" class="text-xs text-red-600">{{ editError }}</p>
            </template>
            <template #footer>
                <button
                    class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50"
                    @click="editTarget = null"
                >
                    {{ t('common.cancel') }}
                </button>
                <button
                    class="px-4 py-2 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-40"
                    :disabled="saving"
                    @click="saveEdit"
                >
                    {{ saving ? t('common.saving') : t('common.save') }}
                </button>
            </template>
        </AdminModal>
    </main>
</template>

<script setup>
    import { ref, onMounted } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { useForm } from 'vee-validate'
    import * as yup from 'yup'
    import { adminService } from '~/services/adminService'
    import { useToastify } from '~/composables/useToastify'
    import { formatDateUTC } from '~/utils/dateFormatUTC'
    import AdminPageHeader from '~/components/admin/AdminPageHeader.vue'
    import AdminTable from '~/components/admin/AdminTable.vue'
    import AdminBadge from '~/components/admin/AdminBadge.vue'
    import AdminModal from '~/components/admin/AdminModal.vue'
    import AdminSearchInput from '~/components/admin/AdminSearchInput.vue'
    import LoadingSpinner from '~/components/LoadingSpinner.vue'
    import Pagination from '~/components/Pagination.vue'
    import Button from '~/components/Button.vue'

    const { notifySuccess, notifyError } = useToastify()
    const { t } = useI18n()

    // vee-validate + yup, same stack as the rest of the app. Mirrors the
    // backend rules in Admin\OrganizationController::update.
    const orgSchema = yup.object({
        paypal_email: yup
            .string()
            .transform((v) => (v === '' ? null : v))
            .required(t('admin.paypalEmailRequired'))
            .email(t('auth.emailInvalid'))
            .max(255, t('admin.fieldTooLong')),
        city: yup.string().nullable().max(255, t('admin.fieldTooLong')),
        state: yup.string().nullable().max(255, t('admin.fieldTooLong')),
        country: yup.string().nullable().max(255, t('admin.fieldTooLong')),
    })

    const {
        handleSubmit: handleOrgSubmit,
        defineField: defineOrgField,
        errors: orgFieldErrors,
        resetForm: resetOrgForm,
    } = useForm({ validationSchema: orgSchema })

    const [editPaypalEmail, editPaypalEmailProps] = defineOrgField('paypal_email')
    const [editCity, editCityProps] = defineOrgField('city')
    const [editState, editStateProps] = defineOrgField('state')
    const [editCountry, editCountryProps] = defineOrgField('country')

    useHead({ title: t('admin.organizations') })
    definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

    const loading = ref(true)
    const organizations = ref([])
    const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })

    const query = ref('')
    const statusFilter = ref('')

    const columns = [
        { key: 'name', label: t('admin.organizationName') },
        { key: 'location', label: t('admin.location') },
        { key: 'website', label: t('admin.website') },
        { key: 'paypal_email', label: t('admin.paypalEmail') },
        { key: 'status', label: t('admin.status') },
        { key: 'created_at', label: t('admin.createdAt') },
        { key: 'verified_at', label: t('admin.verifiedAt') },
        { key: 'actions', label: '', tdClass: 'text-right', thClass: 'text-right' },
    ]

    const editTarget = ref(null)
    const saving = ref(false)
    const editError = ref('')

    const loadPage = async (page = 1) => {
        loading.value = true
        try {
            const params = { page, per_page: 20 }
            if (query.value.trim()) params.q = query.value.trim()
            if (statusFilter.value) params.status = statusFilter.value

            const res = await adminService.getOrganizations(params)
            if (res.success) {
                organizations.value = res.data.data
                meta.value = res.data
            }
        } catch (error) {
            notifyError(error.errors?.[0] || t('admin.failedToLoadOrganizations'))
        } finally {
            loading.value = false
        }
    }

    const openEdit = (row) => {
        editTarget.value = row
        resetOrgForm({
            values: {
                paypal_email: row.paypal_email || '',
                city: row.city || '',
                state: row.state || '',
                country: row.country || '',
            },
        })
        editError.value = ''
    }

    const saveEdit = handleOrgSubmit(async (values) => {
        saving.value = true
        editError.value = ''
        try {
            const res = await adminService.updateOrganization(editTarget.value.id, {
                paypal_email: values.paypal_email || null,
                city: values.city || null,
                state: values.state || null,
                country: values.country || null,
            })
            if (res.success) {
                notifySuccess(t('admin.organizationUpdated'))
                editTarget.value = null
                await loadPage(meta.value.currentPage)
            }
        } catch (error) {
            editError.value =
                error.errors?.paypal_email?.[0] ||
                error.message ||
                t('admin.failedToUpdateOrganization')
        } finally {
            saving.value = false
        }
    })

    const verify = async (row) => {
        try {
            const res = await adminService.verifyOrganization(row.id)
            if (res.success) {
                notifySuccess(t('admin.organizationVerified'))
                await loadPage(meta.value.currentPage)
            }
        } catch (error) {
            notifyError(error.message || t('admin.failedToVerifyOrganization'))
        }
    }

    const unverify = async (row) => {
        try {
            const res = await adminService.unverifyOrganization(row.id)
            if (res.success) {
                notifySuccess(t('admin.organizationUnverified'))
                await loadPage(meta.value.currentPage)
            }
        } catch (error) {
            notifyError(error.message || t('admin.failedToUnverifyOrganization'))
        }
    }

    onMounted(() => loadPage(1))
</script>
