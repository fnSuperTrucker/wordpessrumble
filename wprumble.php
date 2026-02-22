<?php
/**
 * Plugin Name: WPRumble
 * Description: High-Precision Link Hunter for FnSupertrucker.
 * Version: 3.5
 */

function wprumble_hub_display() {
    $api_key = 'YOUR RUMBLE KEY GOES HERE'; 
    $api_url = "https://rumble.com/-livestream-api/get-data?key=" . $api_key;
    $file_path = plugin_dir_path(__FILE__) . 'wprumble-library.json';

    if ( is_admin() || (defined('REST_REQUEST') && REST_REQUEST) ) { return '[wprumble]'; }

    $videos = file_exists($file_path) ? json_decode(file_get_contents($file_path), true) : [];
    $live_ids = [];

    $response = wp_remote_get($api_url, array('timeout' => 20, 'sslverify' => false));
    
    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data['livestreams'])) {
            foreach ($data['livestreams'] as $stream) {
                if (isset($stream['is_live']) && $stream['is_live'] === true) {
                    $sid = $stream['id'];
                    $live_ids[] = $sid;
                    
                    $found_key = -1;
                    foreach($videos as $k => $v) { if ($v['id'] == $sid) $found_key = $k; }

                    if ($found_key === -1 || empty($videos[$found_key]['real_url']) || strpos($videos[$found_key]['real_url'], 'shorts') !== false) {
                        
                        $live_page_url = "https://rumble.com/v" . $sid;
                        $page_content = wp_remote_get($live_page_url);
                        $final_link = $live_page_url; 

                        if (!is_wp_error($page_content)) {
                            $html = wp_remote_retrieve_body($page_content);
                            
                            // 1. Try to find the "og:url" which is Rumble's official permanent link
                            if (preg_match('/property="og:url" content="(https:\/\/rumble.com\/v[a-z0-9\-]+.*?)"/', $html, $matches)) {
                                if (strpos($matches[1], 'shorts') === false) { $final_link = $matches[1]; }
                            }
                            // 2. Backup: Look for the video title slug in the page scripts
                            elseif (preg_match('/"video":"(v[a-z0-9]+)"/', $html, $matches)) {
                                $v_id = $matches[1];
                                $slug = strtolower(trim($stream['title']));
                                $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
                                $final_link = "https://rumble.com/" . $v_id . "-" . $slug . ".html";
                            }
                        }

                        if ($found_key === -1) {
                            array_unshift($videos, [
                                'id'      => $sid,
                                'title'   => $stream['title'],
                                'is_live' => true,
                                'real_url'=> $final_link,
                                'date'    => date("M j, Y")
                            ]);
                        } else {
                            $videos[$found_key]['real_url'] = $final_link;
                            $videos[$found_key]['is_live'] = true;
                        }
                    }
                }
            }
        }
    }

    foreach ($videos as &$v) { $v['is_live'] = in_array($v['id'], $live_ids); }
    $videos = array_slice($videos, 0, 5);
    file_put_contents($file_path, json_encode($videos, JSON_PRETTY_PRINT));

    $output = '<div style="max-width:100%; font-family: sans-serif;">';
    $output .= '<script>!function(r,u,m,b,l,e){r._Rumble=b,r[b]||(r[b]=function(){(r[b]._=r[b]._||[]).push(arguments);if(r[b]._.length==1){l=u.createElement(m),e=u.getElementsByTagName(m)[0],l.async=1,l.src="https://rumble.com/embedJS/uu55ab"+(arguments[1].video?\'.\'+arguments[1].video:\'\')+"/?url="+encodeURIComponent(location.href)+"&args="+encodeURIComponent(JSON.stringify([].slice.apply(arguments))),e.parentNode.insertBefore(l,e)}})}(window, document, "script", "Rumble");</script>';

    foreach ($videos as $v) {
        $output .= "<div style='background:#fff; border:1px solid #ddd; border-radius:12px; padding:20px; margin-bottom:20px; box-shadow:0 4px 10px rgba(0,0,0,0.1);'>";
        if ($v['is_live']) {
            $output .= "<div style='color:#d9534f; font-weight:bold; margin-bottom:10px;'>📡 LIVE NOW: {$v['title']}</div>";
            $output .= "<div id='rumble_v{$v['id']}'></div><script>Rumble('play', {'video':'v{$v['id']}', 'div':'rumble_v{$v['id']}'});</script>";
        } else {
            $output .= "<div style='color:#666; font-weight:bold; margin-bottom:5px;'>PAST EPISODE - {$v['date']}</div>";
            $output .= "<div style='font-size:18px; margin-bottom:15px; color:#333;'>{$v['title']}</div>";
            $output .= "<a href='{$v['real_url']}' target='_blank' style='display:inline-block; background:#85c742; color:#fff; padding:12px 25px; text-decoration:none; border-radius:6px; font-weight:bold;'>WATCH REPLAY →</a>";
        }
        $output .= "</div>";
    }
    return $output . '</div>';
}
add_shortcode('wprumble', 'wprumble_hub_display');