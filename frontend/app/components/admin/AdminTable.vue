<template>
    <div>
        <!-- Desktop table (md+) -->
        <div class="overflow-x-auto hidden md:block">
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

        <!-- Mobile card layout (< md) -->
        <div class="md:hidden divide-y divide-gray-100">
            <div
                v-if="rows.length === 0"
                class="px-4 py-8 text-center text-gray-400"
            >
                {{ emptyText }}
            </div>
            <div
                v-for="(row, i) in rows"
                :key="rowKey ? row[rowKey] : i"
                class="px-4 py-4 space-y-2"
                :class="rowClass"
            >
                <div
                    v-for="col in columns"
                    :key="col.key"
                    class="flex items-start gap-2"
                >
                    <span
                        class="text-xs font-semibold text-gray-500 uppercase tracking-wider min-w-[80px] flex-shrink-0 pt-0.5"
                    >
                        {{ col.label }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <slot
                            :name="`cell-${col.key}`"
                            :row="row"
                            :column="col"
                        >
                            <span class="text-sm text-gray-800">
                                {{ getNestedValue(row, col.key) || '\u2014' }}
                            </span>
                        </slot>
                    </div>
                </div>
            </div>
        </div>
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
