<script setup lang="ts">
import { onMounted, ref } from 'vue';
import client from '@/api/client';
import type { ApiSuccess, DietType, Feature, RestaurantSearchParams } from '@/types';

const filters = defineModel<Partial<RestaurantSearchParams>>('filters', { required: true });

const diets = ref<DietType[]>([]);
const features = ref<Feature[]>([]);

onMounted(async () => {
    const [dietsRes, featuresRes] = await Promise.all([
        client.get<ApiSuccess<DietType[]>>('/diets'),
        client.get<ApiSuccess<Feature[]>>('/features'),
    ]);
    diets.value = dietsRes.data.data;
    features.value = featuresRes.data.data;
});

function toggleDiet(code: string) {
    filters.value.diet = filters.value.diet === code ? undefined : code;
}

function togglePetFriendly() {
    filters.value.pet_friendly = filters.value.pet_friendly ? undefined : true;
}

function toggleParking() {
    filters.value.parking = filters.value.parking ? undefined : true;
}
</script>

<template>
    <div class="filter-drawer">
        <div class="group">
            <span class="label">飲食類型</span>
            <button
                v-for="diet in diets"
                :key="diet.code"
                type="button"
                class="chip"
                :class="{ active: filters.diet === diet.code }"
                @click="toggleDiet(diet.code)"
            >
                {{ diet.label }}
            </button>
        </div>

        <div class="group">
            <span class="label">特色</span>
            <button
                type="button"
                class="chip"
                :class="{ active: filters.pet_friendly }"
                @click="togglePetFriendly"
                v-if="features.some((f) => f.code === 'pet_friendly')"
            >
                寵物友善
            </button>
            <button
                type="button"
                class="chip"
                :class="{ active: filters.parking }"
                @click="toggleParking"
                v-if="features.some((f) => f.code === 'parking')"
            >
                停車
            </button>
        </div>
    </div>
</template>

<style scoped>
.filter-drawer {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 0.75rem 0;
}

.group {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.label {
    font-size: 0.85rem;
    color: #718096;
    margin-right: 0.25rem;
}

.chip {
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    border: 1px solid #cbd5e0;
    background: #fff;
    cursor: pointer;
    font-size: 0.85rem;
}

.chip.active {
    background: #2f855a;
    border-color: #2f855a;
    color: #fff;
}
</style>
