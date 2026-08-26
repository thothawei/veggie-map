/**
 * 必須與後端 `App\Models\Feature::CODES`／FeatureSeeder 一致。
 * useFilterQuery 解析網址時還沒打過 `/features`，所以名單得在前端也有一份。
 */
export const FEATURE_CODES = [
    'pet_friendly',
    'parking',
    'delivery',
    'takeout',
    'reservation',
    'wifi',
    'outdoor_seating',
    'family_friendly',
    'wheelchair',
] as const;

export type FeatureCode = (typeof FEATURE_CODES)[number];
