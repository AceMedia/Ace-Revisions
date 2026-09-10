<?php
/**
 * "History" section on the term edit screen for tracked taxonomies.
 *
 * One row per native revision of the term's snapshot post: when, who, via,
 * and a summary of what changed. "Compare" opens the standard WordPress
 * revisions screen; "Restore" is the native restore.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_Term_History {

    const ACTION = 'ace_revisions_restore_term';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'hook_taxonomies' ] );
        add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_restore' ] );
        add_action( 'admin_notices', [ $this, 'notices' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'load-term.php', [ $this, 'help_tab' ] );
        add_filter( 'wp_redirect', [ $this, 'redirect_after_native_restore' ] );
    }

    public function help_tab(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->taxonomy, (array) Ace_Revisions_Settings::get( 'taxonomies', [] ), true ) ) {
            return;
        }
        $sections = Ace_Revisions_Guide::sections();
        $screen->add_help_tab( [ 'id' => 'ace-revisions-terms', 'title' => $sections['terms']['title'], 'content' => wp_kses_post( $sections['terms']['content'] ) ] );
        $screen->add_help_tab( [ 'id' => 'ace-revisions-sources', 'title' => $sections['sources']['title'], 'content' => wp_kses_post( $sections['sources']['content'] ) ] );
    }

    public function hook_taxonomies(): void {
        foreach ( (array) Ace_Revisions_Settings::get( 'taxonomies', [] ) as $taxonomy ) {
            add_action( "{$taxonomy}_edit_form", [ $this, 'render' ], 100, 2 );
        }
    }

    public function enqueue( string $hook ): void {
        if ( in_array( $hook, [ 'term.php', 'revision.php' ], true ) ) {
            wp_enqueue_style( 'ace-revisions-admin', ACE_REVISIONS_URL . 'assets/css/admin.css', [], ACE_REVISIONS_VERSION );
        }
    }

    public function render( WP_Term $term, string $taxonomy ): void {
        if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
            return;
        }
        $rows        = Ace_Revisions_Terms::history( $term->term_id, $taxonomy, 20 );
        $compare_url = Ace_Revisions_Terms::revisions_url( $term->term_id, $taxonomy );
        $can_restore = current_user_can( 'manage_categories' );
        ?>
        <div class="ace-revisions-history">
            <h2>
                <?php esc_html_e( 'History', 'ace-revisions' ); ?>
                <?php if ( $compare_url ) : ?>
                    <a class="button button-small" href="<?php echo esc_url( $compare_url ); ?>"><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e( 'Browse revisions', 'ace-revisions' ); ?></a>
                <?php endif; ?>
            </h2>
            <?php if ( empty( $rows ) ) : ?>
                <p class="description"><?php esc_html_e( 'No revisions yet. The first save of this term after tracking was switched on creates one.', 'ace-revisions' ); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'Each row is one save of the whole term. Compare shows every field before and after on the standard revisions screen; Restore puts the term back to that save.', 'ace-revisions' ); ?></p>
                <table class="widefat striped ace-revisions-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'When', 'ace-revisions' ); ?></th>
                            <th><?php esc_html_e( 'Who', 'ace-revisions' ); ?></th>
                            <th><?php esc_html_e( 'Changed', 'ace-revisions' ); ?></th>
                            <th><?php esc_html_e( 'Via', 'ace-revisions' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $i => $row ) :
                        $user = $row['user_id'] ? get_userdata( $row['user_id'] ) : null;
                        $when = get_date_from_gmt( $row['date'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
                        $restore_url = wp_nonce_url( add_query_arg( [
                            'action'   => self::ACTION,
                            'revision' => $row['id'],
                            'term'     => $term->term_id,
                            'tax'      => $taxonomy,
                        ], admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $row['id'] );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $when ); ?><?php echo 0 === $i ? ' <span class="ace-rev-current">' . esc_html__( 'current', 'ace-revisions' ) . '</span>' : ''; ?></td>
                            <td><?php echo $user ? esc_html( $user->display_name ) : '<em>' . esc_html__( 'system', 'ace-revisions' ) . '</em>'; ?></td>
                            <td class="ace-rev-changed">
                                <?php if ( $row['changed'] ) : ?>
                                    <?php foreach ( $row['changed'] as $key ) : ?>
                                        <code title="<?php echo esc_attr( Ace_Revisions_Terms::stringify( $row['snapshot'][ $key ] ?? '' ) ); ?>"><?php echo esc_html( Ace_Revisions_Terms::label( $key ) ); ?></code>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'first snapshot', 'ace-revisions' ); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ace-rev-source ace-rev-source-<?php echo esc_attr( $row['source'] ); ?>"><?php echo esc_html( $row['source'] ?: 'admin' ); ?></span>
                                <?php if ( $row['batch'] ) : ?><br><small><?php echo esc_html( $row['batch'] ); ?></small><?php endif; ?>
                            </td>
                            <td class="ace-rev-actions">
                                <a class="button button-small" href="<?php echo esc_url( admin_url( 'revision.php?revision=' . $row['id'] ) ); ?>"><?php esc_html_e( 'Compare', 'ace-revisions' ); ?></a>
                                <?php if ( $can_restore && 0 !== $i ) : ?>
                                    <a class="button button-small" href="<?php echo esc_url( $restore_url ); ?>"><?php esc_html_e( 'Restore', 'ace-revisions' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_restore(): void {
        $revision = isset( $_GET['revision'] ) ? (int) $_GET['revision'] : 0;
        $term     = isset( $_GET['term'] ) ? (int) $_GET['term'] : 0;
        $tax      = isset( $_GET['tax'] ) ? sanitize_key( wp_unslash( $_GET['tax'] ) ) : '';

        if ( ! $revision || ! current_user_can( 'manage_categories' ) || ! current_user_can( 'edit_term', $term ) ) {
            wp_die( esc_html__( 'You do not have permission to restore this revision.', 'ace-revisions' ) );
        }
        check_admin_referer( self::ACTION . '_' . $revision );

        $ok = Ace_Revisions_Terms::restore( $revision );
        wp_safe_redirect( add_query_arg( 'ace_revisions_restored', $ok ? '1' : '0', get_edit_term_link( $term, $tax ) ) );
        exit;
    }

    /**
     * After "Restore this revision" on the native screen, core redirects to the
     * snapshot post's edit screen, which does not exist. Send it to the term instead.
     */
    public function redirect_after_native_restore( string $location ): string {
        if ( false === strpos( $location, 'post.php' ) || false === strpos( $location, 'revision=' ) ) {
            return $location;
        }
        parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
        $post_id = (int) ( $query['post'] ?? 0 );
        if ( ! $post_id || get_post_type( $post_id ) !== Ace_Revisions_Terms::POST_TYPE ) {
            return $location;
        }
        $term_id  = (int) get_post_meta( $post_id, Ace_Revisions_Terms::META_TERM, true );
        $taxonomy = (string) get_post_meta( $post_id, Ace_Revisions_Terms::META_TAX, true );
        $link     = get_edit_term_link( $term_id, $taxonomy );
        return $link ? add_query_arg( 'ace_revisions_restored', '1', $link ) : $location;
    }

    public function notices(): void {
        if ( ! isset( $_GET['ace_revisions_restored'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $ok = '1' === $_GET['ace_revisions_restored']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            $ok ? 'success' : 'error',
            $ok ? esc_html__( 'Term restored to that revision.', 'ace-revisions' ) : esc_html__( 'Nothing was restored.', 'ace-revisions' )
        );
    }
}
