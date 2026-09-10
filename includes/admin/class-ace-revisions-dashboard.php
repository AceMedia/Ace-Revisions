<?php
/**
 * Overview tab: revision counts and size by content type, what limits are in
 * force, and a clean-up for revisions of content untouched for X months.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_Dashboard {

    const ACTION = 'ace_revisions_cleanup';
    const BATCH  = 500;

    public function __construct() {
        add_action( 'ace_revisions_settings_tab_content', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION, [ $this, 'cleanup' ] );
    }

    // ---------------------------------------------------------------- stats

    /**
     * Per parent post type: revision count, bytes (content + title + excerpt + meta), oldest.
     */
    public static function stats(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT parent.post_type AS type,
                    COUNT(*) AS revisions,
                    COUNT(DISTINCT parent.ID) AS objects,
                    SUM(LENGTH(rev.post_content) + LENGTH(rev.post_title) + LENGTH(rev.post_excerpt)) AS bytes,
                    MIN(rev.post_modified_gmt) AS oldest
             FROM {$wpdb->posts} rev
             INNER JOIN {$wpdb->posts} parent ON parent.ID = rev.post_parent
             WHERE rev.post_type = 'revision'
             GROUP BY parent.post_type
             ORDER BY revisions DESC",
            ARRAY_A
        ) ?: [];
        $meta = $wpdb->get_results(
            "SELECT parent.post_type AS type, SUM(LENGTH(pm.meta_key) + LENGTH(pm.meta_value)) AS bytes
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} rev ON rev.ID = pm.post_id AND rev.post_type = 'revision'
             INNER JOIN {$wpdb->posts} parent ON parent.ID = rev.post_parent
             GROUP BY parent.post_type",
            OBJECT_K
        ) ?: [];
        foreach ( $rows as &$row ) {
            $row['revisions']  = (int) $row['revisions'];
            $row['objects']    = (int) $row['objects'];
            $row['bytes']      = (int) $row['bytes'] + (int) ( $meta[ $row['type'] ]->bytes ?? 0 );
            $row['is_terms']   = Ace_Revisions_Terms::POST_TYPE === $row['type'];
            $object            = get_post_type_object( $row['type'] );
            $row['label']      = $row['is_terms'] ? __( 'Term snapshots (Ace Revisions)', 'ace-revisions' ) : ( $object ? $object->labels->name : $row['type'] );
        }
        return $rows;
    }

    /**
     * Revisions whose parent has not been modified for $months, optionally one type.
     */
    public static function stale_count( int $months, string $type = '' ): int {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
        $sql    = "SELECT COUNT(*) FROM {$wpdb->posts} rev INNER JOIN {$wpdb->posts} parent ON parent.ID = rev.post_parent WHERE rev.post_type = 'revision' AND parent.post_modified_gmt < %s";
        $args   = [ $cutoff ];
        if ( $type ) {
            $sql   .= ' AND parent.post_type = %s';
            $args[] = $type;
        } else {
            $sql   .= ' AND parent.post_type <> %s';
            $args[] = Ace_Revisions_Terms::POST_TYPE;
        }
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function stale_ids( int $months, string $type, int $limit ): array {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
        $sql    = "SELECT rev.ID FROM {$wpdb->posts} rev INNER JOIN {$wpdb->posts} parent ON parent.ID = rev.post_parent WHERE rev.post_type = 'revision' AND parent.post_modified_gmt < %s";
        $args   = [ $cutoff ];
        if ( $type ) {
            $sql   .= ' AND parent.post_type = %s';
            $args[] = $type;
        } else {
            $sql   .= ' AND parent.post_type <> %s';
            $args[] = Ace_Revisions_Terms::POST_TYPE;
        }
        $sql   .= ' ORDER BY rev.ID ASC LIMIT %d';
        $args[] = $limit;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // ---------------------------------------------------------------- UI

    public function render( string $tab_id ): void {
        if ( 'overview' !== $tab_id ) {
            return;
        }
        $stats  = self::stats();
        $total  = array_sum( array_column( $stats, 'revisions' ) );
        $bytes  = array_sum( array_column( $stats, 'bytes' ) );
        $months = isset( $_GET['months'] ) ? max( 1, (int) $_GET['months'] ) : 12; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $stale  = self::stale_count( $months, $type );
        $done   = isset( $_GET['ace_cleaned'] ) ? (int) $_GET['ace_cleaned'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="ace-stat-grid">
            <div class="ace-stat"><span class="ace-stat__label"><?php esc_html_e( 'Revisions in database', 'ace-revisions' ); ?></span><span class="ace-stat__value"><?php echo esc_html( number_format_i18n( $total ) ); ?></span></div>
            <div class="ace-stat"><span class="ace-stat__label"><?php esc_html_e( 'Space used', 'ace-revisions' ); ?></span><span class="ace-stat__value"><?php echo esc_html( size_format( $bytes, 1 ) ); ?></span></div>
            <div class="ace-stat"><span class="ace-stat__label"><?php esc_html_e( 'Content types', 'ace-revisions' ); ?></span><span class="ace-stat__value"><?php echo esc_html( number_format_i18n( count( $stats ) ) ); ?></span></div>
            <div class="ace-stat"><span class="ace-stat__label"><?php echo esc_html( sprintf( __( 'Untouched for %d months', 'ace-revisions' ), $months ) ); ?></span><span class="ace-stat__value"><?php echo esc_html( number_format_i18n( $stale ) ); ?></span></div>
        </div>

        <h3><?php esc_html_e( 'By content type', 'ace-revisions' ); ?></h3>
        <table class="widefat striped ace-revisions-table">
            <thead><tr>
                <th><?php esc_html_e( 'Content type', 'ace-revisions' ); ?></th>
                <th><?php esc_html_e( 'Revisions', 'ace-revisions' ); ?></th>
                <th><?php esc_html_e( 'Objects', 'ace-revisions' ); ?></th>
                <th><?php esc_html_e( 'Size', 'ace-revisions' ); ?></th>
                <th><?php esc_html_e( 'Oldest', 'ace-revisions' ); ?></th>
                <th><?php esc_html_e( 'Keeping', 'ace-revisions' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( ! $stats ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No revisions in the database.', 'ace-revisions' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $stats as $row ) :
                if ( $row['is_terms'] ) {
                    $keeping = sprintf( __( '%d per term (Storage tab)', 'ace-revisions' ), (int) Ace_Revisions_Settings::get( 'cap_per_object', 5 ) );
                } else {
                    $eff     = Ace_Revisions_Native::effective( $row['type'] );
                    $keeping = ( -1 === $eff['keep'] ? __( 'unlimited', 'ace-revisions' ) : ( 0 === $eff['keep'] ? __( 'none', 'ace-revisions' ) : sprintf( __( '%d per post', 'ace-revisions' ), $eff['keep'] ) ) ) . ' <small>(' . esc_html( $eff['source'] ) . ')</small>';
                }
                ?>
                <tr>
                    <td><?php echo esc_html( $row['label'] ); ?> <code><?php echo esc_html( $row['type'] ); ?></code></td>
                    <td><?php echo esc_html( number_format_i18n( $row['revisions'] ) ); ?></td>
                    <td><?php echo esc_html( number_format_i18n( $row['objects'] ) ); ?></td>
                    <td><?php echo esc_html( size_format( $row['bytes'], 1 ) ); ?></td>
                    <td><?php echo esc_html( $row['oldest'] ? date_i18n( get_option( 'date_format' ), strtotime( $row['oldest'] ) ) : '—' ); ?></td>
                    <td><?php echo wp_kses_post( $keeping ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3><?php esc_html_e( 'In force right now', 'ace-revisions' ); ?></h3>
        <table class="widefat striped ace-revisions-table ace-revisions-constants">
            <tbody>
            <?php foreach ( Ace_Revisions_Native::constants() as $name => $value ) : ?>
                <tr><th><code><?php echo esc_html( $name ); ?></code></th><td><?php echo esc_html( $value ); ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ( Ace_Revisions_Settings::revision_post_types() as $name => $label ) :
                $eff = Ace_Revisions_Native::effective( $name ); ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $name ); ?></code></th>
                    <td><?php echo esc_html( Ace_Revisions_Native::enabled( $name ) ? ( -1 === $eff['keep'] ? __( 'revisions on, unlimited', 'ace-revisions' ) : sprintf( __( 'revisions on, keep %d', 'ace-revisions' ), $eff['keep'] ) ) : __( 'revisions off', 'ace-revisions' ) ); ?> <small>(<?php echo esc_html( $eff['source'] ); ?>)</small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php esc_html_e( 'Change these on the Native limits tab. A constant set in wp-config.php always wins and is shown as the source.', 'ace-revisions' ); ?></p>

        <h3><?php esc_html_e( 'Clean up old revisions', 'ace-revisions' ); ?></h3>
        <?php if ( null !== $done ) : ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( sprintf( _n( 'Deleted %d revision.', 'Deleted %d revisions.', $done, 'ace-revisions' ), $done ) ); ?><?php echo $stale ? ' ' . esc_html( sprintf( __( '%d more match; run it again.', 'ace-revisions' ), $stale ) ) : ''; ?></p></div>
        <?php endif; ?>
        <p class="description"><?php esc_html_e( 'Deletes revisions belonging to content that nobody has modified for the chosen period. The content itself, its current version and term snapshots are left alone unless you pick them explicitly. Runs in batches of 500.', 'ace-revisions' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ace-filter-bar" onsubmit="return confirm('<?php echo esc_js( __( 'Delete these revisions? This cannot be undone.', 'ace-revisions' ) ); ?>');">
            <?php wp_nonce_field( self::ACTION ); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
            <label><?php esc_html_e( 'Content not modified in', 'ace-revisions' ); ?> <input type="number" name="months" min="1" max="240" value="<?php echo (int) $months; ?>" class="small-text"> <?php esc_html_e( 'months', 'ace-revisions' ); ?></label>
            <label><?php esc_html_e( 'Content type', 'ace-revisions' ); ?>
                <select name="type">
                    <option value=""><?php esc_html_e( 'All except term snapshots', 'ace-revisions' ); ?></option>
                    <?php foreach ( $stats as $row ) : ?>
                        <option value="<?php echo esc_attr( $row['type'] ); ?>" <?php selected( $type, $row['type'] ); ?>><?php echo esc_html( $row['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button" name="mode" value="count"><?php esc_html_e( 'Count', 'ace-revisions' ); ?></button>
            <button class="button button-primary" name="mode" value="delete" <?php disabled( 0 === $stale ); ?>><?php echo esc_html( sprintf( __( 'Delete %s', 'ace-revisions' ), number_format_i18n( min( $stale, self::BATCH ) ) ) ); ?></button>
        </form>
        <?php
    }

    public function cleanup(): void {
        if ( ! current_user_can( Ace_Revisions_Admin::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'ace-revisions' ) );
        }
        check_admin_referer( self::ACTION );
        $months = isset( $_POST['months'] ) ? max( 1, (int) $_POST['months'] ) : 12;
        $type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
        $mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'count';
        $back   = add_query_arg( [ 'months' => $months, 'type' => $type ], admin_url( 'options-general.php?page=' . Ace_Revisions_Admin::PAGE ) );

        if ( 'delete' === $mode ) {
            $deleted = 0;
            foreach ( self::stale_ids( $months, $type, self::BATCH ) as $id ) {
                if ( wp_delete_post_revision( $id ) ) {
                    $deleted++;
                }
            }
            do_action( 'ace_revisions_cleanup_ran', $deleted, $months, $type );
            $back = add_query_arg( 'ace_cleaned', $deleted, $back );
        }
        wp_safe_redirect( $back . '#overview' );
        exit;
    }
}
