<?php
/**
 * Post meta revisions: piggyback on native revisions.
 *
 * - Copy tracked meta from the post onto each new revision (_wp_put_post_revision).
 * - Force a revision when only tracked meta changed (wp_save_post_revision_post_has_changed).
 * - Restore tracked meta when a revision is restored (wp_restore_post_revision).
 * - Show tracked meta in the revisions comparison screen (wp_get_revision_ui_diff).
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_Post_Meta {

    const SOURCE_KEY = '_ace_revisions_source';

    public function __construct() {
        add_action( '_wp_put_post_revision', [ $this, 'copy_meta_to_revision' ] );
        add_filter( 'wp_save_post_revision_post_has_changed', [ $this, 'meta_has_changed' ], 10, 3 );
        add_action( 'wp_restore_post_revision', [ $this, 'restore_meta' ], 10, 2 );
        add_filter( 'wp_get_revision_ui_diff', [ $this, 'add_meta_to_diff' ], 10, 3 );
        add_filter( 'wp_revisions_to_keep', [ $this, 'revisions_to_keep' ], 10, 2 );
    }

    public static function is_tracked_post_type( string $post_type ): bool {
        $types = (array) Ace_Revisions_Settings::get( 'post_types', [] );
        return in_array( $post_type, apply_filters( 'ace_revisions_post_types', $types ), true );
    }

    public static function tracked_keys(): array {
        return apply_filters( 'ace_revisions_meta_keys', Ace_Revisions_Settings::get_list( 'meta_keys' ) );
    }

    /**
     * All tracked meta on a post as key => array of values (meta can be multi-valued).
     */
    public static function collect( int $post_id ): array {
        $all      = get_post_meta( $post_id );
        $patterns = self::tracked_keys();
        $tracked  = [];
        foreach ( $all as $key => $values ) {
            if ( Ace_Revisions::key_matches( (string) $key, $patterns ) ) {
                $tracked[ $key ] = array_map( 'maybe_unserialize', (array) $values );
            }
        }
        ksort( $tracked );
        return $tracked;
    }

    public function copy_meta_to_revision( int $revision_id ): void {
        $revision = get_post( $revision_id );
        if ( ! $revision || ! $revision->post_parent ) {
            return;
        }
        $parent = get_post( $revision->post_parent );
        if ( ! $parent || ! self::is_tracked_post_type( $parent->post_type ) ) {
            return;
        }
        foreach ( self::collect( $parent->ID ) as $key => $values ) {
            foreach ( $values as $value ) {
                add_metadata( 'post', $revision_id, $key, $value );
            }
        }
        add_metadata( 'post', $revision_id, self::SOURCE_KEY, Ace_Revisions::source() );
        do_action( 'ace_revisions_meta_copied', $revision_id, $parent->ID );
    }

    /**
     * Core skips a revision when title/content/excerpt are unchanged. If tracked
     * meta differs from the latest revision, force one so the change is captured.
     */
    public function meta_has_changed( bool $has_changed, WP_Post $latest_revision, WP_Post $post ): bool {
        if ( $has_changed || ! self::is_tracked_post_type( $post->post_type ) ) {
            return $has_changed;
        }
        $current  = self::collect( $post->ID );
        $previous = self::collect( $latest_revision->ID );
        return $current !== $previous;
    }

    public function restore_meta( int $post_id, int $revision_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || ! self::is_tracked_post_type( $post->post_type ) ) {
            return;
        }
        $snapshot = self::collect( $revision_id );
        $patterns = self::tracked_keys();

        // Remove tracked keys that are not in the snapshot, then write the snapshot.
        foreach ( array_keys( self::collect( $post_id ) ) as $key ) {
            if ( ! isset( $snapshot[ $key ] ) && Ace_Revisions::key_matches( $key, $patterns ) ) {
                delete_post_meta( $post_id, $key );
            }
        }
        foreach ( $snapshot as $key => $values ) {
            delete_post_meta( $post_id, $key );
            foreach ( $values as $value ) {
                add_post_meta( $post_id, $key, $value );
            }
        }
        do_action( 'ace_revisions_meta_restored', $post_id, $revision_id, $snapshot );
    }

    /**
     * Add one diff row per tracked meta key that differs between the two revisions.
     */
    public function add_meta_to_diff( array $fields, $compare_from, WP_Post $compare_to ): array {
        $parent_id = $compare_to->post_parent ? $compare_to->post_parent : $compare_to->ID;
        $parent    = get_post( $parent_id );
        if ( ! $parent || ! self::is_tracked_post_type( $parent->post_type ) ) {
            return $fields;
        }

        $from = $compare_from ? self::collect( $compare_from->ID ) : [];
        $to   = self::collect( $compare_to->ID );
        $keys = array_unique( array_merge( array_keys( $from ), array_keys( $to ) ) );
        sort( $keys );

        foreach ( $keys as $key ) {
            $left  = self::stringify( $from[ $key ] ?? [] );
            $right = self::stringify( $to[ $key ] ?? [] );
            if ( $left === $right ) {
                continue;
            }
            $diff = wp_text_diff( $left, $right, [ 'show_split_view' => true, 'title' => '' ] );
            if ( ! $diff ) {
                $diff = '<table class="diff"><tr><td class="diff-deletedline">' . esc_html( $left ) . '</td><td class="diff-addedline">' . esc_html( $right ) . '</td></tr></table>';
            }
            $fields[] = [
                'id'   => 'ace_meta_' . sanitize_key( $key ),
                'name' => sprintf( __( 'Meta: %s', 'ace-revisions' ), $key ),
                'diff' => $diff,
            ];
        }

        $source = get_metadata( 'post', $compare_to->ID, self::SOURCE_KEY, true );
        if ( $source ) {
            $fields[] = [
                'id'   => 'ace_meta_source',
                'name' => __( 'Saved via', 'ace-revisions' ),
                'diff' => '<p><code>' . esc_html( $source ) . '</code></p>',
            ];
        }
        return $fields;
    }

    /**
     * Respect the per-object cap for tracked post types without touching others.
     */
    public function revisions_to_keep( $num, WP_Post $post ) {
        if ( ! self::is_tracked_post_type( $post->post_type ) ) {
            return $num;
        }
        $cap = (int) Ace_Revisions_Settings::get( 'cap_per_object', 5 );
        // Never shrink an explicit site-wide limit; only raise a disabled/low one to the cap.
        if ( -1 === (int) $num ) {
            return $num;
        }
        return max( (int) $num, $cap );
    }

    private static function stringify( array $values ): string {
        $out = [];
        foreach ( $values as $value ) {
            $out[] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        }
        return implode( "\n", $out );
    }
}
