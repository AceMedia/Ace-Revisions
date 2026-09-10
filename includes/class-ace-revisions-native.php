<?php
/**
 * Native revision controls, driven by settings: per post type on/off and
 * keep-count (wp_revisions_to_keep / post type support), autosave interval.
 *
 * Constants in wp-config.php always win, and the Overview tab says so.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_Native {

    public function __construct() {
        add_filter( 'wp_revisions_to_keep', [ $this, 'revisions_to_keep' ], 20, 2 );
        add_action( 'init', [ $this, 'apply_support' ], 99 );
        add_action( 'plugins_loaded', [ $this, 'apply_autosave' ], 1 );
    }

    /**
     * What WordPress will actually keep for a post type, and why.
     *
     * @return array{keep:int, source:string}
     */
    public static function effective( string $post_type ): array {
        if ( ! self::enabled( $post_type ) ) {
            return [ 'keep' => 0, 'source' => 'setting' ];
        }
        if ( defined( 'WP_POST_REVISIONS' ) && true !== WP_POST_REVISIONS ) {
            return [ 'keep' => (int) WP_POST_REVISIONS, 'source' => 'WP_POST_REVISIONS' ];
        }
        $keep = (int) Ace_Revisions_Settings::get( 'revisions_keep_' . $post_type, -1 );
        if ( Ace_Revisions_Post_Meta::is_tracked_post_type( $post_type ) ) {
            $cap = (int) Ace_Revisions_Settings::get( 'cap_per_object', 5 );
            if ( -1 !== $keep && $keep < $cap ) {
                return [ 'keep' => $cap, 'source' => 'tracked cap' ];
            }
        }
        return [ 'keep' => $keep, 'source' => -1 === $keep ? 'default' : 'setting' ];
    }

    public static function enabled( string $post_type ): bool {
        return (bool) Ace_Revisions_Settings::get( 'revisions_enabled_' . $post_type, 1 );
    }

    public function revisions_to_keep( $num, WP_Post $post ) {
        if ( Ace_Revisions_Terms::POST_TYPE === $post->post_type ) {
            return $num; // Handled by the terms class.
        }
        if ( ! isset( Ace_Revisions_Settings::revision_post_types()[ $post->post_type ] ) ) {
            return $num;
        }
        $effective = self::effective( $post->post_type );
        // A wp-config constant already produced $num; leave it alone.
        return 'WP_POST_REVISIONS' === $effective['source'] ? $num : $effective['keep'];
    }

    /**
     * Turn revision support off for types switched off in settings (hides the
     * Revisions panel and stops core creating them).
     */
    public function apply_support(): void {
        foreach ( array_keys( Ace_Revisions_Settings::revision_post_types() ) as $type ) {
            if ( ! self::enabled( $type ) ) {
                remove_post_type_support( $type, 'revisions' );
            }
        }
    }

    public function apply_autosave(): void {
        if ( defined( 'AUTOSAVE_INTERVAL' ) ) {
            return;
        }
        $seconds = (int) Ace_Revisions_Settings::get( 'autosave_interval', 60 );
        if ( $seconds >= 10 && 60 !== $seconds ) {
            define( 'AUTOSAVE_INTERVAL', $seconds );
        }
    }

    /**
     * The constants as WordPress sees them, for the Overview tab.
     */
    public static function constants(): array {
        return [
            'WP_POST_REVISIONS'  => defined( 'WP_POST_REVISIONS' ) ? ( true === WP_POST_REVISIONS ? 'true (unlimited)' : (string) WP_POST_REVISIONS ) : __( 'not set (unlimited)', 'ace-revisions' ),
            'AUTOSAVE_INTERVAL'  => defined( 'AUTOSAVE_INTERVAL' ) ? (string) AUTOSAVE_INTERVAL . 's' : '60s',
            'EMPTY_TRASH_DAYS'   => defined( 'EMPTY_TRASH_DAYS' ) ? (string) EMPTY_TRASH_DAYS : '30',
        ];
    }
}
