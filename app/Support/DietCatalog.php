<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * config/diet.php 的讀取層。Controller／Provider／前端要的 diet 規則都從這裡問，
 * 不要自己讀 config 陣列再寫死 code。
 */
class DietCatalog
{
    /**
     * @return list<array{
     *     code: string,
     *     label: string,
     *     kind: string,
     *     group_label: string,
     *     osm_tag: string|null,
     *     osm_values: list<string>
     * }>
     */
    public static function types(): array
    {
        /** @var list<array<string, mixed>> $types */
        $types = config('diet.types', []);

        return array_map(function (array $type): array {
            $osmValues = $type['osm_values'] ?? [];

            return [
                'code' => (string) $type['code'],
                'label' => (string) $type['label'],
                'kind' => (string) $type['kind'],
                'group_label' => (string) ($type['group_label'] ?? ''),
                'osm_tag' => isset($type['osm_tag']) ? (string) $type['osm_tag'] : null,
                'osm_values' => is_array($osmValues) ? array_values(array_map('strval', $osmValues)) : [],
            ];
        }, $types);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::types(), 'code');
    }

    /**
     * @return list<string>
     */
    public static function codesOfKind(string $kind): array
    {
        return array_values(array_map(
            fn (array $type) => $type['code'],
            array_filter(self::types(), fn (array $type) => $type['kind'] === $kind),
        ));
    }

    /**
     * @param  list<string>  $kinds
     * @return list<string>
     */
    public static function codesOfKinds(array $kinds): array
    {
        $codes = [];

        foreach ($kinds as $kind) {
            $codes = [...$codes, ...self::codesOfKind($kind)];
        }

        return array_values(array_unique($codes));
    }

    public static function kindFor(string $code): ?string
    {
        foreach (self::types() as $type) {
            if ($type['code'] === $code) {
                return $type['kind'];
            }
        }

        return null;
    }

    /**
     * OSM 同步會寫入的 diet codes。手動加上、且不在這份清單裡的關聯，重跑 sync 要保留。
     *
     * @return list<string>
     */
    public static function osmManagedCodes(): array
    {
        return array_values(array_map(
            fn (array $type) => $type['code'],
            array_filter(self::types(), fn (array $type) => $type['osm_tag'] !== null),
        ));
    }

    /**
     * @return list<string>
     */
    public static function osmTags(): array
    {
        $tags = [];

        foreach (self::types() as $type) {
            if ($type['osm_tag'] !== null) {
                $tags[] = $type['osm_tag'];
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return list<string>
     */
    public static function osmAmenities(): array
    {
        /** @var list<string> $amenities */
        $amenities = config('diet.osm_amenities', ['restaurant', 'cafe']);

        return $amenities;
    }

    /**
     * @return array<string, array{feature: string, values: list<string>}>
     */
    public static function osmFeatureMap(): array
    {
        /** @var array<string, array{feature: string, values: list<string>}> $map */
        $map = config('diet.osm_features', []);

        return $map;
    }

    /**
     * @return list<string>
     */
    public static function syncModeNames(): array
    {
        /** @var array<string, mixed> $modes */
        $modes = config('diet.sync_modes', []);

        return array_keys($modes);
    }

    public static function defaultSyncMode(): string
    {
        return (string) config('diet.default_sync_mode', 'only');
    }

    /**
     * @return list<string>
     */
    public static function syncModeOsmValues(string $mode): array
    {
        /** @var list<string> $values */
        $values = config("diet.sync_modes.{$mode}.osm_values", []);

        return $values;
    }

    public static function resolveSyncMode(?string $mode): string
    {
        $resolved = ($mode === null || $mode === '') ? self::defaultSyncMode() : $mode;

        if (! in_array($resolved, self::syncModeNames(), true)) {
            throw new \InvalidArgumentException(
                "Unknown diet sync mode [{$resolved}], expected ".implode(', ', self::syncModeNames()).'.'
            );
        }

        return $resolved;
    }

    public static function syncModeIncludes(string $mode, string $osmValue): bool
    {
        return in_array($osmValue, self::syncModeOsmValues($mode), true);
    }

    /**
     * 某過 Overpass tag 在這個 sync mode 下該比對哪些值。
     *
     * @return list<string>
     */
    public static function osmValuesForTagInMode(string $tag, string $mode): array
    {
        $modeValues = self::syncModeOsmValues($mode);
        $found = [];

        foreach (self::types() as $type) {
            if ($type['osm_tag'] !== $tag) {
                continue;
            }

            foreach ($type['osm_values'] as $value) {
                $found[] = $value;
            }
        }

        $found = array_unique($found);
        $ordered = [];

        foreach ($modeValues as $value) {
            if (in_array($value, $found, true)) {
                $ordered[] = $value;
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $tags
     * @return list<string>
     */
    public static function mapOsmTags(array $tags): array
    {
        $codes = [];

        foreach (self::types() as $type) {
            if ($type['osm_tag'] === null) {
                continue;
            }

            $value = $tags[$type['osm_tag']] ?? null;

            if (is_string($value) && in_array($value, $type['osm_values'], true)) {
                $codes[] = $type['code'];
            }
        }

        return self::applyImplies(array_values(array_unique($codes)));
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function applyImplies(array $codes): array
    {
        /** @var array<string, list<string>> $implies */
        $implies = config('diet.implies', []);

        foreach ($implies as $from => $targets) {
            if (! in_array($from, $codes, true)) {
                continue;
            }

            foreach ($targets as $target) {
                $codes[] = $target;
            }
        }

        return array_values(array_unique($codes));
    }

    public static function venueScopeParam(): string
    {
        return (string) config('diet.venue_scope.param', 'venue_scope');
    }

    public static function venueScopeDefault(): string
    {
        return (string) config('diet.venue_scope.default', 'exclusive');
    }

    public static function venueScopeGroupLabel(): string
    {
        return (string) config('diet.venue_scope.group_label', '');
    }

    /**
     * @return array<string, array{label: string, include_kinds: list<string>|null, exclude_kinds: list<string>}>
     */
    public static function venueScopeValues(): array
    {
        /** @var array<string, array<string, mixed>> $values */
        $values = config('diet.venue_scope.values', []);

        $normalized = [];

        foreach ($values as $key => $value) {
            $include = $value['include_kinds'] ?? null;
            $exclude = $value['exclude_kinds'] ?? [];

            $normalized[$key] = [
                'label' => (string) ($value['label'] ?? $key),
                'include_kinds' => is_array($include) ? array_values(array_map('strval', $include)) : null,
                'exclude_kinds' => is_array($exclude) ? array_values(array_map('strval', $exclude)) : [],
            ];
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    public static function venueScopeKeys(): array
    {
        return array_keys(self::venueScopeValues());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function venueScopeOptions(): array
    {
        $options = [];

        foreach (self::venueScopeValues() as $value => $meta) {
            $options[] = [
                'value' => $value,
                'label' => $meta['label'],
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public static function venueScopeMeta(): array
    {
        return [
            'param' => self::venueScopeParam(),
            'default' => self::venueScopeDefault(),
            'group_label' => self::venueScopeGroupLabel(),
            'values' => self::venueScopeOptions(),
        ];
    }

    /**
     * @param  list<string>  $codes
     */
    public static function venueKindFromCodes(array $codes): ?string
    {
        $hasExclusive = false;
        $hasFriendly = false;

        foreach ($codes as $code) {
            $kind = self::kindFor($code);

            if ($kind === 'exclusive') {
                $hasExclusive = true;
            }

            if ($kind === 'friendly') {
                $hasFriendly = true;
            }
        }

        if ($hasExclusive) {
            return 'exclusive';
        }

        if ($hasFriendly) {
            return 'friendly';
        }

        return null;
    }

    /**
     * @param  list<string>  $codes
     * @return array{kind: string|null, badge: string|null, summary: string|null}
     */
    public static function venuePresentation(array $codes): array
    {
        $kind = self::venueKindFromCodes($codes);
        $copy = self::copyForKind($kind);

        return [
            'kind' => $kind,
            'badge' => $copy !== null ? $copy['badge'] : null,
            'summary' => $copy !== null ? $copy['short'] : null,
        ];
    }

    /**
     * @return array{badge: string, short: string, menu_empty: string, menu_empty_osm: string}|null
     */
    public static function copyForKind(?string $kind): ?array
    {
        if ($kind === null) {
            return null;
        }

        /** @var array{badge?: string, short?: string, menu_empty?: string, menu_empty_osm?: string}|null $copy */
        $copy = config("diet.copy.{$kind}");

        if (! is_array($copy)) {
            return null;
        }

        return [
            'badge' => (string) ($copy['badge'] ?? ''),
            'short' => (string) ($copy['short'] ?? ''),
            'menu_empty' => (string) ($copy['menu_empty'] ?? ''),
            'menu_empty_osm' => (string) ($copy['menu_empty_osm'] ?? ''),
        ];
    }

    public static function menuEmptyMessage(?string $kind, ?string $source = null): string
    {
        $copy = self::copyForKind($kind);

        if ($copy !== null) {
            if ($source === 'osm' && $copy['menu_empty_osm'] !== '') {
                return $copy['menu_empty_osm'];
            }

            if ($copy['menu_empty'] !== '') {
                return $copy['menu_empty'];
            }
        }

        return (string) config('diet.copy.menu_empty_fallback', '菜單尚未建檔。');
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public static function menuItemDiets(): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = config('diet.menu_item_diets', []);

        return array_map(fn (array $item): array => [
            'code' => (string) $item['code'],
            'label' => (string) $item['label'],
        ], $items);
    }

    /**
     * @return list<string>
     */
    public static function menuItemDietCodes(): array
    {
        return array_column(self::menuItemDiets(), 'code');
    }

    public static function menuItemDietLabel(string $code): string
    {
        foreach (self::menuItemDiets() as $item) {
            if ($item['code'] === $code) {
                return $item['label'];
            }
        }

        return $code;
    }

    /**
     * exclusive code 對到同 osm_tag 的 friendly code。ovo_lacto 這種沒有 OSM 標籤的
     * 沒有對應友善類型，降級時直接拿掉，不在這裡寫 vegan → vegan_friendly。
     */
    public static function friendlyCounterpart(string $exclusiveCode): ?string
    {
        $from = null;

        foreach (self::types() as $type) {
            if ($type['code'] === $exclusiveCode) {
                $from = $type;
                break;
            }
        }

        if ($from === null || $from['kind'] !== 'exclusive' || $from['osm_tag'] === null) {
            return null;
        }

        foreach (self::types() as $type) {
            if ($type['kind'] === 'friendly' && $type['osm_tag'] === $from['osm_tag']) {
                return $type['code'];
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function reportActions(): array
    {
        /** @var array<string, array<string, string>> $actions */
        $actions = config('diet.report_actions', []);

        return $actions;
    }

    public static function reportAction(string $type, ?string $kind): string
    {
        $forType = self::reportActions()[$type] ?? [];

        if ($kind !== null && isset($forType[$kind])) {
            return (string) $forType[$kind];
        }

        return isset($forType['*']) ? (string) $forType['*'] : 'noop';
    }

    public static function externalSourceScore(?string $venueKind): int
    {
        $kind = $venueKind ?? 'exclusive';
        $configured = config("diet.confidence.external_source.{$kind}");

        if (is_numeric($configured)) {
            return (int) $configured;
        }

        return (int) config('vegetarian.verification_weights.external_source', 0);
    }

    public static function applyVenueScope(Builder $query, ?string $scope): void
    {
        if ($scope === null || $scope === '') {
            return;
        }

        $values = self::venueScopeValues();

        if (! isset($values[$scope])) {
            return;
        }

        $include = $values[$scope]['include_kinds'];
        $exclude = $values[$scope]['exclude_kinds'];

        if ($include !== null) {
            $codes = self::codesOfKinds($include);

            if ($codes === []) {
                $query->whereRaw('0 = 1');

                return;
            }

            $query->whereHas('dietTypes', fn (Builder $q) => $q->whereIn('code', $codes));
        }

        if ($exclude !== []) {
            $codes = self::codesOfKinds($exclude);

            if ($codes !== []) {
                $query->whereDoesntHave('dietTypes', fn (Builder $q) => $q->whereIn('code', $codes));
            }
        }
    }
}
