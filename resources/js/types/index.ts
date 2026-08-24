export interface DietType {
    code: string;
    label: string;
}

export interface Feature {
    code: string;
    label: string;
}

export interface MenuItem {
    id: number;
    name: string;
    description: string | null;
    price: number | null;
    diet_type: string;
    is_available: boolean;
}

export interface Restaurant {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    address: string;
    city: string | null;
    district: string | null;
    latitude: number;
    longitude: number;
    phone: string | null;
    website: string | null;
    price_level: number | null;
    rating: number;
    rating_count: number;
    status: 'active' | 'inactive' | 'pending';
    distance_meters?: number;
    diet_types?: string[];
    features?: string[];
    menu_items?: MenuItem[];
    confidence_score?: number | null;
    created_at: string;
    updated_at: string;
}

export interface GeocodedPlace {
    display_name: string;
    latitude: number;
    longitude: number;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: 'user' | 'admin';
    created_at: string;
}

export interface ApiSuccess<T> {
    success: true;
    data: T;
    meta?: Record<string, unknown>;
}

export interface ApiError {
    success: false;
    error: { code: string; message: string; fields?: Record<string, string[]> };
}

export interface RestaurantSearchParams {
    keyword?: string;
    latitude?: number;
    longitude?: number;
    radius?: number;
    city?: string;
    district?: string;
    diet?: string;
    price_level?: number;
    rating_min?: number;
    pet_friendly?: boolean;
    parking?: boolean;
    sort?: 'distance' | 'rating' | 'popular' | 'newest';
    per_page?: number;
    cursor?: string;
}
