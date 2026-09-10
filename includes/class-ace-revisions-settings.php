<?php
/**
 * Settings store for Ace Revisions.
 *
 * One option (ace_revisions_options), one schema, one sanitiser. The settings page
 * renders from the same schema: tabs → sections (fieldsets) → fields.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_Settings {

    /** @var array|null Per-request cache of the merged options; cleared on update(). */
    private static $cache = null;

    const OPTION = 'ace_revisions_options';

    /**
     * Field schema keyed by option key.
     * type: checkbox | text | textarea | number | post_types | taxonomies | select
     * tab / section place the field on the settings page.
     */
    public static function fields(): array {
        $fields = [
            'post_types' => [
                'tab' => "tracking",
                'section' => "tracking-types",
                'type' => "post_types",
                'label' => "Post types",
                'help' => "Track post meta on these post types.",
            ],
            'meta_keys' => [
                'tab' => "tracking",
                'section' => "tracking-keys",
                'type' => "textarea",
                'label' => "Meta keys",
                'help' => "One key per line. End a line with * to match a prefix (e.g. _ace_seo_*). Keys starting with _edit_ are always ignored.",
                'default' => "",
                'rows' => 6,
            ],
            'taxonomies' => [
                'tab' => "terms",
                'section' => "terms-taxonomies",
                'type' => "taxonomies",
                'label' => "Taxonomies",
                'help' => "Track name, slug, description, parent and term meta on these taxonomies.",
            ],
            'term_meta_keys' => [
                'tab' => "terms",
                'section' => "terms-keys",
                'type' => "textarea",
                'label' => "Term meta keys",
                'help' => "One key or prefix* per line. Empty tracks everything.",
                'default' => "",
                'rows' => 4,
            ],
            'term_meta_ignore' => [
                'tab' => "terms",
                'section' => "terms-keys",
                'type' => "textarea",
                'label' => "Ignore these term meta keys",
                'help' => "Housekeeping keys plugins rewrite on every save (timestamps, migration markers). One key or prefix* per line.",
                'default' => "_edit_*\n*_migration_check\n*_last_synced*",
                'rows' => 3,
            ],
            'cap_per_object' => [
                'tab' => "storage",
                'section' => "storage-cap",
                'type' => "number",
                'label' => "Revisions kept per object",
                'help' => "Native revisions cap for tracked terms and post types. Older revisions are dropped as new ones are made.",
                'default' => 5,
                'min' => 1,
                'max' => 100,
            ],
            'cleanup_enabled' => [
                'tab' => "storage",
                'section' => "storage-cleanup",
                'type' => "checkbox",
                'label' => "Clean up old revisions nightly",
                'help' => "Runs the Overview clean-up every night for every content type except term snapshots.",
                'default' => 0,
            ],
            'cleanup_months' => [
                'tab' => "storage",
                'section' => "storage-cleanup",
                'type' => "number",
                'label' => "Content not modified in (months)",
                'default' => 12,
                'min' => 1,
                'max' => 240,
            ],
            'only_on_change' => [
                'tab' => "storage",
                'section' => "storage-cap",
                'type' => "checkbox",
                'label' => "Only store when a tracked value actually changed",
                'help' => "A save where nothing tracked differs does not create a revision.",
                'default' => 1,
            ],
        ];

        // Native revision controls, one pair per post type that supports revisions.
        foreach ( self::revision_post_types() as $type => $label ) {
            // Stored inverted (off = 1) so a missing value can never switch revisions off by accident.
            $fields[ 'revisions_off_' . $type ] = [
                'tab'     => 'limits',
                'section' => 'limits-types',
                'type'    => 'checkbox',
                'label'   => sprintf( __( '%s: switch revisions off', 'ace-revisions' ), $label ),
                'help'    => __( 'Also hides the Revisions panel for this type.', 'ace-revisions' ),
                'default' => 0,
            ];
            $fields[ 'revisions_keep_' . $type ] = [
                'tab'     => 'limits',
                'section' => 'limits-types',
                'type'    => 'number',
                'label'   => sprintf( __( '%s: revisions to keep', 'ace-revisions' ), $label ),
                'help'    => __( '-1 keeps every revision (the WordPress default); 0 keeps none.', 'ace-revisions' ),
                'default' => -1,
                'min'     => -1,
                'max'     => 1000,
            ];
        }
        $fields['autosave_interval'] = [
            'tab'     => 'limits',
            'section' => 'limits-autosave',
            'type'    => 'number',
            'label'   => __( 'Autosave interval (seconds)', 'ace-revisions' ),
            'help'    => __( 'How often the editor autosaves. Only applies when AUTOSAVE_INTERVAL is not set in wp-config.php.', 'ace-revisions' ),
            'default' => 60,
            'min'     => 10,
            'max'     => 3600,
        ];

        /**
         * Lets a site (via a must-use plugin) add or adjust settings fields.
         *
         * @param array $fields Schema keyed by option key.
         */
        return apply_filters( 'ace_revisions_settings_fields', $fields );
    }

    /**
     * Tabs: id, label, dashicon, help (guide panel text), sections; custom => true
     * renders through the ace_revisions_settings_tab_content action instead of fields.
     */
    /**
     * Post types that support revisions (by their own registration, before we touch them).
     *
     * @return array<string,string> name => label
     */
    public static function revision_post_types(): array {
        static $types = null;
        if ( null !== $types ) {
            return $types;
        }
        $types = [];
        foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $object ) {
            if ( Ace_Revisions_Terms::POST_TYPE === $object->name ) {
                continue;
            }
            $stored = get_option( self::OPTION );
            if ( post_type_supports( $object->name, 'revisions' ) || isset( $stored[ 'revisions_off_' . $object->name ] ) ) {
                $types[ $object->name ] = $object->labels->name;
            }
        }
        return $types;
    }

    public static function tabs(): array {
        $tabs = [
            [
                'id' => "overview",
                'label' => "Overview",
                'icon' => "dashboard",
                'custom' => true,
                'help' => "How many revisions the database holds and how much room they take, by content type. Term snapshots are the hidden posts this plugin keeps per tracked term. The clean-up removes revisions of content nobody has touched for a while; the content itself is never removed.",
            ],[
            'id' => "tracking",
            'label' => "Post meta",
            'icon' => "backup",
            'help' => "Core only revisions title, content and excerpt. Tick the post types and list the meta keys you care about and every save copies those values onto the revision, so the revisions screen shows a diff for them and a roll-back restores them. Nothing is tracked until you tick something.",
            'sections' => [[
                'id' => "tracking-types",
                'title' => "Post types",
                'icon' => "admin-post",
                'description' => "",
            ], [
                'id' => "tracking-keys",
                'title' => "Meta keys",
                'icon' => "editor-code",
                'description' => "Exact keys, or a prefix with a trailing *. Ace Crawl Enhancer uses _ace_seo_*; WooCommerce prices are _price, _regular_price and _sale_price.",
            ]],
        ], [
            'id' => "terms",
            'label' => "Terms",
            'icon' => "tag",
            'help' => "WordPress has no revisions for terms, so each tracked term gets a hidden snapshot post and every save of the term becomes one native revision of it: name, slug, description, parent and every term meta key, from any panel including plugins you did not build. The term edit screen gets a History section that summarises each revision and links to the standard revisions screen for the full before/after and Restore.",
            'sections' => [[
                'id' => "terms-taxonomies",
                'title' => "Taxonomies",
                'icon' => "category",
                'description' => "",
            ], [
                'id' => "terms-keys",
                'title' => "Term meta keys",
                'icon' => "editor-code",
                'description' => "Leave empty to track every term meta key on the tracked taxonomies.",
            ]],
        ], [
            'id' => "limits",
            'label' => "Native limits",
            'icon' => "admin-settings",
            'help' => "The WordPress revision controls, in one place. Per post type: whether revisions are kept at all and how many. WP_POST_REVISIONS in wp-config.php wins over the per-type number when it is set; AUTOSAVE_INTERVAL likewise. What is currently in force is shown on the Overview tab.",
            'sections' => [[
                'id' => "limits-types",
                'title' => "Per post type",
                'icon' => "admin-post",
                'description' => "Switching revisions off for a type also hides its Revisions panel.",
            ], [
                'id' => "limits-autosave",
                'title' => "Autosave",
                'icon' => "clock",
                'description' => "",
            ]],
        ], [
            'id' => "storage",
            'label' => "Storage",
            'icon' => "database",
            'help' => "One save is one revision holding the whole term or post, however many fields it touched. The cap is the native revisions limit applied per object; wp ace-revisions prune applies it retrospectively.",
            'sections' => [[
                'id' => "storage-cap",
                'title' => "Retention",
                'icon' => "clock",
                'description' => "",
            ], [
                'id' => "storage-cleanup",
                'title' => "Nightly clean-up",
                'icon' => "trash",
                'description' => "The last run and what it removed are shown on the Overview tab.",
            ]],
        ]];
        return apply_filters( 'ace_revisions_settings_tabs', $tabs );
    }

    public static function defaults(): array {
        $defaults = [];
        foreach ( self::fields() as $key => $field ) {
            $defaults[ $key ] = $field['default'] ?? ( in_array( $field['type'], [ 'post_types', 'taxonomies' ], true ) ? [] : '' );
        }
        return $defaults;
    }

    public static function all(): array {
        if ( null === self::$cache ) {
            $stored      = get_option( self::OPTION, [] );
            self::$cache = wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
        }
        return self::$cache;
    }

    public static function get( string $key, $fallback = null ) {
        $all   = self::all();
        $value = array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
        return apply_filters( 'ace_revisions_setting', $value, $key );
    }

    /**
     * Newline-separated textarea setting as a clean list.
     */
    public static function get_list( string $key ): array {
        $raw   = (string) self::get( $key, '' );
        $lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
        return array_values( array_unique( $lines ) );
    }

    /**
     * Change some settings from code without touching the rest (update() treats a
     * missing checkbox as unticked, which is right for the form but not for scripts).
     */
    public static function patch( array $changes ): array {
        return self::update( array_merge( self::all(), $changes ) );
    }

    public static function update( array $raw ): array {
        $clean = self::sanitise( $raw );
        update_option( self::OPTION, $clean, false );
        self::$cache = null;
        update_option( 'ace_revisions_version', ACE_REVISIONS_VERSION, false );
        do_action( 'ace_revisions_settings_saved', $clean );
        return $clean;
    }

    public static function sanitise( array $raw ): array {
        $clean = [];
        foreach ( self::fields() as $key => $field ) {
            $value = $raw[ $key ] ?? null;
            switch ( $field['type'] ) {
                case 'checkbox':
                    $clean[ $key ] = empty( $value ) ? 0 : 1;
                    break;
                case 'number':
                    $number = is_numeric( $value ) ? (int) $value : (int) ( $field['default'] ?? 0 );
                    if ( isset( $field['min'] ) ) {
                        $number = max( (int) $field['min'], $number );
                    }
                    if ( isset( $field['max'] ) ) {
                        $number = min( (int) $field['max'], $number );
                    }
                    $clean[ $key ] = $number;
                    break;
                case 'textarea':
                    $clean[ $key ] = sanitize_textarea_field( wp_unslash( (string) $value ) );
                    break;
                case 'post_types':
                case 'taxonomies':
                    $value         = is_array( $value ) ? $value : [];
                    $clean[ $key ] = array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
                    break;
                case 'select':
                    $options       = $field['options'] ?? [];
                    $value         = sanitize_key( (string) $value );
                    $clean[ $key ] = isset( $options[ $value ] ) ? $value : ( $field['default'] ?? '' );
                    break;
                default:
                    $clean[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
            }
        }
        return apply_filters( 'ace_revisions_sanitise_settings', $clean, $raw );
    }
}
