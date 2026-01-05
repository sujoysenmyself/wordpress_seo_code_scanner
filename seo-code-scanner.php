<?php
/**
 * Plugin Name: SEO Code Scanner Pro
 * Description: Advanced DOM + render-blocking asset scanner with Builder/Plugin/Theme summary and depth path + number.
 * Version: 1.9.0
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

        <form method="post" style="margin-bottom:10px;">
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
                    <th>Depth Number</th>
                    <th>Depth Path</th>
                    <th>HTML (KB)</th>
                    <th>Images</th>
                    <th>Scripts</th>
                    <th>Blocking CSS</th>
                    <th>Blocking JS</th>
                    <th>Top Blocking Contributor</th>
                    <th>Asset Summary</th>
                    <th>PSI</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row):
                    $top = scs_top_contributor($row['blocking_css'], $row['blocking_js']);
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url($row['url']); ?>" target="_blank"><?php echo esc_html($row['title']); ?></a></td>
                        <td><?php echo esc_html($row['type']); ?></td>
                        <td><?php echo esc_html($row['dom_nodes']); ?></td>
                        <td><?php echo esc_html($row['dom_depth']); ?></td>
                        <td style="font-size:12px;"><?php echo esc_html($row['dom_depth_path']); ?></td>
                        <td><?php echo esc_html($row['html_size']); ?></td>
                        <td><?php echo esc_html($row['images']); ?></td>
                        <td><?php echo esc_html($row['script_tags']); ?></td>

                        <td>
                            <?php echo count($row['blocking_css']); ?>
                            <?php if (!empty($row['blocking_css'])): ?>
                                <button class="button button-small scs-view-assets" data-title="Render-Blocking CSS" data-items='<?php echo esc_attr(json_encode($row["blocking_css"])); ?>'>View</button>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php echo count($row['blocking_js']); ?>
                            <?php if (!empty($row['blocking_js'])): ?>
                                <button class="button button-small scs-view-assets" data-title="Render-Blocking JS" data-items='<?php echo esc_attr(json_encode($row["blocking_js"])); ?>'>View</button>
                            <?php endif; ?>
                        </td>

                        <td><?php echo esc_html($top['origin'].' ('.$top['count'].')'); ?></td>

                        <td>
                            <?php 
                            $summary_grouped = scs_asset_summary_grouped($row['blocking_css'],$row['blocking_js']);
                            foreach($summary_grouped as $group => $items): ?>
                                <strong><?php echo esc_html($group); ?>:</strong>
                                <ul style="margin:0 0 5px 15px; padding:0; font-size:12px;">
                                    <?php foreach($items as $name => $count): ?>
                                        <li><?php echo esc_html($name . ' (' . $count . ')'); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endforeach; ?>
                        </td>

                        <td><?php echo esc_html($row['psi']); ?>/100</td>
                        <td><?php echo scs_status_badge($row['dom_nodes'], $row['dom_depth']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- DEFINITIONS BOX -->
        <div style="margin-top:30px; padding:15px; border-left:4px solid #0073aa; background:#f1f1f1;">
            <h2 style="margin-top:0;">Definitions</h2>
            <ul style="font-size:14px; line-height:1.6;">
                <li><strong>DOM Nodes:</strong> Total HTML elements on the page. More nodes = slower rendering.</li>
                <li><strong>Depth Number:</strong> Maximum nesting level of HTML elements. Example: &lt;html&gt;&lt;body&gt;&lt;div&gt;&lt;p&gt;Text&lt;/p&gt;&lt;/div&gt;&lt;/body&gt;&lt;/html&gt; → depth = 4.</li>
                <li><strong>Depth Path:</strong> Full path of the deepest element, e.g., <code>html > body > div > section > article > p</code>.</li>
                <li><strong>Blocking CSS:</strong> CSS files that must load before the page is visible, slowing initial rendering.</li>
                <li><strong>Blocking JS:</strong> JavaScript files that prevent page rendering until loaded.</li>
                <li><strong>Asset Summary:</strong> Count of blocking assets grouped by Builder / Plugin / Theme / Core / External.</li>
                <li><strong>PSI Calculation:</strong> Estimated score based on DOM Nodes + Depth. Max 100, higher complexity = lower score.</li>
                <li><strong>Status:</strong>
                    <ul>
                        <li><strong>Excellent:</strong> DOM Nodes &lt; 800 AND Depth ≤ 8</li>
                        <li><strong>Moderate:</strong> DOM Nodes &lt; 1200 AND Depth ≤ 12</li>
                        <li><strong>Needs Attention:</strong> DOM Nodes ≥ 1200 OR Depth &gt; 12</li>
                    </ul>
                </li>
            </ul>
        </div>

    </div>

    <!-- MODAL -->
    <div id="scs-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999;">
        <div style="background:#fff; max-width:700px; margin:5% auto; padding:20px; border-radius:6px; position:relative;">
            <button onclick="scsCloseModal()" style="position:absolute; top:10px; right:10px;">✕</button>
            <h2 id="scs-modal-title"></h2>
            <ul id="scs-modal-content" style="font-size:13px; max-height:400px; overflow:auto;"></ul>
        </div>
    </div>

    <script>
        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('scs-view-assets')) return;
            const title = e.target.dataset.title;
            const items = JSON.parse(e.target.dataset.items || '[]');
            document.getElementById('scs-modal-title').innerText = title;
            const list = document.getElementById('scs-modal-content');
            list.innerHTML = '';
            if (!items.length) list.innerHTML = '<li>No blocking assets found.</li>';
            items.forEach(item => {
                const li = document.createElement('li');
                li.style.marginBottom='8px';
                li.innerHTML = '<strong>'+item.origin+'</strong> — <code style="font-size:12px;">'+item.url+'</code>';
                list.appendChild(li);
            });
            document.getElementById('scs-modal').style.display='block';
        });
        function scsCloseModal(){document.getElementById('scs-modal').style.display='none';}
    </script>
<?php
}

/*--------------------------------------------------------------
SCANNER FUNCTIONS
--------------------------------------------------------------*/
function scs_scan_all_pages() {
    $query = new WP_Query([
        'post_type'=>['post','page'],
        'post_status'=>'publish',
        'posts_per_page'=>50,
    ]);
    $results=[];
    foreach($query->posts as $post){
        $url = get_permalink($post->ID);
        $scan = scs_scan_url($url);
        if($scan){
            $results[] = [
                'title'=>$post->post_title,
                'type'=>$post->post_type,
                'url'=>$url,
                'dom_nodes'=>$scan['dom_nodes'],
                'dom_depth'=>$scan['dom_depth'],
                'dom_depth_path'=>$scan['dom_depth_path'],
                'html_size'=>$scan['html_size'],
                'images'=>$scan['images'],
                'script_tags'=>$scan['script_tags'],
                'blocking_css'=>$scan['blocking_css'],
                'blocking_js'=>$scan['blocking_js'],
                'psi'=>scs_calculate_psi($scan['dom_nodes'],$scan['dom_depth']),
            ];
        }
    }
    wp_reset_postdata();
    return $results;
}

function scs_scan_url($url){
    $res = wp_remote_get($url,['timeout'=>15]);
    if(is_wp_error($res)) return false;
    $html = wp_remote_retrieve_body($res);
    if(!$html) return false;

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);

    $deepest = scs_get_deepest_path($dom->documentElement);

    return [
        'dom_nodes'=>$dom->getElementsByTagName('*')->length,
        'dom_depth'=>count($deepest),
        'dom_depth_path'=>implode(' > ',$deepest),
        'html_size'=>round(strlen($html)/1024,2),
        'images'=>preg_match_all('/<img[^>]+>/i',$html),
        'script_tags'=>preg_match_all('/<script[^>]*>/i',$html),
        'blocking_css'=>scs_get_render_blocking_css($html),
        'blocking_js'=>scs_get_render_blocking_js($html),
    ];
}

/*--------------------------------------------------------------
HELPERS
--------------------------------------------------------------*/
function scs_get_deepest_path($node,$path=[]){
    $path[]=$node->nodeName;
    $max_path=$path;
    foreach($node->childNodes as $c)
        if($c->nodeType===XML_ELEMENT_NODE){
            $child_path=scs_get_deepest_path($c,$path);
            if(count($child_path)>count($max_path)) $max_path=$child_path;
        }
    return $max_path;
}

function scs_calculate_psi($n,$d){
    $s=100;
    if($n>1800)$s-=35;elseif($n>1200)$s-=25;elseif($n>800)$s-=15;
    if($d>20)$s-=30;elseif($d>16)$s-=20;elseif($d>12)$s-=10;
    return max(0,min(100,$s));
}

function scs_status_badge($n,$d){
    if($n<800 && $d<=8)return'<span style="color:green;font-weight:bold;">Excellent</span>';
    if($n<1200 && $d<=12)return'<span style="color:orange;font-weight:bold;">Moderate</span>';
    return'<span style="color:red;font-weight:bold;">Needs Attention</span>';
}

/*--------------------------------------------------------------
BLOCKING CSS/JS FUNCTIONS
--------------------------------------------------------------*/
function scs_get_render_blocking_css($html){
    preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i',$html,$m);
    $out=[];
    foreach($m[0] as $tag){
        if(stripos($tag,'media=')!==false && stripos($tag,'all')===false) continue;
        if(stripos($tag,'preload')!==false) continue;
        preg_match('/href=["\']([^"\']+)["\']/',$tag,$h);
        if(!empty($h[1])) $out[]=scs_classify_asset_origin($h[1]);
    }
    return $out;
}

function scs_get_render_blocking_js($html){
    preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*><\/script>/i',$html,$m);
    $out=[];
    foreach($m[1] as $i=>$src){
        $tag=$m[0][$i];
        if(stripos($tag,'defer')!==false || stripos($tag,'async')!==false) continue;
        $out[]=scs_classify_asset_origin($src);
    }
    return $out;
}

function scs_classify_asset_origin($url){
    if(strpos($url,content_url())!==false){
        if(strpos($url,'/plugins/')!==false){
            preg_match('#/plugins/([^/]+)/#',$url,$m);
            $plugin_name=$m[1]??'Plugin';
            $builders=['elementor','wpbakery','divi','bricks','oxygen','gutenberg'];
            $type=in_array(strtolower($plugin_name),$builders)?'Builder':'Plugin';
            return ['url'=>$url,'origin'=>$plugin_name,'type'=>$type];
        }
        if(strpos($url,'/themes/')!==false){
            preg_match('#/themes/([^/]+)/#',$url,$m);
            return ['url'=>$url,'origin'=>$m[1]??'Theme','type'=>'Theme'];
        }
        return ['url'=>$url,'origin'=>'Core','type'=>'Core'];
    }
    return ['url'=>$url,'origin'=>'External','type'=>'External'];
}

function scs_asset_summary_grouped($css,$js){
    $groups=['Builder'=>[],'Plugin'=>[],'Theme'=>[],'Core'=>[],'External'=>[]];
    foreach(array_merge($css,$js) as $asset){
        $t=$asset['type']??'Plugin';
        $n=$asset['origin']??'Unknown';
        if(!isset($groups[$t][$n])) $groups[$t][$n]=0;
        $groups[$t][$n]++;
    }
    return $groups;
}

function scs_top_contributor($css,$js){
    $summary=[];
    foreach(array_merge($css,$js) as $a){
        $name=$a['origin']??'Unknown';
        if(!isset($summary[$name])) $summary[$name]=0;
        $summary[$name]++;
    }
    if(empty($summary)) return ['origin'=>'None','count'=>0];
    arsort($summary);
    $top_origin=key($summary);
    return ['origin'=>$top_origin,'count'=>$summary[$top_origin]];
}
