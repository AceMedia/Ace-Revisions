<?php
/**
 * Settings store for Ace Revisions.
 *
 * One option (ace_revisions_options), one schema, one sanitiser. The admin page
 * renders from the same schema so a new setting is a one-line addition here.
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
     */
    public static function fields(): array {
        $fields = [
            'post_types' => [
                'tab' => 'tracking',
                'type' => 'post_types',
                'label' => 'Post types',
                'help' => 'Track post meta on these post types. Nothing is tracked until you tick something.',
            ],
            'meta_keys' => [
                'tab' => 'tracking',
                'type' => 'textarea',
                'label' => 'Meta keys',
                'help' => 'One key per line. End a line with * to match a prefix (e.g. _ace_seo_*). Keys starting with _edit_ are always ignored.',
                'default' => '',
            ],
            'taxonomies' => [
                'tab' => 'terms',
                'type' => 'taxonomies',
                'label' => 'Taxonomies',
                'help' => 'Track name, slug, description, parent and all term meta on these taxonomies.',
            ],
            'term_meta_keys' => [
                'tab' => 'terms',
                'type' => 'textarea',
                'label' => 'Term meta keys',
                'help' => 'Leave empty to track every term meta key on the taxonomies above. Otherwise one key or prefix* per line.',
                'default' => '',
            ],
            'cap_per_object' => [
                'tab' => 'storage',
                'type' => 'number',
                'label' => 'Changes kept per object',
                'help' => 'Older change sets beyond this count are pruned after each save.',
                'default' => 5,
                'min' => 1,
                'max' => 100,
            ],
            'only_on_change' => [
                'tab' => 'storage',
                'type' => 'checkbox',
                'label' => 'Only store when a tracked value actually changed',
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

    public static function tabs(): array {
        $tabs = [['tracking', 'Tracking', 'backup'], ['terms', 'Terms', 'tag'], ['storage', 'Storage', 'database']];
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
        $all = self::all();
        $value = array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
        return apply_filters( 'ace_revisions_setting', $value, $key );
    }

    /**
     * Newline-separated textarea setting as a clean list.
     */
    public static function get_list( string $key ): array {
        $raw = (string) self::get( $key, '' );
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
                    $value = is_array( $value ) ? $value : [];
                    $clean[ $key ] = array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
                    break;
                case 'select':
                    $options = $field['options'] ?? [];
                    $value   = sanitize_key( (string) $value );
                    $clean[ $key ] = isset( $options[ $value ] ) ? $value : ( $field['default'] ?? '' );
                    break;
                default:
                    $clean[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
            }
        }
        return apply_filters( 'ace_revisions_sanitise_settings', $clean, $raw );
    }
}
