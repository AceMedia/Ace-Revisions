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

    const DB_VERSION = '1';

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

        new Ace_Revisions_Post_Meta();
        new Ace_Revisions_Terms();

        if ( is_admin() ) {
            require_once ACE_REVISIONS_PATH . 'includes/admin/class-ace-revisions-term-history.php';
            new Ace_Revisions_Term_History();
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once ACE_REVISIONS_PATH . 'includes/class-ace-revisions-cli.php';
            WP_CLI::add_command( 'ace-revisions', 'Ace_Revisions_CLI' );
        }

        add_action( 'init', [ $this, 'maybe_upgrade' ] );
    }

    /**
     * Activation: create the term change table. Plain CREATE TABLE (no IF NOT EXISTS,
     * dbDelta misparses it and silently stops diffing the table).
     */
    public static function activate(): void {
        self::create_tables();
        update_option( 'ace_revisions_db_version', self::DB_VERSION, false );
    }

    public function maybe_upgrade(): void {
        if ( get_option( 'ace_revisions_db_version' ) !== self::DB_VERSION ) {
            self::create_tables();
            update_option( 'ace_revisions_db_version', self::DB_VERSION, false );
        }
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ace_revisions_terms';
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id bigint(20) unsigned NOT NULL,
            taxonomy varchar(32) NOT NULL,
            field varchar(255) NOT NULL,
            old_value longtext NULL,
            new_value longtext NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(20) NOT NULL DEFAULT 'admin',
            batch_id varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY term_tax (term_id, taxonomy),
            KEY batch_id (batch_id),
            KEY created_at (created_at)
        ) {$charset};";
        $result = dbDelta( $sql );

        // Guard against the silent-failure case: verify the table exists before trusting the version.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            error_log( 'Ace Revisions: failed to create ' . $table . ' - ' . wp_json_encode( $result ) );
        }
    }

    /**
     * Does a meta key match the configured key list (exact or prefix*)?
     */
    public static function key_matches( string $key, array $patterns ): bool {
        if ( 0 === strpos( $key, '_edit_' ) ) {
            return false;
        }
        foreach ( $patterns as $pattern ) {
            if ( '' === $pattern ) {
                continue;
            }
            if ( '*' === substr( $pattern, -1 ) ) {
                if ( 0 === strpos( $key, rtrim( $pattern, '*' ) ) ) {
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
