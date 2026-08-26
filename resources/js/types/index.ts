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

/** 可信度篩選門檻，來自後端 config/vegetarian.php，前端不自己決定分數。 */
export interface ConfidenceFilter {
    value: number;
    label: string;
}

export interface MenuItemDiet {
    code: string;
    label: string;
}

export interface AdminVerificationType {
    code: string;
    label: string;
    score: number;
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

/** 料理種類標籤：`code` 供程式比對，`label` 給畫面顯示。 */
export interface Cuisine {
    code: string;
    label: string;
}

/** 詳情頁的一週營業時間。ranges 空陣列＝當天公休。 */
export interface OpeningHoursDay {
    day: number;
    label: string;
    ranges: string[];
}

export interface Restaurant {
    id: number;
    name: string;
    slug: string;
    /** 列表 API 不撈這個欄位（見後端 LIST_COLUMNS），所以是 optional 而不是 null。 */
    description?: string | null;
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
    cuisines?: Cuisine[];
    features?: string[];
    menu_items?: MenuItem[];
    menu_empty_message?: string | null;
    confidence_score?: number | null;
    /** 這個分數憑什麼——每一種已成立的驗證各取最高分（只有詳情才有）。 */
    confidence_breakdown?: Array<{ code: string; label: string; score: number }>;
    /**
     * 這家店為什麼出現在搜尋結果裡——命中的菜色名稱（最多三個）。只有店名／地址
     * 以外的東西命中時才會出現；店名本身就命中時不需要解釋。
     */
    matched_menu_items?: string[];
    /**
     * 三態：open／closed／unknown。unknown 是 OSM 最常見的情況（多數店家沒填
     * opening_hours），不要在畫面上把它顯示成「已打烊」。
     */
    open_status?: 'open' | 'closed' | 'unknown';
    open_now?: boolean | null;
    /** 營業中才有：今天幾點打烊，"21:00"。 */
    closes_at?: string;
    /** 已打烊且今天稍後還會開才有："17:00"。 */
    next_opens_at?: string;
    opening_hours_raw?: string | null;
    opening_hours_week?: OpeningHoursDay[];
    created_at: string;
    updated_at: string;
}

/** GET /restaurants/suggest 的一筆店名建議。刻意只有建議清單需要的欄位。 */
export interface SuggestedRestaurant {
    id: number;
    name: string;
    slug: string;
    address: string | null;
    city: string | null;
    district: string | null;
}

export interface RestaurantSuggestions {
    restaurants: SuggestedRestaurant[];
    cuisines: Cuisine[];
    districts: Array<{ city: string; district: string }>;
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
    // AI Office 的四個角色（admin／manager／developer／viewer）跟餐廳地圖的
    // 一般消費者 `user` 共用同一個欄位，見 App\Models\User::AI_OFFICE_ROLES。
    role: 'user' | 'admin' | 'manager' | 'developer' | 'viewer';
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
    /** 只留下此刻在該店當地時間營業中的餐廳。 */
    open_now?: boolean;
    /** 素食可信度下限（0–100）。門檻選項見 GET /diets 的 meta.confidence_filters。 */
    confidence_min?: number;
    pet_friendly?: boolean;
    parking?: boolean;
    delivery?: boolean;
    takeout?: boolean;
    reservation?: boolean;
    wifi?: boolean;
    outdoor_seating?: boolean;
    family_friendly?: boolean;
    /** 無障礙（OSM `wheelchair=yes|limited|designated`）。 */
    wheelchair?: boolean;
    sort?: 'relevance' | 'confidence' | 'distance' | 'rating' | 'popular' | 'newest';
    per_page?: number;
    cursor?: string;
}
