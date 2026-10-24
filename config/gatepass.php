<?php

/**
 * Digital Gate Pass Engine configuration.
 *
 * Ported from the CATEGORY_CONFIGS, CATEGORY_PALETTES and CATEGORY_POLICIES
 * constants in src/lib/gate-pass-engine/engine.ts. Visual identity (shape,
 * colour, gradient) stays here so the React pass components render unchanged;
 * the access policies here are now authoritative and enforced server-side.
 */
return [

    'default_community_id' => env('GATE_COMMUNITY_ID', 'CID-CYPRESS-BAY'),

    /*
     | The signing secret. The original engine hardcoded a secret in client-side
     | JS (GATE_ENGINE_SECRET), which shipped to every browser and could be read
     | by anyone — meaning anyone could mint a valid pass. It now lives only on
     | the server and must be set in .env.
     */
    'secret' => env('GATE_ENGINE_SECRET'),

    /* Rolling validity window, in seconds, for a dynamic pass token. */
    'window_seconds' => (int) env('GATE_WINDOW_SECONDS', 30),

    /* Clock-skew tolerance when checking the not-yet-valid boundary. */
    'clock_skew_seconds' => 5,

    'protocol' => [
        'envelope_prefix' => 'CH-GPE:v1.',
        'accepted_prefixes' => ['CH-GPE:v1.', 'GPE:', 'CH-PASS:v1.'],
        'version' => 1,
        'engine_id' => 'GPE',
    ],

    'gates' => [
        'GATE-01' => 'Main Gate',
        'GATE-02' => 'Service Gate',
        'GATE-SOUTH' => 'South Gate',
    ],

    /*
     | Which access zones each gate admits into. A pass's zone must be served
     | by the gate it is scanned at: the service gate is for staff, contractors
     | and security, so a visitor is sent to the main gate.
     */
    'gate_zones' => [
        'GATE-01' => [
            'ZONE-ROOT-CORE', 'ZONE-ALL-PERIMETER', 'ZONE-ADMIN-COMMON',
            'ZONE-FACILITIES-WORKSHOP', 'ZONE-RESIDENTIAL-AMENITIES', 'ZONE-HOST-RESIDENCE',
        ],
        'GATE-02' => [
            'ZONE-ROOT-CORE', 'ZONE-ALL-PERIMETER', 'ZONE-ADMIN-COMMON',
            'ZONE-FACILITIES-WORKSHOP', 'ZONE-RESIDENTIAL-AMENITIES',
        ],
        'GATE-SOUTH' => [
            'ZONE-ROOT-CORE', 'ZONE-ALL-PERIMETER', 'ZONE-ADMIN-COMMON',
            'ZONE-FACILITIES-WORKSHOP', 'ZONE-RESIDENTIAL-AMENITIES', 'ZONE-HOST-RESIDENCE',
        ],
    ],

    /*
     | When a visitor's or contractor's pass is valid, relative to the arrival
     | time on the visitor record. A one-time pass opens an hour early and
     | closes twelve hours after the expected arrival (the guest pass page has
     | always treated a pass as lapsed after twelve hours). A recurring pass
     | lasts thirty days and allows re-entry.
     */
    'guest_windows' => [
        'opens_minutes_before' => 60,
        'one_time_hours' => 12,
        'recurring_days' => 30,
    ],

    /* How long a scanned decision waits for the guard to confirm it. */
    'scan_confirm_seconds' => 120,

    /*
    |---------------------------------------------------------------------------
    | Category visual configuration — shape identifies the profile, colour
    | identifies the access class.
    |---------------------------------------------------------------------------
    */
    'categories' => [

        'SYSADMIN' => [
            'display_name' => 'Supreme System Administrator',
            'shape' => 'STAR_8',
            'shape_label' => '8-Point Star Frame',
            'theme_color' => '#4338CA',
            'contrast_bg' => '#1E1B4B',
            'accent_color' => '#F59E0B',
            'badge_border' => 'border-amber-400/50',
            'gradient' => 'from-indigo-900 via-purple-900 to-slate-950',
            'icon_name' => 'Sparkles',
            'description' => 'Highest administrative tier with master root platform controls, cryptographic ledger oversight & security infrastructure.',
        ],

        'ADMIN' => [
            'display_name' => 'Property & Estate Administrator',
            'shape' => 'OCTAGON',
            'shape_label' => 'Octagonal Frame',
            'theme_color' => '#1D4ED8',
            'contrast_bg' => '#1E1B4B',
            'accent_color' => '#60A5FA',
            'badge_border' => 'border-blue-500/40',
            'gradient' => 'from-blue-700 via-indigo-800 to-slate-900',
            'icon_name' => 'Crown',
            'description' => 'Executive administrative oversight, committee management & configurable property overrides.',
        ],

        'HOMEOWNER' => [
            'display_name' => 'Deeded Property Homeowner',
            'shape' => 'HEXAGON',
            'shape_label' => 'Hexagonal House Frame',
            'theme_color' => '#059669',
            'contrast_bg' => '#064E3B',
            'accent_color' => '#F97316',
            'badge_border' => 'border-emerald-500/40',
            'gradient' => 'from-emerald-700 via-teal-800 to-slate-900',
            'icon_name' => 'Home',
            'description' => 'Permanent residential titleholder with full estate privileges, recreational access & visitor clearances.',
        ],

        'RENTER' => [
            'display_name' => 'Verified Resident / Tenant',
            'shape' => 'ROUNDED_SQUARE',
            'shape_label' => 'Rounded Square Frame',
            'theme_color' => '#C2410C',
            'contrast_bg' => '#7C2D12',
            'accent_color' => '#FB923C',
            'badge_border' => 'border-orange-500/40',
            'gradient' => 'from-orange-700 via-amber-800 to-slate-900',
            'icon_name' => 'KeyRound',
            'description' => 'Fixed-term residential lessee with authorized residential ingress and approved amenity rights.',
        ],

        'STAFF' => [
            'display_name' => 'Community & Grounds Staff',
            'shape' => 'DIAMOND',
            'shape_label' => 'Diamond Frame',
            'theme_color' => '#D97706',
            'contrast_bg' => '#78350F',
            'accent_color' => '#FBBF24',
            'badge_border' => 'border-amber-500/40',
            'gradient' => 'from-amber-700 via-orange-800 to-slate-900',
            'icon_name' => 'Wrench',
            'description' => 'Operations, maintenance & estate grounds personnel operating during scheduled shifts.',
        ],

        'SECURITY' => [
            'display_name' => 'Gate Security & Tactical Guard',
            'shape' => 'SHIELD',
            'shape_label' => 'Heraldic Shield Frame',
            'theme_color' => '#DC2626',
            'contrast_bg' => '#7F1D1D',
            'accent_color' => '#10B981',
            'badge_border' => 'border-red-500/40',
            'gradient' => 'from-red-700 via-rose-900 to-slate-950',
            'icon_name' => 'Shield',
            'description' => 'Perimeter enforcement, incident dispatch, gate control & emergency tactical override.',
        ],

        'HOMEOWNER_STAFF' => [
            'display_name' => 'Domestic & Household Staff',
            'shape' => 'HOUSE_HEX',
            'shape_label' => 'Custom Hex/House Frame',
            'theme_color' => '#0D9488',
            'contrast_bg' => '#134E4A',
            'accent_color' => '#14B8A6',
            'badge_border' => 'border-teal-500/40',
            'gradient' => 'from-teal-700 via-cyan-900 to-slate-950',
            'icon_name' => 'Sparkles',
            'description' => 'Private housekeeper, nanny or gardener assigned strictly to designated homeowner residence.',
        ],

        'VISITOR' => [
            'display_name' => 'Registered Visitor',
            'shape' => 'CIRCLE',
            'shape_label' => 'Visitor Circle Frame',
            'theme_color' => '#0369A1',
            'contrast_bg' => '#0C4A6E',
            'accent_color' => '#38BDF8',
            'badge_border' => 'border-sky-500/40',
            'gradient' => 'from-sky-700 via-cyan-900 to-slate-950',
            'icon_name' => 'UserCheck',
            'description' => 'Guest registered by a resident, valid for the stay the host booked and admitted through the main gate.',
        ],

        'CONTRACTOR' => [
            'display_name' => 'Approved Contractor',
            'shape' => 'PENTAGON',
            'shape_label' => 'Contractor Pentagon Frame',
            'theme_color' => '#854D0E',
            'contrast_bg' => '#422006',
            'accent_color' => '#FACC15',
            'badge_border' => 'border-yellow-500/40',
            'gradient' => 'from-yellow-800 via-amber-900 to-slate-950',
            'icon_name' => 'HardHat',
            'description' => 'Tradesperson or service provider approved by estate security, admitted during working hours only.',
        ],

        'DELEGATE' => [
            'display_name' => 'Authorized Delegate',
            'shape' => 'TICKET',
            'shape_label' => 'Delegate Ticket Frame',
            'theme_color' => '#6D28D9',
            'contrast_bg' => '#2E1065',
            'accent_color' => '#C4B5FD',
            'badge_border' => 'border-violet-500/40',
            'gradient' => 'from-violet-800 via-purple-900 to-slate-950',
            'icon_name' => 'UserCheck',
            'description' => 'Someone a homeowner has authorized to act or enter on their behalf, within the limits and dates the homeowner set.',
        ],

        'LONG_TERM_OCCUPANT' => [
            'display_name' => 'Long-Term Occupant',
            'shape' => 'ARCH',
            'shape_label' => 'Long-Term Occupant Arch Frame',
            'theme_color' => '#047857',
            'contrast_bg' => '#022C22',
            'accent_color' => '#6EE7B7',
            'badge_border' => 'border-emerald-500/40',
            'gradient' => 'from-emerald-800 via-teal-900 to-slate-950',
            'icon_name' => 'Home',
            'description' => 'A family member or companion living at a homeowner\'s residence for an extended stay.',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Approved WCAG AA palettes (contrast ratio >= 4.5:1 against white).
    | Shape stays fixed per profile; colour rotates.
    |---------------------------------------------------------------------------
    */
    'palettes' => [

        'HOMEOWNER' => [
            ['id' => 'ho_blue',   'name' => 'Deep Royal Blue',           'hex' => '#1D4ED8', 'accent_hex' => '#60A5FA', 'contrast_ratio' => 6.7, 'wcag_pass' => true],
            ['id' => 'ho_teal',   'name' => 'Caribbean Coastal Teal',    'hex' => '#0F766E', 'accent_hex' => '#2DD4BF', 'contrast_ratio' => 5.5, 'wcag_pass' => true],
            ['id' => 'ho_green',  'name' => 'Emerald Palm Green',        'hex' => '#15803D', 'accent_hex' => '#4ADE80', 'contrast_ratio' => 5.0, 'wcag_pass' => true],
            ['id' => 'ho_purple', 'name' => 'Imperial Estate Purple',    'hex' => '#7E22CE', 'accent_hex' => '#C084FC', 'contrast_ratio' => 7.0, 'wcag_pass' => true],
            ['id' => 'ho_gold',   'name' => 'Prestige Sovereign Gold',   'hex' => '#B45309', 'accent_hex' => '#FBBF24', 'contrast_ratio' => 5.0, 'wcag_pass' => true],
        ],

        'RENTER' => [
            ['id' => 'ren_orange', 'name' => 'Sunset Amber Orange',      'hex' => '#C2410C', 'accent_hex' => '#FB923C', 'contrast_ratio' => 5.2, 'wcag_pass' => true],
            ['id' => 'ren_coral',  'name' => 'Coral Rose Crimson',       'hex' => '#BE123C', 'accent_hex' => '#FB7185', 'contrast_ratio' => 6.3, 'wcag_pass' => true],
            ['id' => 'ren_purple', 'name' => 'Vibrant Violet Purple',    'hex' => '#6B21A8', 'accent_hex' => '#A855F7', 'contrast_ratio' => 8.7, 'wcag_pass' => true],
            ['id' => 'ren_teal',   'name' => 'Lagoon Maritime Teal',     'hex' => '#0F766E', 'accent_hex' => '#2DD4BF', 'contrast_ratio' => 5.5, 'wcag_pass' => true],
            ['id' => 'ren_blue',   'name' => 'Cobalt Harbor Blue',       'hex' => '#1E40AF', 'accent_hex' => '#60A5FA', 'contrast_ratio' => 8.7, 'wcag_pass' => true],
        ],

        'STAFF' => [
            ['id' => 'stf_green',  'name' => 'Forest Operations Green',  'hex' => '#166534', 'accent_hex' => '#4ADE80', 'contrast_ratio' => 7.1, 'wcag_pass' => true],
            ['id' => 'stf_blue',   'name' => 'Engineering Cobalt Blue',  'hex' => '#0369A1', 'accent_hex' => '#38BDF8', 'contrast_ratio' => 5.9, 'wcag_pass' => true],
            ['id' => 'stf_orange', 'name' => 'Industrial Safety Orange', 'hex' => '#C2410C', 'accent_hex' => '#FDBA74', 'contrast_ratio' => 5.2, 'wcag_pass' => true],
            ['id' => 'stf_violet', 'name' => 'Facilities Deep Violet',   'hex' => '#581C87', 'accent_hex' => '#C084FC', 'contrast_ratio' => 10.9, 'wcag_pass' => true],
            ['id' => 'stf_teal',   'name' => 'Service Marine Teal',      'hex' => '#115E59', 'accent_hex' => '#5EEAD4', 'contrast_ratio' => 7.6, 'wcag_pass' => true],
        ],

        'SYSADMIN' => [
            ['id' => 'sys_indigo',  'name' => 'Royal Cyber Indigo',       'hex' => '#3730A3', 'accent_hex' => '#818CF8', 'contrast_ratio' => 9.9,  'wcag_pass' => true],
            ['id' => 'sys_violet',  'name' => 'Deep Root Violet',         'hex' => '#4C1D95', 'accent_hex' => '#A78BFA', 'contrast_ratio' => 11.0, 'wcag_pass' => true],
            ['id' => 'sys_gold',    'name' => 'Radiant Sovereign Gold',   'hex' => '#92400E', 'accent_hex' => '#FCD34D', 'contrast_ratio' => 7.1,  'wcag_pass' => true],
            ['id' => 'sys_blue',    'name' => 'Midnight Electric Blue',   'hex' => '#1E3A8A', 'accent_hex' => '#60A5FA', 'contrast_ratio' => 10.4,  'wcag_pass' => true],
            ['id' => 'sys_crimson', 'name' => 'Secure Kernel Crimson',    'hex' => '#991B1B', 'accent_hex' => '#F87171', 'contrast_ratio' => 8.3,  'wcag_pass' => true],
        ],

        'ADMIN' => [
            ['id' => 'adm_cyan',    'name' => 'Administrative Cyan Teal', 'hex' => '#0E7490', 'accent_hex' => '#22D3EE', 'contrast_ratio' => 5.4, 'wcag_pass' => true],
            ['id' => 'adm_emerald', 'name' => 'Executive Emerald',        'hex' => '#047857', 'accent_hex' => '#34D399', 'contrast_ratio' => 5.5, 'wcag_pass' => true],
            ['id' => 'adm_navy',    'name' => 'Prestige Estate Navy',     'hex' => '#1E3A8A', 'accent_hex' => '#93C5FD', 'contrast_ratio' => 10.4, 'wcag_pass' => true],
            ['id' => 'adm_amber',   'name' => 'Director Bronze Amber',    'hex' => '#B45309', 'accent_hex' => '#FDE047', 'contrast_ratio' => 5.0, 'wcag_pass' => true],
            ['id' => 'adm_cobalt',  'name' => 'Sovereign Cobalt',         'hex' => '#1D4ED8', 'accent_hex' => '#93C5FD', 'contrast_ratio' => 6.7, 'wcag_pass' => true],
        ],

        'SECURITY' => [
            ['id' => 'sec_midnight', 'name' => 'Tactical Midnight Blue',     'hex' => '#1E1B4B', 'accent_hex' => '#6366F1', 'contrast_ratio' => 16.0, 'wcag_pass' => true],
            ['id' => 'sec_emerald',  'name' => 'Perimeter Forest Emerald',   'hex' => '#064E3B', 'accent_hex' => '#34D399', 'contrast_ratio' => 9.7, 'wcag_pass' => true],
            ['id' => 'sec_violet',   'name' => 'Enforcement Night Violet',   'hex' => '#4A044E', 'accent_hex' => '#E879F9', 'contrast_ratio' => 14.8, 'wcag_pass' => true],
            ['id' => 'sec_slate',    'name' => 'Armored Steel Slate',        'hex' => '#334155', 'accent_hex' => '#94A3B8', 'contrast_ratio' => 10.4,  'wcag_pass' => true],
            ['id' => 'sec_crimson',  'name' => 'Tactical Alert Crimson',     'hex' => '#881337', 'accent_hex' => '#F43F5E', 'contrast_ratio' => 9.6,  'wcag_pass' => true],
        ],

        'HOMEOWNER_STAFF' => [
            ['id' => 'hstf_rose',       'name' => 'Domestic Carmine Rose',    'hex' => '#BE123C', 'accent_hex' => '#FB7185', 'contrast_ratio' => 6.3, 'wcag_pass' => true],
            ['id' => 'hstf_amber',      'name' => 'Estate Warm Amber',        'hex' => '#B45309', 'accent_hex' => '#FCD34D', 'contrast_ratio' => 5.0, 'wcag_pass' => true],
            ['id' => 'hstf_teal',       'name' => 'Household Maritime Teal',  'hex' => '#0F766E', 'accent_hex' => '#5EEAD4', 'contrast_ratio' => 5.5, 'wcag_pass' => true],
            ['id' => 'hstf_plum',       'name' => 'Private Residence Plum',   'hex' => '#701A75', 'accent_hex' => '#F0ABFC', 'contrast_ratio' => 10.0, 'wcag_pass' => true],
            ['id' => 'hstf_terracotta', 'name' => 'Cottage Terracotta',       'hex' => '#9A3412', 'accent_hex' => '#FDBA74', 'contrast_ratio' => 7.3, 'wcag_pass' => true],
        ],

        'VISITOR' => [
            ['id' => 'vis_sky',     'name' => 'Welcome Sky Blue',  'hex' => '#0369A1', 'accent_hex' => '#38BDF8', 'contrast_ratio' => 5.9, 'wcag_pass' => true],
            ['id' => 'vis_indigo',  'name' => 'Guest Indigo',      'hex' => '#4338CA', 'accent_hex' => '#A5B4FC', 'contrast_ratio' => 7.9, 'wcag_pass' => true],
            ['id' => 'vis_magenta', 'name' => 'Arrival Magenta',   'hex' => '#9D174D', 'accent_hex' => '#F9A8D4', 'contrast_ratio' => 7.9, 'wcag_pass' => true],
            ['id' => 'vis_green',   'name' => 'Courtesy Green',    'hex' => '#15803D', 'accent_hex' => '#86EFAC', 'contrast_ratio' => 5.0, 'wcag_pass' => true],
            ['id' => 'vis_cyan',    'name' => 'Lobby Cyan',        'hex' => '#0E7490', 'accent_hex' => '#67E8F9', 'contrast_ratio' => 5.4, 'wcag_pass' => true],
        ],

        'CONTRACTOR' => [
            ['id' => 'con_ochre',    'name' => 'Worksite Ochre',   'hex' => '#854D0E', 'accent_hex' => '#FACC15', 'contrast_ratio' => 6.9,  'wcag_pass' => true],
            ['id' => 'con_rust',     'name' => 'Trade Rust',       'hex' => '#7C2D12', 'accent_hex' => '#FDBA74', 'contrast_ratio' => 9.4,  'wcag_pass' => true],
            ['id' => 'con_graphite', 'name' => 'Toolbox Graphite', 'hex' => '#374151', 'accent_hex' => '#D1D5DB', 'contrast_ratio' => 10.3, 'wcag_pass' => true],
            ['id' => 'con_signal',   'name' => 'Signal Red',       'hex' => '#B91C1C', 'accent_hex' => '#FCA5A5', 'contrast_ratio' => 6.5,  'wcag_pass' => true],
            ['id' => 'con_navy',     'name' => 'Utility Navy',     'hex' => '#1E40AF', 'accent_hex' => '#93C5FD', 'contrast_ratio' => 8.7,  'wcag_pass' => true],
        ],

        'DELEGATE' => [
            ['id' => 'del_violet', 'name' => 'Trust Violet',    'hex' => '#6D28D9', 'accent_hex' => '#C4B5FD', 'contrast_ratio' => 7.1,  'wcag_pass' => true],
            ['id' => 'del_plum',   'name' => 'Deputy Plum',     'hex' => '#5B21B6', 'accent_hex' => '#DDD6FE', 'contrast_ratio' => 9.0,  'wcag_pass' => true],
            ['id' => 'del_orchid', 'name' => 'Proxy Orchid',    'hex' => '#86198F', 'accent_hex' => '#F0ABFC', 'contrast_ratio' => 8.2,  'wcag_pass' => true],
        ],

        'LONG_TERM_OCCUPANT' => [
            ['id' => 'lto_emerald', 'name' => 'Household Emerald', 'hex' => '#047857', 'accent_hex' => '#6EE7B7', 'contrast_ratio' => 5.5, 'wcag_pass' => true],
            ['id' => 'lto_pine',    'name' => 'Homestead Pine',    'hex' => '#065F46', 'accent_hex' => '#A7F3D0', 'contrast_ratio' => 7.7, 'wcag_pass' => true],
            ['id' => 'lto_teal',    'name' => 'Hearth Teal',       'hex' => '#115E59', 'accent_hex' => '#99F6E4', 'contrast_ratio' => 7.6, 'wcag_pass' => true],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Access policies. These are enforced by GatePassEngine::validate() on the
    | server, so a tampered client cannot widen its own clearance.
    |---------------------------------------------------------------------------
    */
    'policies' => [

        'SYSADMIN' => [
            'title' => 'Supreme Administrative & Core Infrastructure Clearance',
            'description' => 'Highest platform clearance tier with unlimited multi-zone oversight, root gate override, security configuration, and cryptographic ledger controls.',
            'authorized_zones' => [
                'Root Security Operations & Network Core',
                'Administrative Pavilion & Executive Command Suite',
                'All Gatehouses & Barrier Controllers (All Portals)',
                'All Residential Lots & Estate Common Amenities',
                'Critical Water Reservoirs, Power & Server Infrastructure',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02', 'GATE-ANY'],
            'operational_hours' => ['is_24_hours' => true],
            'privileges' => [
                'can_manage_guests' => true,
                'can_associate_vehicles' => true,
                'has_emergency_override' => true,
                'has_gate_operation_override' => true,
                'restricted_from_homeowner_functions' => false,
            ],
        ],

        'ADMIN' => [
            'title' => 'Administrative Unrestricted Clearance',
            'description' => 'Access to administrative offices, management suites, general property, and all ingress portals with full audit override.',
            'authorized_zones' => [
                'Administrative Office & Boardroom',
                'Management Pavilion',
                'All Common Amenities (Clubhouse, Pool, Gym, Park)',
                'Security Operations Center',
                'Utility & Server Infrastructure',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02', 'GATE-ANY'],
            'operational_hours' => ['is_24_hours' => true],
            'privileges' => [
                'can_manage_guests' => true,
                'can_associate_vehicles' => true,
                'has_emergency_override' => true,
                'has_gate_operation_override' => true,
                'restricted_from_homeowner_functions' => false,
            ],
        ],

        'HOMEOWNER' => [
            'title' => 'Residential Titleholder Clearance',
            'description' => 'Access to deeded residential lot, all recreational community amenities, pre-clearance guest privileges, and vehicle association.',
            'authorized_zones' => [
                'Deeded Residential Lot & Driveway',
                'Community Clubhouse & Lounge',
                'Aquatic Center & Pool Deck',
                'Fitness Gym & Sports Courts',
                'Recreation Park & Trail System',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02', 'GATE-ANY'],
            'operational_hours' => ['is_24_hours' => true],
            'privileges' => [
                'can_manage_guests' => true,
                'can_associate_vehicles' => true,
                'has_emergency_override' => false,
                'has_gate_operation_override' => false,
                'restricted_from_homeowner_functions' => false,
            ],
        ],

        'RENTER' => [
            'title' => 'Lessee Residential Clearance',
            'description' => 'Access to designated lease property and approved common amenities during the active term of the residential lease.',
            'authorized_zones' => [
                'Designated Rental Unit & Driveway',
                'Community Clubhouse',
                'Aquatic Center & Pool Deck',
                'Fitness Gym',
                'Recreation Park',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02', 'GATE-ANY'],
            'operational_hours' => ['is_24_hours' => true],
            'privileges' => [
                'can_manage_guests' => true,
                'can_associate_vehicles' => true,
                'has_emergency_override' => false,
                'has_gate_operation_override' => false,
                // Cannot vote in AGM or access financial ledgers.
                'restricted_from_homeowner_functions' => true,
            ],
        ],

        'STAFF' => [
            'title' => 'Facility & Operations Clearance',
            'description' => 'Time-bound access to maintenance depots, utility reservoirs, community grounds, and facility workshops during scheduled shifts.',
            'authorized_zones' => [
                'Maintenance Depots & Utility Reservoirs',
                'Groundskeeping Sheds & Workshop',
                'Clubhouse Maintenance Corridors',
                'Waste Management Facilities',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02'],
            'operational_hours' => [
                'is_24_hours' => false,
                'start_hour' => 6,
                'end_hour' => 19,
                'days_of_week' => [1, 2, 3, 4, 5, 6],   // Mon-Sat
            ],
            'privileges' => [
                'can_manage_guests' => false,
                'can_associate_vehicles' => false,
                'has_emergency_override' => false,
                'has_gate_operation_override' => false,
                'restricted_from_homeowner_functions' => true,
            ],
        ],

        'SECURITY' => [
            'title' => 'Perimeter Tactical & Gate Clearance',
            'description' => 'Unrestricted clearance across all guard booths, gate barriers, perimeter surveillance nodes, and emergency incident corridors.',
            'authorized_zones' => [
                'Main Ingress Control Booth (Gate 01)',
                'North Gate Control Post (Gate 02)',
                'Security Patrol Corridors',
                'Perimeter Surveillance Infrastructure',
                'Emergency Access Gates',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02', 'GATE-ANY'],
            'operational_hours' => ['is_24_hours' => true],
            'privileges' => [
                'can_manage_guests' => true,
                'can_associate_vehicles' => true,
                'has_emergency_override' => true,
                'has_gate_operation_override' => true,
                'restricted_from_homeowner_functions' => true,
            ],
        ],

        'HOMEOWNER_STAFF' => [
            'title' => 'Designated Household Staff Clearance',
            'description' => 'Restricted, time-bound ingress permitted only for the specific employer property and designated direct access thoroughfares.',
            'authorized_zones' => [
                'Assigned Employer Residence Only',
                'Direct Pedestrian & Vehicle Ingress Corridors',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02'],
            'operational_hours' => [
                'is_24_hours' => false,
                'start_hour' => 6,
                'end_hour' => 18,
                'days_of_week' => [1, 2, 3, 4, 5, 6],
            ],
            'privileges' => [
                'can_manage_guests' => false,
                'can_associate_vehicles' => false,
                'has_emergency_override' => false,
                'has_gate_operation_override' => false,
                'restricted_from_homeowner_functions' => true,
            ],
        ],

        'VISITOR' => [
            'title' => 'Registered Guest Clearance',
            'description' => 'Entry to the host residence for the stay the resident registered, through the main gate only.',
            'authorized_zones' => [
                'Host Residence & Driveway',
                'Main Ingress Road',
            ],
            'allowed_gates' => ['GATE-01'],
            // The pass's own validity window bounds the visit; no extra shift.
            'operational_hours' => ['is_24_hours' => true],
            'privileges' => [
                'can_manage_guests' => false,
                'can_associate_vehicles' => false,
                'has_emergency_override' => false,
                'has_gate_operation_override' => false,
                'restricted_from_homeowner_functions' => true,
            ],
        ],

        'CONTRACTOR' => [
            'title' => 'Approved Contractor Clearance',
            'description' => 'Working-hours entry for approved tradespeople to the job site and service corridors.',
            'authorized_zones' => [
                'Assigned Job Site',
                'Service Corridors & Utility Areas',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02'],
            'operational_hours' => [
                'is_24_hours' => false,
                'start_hour' => 7,
                'end_hour' => 18,
                'days_of_week' => [1, 2, 3, 4, 5, 6],
            ],
            'privileges' => [
                'can_manage_guests' => false,
                'can_associate_vehicles' => false,
                'has_emergency_override' => false,
                'has_gate_operation_override' => false,
                'restricted_from_homeowner_functions' => true,
            ],
        ],

        // Baselines only: each delegation's own days, hours and gates narrow these.
        'DELEGATE' => [
            'title' => 'Authorized Delegate Clearance',
            'description' => 'Entry on a homeowner\'s behalf to their residence, within the days, hours and dates of the delegation.',
            'authorized_zones' => [
                'Host Residence',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02'],
            'operational_hours' => [
                'is_24_hours' => true,
                'start_hour' => 0,
                'end_hour' => 24,
                'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            ],
            'privileges' => [
                'can_manage_guests' => false,
                'can_associate_vehicles' => false,
                'has_emergency_override' => false,
                'has_gate_operation_override' => false,
                'restricted_from_homeowner_functions' => true,
            ],
        ],

        'LONG_TERM_OCCUPANT' => [
            'title' => 'Long-Term Occupant Clearance',
            'description' => 'Residential entry for someone living at a homeowner\'s residence for an extended stay.',
            'authorized_zones' => [
                'Host Residence',
                'Residential Common Areas',
            ],
            'allowed_gates' => ['GATE-01', 'GATE-02'],
            'operational_hours' => [
                'is_24_hours' => true,
                'start_hour' => 0,
                'end_hour' => 24,
                'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            ],
            'privileges' => [
                'can_manage_guests' => false,
                'can_associate_vehicles' => true,
                'has_emergency_override' => false,
                'has_gate_operation_override' => false,
                'restricted_from_homeowner_functions' => true,
            ],
        ],
    ],
];
