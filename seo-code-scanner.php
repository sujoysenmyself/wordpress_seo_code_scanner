<?php
/**
 * Plugin Name: SEO Code Scanner (DOM Nodes + Depth + Performance)
 * Description: Scans frontend HTML of each post/page, calculates DOM size, DOM depth, frontend performance metrics, and DOM/JS insights.
 * Version: 0.5.0
 * Author: SEO Code Scanner
 */

if (!defined('ABSPATH')) exit;

/**
 * Add admin menu
 */
add_action('admin_menu', function () {
    add_menu_page(
        'SEO Code Scanner',
        'SEO Code Scanner',
        'manage_options',
        'seo-code-scanner',
        'scs_admin_page',
        'dashicons-search',
        80
    );
});

/**
 * Admin Page
 */
function scs_admin_page() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['scs_scan_all'])) {
        $results = scs_scan_all_pages();
        update_option('scs_scan_results', $results);
    } else {
        $results = get_option('scs_scan_results', []);
    }
    ?>
    <div class="wrap">
        <h1>SEO Code Scanner</h1>
        <p>Analyzes frontend HTML structure per page, with performance and DOM/JS insights.</p>

        <form method="post">
            <input type="submit" name="scs_scan_all" class="button button-primary" value="Scan All Pages & Posts">
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>DOM Nodes <span title="Total number of HTML elements in the page. More nodes can slow down page rendering.">🛈</span></th>
                        <th>DOM Depth <span title="Maximum level of nested HTML elements. Deeper DOMs can increase rendering complexity.">🛈</span></th>
                        <th>HTML Size (KB) <span title="Total HTML size of the page. Larger HTML may slow initial load.">🛈</span></th>
                        <th>External Requests <span title="Number of external CSS/JS/images requested by the page.">🛈</span></th>
                        <th>Images <span title="Number of <img> tags on the page.">🛈</span></th>
                        <th>Inline CSS/JS <span title="Number of inline <style> or <script> tags in HTML.">🛈</span></th>
                        <th>Scripts <span title="Number of <script> tags including external JS.">🛈</span></th>
                        <th>Estimated PSI <span title="A rough estimate of PageSpeed Insights score based on DOM complexity.">🛈</span></th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($row['url']); ?>" target="_blank">
                                <?php echo esc_html($row['title']); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($row['type']); ?></td>
                        <td>
                            <?php echo esc_html($row['dom_nodes']); ?>
                            <span style="font-size:0.9em;color:#666;">(Total HTML elements)</span>
                        </td>
                        <td>
                            <?php echo scs_depth_badge($row['dom_depth']); ?>
                            <span style="font-size:0.9em;color:#666;">(Max nested level)</span>
                        </td>
                        <td><?php echo esc_html($row['html_size']); ?></td>
                        <td><?php echo esc_html($row['external_requests']); ?></td>
                        <td><?php echo esc_html($row['images']); ?></td>
                        <td><?php echo esc_html($row['inline_scripts']); ?></td>
                        <td><?php echo esc_html($row['script_tags']); ?></td>
                        <td><?php echo esc_html($row['psi']); ?>/100</td>
                        <td><?php echo scs_status_badge($row['dom_nodes'], $row['dom_depth']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:10px;">
                <em>Estimated PSI is based on DOM complexity only and is not an official Google score.</em>
            </p>

            <hr>
            <h2>Explanation of Terms</h2>
            <ul>
                <li><strong>DOM Nodes:</strong> Total HTML elements. More nodes can slow rendering.</li>
                <li><strong>DOM Depth:</strong> Maximum nesting level of HTML elements.</li>
                <li><strong>HTML Size:</strong> Size of the HTML content in KB.</li>
                <li><strong>External Requests:</strong> Number of external CSS, JS, and images requested.</li>
                <li><strong>Images:</strong> Number of &lt;img&gt; tags on the page.</li>
                <li><strong>Inline CSS/JS:</strong> Number of inline &lt;style&gt; or &lt;script&gt; tags in HTML.</li>
                <li><strong>Scripts:</strong> Total number of &lt;script&gt; tags including external JS.</li>
                <li><strong>Estimated PSI:</strong> Rough estimate of PageSpeed score based on DOM complexity.</li>
                <li><strong>Status:</strong> Page complexity assessment based on DOM nodes and depth.</li>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Scan all posts & pages
 */
function scs_scan_all_pages() {
    $query = new WP_Query([
        'post_type'      => ['post', 'page'],
        'post_status'    => 'publish',
        'posts_per_page' => 50,
    ]);

    $results = [];

    foreach ($query->posts as $post) {
        $url = get_permalink($post->ID);
        $scan = scs_scan_url($url);

        if ($scan) {
            $results[] = [
                'title'             => get_the_title($post->ID),
                'type'              => $post->post_type,
                'url'               => $url,
                'dom_nodes'         => $scan['dom_nodes'],
                'dom_depth'         => $scan['dom_depth'],
                'psi'               => scs_calculate_psi($scan['dom_nodes'], $scan['dom_depth']),
                'html_size'         => $scan['html_size'],
                'external_requests' => $scan['external_requests'],
                'images'            => $scan['images'],
                'inline_scripts'    => $scan['inline_scripts'],
                'script_tags'       => $scan['script_tags'],
            ];
        }
    }

    wp_reset_postdata();
    return $results;
}

/**
 * Scan single URL with performance and DOM/JS insights
 */
function scs_scan_url($url) {
    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) return false;

    $html = wp_remote_retrieve_body($response);
    if (!$html) return false;

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);

    // DOM metrics
    $dom_nodes = $dom->getElementsByTagName('*')->length;
    $dom_depth = scs_get_dom_depth($dom->documentElement);

    // Frontend performance indicators
    $html_size = round(strlen($html) / 1024, 2); // KB
    $external_requests = preg_match_all('/<(script|link|img)[^>]+(src|href)/i', $html, $matches);
    $images = preg_match_all('/<img[^>]+>/i', $html, $matches);

    // DOM & JS insights
    $inline_scripts = preg_match_all('/<script[^>]*>.*?<\/script>/is', $html, $matches);
    $script_tags = preg_match_all('/<script[^>]*>/i', $html, $matches);

    return [
        'dom_nodes'         => $dom_nodes,
        'dom_depth'         => $dom_depth,
        'html_size'         => $html_size,
        'external_requests' => $external_requests,
        'images'            => $images,
        'inline_scripts'    => $inline_scripts,
        'script_tags'       => $script_tags,
    ];
}

/**
 * DOM Depth calculation (recursive)
 */
function scs_get_dom_depth($node, $depth = 1) {
    $max = $depth;

    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $child_depth = scs_get_dom_depth($child, $depth + 1);
            if ($child_depth > $max) $max = $child_depth;
        }
    }
    return $max;
}

/**
 * PSI calculation (DOM size + depth)
 */
function scs_calculate_psi($dom_nodes, $dom_depth) {
    $score = 100;

    if ($dom_nodes > 1800) $score -= 35;
    elseif ($dom_nodes > 1200) $score -= 25;
    elseif ($dom_nodes > 800) $score -= 15;

    if ($dom_depth > 20) $score -= 30;
    elseif ($dom_depth > 16) $score -= 20;
    elseif ($dom_depth > 12) $score -= 10;

    return max(0, min(100, $score));
}

/**
 * DOM depth badge
 */
function scs_depth_badge($depth) {
    if ($depth <= 8) return '<span style="color:green;font-weight:bold;">' . $depth . ' (Shallow)</span>';
    if ($depth <= 12) return '<span style="color:orange;font-weight:bold;">' . $depth . ' (OK)</span>';
    if ($depth <= 16) return '<span style="color:#d35400;font-weight:bold;">' . $depth . ' (Deep)</span>';
    return '<span style="color:red;font-weight:bold;">' . $depth . ' (Critical)</span>';
}

/**
 * Overall status badge
 */
function scs_status_badge($nodes, $depth) {
    if ($nodes < 800 && $depth <= 8) return '<span style="color:green;font-weight:bold;">Excellent</span>';
    if ($nodes < 1200 && $depth <= 12) return '<span style="color:orange;font-weight:bold;">Moderate</span>';
    return '<span style="color:red;font-weight:bold;">Needs Attention</span>';
}