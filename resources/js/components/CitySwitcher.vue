<script setup lang="ts">
import { computed } from 'vue';
import type { City } from '@/types';

const props = defineProps<{
    cities: City[];
    modelValue: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', slug: string): void;
}>();

/**
 * 依國家分組顯示。目前是台灣四市＋東京，未來加城市時分組會自己長出來，
 * 不用改這個元件。
 */
const groups = computed(() => {
    const byCountry = new Map<string, City[]>();

    for (const city of props.cities) {
        const list = byCountry.get(city.country) ?? [];
        list.push(city);
        byCountry.set(city.country, list);
    }

    return [...byCountry.entries()].map(([country, cities]) => ({ country, cities }));
});
</script>

<template>
    <nav class="city-switcher" aria-label="選擇城市">
        <div v-for="group in groups" :key="group.country" class="city-group">
            <span class="country">{{ group.country }}</span>
            <div class="city-buttons">
                <button
                    v-for="city in group.cities"
                    :key="city.slug"
                    type="button"
                    class="city"
                    :class="{ active: city.slug === modelValue }"
                    :aria-pressed="city.slug === modelValue"
                    @click="emit('update:modelValue', city.slug)"
                >
                    {{ city.label }}
                </button>
            </div>
        </div>
    </nav>
</template>

<style scoped>
.city-switcher {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.75rem 1.25rem;
    margin: 0 auto 0.75rem;
}

.city-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.country {
    font-size: 0.75rem;
    color: #718096;
    white-space: nowrap;
}

.city-buttons {
    display: flex;
    gap: 0.35rem;
}

.city {
    padding: 0.35rem 0.85rem;
    border-radius: 999px;
    border: 1px solid #cbd5e0;
    background: #fff;
    color: #1f2933;
    cursor: pointer;
    font-size: 0.9rem;
    line-height: 1.4;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.city:hover {
    border-color: #2f855a;
    color: #2f855a;
}

.city.active {
    background: #2f855a;
    border-color: #2f855a;
    color: #fff;
    font-weight: 600;
}

.city:focus-visible {
    outline: 2px solid #2f855a;
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .city {
        transition: none;
    }
}
</style>
