<?php
/**
 * Term revisions: core fields and term meta logged to a small custom table.
 *
 * Core fields (name, slug, description, parent) are snapshotted on `edit_terms`
 * and diffed on `edited_term`. Term meta is captured on the metadata filters so
 * changes from any plugin's term panel are seen, regardless of who wrote them.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_Terms {

    const CORE_FIELDS = [ 'name', 'slug', 'description', 'parent' ];

    /** @var array<int, array> Pre-edit snapshots keyed by term id. */
    private $snapshots = [];

    public function __construct() {
        add_action( 'edit_terms', [ $this, 'snapshot' ], 10, 2 );
        add_action( 'edited_term', [ $this, 'diff_core' ], 10, 3 );
        add_action( 'delete_term', [ $this, 'log_delete' ], 10, 4 );

        add_filter( 'update_term_metadata', [ $this, 'before_meta_update' ], 10, 5 );
        add_action( 'added_term_meta', [ $this, 'after_meta_add' ], 10, 4 );
        add_filter( 'delete_term_metadata', [ $this, 'before_meta_delete' ], 10, 5 );
    }

    public static function is_tracked( string $taxonomy ): bool {
        $tracked = (array) Ace_Revisions_Settings::get( 'taxonomies', [] );
        return in_array( $taxonomy, apply_filters( 'ace_revisions_taxonomies', $tracked ), true );
    }

    public static function meta_key_tracked( string $key ): bool {
        $patterns = Ace_Revisions_Settings::get_list( 'term_meta_keys' );
        $patterns = apply_filters( 'ace_revisions_term_meta_keys', $patterns );
        if ( empty( $patterns ) ) {
            return 0 !== strpos( $key, '_edit_' );
        }
        return Ace_Revisions::key_matches( $key, $patterns );
    }

    public function snapshot( int $term_id, string $taxonomy ): void {
        if ( ! self::is_tracked( $taxonomy ) ) {
            return;
        }
        $term = get_term( $term_id, $taxonomy );
        if ( $term instanceof WP_Term ) {
            $this->snapshots[ $term_id ] = [
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent'      => (string) $term->parent,
            ];
        }
    }

    public function diff_core( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( ! isset( $this->snapshots[ $term_id ] ) ) {
            return;
        }
        $before = $this->snapshots[ $term_id ];
        unset( $this->snapshots[ $term_id ] );
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof WP_Term ) {
            return;
        }
        foreach ( self::CORE_FIELDS as $field ) {
            $now = (string) $term->$field;
            if ( $now !== (string) $before[ $field ] ) {
                self::log( $term_id, $taxonomy, $field, $before[ $field ], $now );
            }
        }
        self::prune( $term_id, $taxonomy );
    }

    public function log_delete( int $term_id, int $tt_id, string $taxonomy, $deleted_term ): void {
        if ( ! self::is_tracked( $taxonomy ) || ! $deleted_term instanceof WP_Term ) {
            return;
        }
        self::log( $term_id, $taxonomy, '__deleted', wp_json_encode( [
            'name' => $deleted_term->name,
            'slug' => $deleted_term->slug,
        ] ), null );
    }

    /**
     * Runs before the update. Returning null lets core proceed.
     */
    public function before_meta_update( $check, int $term_id, string $meta_key, $meta_value, $prev_value ) {
        if ( null !== $check ) {
            return $check;
        }
        $taxonomy = $this->taxonomy_of( $term_id );
        if ( ! $taxonomy || ! self::meta_key_tracked( $meta_key ) ) {
            return $check;
        }
        if ( ! metadata_exists( 'term', $term_id, $meta_key ) ) {
            return $check; // Core falls through to add_metadata; added_term_meta logs it once.
        }
        $old = get_term_meta( $term_id, $meta_key, true );
        if ( maybe_serialize( $old ) === maybe_serialize( $meta_value ) ) {
            return $check;
        }
        self::log( $term_id, $taxonomy, $meta_key, $old, $meta_value );
        self::prune( $term_id, $taxonomy );
        return $check;
    }

    public function after_meta_add( int $mid, int $term_id, string $meta_key, $meta_value ): void {
        $taxonomy = $this->taxonomy_of( $term_id );
        if ( ! $taxonomy || ! self::meta_key_tracked( $meta_key ) ) {
            return;
        }
        self::log( $term_id, $taxonomy, $meta_key, null, $meta_value );
        self::prune( $term_id, $taxonomy );
    }

    public function before_meta_delete( $check, int $term_id, string $meta_key, $meta_value, bool $delete_all ) {
        if ( null !== $check || $delete_all ) {
            return $check;
        }
        $taxonomy = $this->taxonomy_of( $term_id );
        if ( ! $taxonomy || ! self::meta_key_tracked( $meta_key ) ) {
            return $check;
        }
        $old = get_term_meta( $term_id, $meta_key, true );
        if ( '' === $old || null === $old ) {
            return $check;
        }
        self::log( $term_id, $taxonomy, $meta_key, $old, null );
        return $check;
    }

    private function taxonomy_of( int $term_id ): ?string {
        $term = get_term( $term_id );
        if ( ! $term instanceof WP_Term || ! self::is_tracked( $term->taxonomy ) ) {
            return null;
        }
        return $term->taxonomy;
    }

    /**
     * Write one change row. Values are stored serialised so arrays round-trip on restore.
     */
    public static function log( int $term_id, string $taxonomy, string $field, $old, $new ): int {
        global $wpdb;
        $row = [
            'term_id'    => $term_id,
            'taxonomy'   => $taxonomy,
            'field'      => $field,
            'old_value'  => null === $old ? null : maybe_serialize( $old ),
            'new_value'  => null === $new ? null : maybe_serialize( $new ),
            'user_id'    => get_current_user_id(),
            'source'     => Ace_Revisions::source(),
            'batch_id'   => Ace_Revisions::change_set_id(),
            'created_at' => current_time( 'mysql', true ),
        ];
        $row = apply_filters( 'ace_revisions_term_log_row', $row );
        $wpdb->insert( Ace_Revisions::table(), $row );
        $id = (int) $wpdb->insert_id;
        do_action( 'ace_revisions_term_logged', $id, $row );
        return $id;
    }

    /**
     * Keep only the newest N change sets (batch ids) per term.
     */
    public static function prune( int $term_id, string $taxonomy ): void {
        global $wpdb;
        $cap   = max( 1, (int) Ace_Revisions_Settings::get( 'cap_per_object', 5 ) );
        $table = Ace_Revisions::table();
        $keep  = $wpdb->get_col( $wpdb->prepare(
            "SELECT batch_id FROM {$table} WHERE term_id = %d AND taxonomy = %s GROUP BY batch_id ORDER BY MAX(id) DESC LIMIT %d",
            $term_id,
            $taxonomy,
            $cap
        ) );
        if ( empty( $keep ) ) {
            return;
        }
        $placeholders = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE term_id = %d AND taxonomy = %s AND batch_id NOT IN ({$placeholders})",
            array_merge( [ $term_id, $taxonomy ], $keep )
        ) );
    }

    public static function history( int $term_id, string $taxonomy, int $limit = 100 ): array {
        global $wpdb;
        $table = Ace_Revisions::table();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE term_id = %d AND taxonomy = %s ORDER BY id DESC LIMIT %d",
            $term_id,
            $taxonomy,
            $limit
        ), ARRAY_A );
        foreach ( $rows as &$row ) {
            $row['old_value'] = null === $row['old_value'] ? null : maybe_unserialize( $row['old_value'] );
            $row['new_value'] = null === $row['new_value'] ? null : maybe_unserialize( $row['new_value'] );
        }
        return $rows;
    }

    /**
     * Put a field back to its "old" value from a log row. The restore is itself logged.
     */
    public static function restore( int $row_id ): bool {
        global $wpdb;
        $table = Ace_Revisions::table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $row_id ), ARRAY_A );
        if ( ! $row || '__deleted' === $row['field'] ) {
            return false;
        }
        $term_id  = (int) $row['term_id'];
        $taxonomy = $row['taxonomy'];
        $old      = null === $row['old_value'] ? null : maybe_unserialize( $row['old_value'] );

        add_filter( 'ace_revisions_source', [ __CLASS__, 'restore_source' ] );
        if ( in_array( $row['field'], self::CORE_FIELDS, true ) ) {
            $result = wp_update_term( $term_id, $taxonomy, [ $row['field'] => $old ] );
            $ok     = ! is_wp_error( $result );
        } elseif ( null === $old ) {
            $ok = delete_term_meta( $term_id, $row['field'] );
        } else {
            $ok = false !== update_term_meta( $term_id, $row['field'], $old );
        }
        remove_filter( 'ace_revisions_source', [ __CLASS__, 'restore_source' ] );

        do_action( 'ace_revisions_term_restored', $row_id, $row, $ok );
        return (bool) $ok;
    }

    public static function restore_source(): string {
        return 'restore';
    }
}
