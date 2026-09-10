<?php
/**
 * Term revisions on top of native post revisions.
 *
 * Every tracked term owns a hidden "snapshot" post (post type ace_term_snapshot)
 * whose content is a JSON snapshot of the whole term: name, slug, description,
 * parent and every tracked term meta key. Any change to the term marks it dirty;
 * at the end of the request the snapshot post is updated once, so WordPress
 * creates ONE native revision per save holding the whole lot. The native
 * revisions screen, comparison slider, author/date and restore all just work;
 * the diff is rendered per field with a summary of what changed.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_Terms {

    const POST_TYPE   = 'ace_term_snapshot';
    const CORE_FIELDS = [ 'name', 'slug', 'description', 'parent' ];
    const TERM_META   = '_ace_revisions_post';   // on the term: its snapshot post id
    const META_TERM   = '_ace_term_id';          // on the snapshot post
    const META_TAX    = '_ace_taxonomy';
    const META_SOURCE = '_ace_revisions_source'; // on each revision
    const META_BATCH  = '_ace_revisions_batch';

    /** @var array<int,string> term_id => taxonomy, touched this request. */
    private $dirty = [];

    /** @var bool True while we write a snapshot back to a term (restore). */
    private static $restoring = false;

    public function __construct() {
        add_action( 'init', [ $this, 'register_post_type' ] );

        add_action( 'edited_term', [ $this, 'mark_dirty' ], 10, 3 );
        add_action( 'created_term', [ $this, 'mark_dirty' ], 10, 3 );
        add_action( 'added_term_meta', [ $this, 'mark_dirty_meta' ], 10, 3 );
        add_action( 'updated_term_meta', [ $this, 'mark_dirty_meta' ], 10, 3 );
        add_action( 'deleted_term_meta', [ $this, 'mark_dirty_meta' ], 10, 3 );
        add_action( 'delete_term', [ $this, 'on_delete_term' ], 10, 3 );
        add_action( 'shutdown', [ $this, 'flush' ], 0 );

        add_filter( 'wp_save_post_revision_post_has_changed', [ $this, 'snapshot_changed' ], 10, 3 );
        add_action( '_wp_put_post_revision', [ $this, 'stamp_revision' ] );
        add_action( 'wp_restore_post_revision', [ $this, 'on_restore' ], 10, 2 );
        add_filter( 'wp_get_revision_ui_diff', [ $this, 'field_diff' ], 10, 3 );
        add_filter( 'wp_revisions_to_keep', [ $this, 'revisions_to_keep' ], 10, 2 );
        add_filter( 'wp_prepare_revision_for_js', [ $this, 'revision_for_js' ], 10, 2 );
        add_action( 'load-revision.php', [ $this, 'native_restore_screen' ] );
        add_filter( 'get_edit_post_link', [ $this, 'edit_link_to_term' ], 10, 2 );
    }

    /**
     * "Go to editor" and the title on the revisions screen should open the term, not the hidden post.
     */
    public function edit_link_to_term( $link, $post_id ) {
        if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
            return $link;
        }
        $term_link = get_edit_term_link( (int) get_post_meta( $post_id, self::META_TERM, true ), (string) get_post_meta( $post_id, self::META_TAX, true ) );
        return $term_link ?: $link;
    }

    public function register_post_type(): void {
        register_post_type( self::POST_TYPE, [
            'labels'              => [
                'name'          => __( 'Term snapshots', 'ace-revisions' ),
                'singular_name' => __( 'Term snapshot', 'ace-revisions' ),
            ],
            'public'              => false,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => [ 'title', 'editor', 'revisions', 'author' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );
    }

    // ---------------------------------------------------------------- tracking

    public static function is_tracked( string $taxonomy ): bool {
        $tracked = (array) Ace_Revisions_Settings::get( 'taxonomies', [] );
        return in_array( $taxonomy, apply_filters( 'ace_revisions_taxonomies', $tracked ), true );
    }

    /**
     * Is a term meta key part of the snapshot? Tracked keys minus ignored patterns.
     */
    public static function meta_key_tracked( string $key ): bool {
        $ignore = apply_filters( 'ace_revisions_term_meta_ignore', Ace_Revisions_Settings::get_list( 'term_meta_ignore' ) );
        if ( self::TERM_META === $key || Ace_Revisions::key_matches( $key, $ignore ) ) {
            return false;
        }
        $patterns = apply_filters( 'ace_revisions_term_meta_keys', Ace_Revisions_Settings::get_list( 'term_meta_keys' ) );
        return empty( $patterns ) ? 0 !== strpos( $key, '_edit_' ) : Ace_Revisions::key_matches( $key, $patterns );
    }

    public function mark_dirty( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( ! self::$restoring && self::is_tracked( $taxonomy ) ) {
            $this->dirty[ $term_id ] = $taxonomy;
        }
    }

    public function mark_dirty_meta( $meta_ids, int $term_id, string $meta_key ): void {
        if ( self::$restoring || ! self::meta_key_tracked( $meta_key ) ) {
            return;
        }
        $term = get_term( $term_id );
        if ( $term instanceof WP_Term && self::is_tracked( $term->taxonomy ) ) {
            $this->dirty[ $term_id ] = $term->taxonomy;
        }
    }

    public function on_delete_term( int $term_id, int $tt_id, string $taxonomy ): void {
        unset( $this->dirty[ $term_id ] );
        // Keep the snapshot post and its history; just note the deletion in the title.
        $post_id = (int) get_term_meta( $term_id, self::TERM_META, true );
        if ( $post_id && get_post( $post_id ) ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => get_the_title( $post_id ) . ' ' . __( '(deleted)', 'ace-revisions' ) ] );
        }
    }

    /**
     * One snapshot per dirty term, once per request => one native revision per save.
     */
    public function flush(): void {
        foreach ( $this->dirty as $term_id => $taxonomy ) {
            self::save_snapshot( $term_id, $taxonomy );
        }
        $this->dirty = [];
    }

    // ---------------------------------------------------------------- snapshots

    /**
     * The whole term as an array. Empty values are dropped so a plugin writing ''
     * to twenty keys on every save does not read as twenty changes.
     */
    public static function snapshot( int $term_id, string $taxonomy ): ?array {
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof WP_Term ) {
            return null;
        }
        $data = [
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => (int) $term->parent,
        ];
        foreach ( get_term_meta( $term_id ) as $key => $values ) {
            if ( ! self::meta_key_tracked( (string) $key ) ) {
                continue;
            }
            $values = array_map( 'maybe_unserialize', (array) $values );
            $value  = 1 === count( $values ) ? $values[0] : [ '_multi' => array_values( $values ) ];
            if ( '' === $value || null === $value || [] === $value ) {
                continue;
            }
            $data[ $key ] = $value;
        }
        ksort( $data );
        return apply_filters( 'ace_revisions_term_snapshot', $data, $term_id, $taxonomy );
    }

    public static function encode( array $snapshot ): string {
        return (string) wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    public static function decode( string $json ): array {
        $data = json_decode( $json, true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Snapshot post for a term, created on first use.
     */
    public static function snapshot_post_id( int $term_id, string $taxonomy, bool $create = true ): int {
        $post_id = (int) get_term_meta( $term_id, self::TERM_META, true );
        if ( $post_id && get_post( $post_id ) ) {
            return $post_id;
        }
        if ( ! $create ) {
            return 0;
        }
        $term    = get_term( $term_id, $taxonomy );
        $post_id = wp_insert_post( [
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'private',
            'post_title'   => $term instanceof WP_Term ? $term->name : "#{$term_id}",
            'post_name'    => sanitize_title( $taxonomy . '-' . $term_id ),
            'post_content' => '',
            'meta_input'   => [ self::META_TERM => $term_id, self::META_TAX => $taxonomy ],
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return 0;
        }
        update_term_meta( $term_id, self::TERM_META, $post_id );
        return (int) $post_id;
    }

    public static function save_snapshot( int $term_id, string $taxonomy ): int {
        $snapshot = self::snapshot( $term_id, $taxonomy );
        if ( null === $snapshot ) {
            return 0;
        }
        $post_id = self::snapshot_post_id( $term_id, $taxonomy );
        if ( ! $post_id ) {
            return 0;
        }
        $json = self::encode( $snapshot );
        $post = get_post( $post_id );
        if ( $post->post_content === $json && $post->post_title === $snapshot['name'] && Ace_Revisions_Settings::get( 'only_on_change', 1 ) ) {
            return $post_id; // Nothing tracked changed: no revision.
        }
        wp_update_post( [ 'ID' => $post_id, 'post_title' => $snapshot['name'], 'post_content' => $json ] );
        do_action( 'ace_revisions_term_snapshot_saved', $term_id, $taxonomy, $post_id );
        return $post_id;
    }

    /**
     * Core compares title/content/excerpt: content is the JSON, so this only fires
     * when the snapshot really differs. Force nothing extra.
     */
    public function snapshot_changed( bool $changed, WP_Post $latest, WP_Post $post ): bool {
        return $changed;
    }

    public function stamp_revision( int $revision_id ): void {
        $revision = get_post( $revision_id );
        if ( $revision && get_post_type( $revision->post_parent ) === self::POST_TYPE ) {
            add_metadata( 'post', $revision_id, self::META_SOURCE, self::$restoring ? 'restore' : Ace_Revisions::source() );
            $batch = Ace_Revisions::batch_id();
            if ( $batch ) {
                add_metadata( 'post', $revision_id, self::META_BATCH, $batch );
            }
        }
    }

    // ---------------------------------------------------------------- restore

    /**
     * Native restore on the snapshot post: write that snapshot back into the term.
     */
    public function on_restore( int $post_id, int $revision_id ): void {
        if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
            return;
        }
        $term_id  = (int) get_post_meta( $post_id, self::META_TERM, true );
        $taxonomy = (string) get_post_meta( $post_id, self::META_TAX, true );
        $revision = get_post( $revision_id );
        if ( ! $term_id || ! $revision || ! get_term( $term_id, $taxonomy ) instanceof WP_Term ) {
            return;
        }
        $was = self::$restoring;
        self::apply_snapshot( $term_id, $taxonomy, self::decode( $revision->post_content ) );
        self::$restoring = $was;
    }

    public static function apply_snapshot( int $term_id, string $taxonomy, array $snapshot ): bool {
        self::$restoring = true;
        $core = array_intersect_key( $snapshot, array_flip( self::CORE_FIELDS ) );
        $ok   = true;
        if ( $core ) {
            $result = wp_update_term( $term_id, $taxonomy, $core );
            $ok     = ! is_wp_error( $result );
        }
        $current = self::snapshot( $term_id, $taxonomy ) ?? [];
        foreach ( array_keys( $current ) as $key ) {
            if ( ! in_array( $key, self::CORE_FIELDS, true ) && ! array_key_exists( $key, $snapshot ) ) {
                delete_term_meta( $term_id, $key );
            }
        }
        foreach ( $snapshot as $key => $value ) {
            if ( in_array( $key, self::CORE_FIELDS, true ) ) {
                continue;
            }
            delete_term_meta( $term_id, $key );
            $singles = ( is_array( $value ) && isset( $value['_multi'] ) && is_array( $value['_multi'] ) ) ? $value['_multi'] : [ $value ];
            foreach ( $singles as $single ) {
                add_term_meta( $term_id, $key, $single );
            }
        }
        self::$restoring = false;
        // Record the restore itself as a revision, stamped "restore".
        self::$restoring = true;
        self::save_snapshot( $term_id, $taxonomy );
        self::$restoring = false;
        do_action( 'ace_revisions_term_restored', $term_id, $taxonomy, $snapshot, $ok );
        return $ok;
    }

    // ---------------------------------------------------------------- history & diff

    /**
     * Revisions of a term, newest first, each with a summary of what changed
     * against the one before it.
     *
     * @return array<int, array{id:int, date:string, user_id:int, source:string, batch:string, changed:string[], snapshot:array}>
     */
    public static function history( int $term_id, string $taxonomy, int $limit = 50 ): array {
        $post_id = self::snapshot_post_id( $term_id, $taxonomy, false );
        if ( ! $post_id ) {
            return [];
        }
        $revisions = wp_get_post_revisions( $post_id, [ 'posts_per_page' => $limit + 1, 'order' => 'DESC' ] );
        $revisions = array_values( $revisions );
        $out       = [];
        foreach ( $revisions as $i => $revision ) {
            if ( $i >= $limit ) {
                break;
            }
            $now      = self::decode( $revision->post_content );
            $previous = isset( $revisions[ $i + 1 ] ) ? self::decode( $revisions[ $i + 1 ]->post_content ) : [];
            $out[]    = [
                'id'       => $revision->ID,
                'date'     => $revision->post_date_gmt,
                'user_id'  => (int) $revision->post_author,
                'source'   => (string) get_metadata( 'post', $revision->ID, self::META_SOURCE, true ),
                'batch'    => (string) get_metadata( 'post', $revision->ID, self::META_BATCH, true ),
                'changed'  => self::changed_keys( $previous, $now ),
                'snapshot' => $now,
            ];
        }
        return $out;
    }

    public static function changed_keys( array $before, array $after ): array {
        $keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
        $out  = [];
        foreach ( $keys as $key ) {
            if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
                $out[] = (string) $key;
            }
        }
        sort( $out );
        return $out;
    }

    public static function label( string $key ): string {
        $labels = apply_filters( 'ace_revisions_field_labels', [
            'name'        => __( 'Name', 'ace-revisions' ),
            'slug'        => __( 'Slug', 'ace-revisions' ),
            'description' => __( 'Description', 'ace-revisions' ),
            'parent'      => __( 'Parent', 'ace-revisions' ),
        ] );
        return $labels[ $key ] ?? $key;
    }

    public static function stringify( $value ): string {
        if ( null === $value ) {
            return '';
        }
        return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Native revisions screen: replace the raw JSON diff with a summary row plus
     * one before/after row per changed field.
     */
    public function field_diff( array $fields, $compare_from, WP_Post $compare_to ): array {
        $parent = $compare_to->post_parent ? $compare_to->post_parent : $compare_to->ID;
        if ( get_post_type( $parent ) !== self::POST_TYPE ) {
            return $fields;
        }
        $before  = $compare_from ? self::decode( $compare_from->post_content ) : [];
        $after   = self::decode( $compare_to->post_content );
        $changed = self::changed_keys( $before, $after );

        $out   = [];
        $out[] = [
            'id'   => 'ace_summary',
            'name' => __( 'Changed', 'ace-revisions' ),
            'diff' => '<p>' . ( $changed ? esc_html( implode( ', ', array_map( [ __CLASS__, 'label' ], $changed ) ) ) : esc_html__( 'Nothing tracked changed.', 'ace-revisions' ) ) . '</p>',
        ];
        $source = (string) get_metadata( 'post', $compare_to->ID, self::META_SOURCE, true );
        $batch  = (string) get_metadata( 'post', $compare_to->ID, self::META_BATCH, true );
        if ( $source ) {
            $out[] = [
                'id'   => 'ace_source',
                'name' => __( 'Saved via', 'ace-revisions' ),
                'diff' => '<p><code>' . esc_html( $source ) . '</code>' . ( $batch ? ' <small>' . esc_html( $batch ) . '</small>' : '' ) . '</p>',
            ];
        }
        foreach ( $changed as $key ) {
            $left  = self::stringify( $before[ $key ] ?? null );
            $right = self::stringify( $after[ $key ] ?? null );
            $diff  = wp_text_diff( $left, $right, [ 'show_split_view' => true, 'title' => '' ] );
            if ( ! $diff ) {
                $diff = '<table class="diff"><tr><td class="diff-deletedline">' . esc_html( $left ) . '</td><td class="diff-addedline">' . esc_html( $right ) . '</td></tr></table>';
            }
            $out[] = [ 'id' => 'ace_' . sanitize_key( $key ), 'name' => self::label( $key ), 'diff' => $diff ];
        }
        return $out;
    }

    public function revisions_to_keep( $num, WP_Post $post ) {
        if ( self::POST_TYPE !== $post->post_type ) {
            return $num;
        }
        return (int) Ace_Revisions_Settings::get( 'cap_per_object', 5 );
    }

    /**
     * Revision slider tooltips: show the source next to the author.
     */
    public function revision_for_js( array $data, WP_Post $revision ): array {
        if ( get_post_type( $revision->post_parent ) === self::POST_TYPE ) {
            $source = (string) get_metadata( 'post', $revision->ID, self::META_SOURCE, true );
            if ( $source ) {
                $data['timeAgo'] = $data['timeAgo'] . ' · ' . $source;
            }
        }
        return $data;
    }

    /**
     * Native revisions screen URL for a term's latest revision, or '' if none yet.
     */
    public static function revisions_url( int $term_id, string $taxonomy ): string {
        $post_id = self::snapshot_post_id( $term_id, $taxonomy, false );
        if ( ! $post_id ) {
            return '';
        }
        $latest = wp_get_post_revisions( $post_id, [ 'posts_per_page' => 1 ] );
        $latest = $latest ? reset( $latest ) : null;
        return $latest ? admin_url( 'revision.php?revision=' . $latest->ID ) : '';
    }

    public static function restore( int $revision_id ): bool {
        $revision = wp_get_post_revision( $revision_id );
        if ( ! $revision || get_post_type( $revision->post_parent ) !== self::POST_TYPE ) {
            return false;
        }
        self::$restoring = true; // The native restore's own revision is stamped "restore" too.
        $result          = wp_restore_post_revision( $revision_id );
        self::$restoring = false;
        return (bool) $result;
    }

    /**
     * "Restore this revision" on the native screen goes straight to
     * wp_restore_post_revision(); flag it so that revision is stamped "restore".
     */
    public function native_restore_screen(): void {
        if ( isset( $_GET['action'], $_GET['revision'] ) && 'restore' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision = wp_get_post_revision( (int) $_GET['revision'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( $revision && get_post_type( $revision->post_parent ) === self::POST_TYPE ) {
                self::$restoring = true;
            }
        }
    }
}
