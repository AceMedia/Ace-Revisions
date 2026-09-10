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
<p>Nothing is back-filled: the first tracked save of an object creates its first change set.</p>',
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
<p>Terms have no revisions in WordPress at all, so this plugin keeps its own log: name, slug, description, parent and every term meta key on the tracked taxonomies. It captures changes from any panel, including plugins we did not write, because it listens at the metadata layer rather than to a specific form.</p>
<p>Open a term to edit it and scroll down to <strong>History</strong>. Each row shows when, who, the field, before, after and the source. <strong>Restore</strong> puts that one field back to its "before" value; the restore is itself logged with the source <code>restore</code>, so nothing is ever silently undone.</p>
<p>Deleting a term writes a final row so at least the name and slug survive.</p>',
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
<p>The cap on the <strong>Storage</strong> tab is per object and counts change sets, not rows. With the default of 5, a term keeps its last five saves however many fields each one touched. Pruning runs after each save and can be applied retrospectively with <code>wp ace-revisions prune</code>.</p>
<p>Term history lives in its own table (<code>' . esc_html( Ace_Revisions::table() ) . '</code>). Post meta history lives on the revisions themselves, so it follows the site-wide revision limit if that is lower than the cap.</p>
<p>Deactivating or deleting the plugin leaves all history in place. Nothing is removed unless you drop the table yourself.</p>',
            ],
            'cli' => [
                'title'   => __( 'WP-CLI', 'ace-revisions' ),
                'icon'    => 'editor-code',
                'content' => '
<pre>wp ace-revisions term &lt;term_id&gt; [--taxonomy=&lt;tax&gt;] [--format=json]
wp ace-revisions restore &lt;row_id&gt;
wp ace-revisions prune [--taxonomy=&lt;tax&gt;]</pre>
<p>Row ids come from the <code>term</code> command or the History table.</p>',
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
        return apply_filters( 'ace_revisions_guide_sections', $sections );
    }

    public static function render(): void {
        echo '<div class="ace-guide">';
        foreach ( self::sections() as $id => $section ) {
            printf( '<section id="guide-%1$s"><h3><span class="dashicons dashicons-%2$s" aria-hidden="true"></span>%3$s</h3>%4$s</section>', esc_attr( $id ), esc_attr( $section['icon'] ), esc_html( $section['title'] ), wp_kses_post( $section['content'] ) );
        }
        echo '</div>';
    }
}
