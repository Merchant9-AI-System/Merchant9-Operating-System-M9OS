// Nilai/label WAJIB padan dgn BranchDemandEntryController::GOLD_TYPES / WEIGHT_RANGES / SIZE_RANGES.
// Dikongsi antara ProductFilters.vue (checkbox popover) & ActiveFilterBadges.vue (badge label
// lookup) - satu sumber supaya label tak boleh terpesong antara dua tempat.
export const GOLD_TYPES = [
    { value: '9999', label: '9999' },
    { value: '999', label: '999' },
    { value: '916', label: '916' },
    { value: '750', label: '750' },
    { value: '585', label: '585' },
    { value: '375', label: '375' },
    { value: '925', label: '925 (Perak)' },
];

export const WEIGHT_RANGES = [
    { value: 'w_0_5', label: '< 5g' },
    { value: 'w_5_10', label: '5-10g' },
    { value: 'w_10_20', label: '10-20g' },
    { value: 'w_20_50', label: '20-50g' },
    { value: 'w_50_100', label: '50-100g' },
    { value: 'w_100_plus', label: '> 100g' },
];

export const SIZE_RANGES = [
    { value: 's_0_10', label: '≤ 10' },
    { value: 's_10_15', label: '10-15' },
    { value: 's_15_20', label: '15-20' },
    { value: 's_20_plus', label: '> 20' },
];
