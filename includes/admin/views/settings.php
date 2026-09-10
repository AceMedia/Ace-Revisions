<?php
/**
 * Settings page view for Ace Revisions.
 *
 * Variables: $options, $fields, $tabs, $admin (see Ace_Revisions_Admin::render_settings_page).
 * Markup mirrors Ace Crawl Enhancer so the shared admin.scss applies unchanged.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$first_tab = $tabs[0][0] ?? '';
?>
<div class="wrap ace-redis-settings ace-revisions-settings">
    <h1><?php echo esc_html( get_admin_page_title() ); ?> <span class="ace-version">v<?php echo esc_html( ACE_REVISIONS_VERSION ); ?></span></h1>

    <div class="ace-redis-container">
        <div class="ace-redis-sidebar">
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $index => $tab ) : list( $tab_id, $tab_label, $tab_icon ) = $tab; ?>
                    <a href="#<?php echo esc_attr( $tab_id ); ?>" class="nav-tab<?php echo 0 === $index ? ' nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-<?php echo esc_attr( $tab_icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $tab_label ); ?>
                    </a>
                <?php endforeach; ?>
                <?php
                /**
                 * Extra sidebar navigation for this plugin's other admin screens.
                 */
                do_action( 'ace_revisions_settings_sidebar' );
                ?>
            </nav>
        </div>

        <div class="ace-redis-content">
            <div id="ace-redis-messages" style="display: none;"></div>

            <form id="ace-redis-settings-form" method="post" class="ace-redis-form">
                <?php foreach ( $tabs as $index => $tab ) : list( $tab_id, $tab_label ) = $tab; ?>
                    <div id="<?php echo esc_attr( $tab_id ); ?>" class="tab-content<?php echo 0 === $index ? ' active' : ''; ?>">
                        <h2><?php echo esc_html( $tab_label ); ?></h2>
                        <?php do_action( 'ace_revisions_settings_tab_before', $tab_id ); ?>
                        <table class="form-table" role="presentation">
                            <?php foreach ( $fields as $key => $field ) : if ( ( $field['tab'] ?? $first_tab ) !== $tab_id ) { continue; } ?>
                                <tr>
                                    <th scope="row"><label for="<?php echo esc_attr( Ace_Revisions_Admin::PAGE . '-' . $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                                    <td><?php $admin->render_field( $key, $field, $options[ $key ] ?? null ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                        <?php do_action( 'ace_revisions_settings_tab_after', $tab_id ); ?>
                    </div>
                <?php endforeach; ?>
            </form>
        </div>
    </div>
</div>
