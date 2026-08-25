export interface DietType {
    code: string;
    label: string;
    kind?: string | null;
    group_label?: string | null;
}

export interface VenueScopeOption {
    value: string;
    label: string;
}

export interface VenueScopeMeta {
    param: string;
    default: string;
    group_label: string;
    values: VenueScopeOption[];
}

export interface Feature {
    code: string;
    label: string;
}

/** GET /api/v1/cities，來源是後端 config/cities.php，不是從餐廳的 city 欄位歸納。 */
export interface City {
    slug: string;
    label: string;
    country: string;
    center: [number, number];
    zoom: number;
    /** "minLat,minLng,maxLat,maxLng"，與後端每日同步的涵蓋範圍一致。 */
    bbox: string;
}

export interface MenuItemDiet {
    code: string;
    label: string;
}

export interface MenuItem {
    id: number;
    name: string;
    description: string | null;
    price: number | null;
    diet_type: string;
    diet_label?: string;
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
    recommendation_score?: number;
    diet_types?: string[];
    venue_kind?: string | null;
    venue_badge?: string | null;
    venue_summary?: string | null;
    features?: string[];
    menu_items?: MenuItem[];
    menu_empty_message?: string | null;
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
    bbox?: string;
    city?: string;
    district?: string;
    diet?: string;
    venue_scope?: string;
    price_level?: number;
    rating_min?: number;
    pet_friendly?: boolean;
    parking?: boolean;
    delivery?: boolean;
    takeout?: boolean;
    reservation?: boolean;
    wifi?: boolean;
    outdoor_seating?: boolean;
    family_friendly?: boolean;
    sort?: 'distance' | 'rating' | 'popular' | 'newest';
    per_page?: number;
    cursor?: string;
}
