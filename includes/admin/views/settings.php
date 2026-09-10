<?php
/**
 * Settings page view for Ace Revisions.
 *
 * Variables: $options, $fields, $tabs, $intro, $admin (see Ace_Revisions_Admin::render_settings_page).
 * Markup mirrors Ace Crawl Enhancer: sidebar tabs with sub-navigation, fieldset
 * groups per section, setting rows, a guide panel per tab, fixed save bar.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$field_tabs  = array_values( array_filter( $tabs, static function ( $tab ) { return empty( $tab['custom'] ); } ) );
$custom_tabs = array_values( array_filter( $tabs, static function ( $tab ) { return ! empty( $tab['custom'] ); } ) );
$first_tab   = $tabs[0]['id'] ?? '';

$fields_in = static function ( string $tab_id, string $section_id ) use ( $fields ) {
    $out = [];
    foreach ( $fields as $key => $field ) {
        if ( ( $field['tab'] ?? '' ) === $tab_id && ( $field['section'] ?? '' ) === $section_id ) {
            $out[ $key ] = $field;
        }
    }
    return $out;
};
?>
<div class="wrap ace-redis-settings ace-revisions-settings">
    <h1>
        <span class="dashicons dashicons-admin-generic ace-page-icon" aria-hidden="true"></span>
        <?php echo esc_html( get_admin_page_title() ); ?>
        <span class="ace-version">v<?php echo esc_html( ACE_REVISIONS_VERSION ); ?></span>
    </h1>
    <?php if ( ! empty( $intro ) ) : ?>
        <p class="ace-page-intro"><?php echo esc_html( $intro ); ?></p>
    <?php endif; ?>

    <div class="ace-redis-container">
        <div class="ace-redis-sidebar">
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $index => $tab ) : ?>
                    <a href="#<?php echo esc_attr( $tab['id'] ); ?>" class="nav-tab<?php echo $tab['id'] === $first_tab ? ' nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-<?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span><?php echo esc_html( $tab['label'] ); ?>
                    </a>
                    <?php if ( ! empty( $tab['sections'] ) ) : ?>
                        <div class="ace-tab-subnav" data-tab="<?php echo esc_attr( $tab['id'] ); ?>">
                            <?php foreach ( $tab['sections'] as $section ) : ?>
                                <a href="#<?php echo esc_attr( $tab['id'] . '/' . $section['id'] ); ?>" class="ace-subtab-link" data-target-tab="<?php echo esc_attr( $tab['id'] ); ?>" data-target-group="<?php echo esc_attr( $section['id'] ); ?>">
                                    <span class="dashicons dashicons-<?php echo esc_attr( $section['icon'] ); ?>" aria-hidden="true"></span><?php echo esc_html( $section['title'] ); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ( 'guide' === $tab['id'] ) : ?>
                        <div class="ace-tab-subnav" data-tab="guide">
                            <?php foreach ( Ace_Revisions_Guide::sections() as $gid => $gsection ) : ?>
                                <a href="#guide/guide-<?php echo esc_attr( $gid ); ?>" class="ace-subtab-link" data-target-tab="guide" data-target-group="guide-<?php echo esc_attr( $gid ); ?>">
                                    <span class="dashicons dashicons-<?php echo esc_attr( $gsection['icon'] ?? 'book' ); ?>" aria-hidden="true"></span><?php echo esc_html( $gsection['title'] ); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php do_action( 'ace_revisions_settings_sidebar' ); ?>
            </nav>
        </div>

        <div class="ace-redis-content">
            <div id="ace-redis-messages" style="display: none;"></div>

            <form id="ace-redis-settings-form" method="post" class="ace-redis-form">
                <?php foreach ( $field_tabs as $tab ) : ?>
                    <div id="<?php echo esc_attr( $tab['id'] ); ?>" class="tab-content<?php echo $tab['id'] === $first_tab ? ' active' : ''; ?>">
                        <h2><?php echo esc_html( $tab['label'] ); ?></h2>
                        <?php Ace_Revisions_Admin::guide_panel( $tab['id'], $tab['help'] ?? '' ); ?>
                        <?php do_action( 'ace_revisions_settings_tab_before', $tab['id'] ); ?>
                        <div class="settings-form">
                            <?php foreach ( (array) ( $tab['sections'] ?? [] ) as $section ) : ?>
                                <fieldset id="<?php echo esc_attr( $section['id'] ); ?>" class="ace-settings-group">
                                    <legend><span class="dashicons dashicons-<?php echo esc_attr( $section['icon'] ); ?>" aria-hidden="true"></span><?php echo esc_html( $section['title'] ); ?></legend>
                                    <?php if ( ! empty( $section['description'] ) ) : ?>
                                        <p class="ace-section-description"><?php echo esc_html( $section['description'] ); ?></p>
                                    <?php endif; ?>
                                    <?php foreach ( $fields_in( $tab['id'], $section['id'] ) as $key => $field ) : ?>
                                        <?php $admin->render_row( $key, $field, $options[ $key ] ?? null ); ?>
                                    <?php endforeach; ?>
                                    <?php do_action( 'ace_revisions_settings_section_after', $section['id'], $tab['id'] ); ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>
                        <?php do_action( 'ace_revisions_settings_tab_after', $tab['id'] ); ?>
                    </div>
                <?php endforeach; ?>
            </form>

            <?php foreach ( $custom_tabs as $tab ) : ?>
                <div id="<?php echo esc_attr( $tab['id'] ); ?>" class="tab-content ace-custom-tab<?php echo $tab['id'] === $first_tab ? ' active' : ''; ?>">
                    <h2><?php echo esc_html( $tab['label'] ); ?></h2>
                    <?php Ace_Revisions_Admin::guide_panel( $tab['id'], $tab['help'] ?? '' ); ?>
                    <?php if ( 'guide' === $tab['id'] ) : ?>
                        <?php Ace_Revisions_Guide::render(); ?>
                    <?php else : ?>
                        <?php do_action( 'ace_revisions_settings_tab_content', $tab['id'] ); ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
