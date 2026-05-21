<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 text-gray-600 text-left">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="px-4 py-3 font-medium"
                        :class="col.thClass"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, i) in rows"
                    :key="rowKey ? row[rowKey] : i"
                    class="border-t border-gray-100 hover:bg-gray-50"
                    :class="rowClass"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        class="px-4 py-3"
                        :class="col.tdClass"
                    >
                        <slot
                            :name="`cell-${col.key}`"
                            :row="row"
                            :column="col"
                        >
                            {{ getNestedValue(row, col.key) }}
                        </slot>
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td
                        :colspan="columns.length"
                        class="px-4 py-8 text-center text-gray-400"
                    >
                        {{ emptyText }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
defineProps({
    columns: {
        type: Array,
        required: true,
    },
    rows: {
        type: Array,
        default: () => [],
    },
    rowKey: {
        type: String,
        default: '',
    },
    rowClass: {
        type: String,
        default: '',
    },
    emptyText: {
        type: String,
        default: 'No data',
    },
})

function getNestedValue(obj, path) {
    return path.split('.').reduce((acc, part) => {
        if (acc && typeof acc === 'object' && part in acc) return acc[part]
        return ''
    }, obj)
}
</script>
