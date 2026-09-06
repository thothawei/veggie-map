<script setup lang="ts">
import { SEARCH_SCOPES, type SearchScope } from '@/composables/useSearchScope';

/**
 * 搜尋範圍選單（A5）。首頁與列表頁共用同一個元件、同一組值，但列表頁沒有地圖，
 * 只會傳 `['city', 'all']` 進來——`options` 由呼叫端決定要露出哪幾個選項，
 * 不是這個元件自己知道「哪一頁該有 map」。
 */
const props = defineProps<{
    modelValue: SearchScope;
    options: readonly SearchScope[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: SearchScope): void;
}>();

const LABELS: Record<SearchScope, string> = {
    map: '目前地圖範圍',
    city: '這座城市',
    all: '全部城市',
};

function onChange(event: Event) {
    const value = (event.target as HTMLSelectElement).value;

    if ((SEARCH_SCOPES as readonly string[]).includes(value)) {
        emit('update:modelValue', value as SearchScope);
    }
}
</script>

<template>
    <label class="scope-select">
        範圍
        <select :value="modelValue" @change="onChange">
            <option v-for="value in props.options" :key="value" :value="value">
                {{ LABELS[value] }}
            </option>
        </select>
    </label>
</template>

<style scoped>
.scope-select {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    background: #fff;
    font-size: 0.85rem;
    color: #4a5568;
    white-space: nowrap;
}

.scope-select select {
    border: none;
    background: transparent;
    font-size: 0.9rem;
    color: #1f2933;
    cursor: pointer;
}
</style>
