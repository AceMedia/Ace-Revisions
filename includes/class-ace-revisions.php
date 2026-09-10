<?php
/**
 * Ace Revisions core: wires the post-meta and term trackers and owns the
 * shared helpers (tracked-key matching, change source, batch id).
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions {

    const DB_VERSION = '2';

    private static $instance = null;

    /** @var string|null Batch id for the current process (WP-CLI / bulk ops). */
    private static $batch_id = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        require_once ACE_REVISIONS_PATH . 'includes/class-ace-revisions-post-meta.php';
        require_once ACE_REVISIONS_PATH . 'includes/class-ace-revisions-terms.php';
        require_once ACE_REVISIONS_PATH . 'includes/class-ace-revisions-native.php';

        new Ace_Revisions_Post_Meta();
        new Ace_Revisions_Terms();
        new Ace_Revisions_Native();

        require_once ACE_REVISIONS_PATH . 'includes/admin/class-ace-revisions-dashboard.php';
        new Ace_Revisions_Dashboard();

        if ( is_admin() ) {
            require_once ACE_REVISIONS_PATH . 'includes/admin/class-ace-revisions-term-history.php';
            new Ace_Revisions_Term_History();
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once ACE_REVISIONS_PATH . 'includes/class-ace-revisions-cli.php';
            WP_CLI::add_command( 'ace-revisions', 'Ace_Revisions_CLI' );
        }

        add_action( 'init', [ __CLASS__, 'maybe_upgrade' ] );
    }

    public static function activate(): void {
        self::maybe_upgrade();
    }

    /**
     * Term history moved from a custom table (db version 1, never released) onto
     * native revisions of snapshot posts; drop the old table on upgrade.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'ace_revisions_db_version' ) === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ace_revisions_terms" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        update_option( 'ace_revisions_db_version', self::DB_VERSION, false );
    }

    /**
     * Does a meta key match the configured key list? Exact match, or a wildcard
     * pattern with * anywhere (prefix_*, *_suffix, *middle*).
     */
    public static function key_matches( string $key, array $patterns ): bool {
        if ( 0 === strpos( $key, '_edit_' ) ) {
            return false;
        }
        foreach ( $patterns as $pattern ) {
            if ( '' === $pattern ) {
                continue;
            }
            if ( false !== strpos( $pattern, '*' ) ) {
                if ( fnmatch( $pattern, $key ) ) {
                    return true;
                }
            } elseif ( $key === $pattern ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Where did this change come from? admin | rest | cli | cron | batch | frontend.
     */
    public static function source(): string {
        if ( null !== self::$batch_id ) {
            $source = 'batch';
        } elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
            $source = 'cli';
        } elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $source = 'rest';
        } elseif ( wp_doing_cron() ) {
            $source = 'cron';
        } elseif ( wp_doing_ajax() || is_admin() ) {
            $source = 'admin';
        } else {
            $source = 'frontend';
        }
        return apply_filters( 'ace_revisions_source', $source );
    }

    /**
     * Group every change in this process under one batch id (bulk edits, WP-CLI scripts).
     * `wp --exec` scripts can also set the ACE_REVISIONS_BATCH environment variable.
     */
    public static function set_batch( ?string $id ): void {
        self::$batch_id = $id ? sanitize_key( $id ) : null;
    }

    public static function batch_id(): string {
        if ( null === self::$batch_id ) {
            $env = getenv( 'ACE_REVISIONS_BATCH' );
            if ( $env ) {
                self::$batch_id = sanitize_key( $env );
            }
        }
        return self::$batch_id ?? '';
    }

    /**
     * Change-set id for a single request when no explicit batch is set, so all
     * fields saved together on one screen submit share a group.
     */
    public static function change_set_id(): string {
        $batch = self::batch_id();
        if ( $batch ) {
            return $batch;
        }
        static $id = null;
        if ( null === $id ) {
            $id = 'req_' . substr( md5( (string) microtime( true ) . wp_rand() ), 0, 12 );
        }
        return $id;
    }
}
