<?php
/**
 * The manual: rendered on the Guide tab and as WordPress help tabs.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ace_Revisions_Guide {

    /**
     * @return array<string, array{title:string, icon:string, content:string}>
     */
    public static function sections(): array {
        $settings = Ace_Revisions_Admin::url();
        $sections = [
            'start' => [
                'title'   => __( 'Getting started', 'ace-revisions' ),
                'icon'    => 'welcome-learn-more',
                'content' => '
<p>WordPress only keeps revisions for a post\'s title, content and excerpt. Everything else that matters on a news site lives in meta and terms: SEO titles, noindex flags, redirects, team colours, event dates. When one of those goes wrong there is normally no way to see who changed it or what it was before. This plugin fills that gap.</p>
<ol>
<li>Go to <a href="' . esc_url( $settings ) . '">Settings → Ace Revisions</a>. Nothing is tracked until you tick something.</li>
<li>On <strong>Post meta</strong>, tick the post types and list the meta keys (a trailing <code>*</code> matches a prefix, e.g. <code>_ace_seo_*</code>).</li>
<li>On <strong>Terms</strong>, tick the taxonomies whose terms you want a history for.</li>
<li>Save. From the next edit onwards, history is recorded.</li>
</ol>
<p>Nothing is back-filled: the first tracked save of an object creates its first revision. Use the presets under Meta keys to add the common Ace Crawl Enhancer or WooCommerce keys in one click, and check the Overview tab to see what is tracked at a glance.</p>',
            ],
            'post-meta' => [
                'title'   => __( 'Post meta', 'ace-revisions' ),
                'icon'    => 'backup',
                'content' => '
<p>Tracked meta rides along with the native revision. On every save the tracked keys are copied onto the revision, and if only tracked meta changed a revision is still created (core would normally skip it).</p>
<p>Open <strong>Revisions</strong> on a post as usual. Below the content diff you get one row per changed meta key, with the before and after values, and a <em>Saved via</em> row saying whether the change came from the admin, the REST API, WP-CLI, cron or a batch job.</p>
<p><strong>Restore</strong> works exactly as it always has: restoring a revision puts the tracked meta back too. Keys that did not exist at that revision are removed.</p>
<p>Keys starting with <code>_edit_</code> (lock and last-editor markers) are never tracked.</p>',
            ],
            'terms' => [
                'title'   => __( 'Terms', 'ace-revisions' ),
                'icon'    => 'tag',
                'content' => '
<p>Terms have no revisions in WordPress at all, so this plugin gives each tracked term a hidden <em>snapshot post</em> and lets WordPress do what it already does well. Every save of the term, from any panel including plugins we did not write, becomes <strong>one native revision</strong> holding the whole term: name, slug, description, parent and every tracked meta key.</p>
<p>Open a term to edit it and scroll down to <strong>History</strong>. Each row is one save: when, who, via what, and a summary of which fields changed. <strong>Compare</strong> opens the standard WordPress revisions screen with the slider, where every changed field is shown before and after. <strong>Restore</strong> is the native restore and puts the whole term back to that save; the restore itself becomes a revision marked <code>restore</code>, so nothing is ever silently undone.</p>
<p>Housekeeping keys that plugins rewrite on every save (timestamps, migration markers) are ignored via the list on the Terms tab, so they do not create noise. Empty values are not stored, so a plugin writing blanks to twenty keys does not read as twenty changes. Deleting a term keeps its snapshot post and history.</p>
<p>To start history from today for every term in a taxonomy rather than from each term\'s next edit, run <code>wp ace-revisions snapshot &lt;taxonomy&gt;</code>.</p>',
            ],
            'sources' => [
                'title'   => __( 'Sources and batches', 'ace-revisions' ),
                'icon'    => 'randomize',
                'content' => '
<p>Every change records where it came from:</p>
<table>
<tr><th>admin</th><td>The WordPress admin, including AJAX saves</td></tr>
<tr><th>rest</th><td>The REST API: the block editor, apps, agents</td></tr>
<tr><th>cli</th><td>WP-CLI</td></tr>
<tr><th>cron</th><td>Scheduled tasks</td></tr>
<tr><th>batch</th><td>A run that set an explicit batch id</td></tr>
<tr><th>restore</th><td>A restore from this plugin</td></tr>
</table>
<p>One screen save that changes ten fields is one <strong>change set</strong>. A WP-CLI script that edits 400 terms can share one <strong>batch id</strong> so it shows as a single operation, and can be reviewed or undone as one:</p>
<pre>ACE_REVISIONS_BATCH=teams-colours-2026 wp eval-file update-colours.php</pre>
<p>From PHP, call <code>Ace_Revisions::set_batch( \'id\' )</code> before making changes. Ace Taxonomy Tools does this for its bulk apply automatically.</p>',
            ],
            'storage' => [
                'title'   => __( 'Storage and pruning', 'ace-revisions' ),
                'icon'    => 'database',
                'content' => '
<p>The cap on the <strong>Storage</strong> tab is the native revisions limit applied per tracked term. Post meta history lives on the post\'s own revisions and follows the limits on the <strong>Native limits</strong> tab, raised to the cap for tracked post types so meta history is never shorter than you asked for. <code>wp ace-revisions prune</code> applies the cap retrospectively.</p>
<p>The <strong>Overview</strong> tab shows how many revisions the database holds and how much room they take, per content type, and what limit is in force for each. The clean-up there removes revisions of content nobody has modified for a chosen number of months; the content itself is never touched.</p>
<p>Deactivating or deleting the plugin leaves all history in place: term snapshots are ordinary hidden posts with ordinary revisions.</p>',
            ],
            'limits' => [
                'title'   => __( 'Native limits', 'ace-revisions' ),
                'icon'    => 'admin-settings',
                'content' => '
<p>WordPress has three knobs for revisions and they normally live in <code>wp-config.php</code> or in code. They are all on the <strong>Native limits</strong> tab:</p>
<table>
<tr><th>Switch revisions off (per post type)</th><td>Ticked removes revision support for that type: no revisions are created and the Revisions panel disappears from the editor. Untouched means on.</td></tr>
<tr><th>Revisions to keep (per post type)</th><td>-1 is unlimited (the WordPress default), 0 keeps none, any other number is a rolling limit per post. Applied through <code>wp_revisions_to_keep</code>.</td></tr>
<tr><th>Autosave interval</th><td>Seconds between editor autosaves. Applied by defining <code>AUTOSAVE_INTERVAL</code> when wp-config has not.</td></tr>
</table>
<p>A constant already set in <code>wp-config.php</code> (<code>WP_POST_REVISIONS</code>, <code>AUTOSAVE_INTERVAL</code>) always wins over these settings; the Overview tab shows which source is in force for each type.</p>',
            ],
            'cli' => [
                'title'   => __( 'WP-CLI', 'ace-revisions' ),
                'icon'    => 'editor-code',
                'content' => '
<pre>wp ace-revisions term &lt;term_id&gt; [--taxonomy=&lt;tax&gt;] [--format=json]
wp ace-revisions restore &lt;revision_id&gt;
wp ace-revisions post &lt;post_id&gt;
wp ace-revisions batch &lt;batch_id&gt; [--undo]
wp ace-revisions snapshot &lt;taxonomy&gt;
wp ace-revisions prune [--taxonomy=&lt;tax&gt;]
wp ace-revisions cleanup [--months=&lt;n&gt;] [--type=&lt;post_type&gt;] [--yes]</pre>
<p><code>batch --undo</code> restores every term and post the batch touched to the revision just before it, and records the undo as its own batch.</p>
<p>Revision ids come from the <code>term</code> command, the History table or the revisions screen URL.</p>',
            ],
            'hooks' => [
                'title'   => __( 'Hooks for developers', 'ace-revisions' ),
                'icon'    => 'admin-plugins',
                'content' => '
<p>Site-specific behaviour belongs in a must-use plugin on that site, wired through these filters, never in this shared plugin.</p>
<table>
<tr><th><code>ace_revisions_post_types</code></th><td>Filter the tracked post types</td></tr>
<tr><th><code>ace_revisions_meta_keys</code></th><td>Filter the tracked meta key patterns</td></tr>
<tr><th><code>ace_revisions_taxonomies</code></th><td>Filter the tracked taxonomies</td></tr>
<tr><th><code>ace_revisions_term_meta_keys</code></th><td>Filter the tracked term meta key patterns</td></tr>
<tr><th><code>ace_revisions_source</code></th><td>Override the detected change source</td></tr>
<tr><th><code>ace_revisions_term_log_row</code></th><td>Filter a row before it is written</td></tr>
<tr><th><code>ace_revisions_term_logged</code></th><td>Action after a row is written</td></tr>
<tr><th><code>ace_revisions_meta_restored</code>, <code>ace_revisions_term_restored</code></th><td>Actions after a restore</td></tr>
</table>',
            ],
        ];
        $sections['changelog'] = [
            'title'   => __( "What's new", 'ace-revisions' ),
            'icon'    => 'megaphone',
            'content' => self::changelog_html(),
        ];
        return apply_filters( 'ace_revisions_guide_sections', $sections );
    }

    /**
     * CHANGELOG.md as HTML (headings, bullets, paragraphs only).
     */
    public static function changelog_html(): string {
        $file = ACE_REVISIONS_PATH . 'CHANGELOG.md';
        if ( ! file_exists( $file ) ) {
            return '';
        }
        $html = '';
        $list = false;
        foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
            if ( 0 === strpos( $line, '# ' ) ) {
                continue;
            }
            if ( 0 === strpos( $line, '## ' ) ) {
                $html .= ( $list ? '</ul>' : '' ) . '<h4>' . esc_html( substr( $line, 3 ) ) . '</h4>';
                $list  = false;
            } elseif ( 0 === strpos( $line, '- ' ) ) {
                $html .= ( $list ? '' : '<ul>' ) . '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
                $list  = true;
            } elseif ( '' !== trim( $line ) ) {
                $html .= ( $list ? '</ul>' : '' ) . '<p>' . esc_html( $line ) . '</p>';
                $list  = false;
            }
        }
        return $html . ( $list ? '</ul>' : '' );
    }

    public static function render(): void {
        echo '<div class="ace-guide">';
        foreach ( self::sections() as $id => $section ) {
            printf( '<section id="guide-%1$s"><h3><span class="dashicons dashicons-%2$s" aria-hidden="true"></span>%3$s</h3>%4$s</section>', esc_attr( $id ), esc_attr( $section['icon'] ), esc_html( $section['title'] ), wp_kses_post( $section['content'] ) );
        }
        echo '</div>';
    }
}
