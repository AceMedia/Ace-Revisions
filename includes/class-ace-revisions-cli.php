<?php
/**
 * WP-CLI: inspect and manage tracked history.
 *
 *   wp ace-revisions term <term_id> [--taxonomy=<tax>]   A term's revisions with what changed.
 *   wp ace-revisions post <post_id>                       A post's revisions with tracked meta changes.
 *   wp ace-revisions batch <batch_id> [--undo]            Everything a batch touched; --undo rolls each back.
 *   wp ace-revisions restore <revision_id>                Native restore of one revision (term or post).
 *   wp ace-revisions snapshot <taxonomy>                  First snapshot of every term now.
 *   wp ace-revisions prune [--taxonomy=<tax>]             Apply the cap to every tracked term.
 *   wp ace-revisions cleanup [--months=<n>] [--type=<pt>] [--yes]   Stale-revision clean-up (dry run without --yes).
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
     * List a post's revisions with the tracked meta keys that changed in each.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Post id.
     *
     * [--format=<format>]
     * : table, json, csv, yaml. Default table.
     */
    public function post( array $args, array $assoc ): void {
        $post_id = (int) $args[0];
        if ( ! get_post( $post_id ) ) {
            WP_CLI::error( 'Post not found.' );
        }
        $revisions = array_values( wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] ) );
        $rows      = [];
        foreach ( $revisions as $i => $revision ) {
            $previous = $revisions[ $i + 1 ] ?? null;
            $changed  = [];
            if ( $previous ) {
                foreach ( [ 'post_title', 'post_content', 'post_excerpt' ] as $field ) {
                    if ( $previous->$field !== $revision->$field ) {
                        $changed[] = substr( $field, 5 );
                    }
                }
                $changed = array_merge( $changed, Ace_Revisions_Post_Meta::changed_keys( $previous->ID, $revision->ID ) );
            }
            $user   = get_userdata( (int) $revision->post_author );
            $rows[] = [
                'revision' => $revision->ID,
                'date'     => $revision->post_date_gmt,
                'user'     => $user ? $user->user_login : '',
                'source'   => (string) get_metadata( 'post', $revision->ID, Ace_Revisions_Post_Meta::SOURCE_KEY, true ),
                'batch'    => (string) get_metadata( 'post', $revision->ID, Ace_Revisions_Post_Meta::BATCH_KEY, true ),
                'changed'  => $previous ? implode( ', ', $changed ) : '(first)',
            ];
        }
        WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'revision', 'date', 'user', 'source', 'batch', 'changed' ] );
    }

    /**
     * List every revision a batch created, across terms and posts, and optionally undo it.
     *
     * ## OPTIONS
     *
     * <batch_id>
     * : The batch id (ACE_REVISIONS_BATCH or Ace_Revisions::set_batch()).
     *
     * [--undo]
     * : Restore every affected object to the revision just before the batch's one.
     *
     * [--format=<format>]
     * : table, json, csv, yaml. Default table.
     */
    public function batch( array $args, array $assoc ): void {
        global $wpdb;
        $batch = sanitize_key( $args[0] );
        $ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'revision' AND pm.meta_key IN (%s, %s) AND pm.meta_value = %s ORDER BY pm.post_id ASC",
            Ace_Revisions_Post_Meta::BATCH_KEY,
            Ace_Revisions_Terms::META_BATCH,
            $batch
        ) ) );
        if ( ! $ids ) {
            WP_CLI::error( "No revisions carry batch id {$batch}." );
        }
        $rows = [];
        $undo = ! empty( $assoc['undo'] );
        if ( $undo ) {
            Ace_Revisions::set_batch( $batch . '_undo' );
        }
        foreach ( $ids as $revision_id ) {
            $revision = wp_get_post_revision( $revision_id );
            if ( ! $revision ) {
                continue;
            }
            $parent   = get_post( $revision->post_parent );
            $is_term  = $parent && Ace_Revisions_Terms::POST_TYPE === $parent->post_type;
            $object   = $is_term
                ? get_post_meta( $parent->ID, Ace_Revisions_Terms::META_TAX, true ) . ':' . get_post_meta( $parent->ID, Ace_Revisions_Terms::META_TERM, true ) . ' (' . $parent->post_title . ')'
                : ( $parent ? $parent->post_type . ':' . $parent->ID . ' (' . $parent->post_title . ')' : '?' );
            $previous = $this->previous_revision( $revision );
            $result   = '';
            if ( $undo ) {
                if ( $previous ) {
                    $result = wp_restore_post_revision( $previous->ID ) ? 'restored ' . $previous->ID : 'restore failed';
                } else {
                    $result = 'no earlier revision';
                }
            }
            $rows[] = [ 'revision' => $revision_id, 'object' => $object, 'date' => $revision->post_date_gmt, 'before' => $previous ? $previous->ID : '', 'undo' => $result ];
        }
        WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'revision', 'object', 'date', 'before', 'undo' ] );
        if ( $undo ) {
            WP_CLI::success( sprintf( 'Undo run recorded as batch %s_undo.', $batch ) );
        }
    }

    private function previous_revision( WP_Post $revision ): ?WP_Post {
        $all = array_values( wp_get_post_revisions( $revision->post_parent, [ 'order' => 'DESC' ] ) );
        foreach ( $all as $i => $candidate ) {
            if ( $candidate->ID === $revision->ID ) {
                return $all[ $i + 1 ] ?? null;
            }
        }
        return null;
    }

    /**
     * Restore one revision (native wp_restore_post_revision).
     *
     * ## OPTIONS
     *
     * <revision_id>
     * : Revision id from `term`, `post`, `batch` or the revisions screen.
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
     * Take a first snapshot of every term in a tracked taxonomy.
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
        $query = [ 'post_type' => Ace_Revisions_Terms::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ];
        if ( ! empty( $assoc['taxonomy'] ) ) {
            $query['meta_key']   = Ace_Revisions_Terms::META_TAX; // phpcs:ignore WordPress.DB.SlowDBQuery
            $query['meta_value'] = (string) $assoc['taxonomy'];  // phpcs:ignore WordPress.DB.SlowDBQuery
        }
        $cap     = (int) Ace_Revisions_Settings::get( 'cap_per_object', 5 );
        $deleted = 0;
        foreach ( get_posts( $query ) as $post_id ) {
            foreach ( array_slice( array_values( wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] ) ), $cap ) as $old ) {
                wp_delete_post_revision( $old->ID );
                $deleted++;
            }
        }
        WP_CLI::success( sprintf( 'Deleted %d revisions beyond the cap of %d.', $deleted, $cap ) );
    }

    /**
     * Delete revisions of content not modified for N months. Dry run unless --yes.
     *
     * ## OPTIONS
     *
     * [--months=<n>]
     * : Default: the Storage tab setting (12).
     *
     * [--type=<post_type>]
     * : One content type; default all except term snapshots.
     *
     * [--yes]
     * : Actually delete.
     */
    public function cleanup( array $args, array $assoc ): void {
        $months = max( 1, (int) ( $assoc['months'] ?? Ace_Revisions_Settings::get( 'cleanup_months', 12 ) ) );
        $type   = sanitize_key( (string) ( $assoc['type'] ?? '' ) );
        $count  = Ace_Revisions_Dashboard::stale_count( $months, $type );
        if ( empty( $assoc['yes'] ) ) {
            WP_CLI::success( sprintf( '%d revisions match (content untouched for %d months). Re-run with --yes to delete.', $count, $months ) );
            return;
        }
        $deleted = 0;
        while ( $ids = Ace_Revisions_Dashboard::stale_ids( $months, $type, Ace_Revisions_Dashboard::BATCH ) ) {
            foreach ( $ids as $id ) {
                if ( wp_delete_post_revision( $id ) ) {
                    $deleted++;
                }
            }
            WP_CLI::log( "Deleted {$deleted}..." );
        }
        WP_CLI::success( sprintf( 'Deleted %d revisions.', $deleted ) );
    }
}
