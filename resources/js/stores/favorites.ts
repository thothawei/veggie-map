import { defineStore } from 'pinia';
import client from '@/api/client';
import type { ApiSuccess, Restaurant } from '@/types';

export const useFavoritesStore = defineStore('favorites', {
    state: () => ({
        ids: new Set<number>(),
        restaurants: [] as Restaurant[],
        loaded: false,
    }),
    actions: {
        async fetchAll() {
            const response = await client.get<ApiSuccess<Restaurant[]>>('/me/favorites', {
                params: { per_page: 100 },
            });
            this.restaurants = response.data.data;
            this.ids = new Set(response.data.data.map((r) => r.id));
            this.loaded = true;
        },
        async add(restaurantId: number) {
            await client.post(`/restaurants/${restaurantId}/favorite`);
            this.ids.add(restaurantId);
        },
        async remove(restaurantId: number) {
            await client.delete(`/restaurants/${restaurantId}/favorite`);
            this.ids.delete(restaurantId);
            this.restaurants = this.restaurants.filter((r) => r.id !== restaurantId);
        },
        isFavorite(restaurantId: number): boolean {
            return this.ids.has(restaurantId);
        },
        reset() {
            this.ids = new Set();
            this.restaurants = [];
            this.loaded = false;
        },
    },
});
