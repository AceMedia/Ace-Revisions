<?php
/**
 * WP-CLI: inspect and manage tracked history.
 *
 *   wp ace-revisions term <term_id> [--taxonomy=<tax>]      Show a term's history.
 *   wp ace-revisions restore <row_id>                        Restore one change row.
 *   wp ace-revisions prune [--taxonomy=<tax>]                Apply the cap to every tracked term.
 *   wp ace-revisions batch <id> -- <command>                 Not needed: set ACE_REVISIONS_BATCH=<id>
 *                                                            in the environment of any wp command so
 *                                                            every change it makes shares one batch id.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_CLI {

    /**
     * Show tracked history for a term.
     *
     * ## OPTIONS
     *
     * <term_id>
     * : Term id.
     *
     * [--taxonomy=<taxonomy>]
     * : Taxonomy. Detected from the term when omitted.
     *
     * [--format=<format>]
     * : table, json, csv, yaml. Default table.
     */
    public function term( array $args, array $assoc ): void {
        $term_id  = (int) $args[0];
        $taxonomy = $assoc['taxonomy'] ?? '';
        if ( ! $taxonomy ) {
            $term = get_term( $term_id );
            if ( ! $term instanceof WP_Term ) {
                WP_CLI::error( 'Term not found.' );
            }
            $taxonomy = $term->taxonomy;
        }
        $rows = Ace_Revisions_Terms::history( $term_id, $taxonomy, 500 );
        foreach ( $rows as &$row ) {
            $row['old_value'] = is_scalar( $row['old_value'] ) || null === $row['old_value'] ? $row['old_value'] : wp_json_encode( $row['old_value'] );
            $row['new_value'] = is_scalar( $row['new_value'] ) || null === $row['new_value'] ? $row['new_value'] : wp_json_encode( $row['new_value'] );
        }
        WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'id', 'created_at', 'user_id', 'field', 'old_value', 'new_value', 'source', 'batch_id' ] );
    }

    /**
     * Restore a single change row (puts the field back to its "before" value).
     *
     * ## OPTIONS
     *
     * <row_id>
     * : Row id from `wp ace-revisions term`.
     */
    public function restore( array $args ): void {
        if ( Ace_Revisions_Terms::restore( (int) $args[0] ) ) {
            WP_CLI::success( 'Restored.' );
        } else {
            WP_CLI::error( 'Nothing restored.' );
        }
    }

    /**
     * Apply the per-object cap to every tracked term.
     *
     * ## OPTIONS
     *
     * [--taxonomy=<taxonomy>]
     * : Limit to one taxonomy.
     */
    public function prune( array $args, array $assoc ): void {
        global $wpdb;
        $table = Ace_Revisions::table();
        $where = '';
        $params = [];
        if ( ! empty( $assoc['taxonomy'] ) ) {
            $where    = 'WHERE taxonomy = %s';
            $params[] = $assoc['taxonomy'];
        }
        $sql   = "SELECT DISTINCT term_id, taxonomy FROM {$table} {$where}";
        $pairs = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
        foreach ( $pairs as $pair ) {
            Ace_Revisions_Terms::prune( (int) $pair['term_id'], $pair['taxonomy'] );
        }
        WP_CLI::success( sprintf( 'Pruned %d terms.', count( $pairs ) ) );
    }
}
