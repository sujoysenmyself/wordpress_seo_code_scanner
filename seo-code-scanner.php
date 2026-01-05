<?php
/**
 * Plugin Name: SEO Code Scanner (DOM + Performance)
 * Description: Scans frontend HTML of pages/posts for DOM complexity, performance issues, and render-blocking assets.
 * Version: 1.0.0
 * Author: SEO Code Scanner
 */

if (!defined('ABSPATH')) exit;

/*--------------------------------------------------------------
 ADMIN MENU
--------------------------------------------------------------*/
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

/*--------------------------------------------------------------
 ADMIN PAGE
--------------------------------------------------------------*/
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
        <p>Analyze DOM size, depth, performance indicators, and render-blocking assets.</p>

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
                    <th>DOM Nodes</th>
                    <th>DOM Depth</th>
                    <th>HTML (KB)</th>
                    <th>Images</th>
                    <th>Scripts</th>
                    <th>Blocking CSS</th>
                    <th>Blocking JS</th>
                    <th>Estimated PSI</th>
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
                        <td><?php echo esc_html($row['dom_nodes']); ?></td>
                        <td><?php echo scs_depth_badge($row['dom_depth']); ?></td>
                        <td><?php echo esc_html($row['html_size']); ?></td>
                        <td><?php echo esc_html($row['images']); ?></td>
                        <td><?php echo esc_html($row['script_tags']); ?></td>

                        <!-- Blocking CSS -->
                        <td>
                            <?php echo count($row['blocking_css']); ?>
                            <?php if (!empty($row['blocking_css'])): ?>
                                <button
                                    class="button button-small scs-view-assets"
                                    data-title="Render-Blocking CSS"
                                    data-items='<?php echo esc_attr(json_encode($row['blocking_css'])); ?>'>
                                    View
                                </button>
                            <?php endif; ?>
                        </td>

                        <!-- Blocking JS -->
                        <td>
                            <?php echo count($row['blocking_js']); ?>
                            <?php if (!empty($row['blocking_js'])): ?>
                                <button
                                    class="button button-small scs-view-assets"
                                    data-title="Render-Blocking JS"
                                    data-items='<?php echo esc_attr(json_encode($row['blocking_js'])); ?>'>
                                    View
                                </button>
                            <?php endif; ?>
                        </td>

                        <td><?php echo esc_html($row['psi']); ?>/100</td>
                        <td><?php echo scs_status_badge($row['dom_nodes'], $row['dom_depth']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- MODAL -->
    <div id="scs-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999;">
        <div style="background:#fff; max-width:700px; margin:5% auto; padding:20px; border-radius:6px; position:relative;">
            <button onclick="scsCloseModal()" style="position:absolute; top:10px; right:10px;">✕</button>
            <h2 id="scs-modal-title"></h2>
            <ul id="scs-modal-content" style="font-size:13px; max-height:400px; overflow:auto;"></ul>
            <p style="font-size:12px;color:#666;margin-top:10px;">
                These files block rendering and can delay First Paint and LCP.
            </p>
        </div>
    </div>

    <!-- JS -->
    <script>
        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('scs-view-assets')) return;

            const title = e.target.dataset.title;
            const items = JSON.parse(e.target.dataset.items || '[]');

            document.getElementById('scs-modal-title').innerText = title;
            const list = document.getElementById('scs-modal-content');
            list.innerHTML = '';

            if (!items.length) {
                list.innerHTML = '<li>No blocking assets found.</li>';
            }

            items.forEach(item => {
                const li = document.createElement('li');
                li.style.marginBottom = '10px';
                li.innerHTML =
                    '<strong>' + item.origin + '</strong><br>' +
                    '<code style="font-size:12px;">' + item.url + '</code>';
                list.appendChild(li);
            });

            document.getElementById('scs-modal').style.display = 'block';
        });

        function scsCloseModal() {
            document.getElementById('scs-modal').style.display = 'none';
        }
    </script>
    <?php
}

/*--------------------------------------------------------------
 SCAN ALL PAGES
--------------------------------------------------------------*/
function scs_scan_all_pages() {
    $query = new WP_Query([
        'post_type'      => ['post', 'page'],
        'post_status'    => 'publish',
        'posts_per_page' => 50,
    ]);

    $results = [];

    foreach ($query->posts as $post) {
        $url  = get_permalink($post->ID);
        $scan = scs_scan_url($url);

        if ($scan) {
            $results[] = [
                'title'        => get_the_title($post->ID),
                'type'         => $post->post_type,
                'url'          => $url,
                'dom_nodes'    => $scan['dom_nodes'],
                'dom_depth'    => $scan['dom_depth'],
                'html_size'    => $scan['html_size'],
                'images'       => $scan['images'],
                'script_tags'  => $scan['script_tags'],
                'blocking_css' => $scan['blocking_css'],
                'blocking_js'  => $scan['blocking_js'],
                'psi'          => scs_calculate_psi($scan['dom_nodes'], $scan['dom_depth']),
            ];
        }
    }

    wp_reset_postdata();
    return $results;
}

/*--------------------------------------------------------------
 SCAN SINGLE URL
--------------------------------------------------------------*/
function scs_scan_url($url) {
    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) return false;

    $html = wp_remote_retrieve_body($response);
    if (!$html) return false;

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);

    return [
        'dom_nodes'    => $dom->getElementsByTagName('*')->length,
        'dom_depth'    => scs_get_dom_depth($dom->documentElement),
        'html_size'    => round(strlen($html) / 1024, 2),
        'images'       => preg_match_all('/<img[^>]+>/i', $html),
        'script_tags'  => preg_match_all('/<script[^>]*>/i', $html),
        'blocking_css' => scs_get_render_blocking_css($html),
        'blocking_js'  => scs_get_render_blocking_js($html),
    ];
}

/*--------------------------------------------------------------
 RENDER BLOCKING DETECTION
--------------------------------------------------------------*/
function scs_get_render_blocking_css($html) {
    preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $matches);
    $files = [];

    foreach ($matches[0] as $tag) {
        if (stripos($tag, 'media=') !== false && stripos($tag, 'all') === false) continue;
        if (stripos($tag, 'preload') !== false) continue;

        preg_match('/href=["\']([^"\']+)["\']/', $tag, $href);
        if (!empty($href[1])) {
            $files[] = scs_classify_asset_origin($href[1]);
        }
    }
    return $files;
}

function scs_get_render_blocking_js($html) {
    preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*><\/script>/i', $html, $matches);
    $files = [];

    foreach ($matches[1] as $i => $src) {
        $tag = $matches[0][$i];

        if (stripos($tag, 'defer') !== false || stripos($tag, 'async') !== false) continue;

        $files[] = scs_classify_asset_origin($src);
    }
    return $files;
}

function scs_classify_asset_origin($url) {
    if (strpos($url, content_url()) !== false) {
        if (strpos($url, '/plugins/') !== false) return ['url' => $url, 'origin' => 'Plugin'];
        if (strpos($url, '/themes/') !== false) return ['url' => $url, 'origin' => 'Theme'];
        return ['url' => $url, 'origin' => 'Core'];
    }
    return ['url' => $url, 'origin' => 'External'];
}

/*--------------------------------------------------------------
 HELPERS
--------------------------------------------------------------*/
function scs_get_dom_depth($node, $depth = 1) {
    $max = $depth;
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $max = max($max, scs_get_dom_depth($child, $depth + 1));
        }
    }
    return $max;
}

function scs_calculate_psi($nodes, $depth) {
    $score = 100;
    if ($nodes > 1800) $score -= 35;
    elseif ($nodes > 1200) $score -= 25;
    elseif ($nodes > 800) $score -= 15;

    if ($depth > 20) $score -= 30;
    elseif ($depth > 16) $score -= 20;
    elseif ($depth > 12) $score -= 10;

    return max(0, min(100, $score));
}

function scs_depth_badge($depth) {
    if ($depth <= 8) return '<span style="color:green;font-weight:bold;">'.$depth.'</span>';
    if ($depth <= 12) return '<span style="color:orange;font-weight:bold;">'.$depth.'</span>';
    return '<span style="color:red;font-weight:bold;">'.$depth.'</span>';
}

function scs_status_badge($nodes, $depth) {
    if ($nodes < 800 && $depth <= 8) return '<span style="color:green;font-weight:bold;">Excellent</span>';
    if ($nodes < 1200 && $depth <= 12) return '<span style="color:orange;font-weight:bold;">Moderate</span>';
    return '<span style="color:red;font-weight:bold;">Needs Attention</span>';
}
