<?php
/**
 * WP-CLI: inspect and manage tracked history.
 *
 *   wp ace-revisions term <term_id> [--taxonomy=<tax>]   List a term's revisions with what changed.
 *   wp ace-revisions restore <revision_id>                Native restore of one revision (term or post).
 *   wp ace-revisions snapshot <taxonomy> [--all]          Take a first snapshot of every term now.
 *   wp ace-revisions prune [--taxonomy=<tax>]             Apply the cap to every tracked term.
 *
 * Set ACE_REVISIONS_BATCH=<id> in the environment of any wp command so every
 * change it makes shares one batch id.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_CLI {

    private function taxonomy_of( int $term_id, array $assoc ): string {
        if ( ! empty( $assoc['taxonomy'] ) ) {
            return (string) $assoc['taxonomy'];
        }
        $term = get_term( $term_id );
        if ( ! $term instanceof WP_Term ) {
            WP_CLI::error( 'Term not found.' );
        }
        return $term->taxonomy;
    }

    /**
     * List a term's revisions.
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
        $taxonomy = $this->taxonomy_of( $term_id, $assoc );
        $rows     = [];
        foreach ( Ace_Revisions_Terms::history( $term_id, $taxonomy, 200 ) as $row ) {
            $user   = $row['user_id'] ? get_userdata( $row['user_id'] ) : null;
            $rows[] = [
                'revision' => $row['id'],
                'date'     => $row['date'],
                'user'     => $user ? $user->user_login : '',
                'source'   => $row['source'],
                'batch'    => $row['batch'],
                'changed'  => implode( ', ', $row['changed'] ),
            ];
        }
        WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'revision', 'date', 'user', 'source', 'batch', 'changed' ] );
    }

    /**
     * Restore one revision (native wp_restore_post_revision).
     *
     * ## OPTIONS
     *
     * <revision_id>
     * : Revision id from `wp ace-revisions term` or the revisions screen.
     */
    public function restore( array $args ): void {
        $revision = wp_get_post_revision( (int) $args[0] );
        if ( ! $revision ) {
            WP_CLI::error( 'Revision not found.' );
        }
        if ( wp_restore_post_revision( $revision->ID ) ) {
            WP_CLI::success( 'Restored.' );
        } else {
            WP_CLI::error( 'Nothing restored.' );
        }
    }

    /**
     * Take a first snapshot of every term in a tracked taxonomy, so history
     * starts from "now" rather than from each term's next edit.
     *
     * ## OPTIONS
     *
     * <taxonomy>
     * : Taxonomy name.
     */
    public function snapshot( array $args ): void {
        $taxonomy = (string) $args[0];
        if ( ! Ace_Revisions_Terms::is_tracked( $taxonomy ) ) {
            WP_CLI::error( "Taxonomy {$taxonomy} is not tracked (see settings)." );
        }
        $ids = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ] );
        $n   = 0;
        foreach ( (array) $ids as $id ) {
            if ( Ace_Revisions_Terms::save_snapshot( (int) $id, $taxonomy ) ) {
                $n++;
            }
        }
        WP_CLI::success( sprintf( 'Snapshotted %d of %d terms.', $n, count( (array) $ids ) ) );
    }

    /**
     * Apply the per-object cap to every tracked term's revisions.
     *
     * ## OPTIONS
     *
     * [--taxonomy=<taxonomy>]
     * : Limit to one taxonomy.
     */
    public function prune( array $args, array $assoc ): void {
        $query = [
            'post_type'      => Ace_Revisions_Terms::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        if ( ! empty( $assoc['taxonomy'] ) ) {
            $query['meta_key']   = Ace_Revisions_Terms::META_TAX; // phpcs:ignore WordPress.DB.SlowDBQuery
            $query['meta_value'] = (string) $assoc['taxonomy'];  // phpcs:ignore WordPress.DB.SlowDBQuery
        }
        $cap     = (int) Ace_Revisions_Settings::get( 'cap_per_object', 5 );
        $deleted = 0;
        foreach ( get_posts( $query ) as $post_id ) {
            $revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );
            foreach ( array_slice( array_values( $revisions ), $cap ) as $old ) {
                wp_delete_post_revision( $old->ID );
                $deleted++;
            }
        }
        WP_CLI::success( sprintf( 'Deleted %d revisions beyond the cap of %d.', $deleted, $cap ) );
    }
}
