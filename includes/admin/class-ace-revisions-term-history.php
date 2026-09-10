<?php
/**
 * "History" section on the term edit screen for tracked taxonomies:
 * who, when, field, before/after, source, restore button.
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
        if ( 'term.php' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'ace-revisions-admin', ACE_REVISIONS_URL . 'assets/css/admin.css', [], ACE_REVISIONS_VERSION );
    }

    public function render( WP_Term $term, string $taxonomy ): void {
        if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
            return;
        }
        $rows = Ace_Revisions_Terms::history( $term->term_id, $taxonomy );
        $can_restore = current_user_can( 'manage_categories' );
        ?>
        <div class="ace-revisions-history">
            <h2><?php esc_html_e( 'History', 'ace-revisions' ); ?></h2>
            <?php if ( empty( $rows ) ) : ?>
                <p class="description"><?php esc_html_e( 'No tracked changes yet.', 'ace-revisions' ); ?></p>
            <?php else : ?>
                <table class="widefat striped ace-revisions-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'When', 'ace-revisions' ); ?></th>
                            <th><?php esc_html_e( 'Who', 'ace-revisions' ); ?></th>
                            <th><?php esc_html_e( 'Field', 'ace-revisions' ); ?></th>
                            <th><?php esc_html_e( 'Before', 'ace-revisions' ); ?></th>
                            <th><?php esc_html_e( 'After', 'ace-revisions' ); ?></th>
                            <th><?php esc_html_e( 'Via', 'ace-revisions' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $row ) :
                        $user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
                        $when = get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
                        $restore_url = wp_nonce_url( add_query_arg( [
                            'action' => self::ACTION,
                            'row'    => (int) $row['id'],
                            'term'   => $term->term_id,
                            'tax'    => $taxonomy,
                        ], admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $row['id'] );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $when ); ?></td>
                            <td><?php echo $user ? esc_html( $user->display_name ) : '<em>' . esc_html__( 'system', 'ace-revisions' ) . '</em>'; ?></td>
                            <td><code><?php echo esc_html( $row['field'] ); ?></code></td>
                            <td class="ace-rev-value"><?php echo esc_html( self::short( $row['old_value'] ) ); ?></td>
                            <td class="ace-rev-value"><?php echo esc_html( self::short( $row['new_value'] ) ); ?></td>
                            <td>
                                <span class="ace-rev-source ace-rev-source-<?php echo esc_attr( $row['source'] ); ?>"><?php echo esc_html( $row['source'] ); ?></span>
                                <?php if ( 0 !== strpos( $row['batch_id'], 'req_' ) && '' !== $row['batch_id'] ) : ?>
                                    <br><small><?php echo esc_html( $row['batch_id'] ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $can_restore && '__deleted' !== $row['field'] ) : ?>
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
        $row_id = isset( $_GET['row'] ) ? (int) $_GET['row'] : 0;
        $term   = isset( $_GET['term'] ) ? (int) $_GET['term'] : 0;
        $tax    = isset( $_GET['tax'] ) ? sanitize_key( wp_unslash( $_GET['tax'] ) ) : '';

        if ( ! $row_id || ! current_user_can( 'manage_categories' ) || ! current_user_can( 'edit_term', $term ) ) {
            wp_die( esc_html__( 'You do not have permission to restore this change.', 'ace-revisions' ) );
        }
        check_admin_referer( self::ACTION . '_' . $row_id );

        $ok = Ace_Revisions_Terms::restore( $row_id );
        $back = get_edit_term_link( $term, $tax );
        wp_safe_redirect( add_query_arg( 'ace_revisions_restored', $ok ? '1' : '0', $back ) );
        exit;
    }

    public function notices(): void {
        if ( ! isset( $_GET['ace_revisions_restored'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $ok = '1' === $_GET['ace_revisions_restored']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            $ok ? 'success' : 'error',
            $ok ? esc_html__( 'Value restored.', 'ace-revisions' ) : esc_html__( 'Nothing was restored.', 'ace-revisions' )
        );
    }

    private static function short( $value ): string {
        if ( null === $value ) {
            return '—';
        }
        $text = is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
        return mb_strlen( $text ) > 120 ? mb_substr( $text, 0, 117 ) . '…' : $text;
    }
}
