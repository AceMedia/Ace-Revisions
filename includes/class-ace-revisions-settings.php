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
            'cap_per_object' => [
                'tab' => "storage",
                'section' => "storage-cap",
                'type' => "number",
                'label' => "Change sets kept per object",
                'help' => "Older change sets beyond this count are pruned after each save.",
                'default' => 5,
                'min' => 1,
                'max' => 100,
            ],
            'only_on_change' => [
                'tab' => "storage",
                'section' => "storage-cap",
                'type' => "checkbox",
                'label' => "Only store when a tracked value actually changed",
                'help' => "Saves that touch nothing tracked do not create a change set.",
                'default' => 1,
            ],
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
    public static function tabs(): array {
        $tabs = [[
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
            'help' => "Terms get their own small change log because WordPress has no revisions for them at all. Name, slug, description, parent and every term meta key are captured from any panel, including plugins you did not build. A History section with Restore appears at the bottom of the term edit screen.",
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
            'id' => "storage",
            'label' => "Storage",
            'icon' => "database",
            'help' => "One change set is one save (or one batch run). The cap counts change sets per object, not rows, so a screen that saves ten fields still counts as one. Older sets are pruned after each save and by wp ace-revisions prune.",
            'sections' => [[
                'id' => "storage-cap",
                'title' => "Retention",
                'icon' => "clock",
                'description' => "",
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
        static $cache = null;
        if ( null === $cache ) {
            $stored = get_option( self::OPTION, [] );
            $cache  = wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
        }
        return $cache;
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

    public static function update( array $raw ): array {
        $clean = self::sanitise( $raw );
        update_option( self::OPTION, $clean, false );
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
