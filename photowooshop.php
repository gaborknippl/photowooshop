<?php
/**
 * Plugin Name: Photowooshop
 * Plugin URI:  https://github.com/gaborknippl/photowooshop
 * Update URI:  https://github.com/gaborknippl/photowooshop
 * Description: Teljesen egyedi, 6 fotós montázs készítő WooCommerce termékekhez.
 * Version:     1.1.30
 * Author:      Flodesign
 * Author URI:  https://www.flodesign.hu
 * Text Domain: photowooshop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Photowooshop
{
    private static $instance = null;
    const PLUGIN_VERSION = '1.1.30';
    const VERSION_OPTION = 'photowooshop_plugin_version';
    const UPLOAD_SUBDIR = 'photowooshop';
    const IMAGE_UPLOAD_MAX_BYTES = 12582912; // 12 MB
    const AUDIO_UPLOAD_MAX_BYTES = 26214400; // 25 MB
    const CLEANUP_AGE_DAYS = 14;
    const CLEANUP_HOOK = 'photowooshop_daily_cleanup';
    const CLEANUP_REPORT_OPTION = 'photowooshop_cleanup_last_report';
    const MIGRATION_REPORT_OPTION = 'photowooshop_migration_last_report';
    const SAFE_MODE_OPTION = 'photowooshop_safe_mode_enabled';
    const INDEX_CACHE_OPTION = 'photowooshop_material_index_cache';
    const INDEX_REPORT_OPTION = 'photowooshop_material_index_last_report';
    const GLOBAL_REPAIR_REPORT_OPTION = 'photowooshop_global_repair_last_report';
    const SMOKE_REPORT_OPTION = 'photowooshop_smoke_last_report';
    const UPDATE_CACHE_OPTION = 'photowooshop_github_update_cache';
    const GITHUB_REPOSITORY = 'gaborknippl/photowooshop';
    const GITHUB_LATEST_RELEASE_API = 'https://api.github.com/repos/gaborknippl/photowooshop/releases/latest';
    const GITHUB_TAGS_API = 'https://api.github.com/repos/gaborknippl/photowooshop/tags?per_page=1';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'maybe_run_upgrade'), 5);
        add_action('init', array($this, 'ensure_cleanup_schedule'));
        add_action(self::CLEANUP_HOOK, array($this, 'cleanup_orphan_uploads'));
        add_action('photowooshop_daily_index_rebuild', array($this, 'rebuild_material_index_snapshot'));
        add_action('admin_post_photowooshop_run_cleanup', array($this, 'handle_manual_cleanup'));
        add_action('admin_post_photowooshop_rebuild_index', array($this, 'handle_rebuild_index'));
        add_action('admin_post_photowooshop_run_global_repair', array($this, 'handle_run_global_repair'));
        add_action('admin_post_photowooshop_run_smoke_test', array($this, 'handle_run_smoke_test'));
        add_action('admin_post_photowooshop_force_update_check', array($this, 'handle_force_update_check'));
        add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_github_plugin_update'));
        add_filter('plugins_api', array($this, 'filter_github_plugin_information'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_github_upgrader_source_dir'), 10, 4);

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('woocommerce_before_add_to_cart_button', array($this, 'add_customize_button'));
        add_action('woocommerce_before_add_to_cart_button', array($this, 'add_audio_consent_checkbox'), 12);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_audio_consent_checkbox'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'get_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_order_item_meta'), 10, 4);

        // AJAX Handlers
        add_action('wp_ajax_photowooshop_save_image', array($this, 'ajax_save_image'));
        add_action('wp_ajax_nopriv_photowooshop_save_image', array($this, 'ajax_save_image'));
        add_action('wp_ajax_photowooshop_upload_audio', array($this, 'ajax_upload_audio'));
        add_action('wp_ajax_nopriv_photowooshop_upload_audio', array($this, 'ajax_upload_audio'));
        add_action('wp_ajax_photowooshop_sync_session', array($this, 'ajax_sync_session'));
        add_action('wp_ajax_nopriv_photowooshop_sync_session', array($this, 'ajax_sync_session'));
        add_action('wp_ajax_photowooshop_get_order_details', array($this, 'ajax_get_order_details'));
        add_action('wp_ajax_photowooshop_download_zip', array($this, 'ajax_download_zip'));
        add_action('wp_ajax_photowooshop_delete_materials', array($this, 'ajax_delete_materials'));
        add_action('wp_ajax_photowooshop_repair_order_materials', array($this, 'ajax_repair_order_materials'));

        // Admin Settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        // CPT & Metaboxes
        add_action('init', array($this, 'register_template_cpt'));
        add_action('add_meta_boxes', array($this, 'add_template_metabox'));
        add_action('save_post_photowooshop_tpl', array($this, 'save_template_data'));

        // Per-Product Settings
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_product_settings_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_settings_field'));

        // Asset loading fallback
        add_action('wp_footer', array($this, 'late_enqueue'), 5);

        // Modal in footer
        add_action('wp_footer', array($this, 'render_modal'));
    }

    public static function activate()
    {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }

        update_option(self::VERSION_OPTION, self::PLUGIN_VERSION, false);
    }

    public static function deactivate()
    {
        $timestamp = wp_next_scheduled(self::CLEANUP_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CLEANUP_HOOK);
        }
    }

    public function ensure_cleanup_schedule()
    {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }

        if (!wp_next_scheduled('photowooshop_daily_index_rebuild')) {
            wp_schedule_event(time() + (2 * HOUR_IN_SECONDS), 'daily', 'photowooshop_daily_index_rebuild');
        }
    }

    public function maybe_run_upgrade()
    {
        $installed_version = get_option(self::VERSION_OPTION, '0.0.0');
        if (version_compare((string) $installed_version, self::PLUGIN_VERSION, '>=')) {
            return;
        }

        if (version_compare((string) $installed_version, '1.1.15', '<')) {
            try {
                $this->migrate_legacy_uploads_to_subdir();
            } catch (Throwable $e) {
                update_option(self::MIGRATION_REPORT_OPTION, array(
                    'status' => 'failed_exception',
                    'started_at' => current_time('mysql'),
                    'finished_at' => current_time('mysql'),
                    'error_message' => sanitize_text_field($e->getMessage()),
                ), false);
            }
        }

        update_option(self::VERSION_OPTION, self::PLUGIN_VERSION, false);
    }

    private function get_latest_github_release($force_refresh = false)
    {
        $cached = get_option(self::UPDATE_CACHE_OPTION, array());
        if (
            !$force_refresh
            && is_array($cached)
            && !empty($cached['checked_at'])
            && isset($cached['data'])
            && (time() - (int) $cached['checked_at']) < (6 * HOUR_IN_SECONDS)
        ) {
            return is_array($cached['data']) ? $cached['data'] : null;
        }

        $response = wp_remote_get(self::GITHUB_LATEST_RELEASE_API, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ),
        ));

        $release_data = null;
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $payload = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($payload) && !empty($payload['tag_name'])) {
                $tag = (string) $payload['tag_name'];
                $release_data = array(
                    'version' => ltrim($tag, 'vV'),
                    'tag' => $tag,
                    'zipball_url' => isset($payload['zipball_url']) ? (string) $payload['zipball_url'] : '',
                    'html_url' => isset($payload['html_url']) ? (string) $payload['html_url'] : ('https://github.com/' . self::GITHUB_REPOSITORY),
                    'published_at' => isset($payload['published_at']) ? (string) $payload['published_at'] : '',
                    'body' => isset($payload['body']) ? (string) $payload['body'] : '',
                    'source' => 'release',
                );
            }
        }

        // Fallback: if Releases are not used yet, use the latest tag.
        if (empty($release_data)) {
            $tags_response = wp_remote_get(self::GITHUB_TAGS_API, array(
                'timeout' => 15,
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                ),
            ));

            if (!is_wp_error($tags_response) && (int) wp_remote_retrieve_response_code($tags_response) === 200) {
                $tags_payload = json_decode(wp_remote_retrieve_body($tags_response), true);
                if (is_array($tags_payload) && !empty($tags_payload[0]['name'])) {
                    $tag = (string) $tags_payload[0]['name'];
                    $release_data = array(
                        'version' => ltrim($tag, 'vV'),
                        'tag' => $tag,
                        'zipball_url' => 'https://github.com/' . self::GITHUB_REPOSITORY . '/archive/refs/tags/' . rawurlencode($tag) . '.zip',
                        'html_url' => 'https://github.com/' . self::GITHUB_REPOSITORY . '/releases/tag/' . rawurlencode($tag),
                        'published_at' => '',
                        'body' => '',
                        'source' => 'tag',
                    );
                }
            }
        }

        if (empty($release_data)) {
            return (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) ? $cached['data'] : null;
        }

        update_option(self::UPDATE_CACHE_OPTION, array(
            'checked_at' => time(),
            'data' => $release_data,
        ), false);

        return $release_data;
    }

    public function inject_github_plugin_update($transient)
    {
        if (empty($transient) || !is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        $release = $this->get_latest_github_release();

        if (empty($release) || empty($release['version'])) {
            return $transient;
        }

        if (version_compare(self::PLUGIN_VERSION, (string) $release['version'], '<')) {
            $package_url = !empty($release['zipball_url'])
                ? $release['zipball_url']
                : ('https://github.com/' . self::GITHUB_REPOSITORY . '/archive/refs/tags/' . rawurlencode((string) $release['tag']) . '.zip');

            $transient->response[$plugin_file] = (object) array(
                'slug' => dirname($plugin_file),
                'plugin' => $plugin_file,
                'new_version' => (string) $release['version'],
                'url' => (string) $release['html_url'],
                'package' => $package_url,
            );
        } elseif (!empty($transient->response[$plugin_file])) {
            unset($transient->response[$plugin_file]);
        }

        return $transient;
    }

    public function filter_github_plugin_information($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug)) {
            return $result;
        }

        $plugin_slug = dirname(plugin_basename(__FILE__));
        if ((string) $args->slug !== (string) $plugin_slug) {
            return $result;
        }

        $release = $this->get_latest_github_release();
        $version = !empty($release['version']) ? (string) $release['version'] : self::PLUGIN_VERSION;
        $homepage = !empty($release['html_url']) ? (string) $release['html_url'] : ('https://github.com/' . self::GITHUB_REPOSITORY);
        $download_link = !empty($release['zipball_url']) ? (string) $release['zipball_url'] : '';
        $changelog = !empty($release['body']) ? nl2br(esc_html((string) $release['body'])) : 'Nincs változásnapló megadva.';

        return (object) array(
            'name' => 'Photowooshop',
            'slug' => $plugin_slug,
            'version' => $version,
            'author' => '<a href="https://www.flodesign.hu">Flodesign</a>',
            'homepage' => $homepage,
            'download_link' => $download_link,
            'requires' => '6.0',
            'tested' => get_bloginfo('version'),
            'sections' => array(
                'description' => 'Photowooshop egyedi montázs készítő WooCommerce termékekhez.',
                'changelog' => $changelog,
            ),
        );
    }

    public function fix_github_upgrader_source_dir($source, $remote_source, $upgrader, $hook_extra)
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
            return $source;
        }

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;
        if (!$wp_filesystem || !is_object($wp_filesystem)) {
            WP_Filesystem();
        }

        if (!$wp_filesystem || !is_object($wp_filesystem)) {
            return $source;
        }

        $expected = trailingslashit($remote_source) . dirname(plugin_basename(__FILE__));
        if ($source === $expected) {
            return $source;
        }

        if ($wp_filesystem->exists($expected)) {
            $wp_filesystem->delete($expected, true);
        }

        $moved = $wp_filesystem->move($source, $expected, true);
        return $moved ? $expected : $source;
    }

    public function add_product_settings_field()
    {
        woocommerce_wp_checkbox(array(
            'id' => '_photowooshop_enabled',
            'label' => __('Photowooshop Montázs Engedélyezése', 'photowooshop'),
            'description' => __('Engedélyezi az egyedi montázs tervezőt ehhez a termékhez.', 'photowooshop'),
            'desc_tip' => true,
        ));

        // Get all templates
        $templates = get_posts(array(
            'post_type' => 'photowooshop_tpl',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));
        $tpl_options = array('' => 'Válassz sablont...');
        foreach ($templates as $tpl) {
            $tpl_options[$tpl->ID] = $tpl->post_title;
        }

        woocommerce_wp_select(array(
            'id' => '_photowooshop_tpl_id',
            'label' => __('Választott Sablon', 'photowooshop'),
            'options' => $tpl_options,
            'description' => __('Válaszd ki az előre megalkotott sablont.', 'photowooshop'),
            'desc_tip' => true,
        ));

        woocommerce_wp_checkbox(array(
            'id' => '_photowooshop_audio_enabled',
            'label' => __('Hangfájl Feltöltés Engedélyezése', 'photowooshop'),
            'description' => __('Lehetővé teszi a vásárlónak hangfájl csatolását.', 'photowooshop'),
            'desc_tip' => true,
        ));
    }


    public function save_product_settings_field($product_id)
    {
        $enabled = isset($_POST['_photowooshop_enabled']) ? 'yes' : 'no';
        update_post_meta($product_id, '_photowooshop_enabled', $enabled);

        if (isset($_POST['_photowooshop_tpl_id'])) {
            update_post_meta($product_id, '_photowooshop_tpl_id', sanitize_text_field($_POST['_photowooshop_tpl_id']));
        }

        $audio_enabled = isset($_POST['_photowooshop_audio_enabled']) ? 'yes' : 'no';
        update_post_meta($product_id, '_photowooshop_audio_enabled', $audio_enabled);
    }

    public function register_template_cpt()
    {
        register_post_type('photowooshop_tpl', array(
            'labels' => array(
                'name' => 'Montázs Sablonok',
                'singular_name' => 'Sablon',
                'add_new' => 'Új Sablon',
                'add_new_item' => 'Új Sablon Hozzáadása',
                'edit_item' => 'Sablon Szerkesztése'
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'photowooshop-main',
            'supports' => array('title'),
            'menu_icon' => 'dashicons-layout'
        ));
    }

    public function add_template_metabox()
    {
        add_meta_box('photowooshop_tpl_editor', 'Sablon Szerkesztő', array($this, 'render_tpl_editor_metabox'), 'photowooshop_tpl', 'normal', 'high');
    }

    public function render_tpl_editor_metabox($post)
    {
        wp_nonce_field('photowooshop_tpl_save', 'photowooshop_tpl_nonce');
        $bg_url = get_post_meta($post->ID, '_photowooshop_bg_url', true);
        $slots_json = get_post_meta($post->ID, '_photowooshop_slots_json', true);
        ?>
        <div id="photowooshop-admin-editor">
            <div class="tpl-toolbar">
                <input type="text" id="_photowooshop_bg_url" name="_photowooshop_bg_url"
                    value="<?php echo esc_attr($bg_url); ?>" placeholder="Háttérkép URL" style="width: 70%;">
                <button type="button" class="button photowooshop-browse-media">Tallózás</button>
                <button type="button" id="add-slot-btn" class="button button-primary">Új Képhely Hozzáadása</button>
            </div>
            <div id="tpl-workspace-wrapper"
                style="margin-top: 20px; background: #eee; position: relative; min-height: 400px; display: flex; justify-content: center; align-items: stretch; gap: 16px; overflow: hidden; padding: 12px;">
                <div id="tpl-workspace" style="position: relative; background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.1);">
                    <!-- Background and slots will go here -->
                    <div id="tpl-text" class="tpl-text-placeholder"
                        style="position: absolute; display: none; transform: translateX(-50%); cursor: move; background: rgba(255, 193, 7, 0.5); border: 2px dashed #ffc107; padding: 5px; color: #000; font-weight: bold; font-size: 14px; white-space: nowrap; z-index: 100;">
                        [Egyedi Szöveg Helye]
                    </div>
                </div>
                <div id="tpl-layer-panel" style="width: 300px; min-width: 260px; background:#fff; border:1px solid #ddd; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.06); display:flex; flex-direction:column;">
                    <div style="padding:12px 12px 8px; border-bottom:1px solid #eee;">
                        <h4 style="margin:0; font-size:14px;">Rétegek</h4>
                        <p style="margin:6px 0 0; color:#666; font-size:12px;">Fogd meg és húzd fel/le. Dupla katt név szerkesztéshez.</p>
                    </div>
                    <div id="tpl-layer-list" style="padding:8px; overflow:auto; max-height:560px;"></div>
                </div>
            </div>
            <input type="hidden" id="_photowooshop_slots_json" name="_photowooshop_slots_json"
                value="<?php echo esc_attr($slots_json); ?>">

            <div class="tpl-settings" style="margin-top:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd;">
                <h4>Képhelyek Beállításai</h4>
                <p style="margin: 0 0 10px; color:#666;">A képhelyek sarok lekerekítése és rétegsorrendje itt állítható.</p>
                <div id="image-slots-container"></div>
            </div>

            <div class="tpl-settings" style="margin-top:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd;">
                <h4>Egyedi Szöveg Beállítások</h4>
                <button type="button" id="add-text-btn" class="button button-secondary" style="margin-bottom:15px;">+ Új Szöveg
                    Hozzáadása</button>
                <div id="text-slots-container"></div>
                <input type="hidden" id="_photowooshop_text_slots_json" name="_photowooshop_text_slots_json"
                    value="<?php echo esc_attr(get_post_meta($post->ID, '_photowooshop_text_slots_json', true) ?: '[]'); ?>">
            </div>

            <div class="tpl-settings" style="margin-top:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd;">
                <h4>Alakzatok Beállításai</h4>
                <button type="button" id="add-shape-btn" class="button button-secondary" style="margin-bottom:15px;">+ Új Alakzat
                    Hozzáadása</button>
                <p style="margin: 0 0 10px; color:#666;">Tipp: helyezzen alakzatot a szöveg mögé áttetsző háttérhez.</p>
                <div id="shape-slots-container"></div>
                <input type="hidden" id="_photowooshop_shape_slots_json" name="_photowooshop_shape_slots_json"
                    value="<?php echo esc_attr(get_post_meta($post->ID, '_photowooshop_shape_slots_json', true) ?: '[]'); ?>">
            </div>

            <h4>További Mockupok (3 db automatikusan generált kép)</h4>
            <div class="mockup-design-settings">
                <?php for ($i = 1; $i <= 3; $i++):
                    $m_url = get_post_meta($post->ID, "_photowooshop_mockup_{$i}_url", true);
                    $points = array(
                        'tl' => get_post_meta($post->ID, "_photowooshop_mockup_{$i}_tl", true) ?: '10,10',
                        'tr' => get_post_meta($post->ID, "_photowooshop_mockup_{$i}_tr", true) ?: '90,10',
                        'bl' => get_post_meta($post->ID, "_photowooshop_mockup_{$i}_bl", true) ?: '10,90',
                        'br' => get_post_meta($post->ID, "_photowooshop_mockup_{$i}_br", true) ?: '90,90',
                    );
                    ?>
                    <div class="mockup-row" style="margin-bottom: 15px; padding: 10px; border: 1px dashed #ccc;">
                        <strong>Mockup <?php echo $i; ?> (Perspektíva)</strong><br>
                        <label>Háttér:</label> <input type="text" id="m_url_<?php echo $i; ?>"
                            name="_photowooshop_mockup_<?php echo $i; ?>_url" value="<?php echo esc_attr($m_url); ?>"
                            style="width:40%;">
                        <button type="button" class="button photowooshop-browse-mockup"
                            data-target="m_url_<?php echo $i; ?>">Tallózás</button>
                        <button type="button" class="button mockup-visual-btn" data-index="<?php echo $i; ?>"
                            style="background:#007cba; color:#fff;">Perspektíva beállítás</button>
                        <div class="mockup-coords" style="margin-top:5px; font-size:11px;">
                            TL: <input type="text" name="_photowooshop_mockup_<?php echo $i; ?>_tl" id="m_tl_<?php echo $i; ?>"
                                value="<?php echo esc_attr($points['tl']); ?>" style="width:60px;">
                            TR: <input type="text" name="_photowooshop_mockup_<?php echo $i; ?>_tr" id="m_tr_<?php echo $i; ?>"
                                value="<?php echo esc_attr($points['tr']); ?>" style="width:60px;">
                            BL: <input type="text" name="_photowooshop_mockup_<?php echo $i; ?>_bl" id="m_bl_<?php echo $i; ?>"
                                value="<?php echo esc_attr($points['bl']); ?>" style="width:60px;">
                            BR: <input type="text" name="_photowooshop_mockup_<?php echo $i; ?>_br" id="m_br_<?php echo $i; ?>"
                                value="<?php echo esc_attr($points['br']); ?>" style="width:60px;">
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        </div>
        <style>
            #tpl-workspace .tpl-slot {
                background: rgba(98, 0, 238, 0.3);
                border: 2px solid #6200ee;
                position: absolute;
                cursor: move;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-weight: bold;
                font-size: 10px;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
            }

            #tpl-workspace .tpl-slot .remove-slot {
                position: absolute;
                top: -10px;
                right: -10px;
                background: red;
                color: white;
                border-radius: 50%;
                width: 20px;
                height: 20px;
                cursor: pointer;
                text-align: center;
                line-height: normal;
                display: none;
            }

            #tpl-workspace .tpl-slot:hover .remove-slot {
                display: block;
            }

            #tpl-workspace .mockup-handle {
                position: absolute;
                width: 24px;
                height: 24px;
                background: #ff5722;
                border: 3px solid #fff;
                border-radius: 50%;
                transform: translate(-50%, -50%);
                cursor: pointer;
                z-index: 100 !important;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
            }

            #tpl-workspace .mockup-handle:hover {
                transform: translate(-50%, -50%) scale(1.2);
            }

            #mockup-preview-canvas {
                pointer-events: none;
            }

            #tpl-layer-panel .layer-item {
                display: block;
                padding: 8px;
                border: 1px solid #e7e7e7;
                border-radius: 6px;
                margin-bottom: 8px;
                background: #fafafa;
                cursor: grab;
                width: 100%;
                box-sizing: border-box;
            }

            #tpl-layer-panel .layer-item-header {
                display: flex;
                align-items: center;
                gap: 8px;
                width: 100%;
                box-sizing: border-box;
            }

            #tpl-layer-panel .layer-item-body {
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid #ececec;
                width: 100%;
                box-sizing: border-box;
            }

            #tpl-layer-panel .layer-item.dragging {
                opacity: 0.45;
            }

            #tpl-layer-panel .layer-handle {
                color: #777;
                font-size: 15px;
                line-height: 1;
                user-select: none;
            }

            #tpl-layer-panel .layer-type {
                font-size: 11px;
                font-weight: 700;
                border-radius: 999px;
                padding: 2px 7px;
                background: #ececec;
                white-space: nowrap;
            }

            #tpl-layer-panel .layer-type.layer-image { background: #eaf2ff; color: #0b57d0; }
            #tpl-layer-panel .layer-type.layer-text { background: #fff3e8; color: #b54708; }
            #tpl-layer-panel .layer-type.layer-shape { background: #ecfdf3; color: #067647; }

            #tpl-layer-panel .layer-name-input {
                width: 100%;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 4px 6px;
                font-size: 12px;
            }

            #tpl-layer-panel .layer-z {
                font-size: 11px;
                color: #666;
                white-space: nowrap;
            }

            @media (max-width: 1200px) {
                #tpl-workspace-wrapper {
                    flex-direction: column;
                }

                #tpl-layer-panel {
                    width: 100% !important;
                    min-width: 0 !important;
                }
            }
        </style>
        <?php
    }

    public function save_template_data($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        if (!isset($_POST['photowooshop_tpl_nonce']) || !wp_verify_nonce($_POST['photowooshop_tpl_nonce'], 'photowooshop_tpl_save')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['_photowooshop_bg_url'])) {
            update_post_meta($post_id, '_photowooshop_bg_url', sanitize_text_field($_POST['_photowooshop_bg_url']));
        }
        if (isset($_POST['_photowooshop_slots_json'])) {
            update_post_meta($post_id, '_photowooshop_slots_json', $_POST['_photowooshop_slots_json']);
        }
        if (isset($_POST['_photowooshop_text_slots_json'])) {
            update_post_meta($post_id, '_photowooshop_text_slots_json', $_POST['_photowooshop_text_slots_json']);
        }
        if (isset($_POST['_photowooshop_shape_slots_json'])) {
            update_post_meta($post_id, '_photowooshop_shape_slots_json', $_POST['_photowooshop_shape_slots_json']);
        }

        for ($i = 1; $i <= 3; $i++) {
            if (isset($_POST["_photowooshop_mockup_{$i}_url"])) {
                update_post_meta($post_id, "_photowooshop_mockup_{$i}_url", sanitize_text_field($_POST["_photowooshop_mockup_{$i}_url"]));
            }
            if (isset($_POST["_photowooshop_mockup_{$i}_tl"])) {
                update_post_meta($post_id, "_photowooshop_mockup_{$i}_tl", sanitize_text_field($_POST["_photowooshop_mockup_{$i}_tl"]));
            }
            if (isset($_POST["_photowooshop_mockup_{$i}_tr"])) {
                update_post_meta($post_id, "_photowooshop_mockup_{$i}_tr", sanitize_text_field($_POST["_photowooshop_mockup_{$i}_tr"]));
            }
            if (isset($_POST["_photowooshop_mockup_{$i}_bl"])) {
                update_post_meta($post_id, "_photowooshop_mockup_{$i}_bl", sanitize_text_field($_POST["_photowooshop_mockup_{$i}_bl"]));
            }
            if (isset($_POST["_photowooshop_mockup_{$i}_br"])) {
                update_post_meta($post_id, "_photowooshop_mockup_{$i}_br", sanitize_text_field($_POST["_photowooshop_mockup_{$i}_br"]));
            }
        }
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'Photowooshop',
            'Photowooshop',
            'manage_options',
            'photowooshop-main',
            array($this, 'materials_page_html'),
            'dashicons-format-gallery',
            56
        );

        add_submenu_page(
            'photowooshop-main',
            'Rendelés anyagai',
            'Rendelés anyagai',
            'manage_options',
            'photowooshop-materials',
            array($this, 'materials_page_html')
        );

        add_submenu_page(
            'photowooshop-main',
            'Beállítások',
            'Beállítások',
            'manage_options',
            'photowooshop',
            array($this, 'settings_page_html')
        );

        add_submenu_page(
            'photowooshop-main',
            'Súgó',
            'Súgó',
            'manage_options',
            'photowooshop-help',
            array($this, 'help_page_html')
        );

        // Hide the automatic duplicate first submenu entry (Photowooshop -> Photowooshop).
        remove_submenu_page('photowooshop-main', 'photowooshop-main');
    }

    public function register_settings()
    {
        register_setting('photowooshop_settings_group', 'photowooshop_enabled_globally');
        register_setting('photowooshop_settings_group', 'photowooshop_button_text');
        register_setting('photowooshop_settings_group', 'photowooshop_audio_consent_enabled', array(
            'sanitize_callback' => array($this, 'sanitize_checkbox_setting')
        ));
        register_setting('photowooshop_settings_group', 'photowooshop_audio_consent_text', array(
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('photowooshop_settings_group', self::SAFE_MODE_OPTION, array(
            'sanitize_callback' => array($this, 'sanitize_checkbox_setting')
        ));
    }

    public function sanitize_checkbox_setting($value)
    {
        return $value === 'yes' ? 'yes' : 'no';
    }

    private function is_safe_mode_enabled()
    {
        return get_option(self::SAFE_MODE_OPTION, 'yes') === 'yes';
    }

    private function get_client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return sanitize_text_field($ip);
    }

    private function check_rate_limit($action_key, $max_requests, $window_seconds)
    {
        $ip = $this->get_client_ip();
        $transient_key = 'pws_rl_' . md5($action_key . '|' . $ip);
        $count = (int) get_transient($transient_key);

        if ($count >= $max_requests) {
            return false;
        }

        set_transient($transient_key, $count + 1, $window_seconds);
        return true;
    }

    private function get_or_create_upload_token($product_id)
    {
        return wp_create_nonce('photowooshop_upload_' . (int) $product_id);
    }

    private function verify_upload_token($product_id, $token)
    {
        return (bool) wp_verify_nonce($token, 'photowooshop_upload_' . (int) $product_id);
    }

    private function get_upload_subdir()
    {
        return '/' . trim(self::UPLOAD_SUBDIR, '/');
    }

    public function filter_photowooshop_upload_dir($dirs)
    {
        $subdir = $this->get_upload_subdir();
        $dirs['subdir'] = $subdir;
        $dirs['path'] = $dirs['basedir'] . $subdir;
        $dirs['url'] = $dirs['baseurl'] . $subdir;

        return $dirs;
    }

    private function run_with_photowooshop_upload_dir($callback)
    {
        add_filter('upload_dir', array($this, 'filter_photowooshop_upload_dir'));
        $result = call_user_func($callback);
        remove_filter('upload_dir', array($this, 'filter_photowooshop_upload_dir'));

        return $result;
    }

    private function is_photowooshop_legacy_file($filename)
    {
        return strpos($filename, 'photowooshop_') === 0 || strpos($filename, 'photowooshop_audio_') === 0;
    }

    private function migrate_legacy_uploads_to_subdir()
    {
        $report = array(
            'status' => 'started',
            'started_at' => current_time('mysql'),
            'finished_at' => '',
            'scanned_files' => 0,
            'matched_legacy_prefix' => 0,
            'moved_files' => 0,
            'move_errors' => 0,
            'rewritten_urls' => 0,
        );

        $uploads = wp_upload_dir();
        if (empty($uploads['basedir']) || !is_dir($uploads['basedir'])) {
            $report['status'] = 'skipped_upload_dir_missing';
            $report['finished_at'] = current_time('mysql');
            update_option(self::MIGRATION_REPORT_OPTION, $report, false);
            return;
        }

        $target_dir = trailingslashit($uploads['basedir']) . self::UPLOAD_SUBDIR;
        if (!wp_mkdir_p($target_dir)) {
            $report['status'] = 'failed_cannot_create_target_dir';
            $report['finished_at'] = current_time('mysql');
            update_option(self::MIGRATION_REPORT_OPTION, $report, false);
            return;
        }

        $target_dir_normalized = wp_normalize_path($target_dir);
        $base_url = trailingslashit($uploads['baseurl']);
        $moved_map = array();

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads['basedir'], FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $report['scanned_files']++;

                $file_path = $file->getPathname();
                $file_path_normalized = wp_normalize_path($file_path);
                if (strpos($file_path_normalized, $target_dir_normalized . '/') === 0) {
                    continue;
                }

                $filename = $file->getFilename();
                if (!$this->is_photowooshop_legacy_file($filename)) {
                    continue;
                }

                $report['matched_legacy_prefix']++;

                $unique_name = wp_unique_filename($target_dir, $filename);
                $destination = trailingslashit($target_dir) . $unique_name;

                $moved = @rename($file_path, $destination);
                if (!$moved) {
                    $moved = @copy($file_path, $destination);
                    if ($moved) {
                        @unlink($file_path);
                    }
                }

                if (!$moved) {
                    $report['move_errors']++;
                    continue;
                }

                $report['moved_files']++;

                $old_url = $this->build_upload_url_from_path($file_path, $uploads);
                $new_url = $base_url . self::UPLOAD_SUBDIR . '/' . $unique_name;
                if (!empty($old_url)) {
                    $moved_map[$old_url] = $new_url;
                }
            }
        } catch (Throwable $e) {
            $report['status'] = 'failed_scan_exception';
            $report['error_message'] = sanitize_text_field($e->getMessage());
            $report['finished_at'] = current_time('mysql');
            update_option(self::MIGRATION_REPORT_OPTION, $report, false);
            return;
        }

        if (!empty($moved_map)) {
            $report['rewritten_urls'] = $this->rewrite_order_material_urls($moved_map);
        }

        $report['status'] = 'completed';
        $report['finished_at'] = current_time('mysql');
        update_option(self::MIGRATION_REPORT_OPTION, $report, false);
    }

    private function rewrite_order_material_urls($url_map)
    {
        if (!function_exists('wc_get_orders') || empty($url_map)) {
            return 0;
        }

        $rewrite_count = 0;
        $statuses = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed');
        $offset = 0;
        $chunk_size = 100;

        while (true) {
            $orders = wc_get_orders(array(
                'limit' => $chunk_size,
                'offset' => $offset,
                'status' => $statuses,
                'orderby' => 'date',
                'order' => 'DESC',
            ));

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    $item_changed = false;

                    $single_url_keys = array(
                        '_photowooshop_montage_url',
                        'Egyedi Montázs URL',
                        '_photowooshop_audio_url',
                        'Hangfájl',
                    );

                    foreach ($single_url_keys as $key) {
                        $old = $item->get_meta($key, true);
                        if (!empty($old) && isset($url_map[$old])) {
                            $item->update_meta_data($key, $url_map[$old]);
                            $item_changed = true;
                            $rewrite_count++;
                        }
                    }

                    $json_urls = $item->get_meta('_photowooshop_individual_images', true);
                    if (!empty($json_urls)) {
                        $decoded = json_decode($json_urls, true);
                        if (is_array($decoded)) {
                            $updated = false;
                            foreach ($decoded as $idx => $url) {
                                if (!empty($url) && isset($url_map[$url])) {
                                    $decoded[$idx] = $url_map[$url];
                                    $updated = true;
                                    $rewrite_count++;
                                }
                            }
                            if ($updated) {
                                $item->update_meta_data('_photowooshop_individual_images', wp_json_encode($decoded));
                                $item_changed = true;
                            }
                        }
                    }

                    if ($item_changed) {
                        $item->save();
                    }
                }
            }

            if (count($orders) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }

        return $rewrite_count;
    }

    private function validate_audio_upload_file($file)
    {
        if (empty($file) || !is_array($file) || empty($file['name']) || empty($file['tmp_name'])) {
            return new WP_Error('invalid_audio', 'Érvénytelen hangfájl.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('invalid_audio_source', 'A feltöltés forrása érvénytelen.');
        }

        $allowed_ext_map = array(
            'mp3' => array('audio/mpeg', 'audio/mp3'),
            'wav' => array('audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'),
            'ogg' => array('audio/ogg', 'application/ogg'),
            'm4a' => array('audio/mp4', 'video/mp4', 'audio/x-m4a'),
            'aac' => array('audio/aac', 'audio/x-aac'),
        );

        $detected = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $ext = !empty($detected['ext']) ? strtolower($detected['ext']) : '';

        if (empty($ext) || !isset($allowed_ext_map[$ext])) {
            return new WP_Error('invalid_audio_ext', 'Nem támogatott hangfájl formátum.');
        }

        if (!empty($detected['type']) && !in_array(strtolower($detected['type']), $allowed_ext_map[$ext], true)) {
            return new WP_Error('invalid_audio_mime', 'A hangfájl MIME típusa érvénytelen.');
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $real_mime = @finfo_file($finfo, $file['tmp_name']);
                @finfo_close($finfo);
                if (!empty($real_mime) && !in_array(strtolower($real_mime), $allowed_ext_map[$ext], true)) {
                    return new WP_Error('invalid_audio_signature', 'A hangfájl tartalma nem egyezik a kiterjesztéssel.');
                }
            }
        }

        return true;
    }

    private function get_audio_consent_text()
    {
        $default_text = 'Engedélyezem a feltöltött hangfájl használatát a termék elkészítéséhez.';
        $text = get_option('photowooshop_audio_consent_text', $default_text);

        return !empty($text) ? $text : $default_text;
    }

    private function is_photowooshop_enabled_for_product($product_id)
    {
        $product_enabled = get_post_meta($product_id, '_photowooshop_enabled', true);
        $global_enabled = get_option('photowooshop_enabled_globally');

        if ($product_enabled === 'no') {
            return false;
        }

        if ($product_enabled !== 'yes' && !$global_enabled) {
            return false;
        }

        return true;
    }

    private function is_audio_enabled_for_product($product_id, $variation_id = 0)
    {
        if ($variation_id) {
            $variation_audio_enabled = get_post_meta($variation_id, '_photowooshop_audio_enabled', true);
            if ($variation_audio_enabled === 'yes') {
                return true;
            }
            if ($variation_audio_enabled === 'no') {
                return false;
            }
        }

        return get_post_meta($product_id, '_photowooshop_audio_enabled', true) === 'yes';
    }

    private function is_audio_consent_feature_enabled()
    {
        return get_option('photowooshop_audio_consent_enabled', 'yes') === 'yes';
    }

    public function settings_page_html()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $global_enabled = get_option('photowooshop_enabled_globally', '');
        $button_text = get_option('photowooshop_button_text', 'Egyedi montázs tervezése');
        $audio_consent_enabled = get_option('photowooshop_audio_consent_enabled', 'yes');
        $audio_consent_text = $this->get_audio_consent_text();
        $safe_mode_enabled = get_option(self::SAFE_MODE_OPTION, 'yes');
        $cleanup_report = get_option(self::CLEANUP_REPORT_OPTION, array());
        $migration_report = get_option(self::MIGRATION_REPORT_OPTION, array());
        $index_report = get_option(self::INDEX_REPORT_OPTION, array());
        $global_repair_report = get_option(self::GLOBAL_REPAIR_REPORT_OPTION, array());
        $smoke_report = get_option(self::SMOKE_REPORT_OPTION, array());
        $index_cache = get_option(self::INDEX_CACHE_OPTION, array());
        $cleanup_ran = isset($_GET['photowooshop_cleanup_ran']) && $_GET['photowooshop_cleanup_ran'] === '1';
        $update_check_ran = isset($_GET['photowooshop_update_check_ran']) && $_GET['photowooshop_update_check_ran'] === '1';
        $update_check_release = $update_check_ran ? $this->get_latest_github_release(false) : null;
        $cached_update_data = get_option(self::UPDATE_CACHE_OPTION, array());
        $index_ran = isset($_GET['photowooshop_index_ran']) && $_GET['photowooshop_index_ran'] === '1';
        $global_repair_ran = isset($_GET['photowooshop_global_repair_ran']) && $_GET['photowooshop_global_repair_ran'] === '1';
        $smoke_ran = isset($_GET['photowooshop_smoke_ran']) && $_GET['photowooshop_smoke_ran'] === '1';
        ?>
        <div class="wrap">
            <h1>Photowooshop Beállítások</h1>
            <?php if ($cleanup_ran): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Az automatikus takarítás manuálisan lefutott.</p>
                </div>
            <?php endif; ?>
            <?php if ($update_check_ran): ?>
                <div class="notice notice-success is-dismissible">
                    <p>GitHub frissítés cache törölve és újraellenőrizve.</p>
                </div>
            <?php endif; ?>
            <?php if ($index_ran): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Az anyag-index újraépítése lefutott.</p>
                </div>
            <?php endif; ?>
            <?php if ($global_repair_ran): ?>
                <div class="notice notice-success is-dismissible">
                    <p>A globális javítás lefutott.</p>
                </div>
            <?php endif; ?>
            <?php if ($smoke_ran): ?>
                <div class="notice notice-success is-dismissible">
                    <p>A smoke teszt lefutott.</p>
                </div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('photowooshop_settings_group'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Photowooshop Globális Engedélyezés</th>
                        <td>
                            <label>
                                <input type="hidden" name="photowooshop_enabled_globally" value="0">
                                <input type="checkbox" name="photowooshop_enabled_globally" value="1" <?php checked($global_enabled, '1'); ?>>
                                Minden terméken engedélyezett legyen alapból
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Szerkesztő gomb szövege</th>
                        <td>
                            <input type="text" name="photowooshop_button_text" value="<?php echo esc_attr($button_text); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hangfájl hozzájáruló checkbox</th>
                        <td>
                            <label>
                                <input type="hidden" name="photowooshop_audio_consent_enabled" value="no">
                                <input type="checkbox" name="photowooshop_audio_consent_enabled" value="yes" <?php checked($audio_consent_enabled, 'yes'); ?>>
                                Kötelező checkbox megjelenítése, ha hangfájl feltöltés engedélyezett
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hozzájáruló checkbox szövege</th>
                        <td>
                            <textarea name="photowooshop_audio_consent_text" rows="3" class="large-text"><?php echo esc_textarea($audio_consent_text); ?></textarea>
                            <p class="description">Ez a szöveg jelenik meg a kötelező checkbox mellett.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Safe mode (stabilitási mód)</th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr(self::SAFE_MODE_OPTION); ?>" value="no">
                                <input type="checkbox" name="<?php echo esc_attr(self::SAFE_MODE_OPTION); ?>" value="yes" <?php checked($safe_mode_enabled, 'yes'); ?>>
                                Stabilabb, korlátozott lekérdezési mód az admin anyaglistán
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2 style="margin-top:24px;">Automatikus Takarítás Állapota</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 10px 0 14px;">
                <input type="hidden" name="action" value="photowooshop_run_cleanup">
                <?php wp_nonce_field('photowooshop_run_cleanup', 'photowooshop_run_cleanup_nonce'); ?>
                <button type="submit" class="button button-secondary">Takarítás most</button>
            </form>
            <?php if (!empty($cleanup_report) && is_array($cleanup_report)): ?>
                <table class="widefat striped" style="max-width: 760px;">
                    <tbody>
                        <tr>
                            <td><strong>Utolsó futás</strong></td>
                            <td><?php echo !empty($cleanup_report['finished_at']) ? esc_html($cleanup_report['finished_at']) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Állapot</strong></td>
                            <td><?php echo !empty($cleanup_report['status']) ? esc_html($cleanup_report['status']) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Átvizsgált fájlok</strong></td>
                            <td><?php echo isset($cleanup_report['scanned_files']) ? esc_html((string) $cleanup_report['scanned_files']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Prefix alapján releváns</strong></td>
                            <td><?php echo isset($cleanup_report['matched_prefix']) ? esc_html((string) $cleanup_report['matched_prefix']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Korhatárnál régebbi</strong></td>
                            <td><?php echo isset($cleanup_report['older_than_cutoff']) ? esc_html((string) $cleanup_report['older_than_cutoff']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Hivatkozás miatt megtartva</strong></td>
                            <td><?php echo isset($cleanup_report['referenced_skipped']) ? esc_html((string) $cleanup_report['referenced_skipped']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Törölt fájlok</strong></td>
                            <td><?php echo isset($cleanup_report['deleted_files']) ? esc_html((string) $cleanup_report['deleted_files']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Törlési hibák</strong></td>
                            <td><?php echo isset($cleanup_report['delete_errors']) ? esc_html((string) $cleanup_report['delete_errors']) : '0'; ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Még nem futott le automatikus takarítás.</p>
            <?php endif; ?>

            <h2 style="margin-top:24px;">Frissítési Migráció Állapota</h2>
            <?php if (!empty($migration_report) && is_array($migration_report)): ?>
                <table class="widefat striped" style="max-width: 760px;">
                    <tbody>
                        <tr>
                            <td><strong>Utolsó futás</strong></td>
                            <td><?php echo !empty($migration_report['finished_at']) ? esc_html($migration_report['finished_at']) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Állapot</strong></td>
                            <td><?php echo !empty($migration_report['status']) ? esc_html($migration_report['status']) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Átvizsgált fájlok</strong></td>
                            <td><?php echo isset($migration_report['scanned_files']) ? esc_html((string) $migration_report['scanned_files']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Legacy prefix alapján releváns</strong></td>
                            <td><?php echo isset($migration_report['matched_legacy_prefix']) ? esc_html((string) $migration_report['matched_legacy_prefix']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Átmozgatott fájlok</strong></td>
                            <td><?php echo isset($migration_report['moved_files']) ? esc_html((string) $migration_report['moved_files']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Átírt rendelés URL-ek</strong></td>
                            <td><?php echo isset($migration_report['rewritten_urls']) ? esc_html((string) $migration_report['rewritten_urls']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Mozgatási hibák</strong></td>
                            <td><?php echo isset($migration_report['move_errors']) ? esc_html((string) $migration_report['move_errors']) : '0'; ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Migrációs futás még nem történt.</p>
            <?php endif; ?>

            <h2 style="margin-top:24px;">Gyors Changelog (1.1.17 - 1.1.25)</h2>
            <table class="widefat striped" style="max-width: 760px;">
                <tbody>
                    <tr><td><strong>1.1.17</strong></td><td>Anyaglista teljesítmény hotfix (500 hiba csökkentése).</td></tr>
                    <tr><td><strong>1.1.18</strong></td><td>Biztonságos lapozás egyszerusites az admin listahoz.</td></tr>
                    <tr><td><strong>1.1.19</strong></td><td>Konzisztensebb listaszamlalas es lapozasi viselkedes.</td></tr>
                    <tr><td><strong>1.1.20</strong></td><td>Migralt fajlok URL-feloldasa (fallback), anyagok jobb megtalalasa.</td></tr>
                    <tr><td><strong>1.1.21</strong></td><td>Kompatibilitasi/fail-safe vedelmek admin oldalon.</td></tr>
                    <tr><td><strong>1.1.22</strong></td><td>Safe mode lista: csak konnyu lekerdezes a stabilitasert.</td></tr>
                    <tr><td><strong>1.1.23</strong></td><td>Fajlnev fallback kereses migralt allomanyokra.</td></tr>
                    <tr><td><strong>1.1.24</strong></td><td>Modal ures-allapot uzenet es anyagmeta szures javitas.</td></tr>
                    <tr><td><strong>1.1.25</strong></td><td>Kritikus syntax javitas, stabilitasi korrekcio.</td></tr>
                </tbody>
            </table>

            <h2 style="margin-top:24px;">Diagnosztika</h2>
            <table class="widefat striped" style="max-width: 760px;">
                <tbody>
                    <tr>
                        <td><strong>Plugin verzió</strong></td>
                        <td><?php echo esc_html(self::PLUGIN_VERSION); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Környezet</strong></td>
                        <td><?php echo esc_html(function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Safe mode</strong></td>
                        <td><?php echo $this->is_safe_mode_enabled() ? 'BE' : 'KI'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Index cache elemek</strong></td>
                        <td><?php echo isset($index_cache['total_indexed']) ? esc_html((string) $index_cache['total_indexed']) : '0'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Index cache ideje</strong></td>
                        <td><?php echo !empty($index_cache['generated_at']) ? esc_html($index_cache['generated_at']) : 'N/A'; ?></td>
                    </tr>
                    <?php
                    $uc = is_array($cached_update_data) ? $cached_update_data : array();
                    $github_version = !empty($uc['data']['version']) ? esc_html($uc['data']['version']) : 'N/A (cache üres)';
                    $github_source  = !empty($uc['data']['source'])  ? esc_html($uc['data']['source'])  : '-';
                    $github_checked = !empty($uc['checked_at']) ? esc_html(date('Y-m-d H:i:s', (int)$uc['checked_at'])) : 'Még nem ellenőrzött';
                    $github_age_min = !empty($uc['checked_at']) ? round((time() - (int)$uc['checked_at']) / 60) : null;
                    $needs_update   = !empty($uc['data']['version']) && version_compare(self::PLUGIN_VERSION, $uc['data']['version'], '<');
                    ?>
                    <tr>
                        <td><strong>GitHub legújabb verzió</strong></td>
                        <td><?php echo $github_version; ?> <small style="color:#888">(forrás: <?php echo $github_source; ?>)</small></td>
                    </tr>
                    <tr>
                        <td><strong>GitHub API lekérve</strong></td>
                        <td><?php echo $github_checked; ?><?php if ($github_age_min !== null): ?> <small style="color:#888">(<?php echo esc_html((string)$github_age_min); ?> perce)</small><?php endif; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Frissítés szükséges?</strong></td>
                        <td><?php if (!empty($uc['data']['version'])): echo $needs_update ? '<span style="color:green;font-weight:bold">IGEN – WP frissítésértesítő aktív</span>' : '<span style="color:#888">Nem – verziók egyenlők vagy GitHub régebbi</span>'; else: echo '<span style="color:orange">Cache üres – nyomj Force check-et</span>'; endif; ?></td>
                    </tr>
                </tbody>
            </table>

            <h2 style="margin-top:24px;">Karbantartás</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 10px 0 8px; display:flex; gap:8px; flex-wrap:wrap;">
                <input type="hidden" name="action" value="photowooshop_force_update_check">
                <?php wp_nonce_field('photowooshop_force_update_check', 'photowooshop_force_update_check_nonce'); ?>
                <button type="submit" class="button button-primary">Force update check (GitHub cache törlés)</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 10px 0 8px; display:flex; gap:8px; flex-wrap:wrap;">
                <input type="hidden" name="action" value="photowooshop_rebuild_index">
                <?php wp_nonce_field('photowooshop_rebuild_index', 'photowooshop_rebuild_index_nonce'); ?>
                <button type="submit" class="button">Index újraépítés</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0 0 8px; display:flex; gap:8px; flex-wrap:wrap;">
                <input type="hidden" name="action" value="photowooshop_run_global_repair">
                <?php wp_nonce_field('photowooshop_run_global_repair', 'photowooshop_run_global_repair_nonce'); ?>
                <button type="submit" class="button">Globális javítás (batch)</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0 0 14px; display:flex; gap:8px; flex-wrap:wrap;">
                <input type="hidden" name="action" value="photowooshop_run_smoke_test">
                <?php wp_nonce_field('photowooshop_run_smoke_test', 'photowooshop_run_smoke_test_nonce'); ?>
                <button type="submit" class="button">Smoke teszt futtatás</button>
            </form>

            <?php if (!empty($index_report) && is_array($index_report)): ?>
                <p><strong>Utolsó index rebuild:</strong> <?php echo !empty($index_report['finished_at']) ? esc_html($index_report['finished_at']) : 'N/A'; ?> (<?php echo !empty($index_report['status']) ? esc_html($index_report['status']) : 'N/A'; ?>)</p>
            <?php endif; ?>
            <?php if (!empty($global_repair_report) && is_array($global_repair_report)): ?>
                <p><strong>Utolsó globális javítás:</strong> <?php echo !empty($global_repair_report['finished_at']) ? esc_html($global_repair_report['finished_at']) : 'N/A'; ?> (frissítve: <?php echo isset($global_repair_report['updated']) ? esc_html((string) $global_repair_report['updated']) : '0'; ?>)</p>
            <?php endif; ?>
            <?php if (!empty($smoke_report) && is_array($smoke_report)): ?>
                <p><strong>Utolsó smoke teszt:</strong> <?php echo !empty($smoke_report['finished_at']) ? esc_html($smoke_report['finished_at']) : 'N/A'; ?> (<?php echo !empty($smoke_report['status']) ? esc_html($smoke_report['status']) : 'N/A'; ?>)</p>
            <?php endif; ?>

            <h2 style="margin-top:24px;">Release Checklist</h2>
            <ol style="max-width: 760px;">
                <li>Staging környezetben frissítés + regressziós smoke teszt.</li>
                <li>Index rebuild + globális javítás futtatása.</li>
                <li>Rendelés anyagai: Megtekintés + ZIP + Törlés próbák.</li>
                <li>Cleanup és migráció report ellenőrzése.</li>
                <li>Csak ezután élesítés, rollback tervvel.</li>
            </ol>
        </div>
        <?php
    }

    public function help_page_html()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Photowooshop Súgó</h1>
            <p style="max-width:900px;">Ezen az oldalon a Photowooshop plugin használatának legfontosabb lépéseit találja. A folyamat célja, hogy a vásárló egyszeruen testreszabható montázst készítsen, az admin pedig gyorsan kezelje a beérkezett anyagokat.</p>

            <h2>1. Gyors indulás</h2>
            <ol style="max-width:900px;">
                <li>Nyissa meg a <strong>Photowooshop -> Beállítások</strong> oldalt és állítsa be az alap opciókat.</li>
                <li>Hozzon létre sablont a <strong>Montázs Sablonok</strong> menüpontban.</li>
                <li>A terméknél kapcsolja be a Photowooshopot és válassza ki a sablont.</li>
                <li>Ellenőrizze egy próbarendeléssel a szerkesztési és mentési folyamatot.</li>
            </ol>

            <h2>2. Sablon készítés (admin)</h2>
            <ul style="max-width:900px; list-style:disc; padding-left:20px;">
                <li>Adjon meg háttérképet a sablonhoz.</li>
                <li>Vegyen fel <strong>Képhely</strong>, <strong>Szöveg</strong> és <strong>Alakzat</strong> rétegeket.</li>
                <li>A <strong>Rétegek</strong> panelben a rétegre kattintva lenyílik az összes beállítás.</li>
                <li>A rétegek drag-and-drop módban rendezhetők, a z-index automatikusan frissül.</li>
                <li>Képhelyeknél külön saroklekerekítés is állítható (bal felső, jobb felső, jobb alsó, bal alsó).</li>
            </ul>

            <h2>3. Termék beállítása</h2>
            <ul style="max-width:900px; list-style:disc; padding-left:20px;">
                <li><strong>Photowooshop Montázs Engedélyezése:</strong> kapcsolja be.</li>
                <li><strong>Választott Sablon:</strong> válassza ki az előbb létrehozott sablont.</li>
                <li><strong>Hangfájl Feltöltés:</strong> opcionálisan bekapcsolható.</li>
            </ul>

            <h2>4. Vásárlói folyamat</h2>
            <ol style="max-width:900px;">
                <li>A termékoldalon a vásárló megnyitja a szerkesztőt.</li>
                <li>Feltölti a képeket, megadja a szövegeket, szükség esetén hangfájlt csatol.</li>
                <li>A szerkesztés befejezése után kosárba teszi a testreszabott terméket.</li>
            </ol>

            <h2>5. Rendelés anyagainak kezelése</h2>
            <ul style="max-width:900px; list-style:disc; padding-left:20px;">
                <li><strong>Photowooshop -> Rendelés anyagai</strong> oldalon listázva láthatók a rendelési anyagok.</li>
                <li>Lehetőségek: megtekintés, ZIP letöltés, javítás, törlés.</li>
                <li>Ha anyag nem található, használja a javítási funkciót (egyedi vagy globális).</li>
            </ul>

            <h2>6. Karbantartás és stabilitás</h2>
            <ul style="max-width:900px; list-style:disc; padding-left:20px;">
                <li><strong>Safe mode:</strong> nagy terhelés esetén stabilabb admin lista működést ad.</li>
                <li><strong>Index újraépítés:</strong> az anyaglista gyorsításához és pontosításához.</li>
                <li><strong>Globális javítás:</strong> migráció utáni URL/meta problémák javítására.</li>
                <li><strong>Smoke teszt:</strong> gyors állapotellenőrzés kiadás előtt.</li>
            </ul>

            <h2>7. Frissítés és visszaállítás</h2>
            <ul style="max-width:900px; list-style:disc; padding-left:20px;">
                <li>A plugin GitHub verziókövetést használ.</li>
                <li>WordPress frissítés ellenőrzés elsődlegesen Release, másodlagosan Tag alapján működik.</li>
                <li>Rollback esetén célszeru stabil tagre visszaállni.</li>
            </ul>

            <h2>8. Hibakeresési tippek</h2>
            <ul style="max-width:900px; list-style:disc; padding-left:20px;">
                <li>Ha a lista lassú vagy hibás: kapcsolja be a Safe mode-ot, majd futtasson Index újraépítést.</li>
                <li>Ha anyagok hiányoznak: futtasson Javítás műveletet az érintett rendelésen.</li>
                <li>Ha továbbra is gond van: nézze át a Beállítások oldalon a diagnosztikai blokkokat.</li>
            </ul>
        </div>
        <?php
    }

    public function handle_manual_cleanup()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        if (!isset($_POST['photowooshop_run_cleanup_nonce']) || !wp_verify_nonce($_POST['photowooshop_run_cleanup_nonce'], 'photowooshop_run_cleanup')) {
            wp_die('Invalid request');
        }

        $this->cleanup_orphan_uploads();

        $redirect_url = add_query_arg(array(
            'page' => 'photowooshop',
            'photowooshop_cleanup_ran' => '1',
        ), admin_url('admin.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_rebuild_index()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        if (!isset($_POST['photowooshop_rebuild_index_nonce']) || !wp_verify_nonce($_POST['photowooshop_rebuild_index_nonce'], 'photowooshop_rebuild_index')) {
            wp_die('Invalid request');
        }

        $this->rebuild_material_index_snapshot();

        $redirect_url = add_query_arg(array(
            'page' => 'photowooshop',
            'photowooshop_index_ran' => '1',
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_run_global_repair()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        if (!isset($_POST['photowooshop_run_global_repair_nonce']) || !wp_verify_nonce($_POST['photowooshop_run_global_repair_nonce'], 'photowooshop_run_global_repair')) {
            wp_die('Invalid request');
        }

        $report = array(
            'status' => 'started',
            'started_at' => current_time('mysql'),
            'finished_at' => '',
            'orders_scanned' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
        );

        if (!function_exists('wc_get_orders')) {
            $report['status'] = 'skipped_woocommerce_missing';
            $report['finished_at'] = current_time('mysql');
            update_option(self::GLOBAL_REPAIR_REPORT_OPTION, $report, false);
        } else {
            $offset = 0;
            $chunk_size = 100;
            $statuses = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed');

            while (true) {
                $orders = wc_get_orders(array(
                    'limit' => $chunk_size,
                    'offset' => $offset,
                    'status' => $statuses,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ));

                if (empty($orders)) {
                    break;
                }

                foreach ($orders as $order) {
                    $report['orders_scanned']++;
                    try {
                        $this->repair_order_materials_meta($order, $report['updated'], $report['unchanged']);
                    } catch (Throwable $e) {
                        $report['errors']++;
                    }
                }

                if (count($orders) < $chunk_size) {
                    break;
                }

                $offset += $chunk_size;
            }

            $report['status'] = 'completed';
            $report['finished_at'] = current_time('mysql');
            update_option(self::GLOBAL_REPAIR_REPORT_OPTION, $report, false);
        }

        $redirect_url = add_query_arg(array(
            'page' => 'photowooshop',
            'photowooshop_global_repair_ran' => '1',
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_run_smoke_test()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        if (!isset($_POST['photowooshop_run_smoke_test_nonce']) || !wp_verify_nonce($_POST['photowooshop_run_smoke_test_nonce'], 'photowooshop_run_smoke_test')) {
            wp_die('Invalid request');
        }

        $this->run_smoke_test();

        $redirect_url = add_query_arg(array(
            'page' => 'photowooshop',
            'photowooshop_smoke_ran' => '1',
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_force_update_check()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        if (!isset($_POST['photowooshop_force_update_check_nonce']) || !wp_verify_nonce($_POST['photowooshop_force_update_check_nonce'], 'photowooshop_force_update_check')) {
            wp_die('Invalid request');
        }

        delete_option(self::UPDATE_CACHE_OPTION);
        delete_site_transient('update_plugins');
        $this->get_latest_github_release(true);

        $redirect_url = add_query_arg(array(
            'page' => 'photowooshop',
            'photowooshop_update_check_ran' => '1',
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function rebuild_material_index_snapshot()
    {
        $report = array(
            'status' => 'started',
            'started_at' => current_time('mysql'),
            'finished_at' => '',
            'orders_scanned' => 0,
            'orders_indexed' => 0,
            'errors' => 0,
        );

        if (!function_exists('wc_get_orders')) {
            $report['status'] = 'skipped_woocommerce_missing';
            $report['finished_at'] = current_time('mysql');
            update_option(self::INDEX_REPORT_OPTION, $report, false);
            return;
        }

        $offset = 0;
        $chunk_size = 100;
        $max_scanned = 5000;
        $scanned = 0;
        $statuses = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed');
        $index_orders = array();

        while (true) {
            $orders = wc_get_orders(array(
                'limit' => $chunk_size,
                'offset' => $offset,
                'status' => $statuses,
                'orderby' => 'date',
                'order' => 'DESC',
            ));

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                $scanned++;
                $report['orders_scanned'] = $scanned;
                if ($scanned > $max_scanned) {
                    break 2;
                }

                try {
                    if (!$this->order_has_photowooshop_data($order)) {
                        continue;
                    }

                    $counts = $this->get_order_material_counts_for_list($order);
                    $index_orders[(int) $order->get_id()] = array(
                        'images' => (int) $counts['images'],
                        'audio' => !empty($counts['audio']),
                        'status' => $order->get_status(),
                        'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                    );
                } catch (Throwable $e) {
                    $report['errors']++;
                }
            }

            if (count($orders) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }

        $cache = array(
            'generated_at' => current_time('mysql'),
            'total_indexed' => count($index_orders),
            'orders' => $index_orders,
        );
        update_option(self::INDEX_CACHE_OPTION, $cache, false);

        $report['orders_indexed'] = count($index_orders);
        $report['status'] = 'completed';
        $report['finished_at'] = current_time('mysql');
        update_option(self::INDEX_REPORT_OPTION, $report, false);
    }

    private function run_smoke_test()
    {
        $report = array(
            'status' => 'started',
            'started_at' => current_time('mysql'),
            'finished_at' => '',
            'checks' => array(),
        );

        $uploads = wp_upload_dir();
        $report['checks'][] = array(
            'name' => 'Uploads dir available',
            'ok' => !empty($uploads['basedir']) && is_dir($uploads['basedir']),
        );
        $report['checks'][] = array(
            'name' => 'Uploads dir writable',
            'ok' => !empty($uploads['basedir']) && is_writable($uploads['basedir']),
        );
        $report['checks'][] = array(
            'name' => 'WooCommerce orders API',
            'ok' => function_exists('wc_get_orders'),
        );
        $report['checks'][] = array(
            'name' => 'Safe mode setting exists',
            'ok' => in_array(get_option(self::SAFE_MODE_OPTION, 'yes'), array('yes', 'no'), true),
        );
        $report['checks'][] = array(
            'name' => 'Repair AJAX hook active',
            'ok' => has_action('wp_ajax_photowooshop_repair_order_materials') !== false,
        );

        $all_ok = true;
        foreach ($report['checks'] as $check) {
            if (empty($check['ok'])) {
                $all_ok = false;
                break;
            }
        }

        $report['status'] = $all_ok ? 'passed' : 'warning';
        $report['finished_at'] = current_time('mysql');
        update_option(self::SMOKE_REPORT_OPTION, $report, false);
    }

    public function materials_page_html()
    {
        $page = isset($_GET['pws_page']) ? max(1, intval($_GET['pws_page'])) : 1;
        $per_page = 20;
        $default_statuses = array('processing', 'completed', 'on-hold', 'pending');

        $status = isset($_GET['pws_status']) ? sanitize_text_field($_GET['pws_status']) : 'all';
        $date_from = isset($_GET['pws_date_from']) ? sanitize_text_field($_GET['pws_date_from']) : '';
        $date_to = isset($_GET['pws_date_to']) ? sanitize_text_field($_GET['pws_date_to']) : '';
        $order_id = isset($_GET['pws_order_id']) ? max(0, intval($_GET['pws_order_id'])) : 0;

        $statuses = $status === 'all' ? $default_statuses : array($status);
        $filters = array(
            'date_from' => $date_from,
            'date_to' => $date_to,
            'order_id' => $order_id,
        );

        try {
            $pagination = $this->get_photowooshop_orders_page($page, $per_page, $statuses, $filters);
        } catch (Throwable $e) {
            $pagination = array(
                'orders' => array(),
                'has_next' => false,
                'total_count' => 0,
                'total_pages' => 0,
                'scan_truncated' => true,
            );
        }

        $photowooshop_orders = isset($pagination['orders']) && is_array($pagination['orders']) ? $pagination['orders'] : array();
        $has_next_page = !empty($pagination['has_next']);
        $total_count = isset($pagination['total_count']) ? (int) $pagination['total_count'] : 0;
        $total_pages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 0;
        $scan_truncated = !empty($pagination['scan_truncated']);

        $query_base_args = array();
        if ($status !== 'all') {
            $query_base_args['pws_status'] = $status;
        }
        if (!empty($date_from)) {
            $query_base_args['pws_date_from'] = $date_from;
        }
        if (!empty($date_to)) {
            $query_base_args['pws_date_to'] = $date_to;
        }
        if ($order_id > 0) {
            $query_base_args['pws_order_id'] = $order_id;
        }
        ?>
        <div class="wrap">
            <h1>Rendelés anyagai</h1>
            <p>Itt kezelheti a montázsokhoz feltöltött egyedi anyagokat rendelésenként.</p>
            <p style="margin: 0 0 10px;"><strong>Összes találat:</strong> <?php echo $scan_truncated ? 'legalább ' : ''; ?><?php echo esc_html($total_count); ?> db</p>
            <?php if ($scan_truncated): ?>
                <p style="margin: 0 0 10px; color:#b32d2e;">A teljesítmény védelme miatt a találatszám részlegesen került számításra.</p>
            <?php endif; ?>

            <form method="get" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin: 10px 0 14px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'photowooshop-main'); ?>">

                <div>
                    <label for="pws_status" style="display:block; margin-bottom:4px;">Státusz</label>
                    <select name="pws_status" id="pws_status">
                        <option value="all" <?php selected($status, 'all'); ?>>Összes</option>
                        <option value="processing" <?php selected($status, 'processing'); ?>>Feldolgozás alatt</option>
                        <option value="completed" <?php selected($status, 'completed'); ?>>Teljesített</option>
                        <option value="on-hold" <?php selected($status, 'on-hold'); ?>>Várakozó</option>
                        <option value="pending" <?php selected($status, 'pending'); ?>>Függő</option>
                    </select>
                </div>

                <div>
                    <label for="pws_date_from" style="display:block; margin-bottom:4px;">Dátum -tól</label>
                    <input type="date" name="pws_date_from" id="pws_date_from" value="<?php echo esc_attr($date_from); ?>">
                </div>

                <div>
                    <label for="pws_date_to" style="display:block; margin-bottom:4px;">Dátum -ig</label>
                    <input type="date" name="pws_date_to" id="pws_date_to" value="<?php echo esc_attr($date_to); ?>">
                </div>

                <div>
                    <label for="pws_order_id" style="display:block; margin-bottom:4px;">Rendelés ID</label>
                    <input type="number" min="1" name="pws_order_id" id="pws_order_id" value="<?php echo $order_id > 0 ? esc_attr($order_id) : ''; ?>" placeholder="pl. 123">
                </div>

                <button type="submit" class="button button-primary">Szűrés</button>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'photowooshop-main', 'pws_page' => null, 'pws_status' => null, 'pws_date_from' => null, 'pws_date_to' => null, 'pws_order_id' => null))); ?>">Szűrők törlése</a>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Rendelés ID</th>
                        <th>Dátum</th>
                        <th>Vevő</th>
                        <th>Anyagok állapota</th>
                        <th>Műveletek</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($photowooshop_orders)): ?>
                        <tr>
                            <td colspan="5">Nincs megjeleníthető rendelés.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($photowooshop_orders as $order):
                            $created = $order->get_date_created();
                            $created_text = $created ? $created->date('Y-m-d H:i') : '-';
                            $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                            $material_counts = $this->get_order_material_counts_for_list($order);
                            ?>
                            <tr>
                                <td>#<?php echo esc_html($order->get_id()); ?></td>
                                <td><?php echo esc_html($created_text); ?></td>
                                <td><?php echo esc_html($customer_name); ?></td>
                                <td>
                                    <?php echo esc_html($material_counts['images']); ?> kép,
                                    <?php echo esc_html($material_counts['audio'] ? '1 hangfájl' : 'nincs hang'); ?>
                                </td>
                                <td>
                                    <button class="button photowooshop-view-details"
                                        data-id="<?php echo esc_attr($order->get_id()); ?>">Megtekintés</button>
                                    <button class="button photowooshop-zip-dl" data-id="<?php echo esc_attr($order->get_id()); ?>">ZIP
                                        Letöltés</button>
                                    <button class="button photowooshop-repair-materials" data-id="<?php echo esc_attr($order->get_id()); ?>">Javítás</button>
                                    <button class="button delete-materials" data-id="<?php echo esc_attr($order->get_id()); ?>"
                                        style="color:red;">Törlés</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="tablenav" style="margin-top: 12px;">
                <div class="tablenav-pages" style="display:flex; gap:8px; align-items:center;">
                    <?php if ($page > 1): ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_base_args, array('pws_page' => $page - 1)))); ?>">&laquo; Előző</a>
                    <?php endif; ?>
                    <span class="displaying-num">Oldal: <?php echo esc_html($page); ?> / <?php echo esc_html($scan_truncated ? '?' : max(1, $total_pages)); ?></span>
                    <?php if ($has_next_page): ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_base_args, array('pws_page' => $page + 1)))); ?>">Következő &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div id="photowooshop-admin-modal"
            style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8); overflow:auto; padding:40px 20px;">
            <div style="background:#fff; max-width:800px; margin:auto; border-radius:8px; padding:30px; position:relative;">
                <span id="close-admin-modal"
                    style="position:absolute; right:20px; top:15px; font-size:24px; cursor:pointer;">&times;</span>
                <h2 id="modal-order-title" style="margin-top:0;">Rendelés részletei</h2>
                <hr>
                <div id="modal-content-body">
                    <p>Betöltés...</p>
                </div>
            </div>
        </div>

        <style>
            .admin-materials-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 10px;
                margin-top: 15px;
            }

            .admin-material-item img {
                width: 100%;
                height: auto;
                border: 1px solid #ddd;
                border-radius: 4px;
            }

            .admin-section-label {
                font-weight: bold;
                margin: 20px 0 10px;
                display: block;
                border-left: 4px solid #6200ee;
                padding-left: 10px;
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                function safeUrl(url) {
                    if (typeof url !== 'string') return '';
                    const trimmed = url.trim();
                    if (/^https?:\/\//i.test(trimmed) || trimmed.startsWith('/')) {
                        return trimmed;
                    }
                    return '';
                }

                function appendSectionLabel($root, label) {
                    $('<span>', {
                        class: 'admin-section-label',
                        text: label
                    }).appendTo($root);
                }

                $('.photowooshop-view-details').click(function () {
                    var orderId = $(this).data('id');
                    $('#modal-order-title').text('Rendelés #' + orderId + ' anyagai');
                    $('#modal-content-body').html('<p>Betöltés...</p>');
                    $('#photowooshop-admin-modal').fadeIn();

                    $.post(ajaxurl, {
                        action: 'photowooshop_get_order_details',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce("photowooshop_admin_nonce"); ?>'
                    }, function (response) {
                        if (response.success) {
                            var data = response.data;
                            var $body = $('<div>');

                            // Montage
                            if (data.montage) {
                                var montageUrl = safeUrl(data.montage);
                                if (montageUrl) {
                                    appendSectionLabel($body, 'Kész Montázs');
                                    var $montageLink = $('<a>', { href: montageUrl, target: '_blank', rel: 'noopener noreferrer' });
                                    $('<img>', {
                                        src: montageUrl,
                                        css: { maxWidth: '300px', border: '1px solid #ddd' }
                                    }).appendTo($montageLink);
                                    $body.append($montageLink);
                                }
                            }

                            // Individual Images
                            if (data.individual && data.individual.length > 0) {
                                appendSectionLabel($body, 'Eredeti képek (' + data.individual.length + ' db)');
                                var $grid = $('<div>', { class: 'admin-materials-grid' });
                                data.individual.forEach(function (url) {
                                    var imageUrl = safeUrl(url);
                                    if (!imageUrl) return;
                                    var $item = $('<div>', { class: 'admin-material-item' });
                                    var $link = $('<a>', { href: imageUrl, target: '_blank', rel: 'noopener noreferrer' });
                                    $('<img>', { src: imageUrl }).appendTo($link);
                                    $item.append($link);
                                    $grid.append($item);
                                });
                                $body.append($grid);
                            }

                            // Text
                            if (data.text) {
                                appendSectionLabel($body, 'Egyedi Szöveg');
                                $('<p>', {
                                    text: data.text,
                                    css: {
                                        fontSize: '18px',
                                        padding: '10px',
                                        background: '#f9f9f9',
                                        borderRadius: '4px'
                                    }
                                }).appendTo($body);
                            }

                            // Audio
                            if (data.audio) {
                                var audioUrl = safeUrl(data.audio);
                                if (audioUrl) {
                                    appendSectionLabel($body, 'Hangfájl');
                                    $('<audio>', {
                                        controls: true,
                                        src: audioUrl,
                                        css: { width: '100%' }
                                    }).appendTo($body);
                                    var $downloadWrap = $('<p>');
                                    $('<a>', {
                                        href: audioUrl,
                                        target: '_blank',
                                        rel: 'noopener noreferrer',
                                        class: 'button',
                                        text: 'Letöltés'
                                    }).appendTo($downloadWrap);
                                    $body.append($downloadWrap);
                                }
                            }

                            if ($body.children().length === 0) {
                                $('<p>', {
                                    text: 'Ehhez a rendeléshez nem található elérhető Photowooshop anyag.',
                                    css: { color: '#666' }
                                }).appendTo($body);
                            }

                            $('#modal-content-body').empty().append($body.contents());
                        } else {
                            var errorText = (response && response.data) ? String(response.data) : 'Ismeretlen hiba';
                            $('#modal-content-body').html('');
                            $('<p>', {
                                text: 'Hiba: ' + errorText,
                                css: { color: 'red' }
                            }).appendTo('#modal-content-body');
                        }
                    });
                });

                $('#close-admin-modal, #photowooshop-admin-modal').click(function (e) {
                    if (e.target === this) $('#photowooshop-admin-modal').fadeOut();
                });

                $('.photowooshop-zip-dl').click(function () {
                    var orderId = $(this).data('id');
                    var btn = $(this);
                    btn.prop('disabled', true).text('Csomagolás...');

                    window.location.href = ajaxurl + '?action=photowooshop_download_zip&order_id=' + orderId + '&nonce=<?php echo wp_create_nonce("photowooshop_admin_nonce"); ?>';

                    setTimeout(function () {
                        btn.prop('disabled', false).text('ZIP Letöltés');
                    }, 3000);
                });

                $('.delete-materials').click(function () {
                    if (!confirm('Biztosan törölni szeretné a rendeléshez tartozó összes fájlt? Ez a művelet nem vonható vissza.')) return;

                    var orderId = $(this).data('id');
                    var btn = $(this);

                    $.post(ajaxurl, {
                        action: 'photowooshop_delete_materials',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce("photowooshop_admin_nonce"); ?>'
                    }, function (response) {
                        if (response.success) {
                            btn.closest('tr').fadeOut();
                        } else {
                            alert('Hiba történt: ' + response.data);
                        }
                    });
                });

                $('.photowooshop-repair-materials').click(function () {
                    if (!confirm('Futtassuk le a rendelés anyag linkjeinek javítását?')) return;

                    var orderId = $(this).data('id');
                    var btn = $(this);
                    var original = btn.text();
                    btn.prop('disabled', true).text('Javítás...');

                    $.post(ajaxurl, {
                        action: 'photowooshop_repair_order_materials',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce("photowooshop_admin_nonce"); ?>'
                    }, function (response) {
                        if (response && response.success) {
                            var msg = 'Javítás kész. Frissített elemek: ' + (response.data.updated || 0) + ', változatlan: ' + (response.data.unchanged || 0);
                            alert(msg);
                        } else {
                            var err = (response && response.data) ? String(response.data) : 'Ismeretlen hiba';
                            alert('Javítás sikertelen: ' + err);
                        }
                    }).always(function () {
                        btn.prop('disabled', false).text(original);
                    });
                });
            });
        </script>
        <?php
    }

    private function order_has_photowooshop_data($order)
    {
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('Egyedi Montázs URL') || $item->get_meta('_photowooshop_montage_url')) {
                return true;
            }

            if ($item->get_meta('Hangfájl') || $item->get_meta('_photowooshop_audio_url')) {
                return true;
            }

            $individual = $item->get_meta('_photowooshop_individual_images');
            if (!empty($individual)) {
                return true;
            }

            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, 'Egyedi Kép') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function get_photowooshop_orders_page($page, $per_page, $statuses, $filters = array())
    {
        $page = max(1, intval($page));
        $per_page = max(1, intval($per_page));

        $order_id = isset($filters['order_id']) ? intval($filters['order_id']) : 0;
        $date_from = isset($filters['date_from']) ? sanitize_text_field($filters['date_from']) : '';
        $date_to = isset($filters['date_to']) ? sanitize_text_field($filters['date_to']) : '';

        $query_args = array(
            'limit' => $per_page + 1,
            'offset' => ($page - 1) * $per_page,
            'status' => $statuses,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        if ($order_id > 0) {
            $query_args['include'] = array($order_id);
            $query_args['limit'] = 1;
            $query_args['offset'] = 0;
        }

        if (!empty($date_from) || !empty($date_to)) {
            $from = !empty($date_from) ? $date_from . ' 00:00:00' : '';
            $to = !empty($date_to) ? $date_to . ' 23:59:59' : '';
            if (!empty($from) && !empty($to)) {
                $query_args['date_created'] = $from . '...' . $to;
            } elseif (!empty($from)) {
                $query_args['date_created'] = '>=' . $from;
            } elseif (!empty($to)) {
                $query_args['date_created'] = '<=' . $to;
            }
        }

        try {
            $orders = wc_get_orders($query_args);
        } catch (Throwable $e) {
            return array(
                'orders' => array(),
                'has_next' => false,
                'total_count' => 0,
                'total_pages' => 0,
                'scan_truncated' => true,
            );
        }

        if (empty($orders)) {
            return array(
                'orders' => array(),
                'has_next' => false,
                'total_count' => 0,
                'total_pages' => 0,
                'scan_truncated' => false,
            );
        }

        $has_next = count($orders) > $per_page;
        $orders = array_slice($orders, 0, $per_page);

        $filtered_orders = array();
        foreach ($orders as $order) {
            if ($this->order_has_photowooshop_data($order)) {
                $filtered_orders[] = $order;
            }
        }

        $safe_mode = $this->is_safe_mode_enabled();

        // Keep list lightweight in safe mode, while still showing relevant rows only.
        $approx_total = (($page - 1) * $per_page) + count($filtered_orders) + ($has_next ? 1 : 0);
        $approx_pages = $has_next ? ($page + 1) : $page;

        return array(
            'orders' => $filtered_orders,
            'has_next' => $has_next,
            'total_count' => $approx_total,
            'total_pages' => $safe_mode ? 0 : $approx_pages,
            'scan_truncated' => $safe_mode && ($order_id <= 0),
        );
    }

    private function get_order_material_counts_for_list($order)
    {
        $index_cache = get_option(self::INDEX_CACHE_OPTION, array());
        $order_id = (int) $order->get_id();
        if (!empty($index_cache['orders']) && is_array($index_cache['orders']) && isset($index_cache['orders'][$order_id])) {
            $indexed = $index_cache['orders'][$order_id];
            return array(
                'images' => isset($indexed['images']) ? (int) $indexed['images'] : 0,
                'audio' => !empty($indexed['audio']),
            );
        }

        $images = 0;
        $has_audio = false;

        foreach ($order->get_items() as $item) {
            $montage = $item->get_meta('_photowooshop_montage_url') ?: $item->get_meta('Egyedi Montázs URL');
            if (!empty($montage)) {
                $images++;
            }

            $audio = $item->get_meta('_photowooshop_audio_url') ?: $item->get_meta('Hangfájl');
            if (!empty($audio)) {
                $has_audio = true;
            }

            $individual = $item->get_meta('_photowooshop_individual_images');
            if (!empty($individual)) {
                $urls = json_decode($individual, true);
                if (is_array($urls)) {
                    foreach ($urls as $url) {
                        if (!empty($url)) {
                            $images++;
                        }
                    }
                }
            } else {
                foreach ($item->get_meta_data() as $meta) {
                    if (strpos($meta->key, 'Egyedi Kép') !== false && !empty($meta->value)) {
                        $images++;
                    }
                }
            }
        }

        return array(
            'images' => $images,
            'audio' => $has_audio,
        );
    }
    public function get_dynamic_fonts()
    {
        $fonts_dir = plugin_dir_path(__FILE__) . 'assets/fonts/';
        $fonts_url = plugin_dir_url(__FILE__) . 'assets/fonts/';
        
        // Base fonts we explicitly defined in fonts.css
        $fonts_list = array('hello honey', 'Densia Sans', 'Capsuula Regular', 'Arima Koshi Regular', 'Arial', 'Times New Roman', 'Courier New', 'Impact');
        $dynamic_css = "";
        
        if (is_dir($fonts_dir)) {
            $font_files = scandir($fonts_dir);
            $font_groups = array();
            
            // Files we know are handled by fonts.css to skip defining dynamic CSS for them
            $excluded_files = array('hello-honey.ttf', 'hello-honey.otf', 'hello-honey.woff', 'hello-honey.woff2', 
                                    'DensiaSans.ttf', 'DensiaSans.otf', 'DensiaSans.woff', 'DensiaSans.woff2',
                                    'Capsuula.ttf', 'Capsuula.otf', 'Capsuula.woff', 'Capsuula.woff2',
                                    'ArimaKoshi.ttf', 'ArimaKoshi.otf', 'ArimaKoshi.woff', 'ArimaKoshi.woff2');
            
            foreach ($font_files as $file) {
                if ($file === '.' || $file === '..') continue;
                if (in_array($file, $excluded_files)) continue; // skip defaults
                
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, array('ttf', 'otf', 'woff', 'woff2'))) {
                    $font_name = pathinfo($file, PATHINFO_FILENAME);
                    $font_groups[$font_name][$ext] = $file;
                }
            }
            
            foreach ($font_groups as $font_name => $files) {
                if (!in_array($font_name, $fonts_list)) {
                    $fonts_list[] = $font_name;
                }
                
                $dynamic_css .= "@font-face {\n";
                $dynamic_css .= "    font-family: '{$font_name}';\n";
                $src = array();
                foreach ($files as $ext => $file) {
                    $format = '';
                    if ($ext === 'ttf') $format = 'truetype';
                    elseif ($ext === 'otf') $format = 'opentype';
                    elseif ($ext === 'woff') $format = 'woff';
                    elseif ($ext === 'woff2') $format = 'woff2';
                    $src[] = "url('{$fonts_url}{$file}') format('{$format}')";
                }
                $dynamic_css .= "    src: " . implode(",\n         ", $src) . ";\n";
                $dynamic_css .= "    font-weight: normal;\n";
                $dynamic_css .= "    font-style: normal;\n";
                $dynamic_css .= "}\n\n";
            }
        }
        
        return array(
            'list' => $fonts_list,
            'css' => $dynamic_css
        );
    }

    public function admin_enqueue_scripts($hook)
    {
        if ($hook == 'post.php' || $hook == 'post-new.php') {
            global $post;
            wp_enqueue_media();
            if ($post && $post->post_type === 'photowooshop_tpl') {
                $fonts = $this->get_dynamic_fonts();
                wp_enqueue_style('photowooshop-fonts', plugin_dir_url(__FILE__) . 'assets/css/fonts.css', array(), self::PLUGIN_VERSION);
                if (!empty($fonts['css'])) {
                    wp_add_inline_style('photowooshop-fonts', $fonts['css']);
                }
                wp_enqueue_script('photowooshop-interact', 'https://cdnjs.cloudflare.com/ajax/libs/interact.js/1.10.11/interact.min.js', array(), '1.10.11', true);
                wp_enqueue_script('photowooshop-admin-editor', plugin_dir_url(__FILE__) . 'assets/js/admin-editor.js', array('jquery', 'photowooshop-interact'), self::PLUGIN_VERSION, true);

                wp_localize_script('photowooshop-admin-editor', 'photowooshop_admin_vars', array(
                    'bg_url' => get_post_meta($post->ID, '_photowooshop_bg_url', true),
                    'slots' => get_post_meta($post->ID, '_photowooshop_slots_json', true),
                    'text_slots' => get_post_meta($post->ID, '_photowooshop_text_slots_json', true),
                    'shape_slots' => get_post_meta($post->ID, '_photowooshop_shape_slots_json', true),
                    'font_families' => $fonts['list']
                ));
            }
        }
    }

    private $active_product_id = 0;

    public function enqueue_scripts()
    {
        // Register scripts and styles everywhere so they are available
        wp_register_style('photowooshop-cropper', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css');
        wp_register_script('photowooshop-cropper', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js', array(), '1.5.13', true);

        wp_register_style('photowooshop-editor', plugin_dir_url(__FILE__) . 'assets/css/editor.css', array(), self::PLUGIN_VERSION);
        wp_register_script('photowooshop-editor', plugin_dir_url(__FILE__) . 'assets/js/editor.js', array('jquery'), self::PLUGIN_VERSION, true);

        // If standard product page, load immediately
        if (is_product()) {
            global $post;
            $this->load_editor_assets($post->ID);
        }
    }

    public function late_enqueue()
    {
        if (!wp_script_is('photowooshop-editor') && $this->active_product_id) {
            $this->load_editor_assets($this->active_product_id);
        }
    }

    private function load_editor_assets($product_id)
    {
        $tpl_id = get_post_meta($product_id, '_photowooshop_tpl_id', true);
        $bg_url = '';
        $slots = '';

        if ($tpl_id) {
            $bg_url = get_post_meta($tpl_id, '_photowooshop_bg_url', true);
            $slots = get_post_meta($tpl_id, '_photowooshop_slots_json', true);
        }

        $fonts = $this->get_dynamic_fonts();
        wp_enqueue_style('photowooshop-fonts', plugin_dir_url(__FILE__) . 'assets/css/fonts.css', array(), '1.0.0');
        if (!empty($fonts['css'])) {
            wp_add_inline_style('photowooshop-fonts', $fonts['css']);
        }
        
        wp_enqueue_style('photowooshop-cropper');
        wp_enqueue_script('photowooshop-cropper');
        wp_enqueue_style('photowooshop-editor');
        wp_enqueue_script('photowooshop-editor');

        // Handle variations - if parent has it, children might too
        $audio_enabled = get_post_meta($product_id, '_photowooshop_audio_enabled', true) === 'yes';
        $upload_token = $this->get_or_create_upload_token($product_id);

        wp_localize_script('photowooshop-editor', 'photowooshop_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('photowooshop_nonce'),
            'product_id' => (int) $product_id,
            'upload_token' => $upload_token,
            'bg_url' => $bg_url,
            'slots_data' => $slots,
            'audio_enabled' => $audio_enabled,
            'text_enabled' => true,
            'text_slots_data' => get_post_meta($tpl_id, '_photowooshop_text_slots_json', true),
            'shape_slots_data' => get_post_meta($tpl_id, '_photowooshop_shape_slots_json', true),
            'font_families' => $fonts['list'],
            'mockups' => array(
                'm1' => array(
                    'url' => get_post_meta($tpl_id, '_photowooshop_mockup_1_url', true),
                    'tl' => get_post_meta($tpl_id, '_photowooshop_mockup_1_tl', true) ?: '10,10',
                    'tr' => get_post_meta($tpl_id, '_photowooshop_mockup_1_tr', true) ?: '90,10',
                    'bl' => get_post_meta($tpl_id, '_photowooshop_mockup_1_bl', true) ?: '10,90',
                    'br' => get_post_meta($tpl_id, '_photowooshop_mockup_1_br', true) ?: '90,90',
                ),
                'm2' => array(
                    'url' => get_post_meta($tpl_id, '_photowooshop_mockup_2_url', true),
                    'tl' => get_post_meta($tpl_id, '_photowooshop_mockup_2_tl', true) ?: '10,10',
                    'tr' => get_post_meta($tpl_id, '_photowooshop_mockup_2_tr', true) ?: '90,10',
                    'bl' => get_post_meta($tpl_id, '_photowooshop_mockup_2_bl', true) ?: '10,90',
                    'br' => get_post_meta($tpl_id, '_photowooshop_mockup_2_br', true) ?: '90,90',
                ),
                'm3' => array(
                    'url' => get_post_meta($tpl_id, '_photowooshop_mockup_3_url', true),
                    'tl' => get_post_meta($tpl_id, '_photowooshop_mockup_3_tl', true) ?: '10,10',
                    'tr' => get_post_meta($tpl_id, '_photowooshop_mockup_3_tr', true) ?: '90,10',
                    'bl' => get_post_meta($tpl_id, '_photowooshop_mockup_3_bl', true) ?: '10,90',
                    'br' => get_post_meta($tpl_id, '_photowooshop_mockup_3_br', true) ?: '90,90',
                ),
            )
        ));
    }

    public function add_customize_button()
    {
        global $product;

        if (!$product || !$this->is_photowooshop_enabled_for_product($product->get_id())) {
            return;
        }

        // Track that we are rendering this button, so assets can be loaded late if needed
        $this->active_product_id = $product->get_id();

        $button_text = get_option('photowooshop_button_text', 'Egyedi montázs tervezése');
        if (empty($button_text)) {
            $button_text = 'Egyedi montázs tervezése';
        }

        echo '<button type="button" id="photowooshop-open-editor" class="button alt photowooshop-trigger-btn" style="margin-bottom: 20px;">' . esc_html($button_text) . '</button>';
    }

    public function add_audio_consent_checkbox()
    {
        global $product;

        if (!$product) {
            return;
        }

        $product_id = $product->get_id();
        if (!$this->is_photowooshop_enabled_for_product($product_id)) {
            return;
        }

        if (!$this->is_audio_enabled_for_product($product_id)) {
            return;
        }

        if (!$this->is_audio_consent_feature_enabled()) {
            return;
        }

        $consent_text = $this->get_audio_consent_text();
        ?>
        <div class="photowooshop-audio-consent" style="margin: 12px 0 18px; padding: 10px; background:#f7f7f7; border:1px solid #ddd; border-radius:6px;">
            <label style="display:flex; gap:8px; align-items:flex-start;">
                <input type="checkbox" name="photowooshop_audio_consent" value="yes" required style="margin-top:2px;">
                <span><?php echo esc_html($consent_text); ?></span>
            </label>
        </div>
        <?php
    }

    public function validate_audio_consent_checkbox($passed, $product_id, $quantity, $variation_id = 0, $variations = array())
    {
        if (!$passed) {
            return $passed;
        }

        if (!$this->is_photowooshop_enabled_for_product($product_id)) {
            return $passed;
        }

        if (!$this->is_audio_enabled_for_product($product_id, $variation_id)) {
            return $passed;
        }

        if (!$this->is_audio_consent_feature_enabled()) {
            return $passed;
        }

        $consent_checked = isset($_POST['photowooshop_audio_consent']) && $_POST['photowooshop_audio_consent'] === 'yes';
        if (!$consent_checked) {
            wc_add_notice($this->get_audio_consent_text(), 'error');
            return false;
        }

        return $passed;
    }

    public function render_modal()
    {
        // Render if standard product page OR if we detected the button use elsewhere
        if (is_product() || $this->active_product_id) {
            echo '<div id="photowooshop-modal" style="display:none;">
                    <div class="photowooshop-modal-content">
                        <span class="photowooshop-close" title="Bezárás">×</span>
                        <div id="photowooshop-editor-container"></div>
                    </div>
                  </div>';
        }
    }

    public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        // Try getting from POST first
        $montage_url = isset($_POST['photowooshop_custom_image']) ? sanitize_text_field($_POST['photowooshop_custom_image']) : '';
        $individual_images = isset($_POST['photowooshop_individual_images']) ? json_decode(stripslashes($_POST['photowooshop_individual_images']), true) : array();
        $text = isset($_POST['photowooshop_custom_text']) ? sanitize_text_field($_POST['photowooshop_custom_text']) : '';
        $audio_url = '';

        // Handle Audio if sent via standard post (non-AJAX)
        if (!empty($_FILES['photowooshop_audio'])) {
            if (!function_exists('wp_handle_upload')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }

            $audio_file = $_FILES['photowooshop_audio'];
            $audio_validation = $this->validate_audio_upload_file($audio_file);
            if (is_wp_error($audio_validation)) {
                wc_add_notice($audio_validation->get_error_message(), 'error');
                return $cart_item_data;
            }

            $audio_ext = strtolower(pathinfo($audio_file['name'], PATHINFO_EXTENSION));
            if (empty($audio_ext)) {
                $audio_ext = 'mp3';
            }
            $audio_file['name'] = 'photowooshop_audio_' . uniqid('', true) . '.' . sanitize_file_name($audio_ext);

            $upload = $this->run_with_photowooshop_upload_dir(function () use ($audio_file) {
                return wp_handle_upload($audio_file, array('test_form' => false));
            });
            if (!isset($upload['error']) && isset($upload['url'])) {
                $audio_url = $upload['url'];
            }
        }

        // Fallback to WC Session if data is missing (handles cases where theme AJAX ignores hidden fields)
        if (empty($montage_url) && function_exists('WC') && WC()->session) {
            $session_data = WC()->session->get('photowooshop_temp_data_' . $product_id);
            if ($session_data) {
                $montage_url = isset($session_data['montage_url']) ? $session_data['montage_url'] : '';
                $individual_images = isset($session_data['individual_images']) ? $session_data['individual_images'] : array();
                $text = isset($session_data['text']) ? $session_data['text'] : '';
                $audio_url = !empty($audio_url) ? $audio_url : (isset($session_data['audio_url']) ? $session_data['audio_url'] : '');

                // Clear session data after retrieval to prevent pollution
                WC()->session->set('photowooshop_temp_data_' . $product_id, null);
            }
        }

        if (!empty($montage_url)) {
            $cart_item_data['photowooshop_image'] = $montage_url;
        }

        if (!empty($individual_images)) {
            $cart_item_data['photowooshop_individual_images'] = $individual_images;
        }

        if (!empty($audio_url)) {
            $cart_item_data['photowooshop_audio'] = $audio_url;
        }

        if (!empty($text)) {
            $cart_item_data['photowooshop_text'] = $text;
        }

        if (isset($_POST['photowooshop_audio_consent']) && $_POST['photowooshop_audio_consent'] === 'yes') {
            $cart_item_data['photowooshop_audio_consent'] = 'yes';
        }

        return $cart_item_data;
    }

    public function ajax_save_image()
    {
        check_ajax_referer('photowooshop_nonce', 'nonce');

        if (!$this->check_rate_limit('save_image', 60, 60)) {
            wp_send_json_error('Túl sok kérés, próbáld újra később.');
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $upload_token = isset($_POST['upload_token']) ? sanitize_text_field($_POST['upload_token']) : '';
        if (!$product_id || !$this->verify_upload_token($product_id, $upload_token)) {
            wp_send_json_error('Érvénytelen munkamenet.');
        }

        if (empty($_POST['image'])) {
            wp_send_json_error('No image data');
        }

        $img = $_POST['image'];
        // Handle various base64 formats
        $img = preg_replace('#^data:image/[^;]+;base64,#', '', $img);
        $img = str_replace(' ', '+', $img);
        $data = base64_decode($img, true);
        if ($data === false) {
            wp_send_json_error('Invalid image data');
        }

        if (strlen($data) > self::IMAGE_UPLOAD_MAX_BYTES) {
            wp_send_json_error('A kép túl nagy.');
        }

        $img_info = @getimagesizefromstring($data);
        if (!$img_info || empty($img_info['mime']) || !in_array($img_info['mime'], array('image/jpeg', 'image/png', 'image/webp'), true)) {
            wp_send_json_error('Nem támogatott képformátum.');
        }

        $ext = 'jpg';
        if ($img_info['mime'] === 'image/png') {
            $ext = 'png';
        } elseif ($img_info['mime'] === 'image/webp') {
            $ext = 'webp';
        }

        $filename = 'photowooshop_' . uniqid('', true) . '.' . $ext;
        $upload = $this->run_with_photowooshop_upload_dir(function () use ($filename, $data) {
            return wp_upload_bits($filename, null, $data);
        });

        if ($upload['error']) {
            wp_send_json_error($upload['error']);
        }

        wp_send_json_success(array('url' => $upload['url']));
    }

    public function ajax_upload_audio()
    {
        check_ajax_referer('photowooshop_nonce', 'nonce');

        if (!$this->check_rate_limit('upload_audio', 20, 60)) {
            wp_send_json_error('Túl sok kérés, próbáld újra később.');
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $upload_token = isset($_POST['upload_token']) ? sanitize_text_field($_POST['upload_token']) : '';
        if (!$product_id || !$this->verify_upload_token($product_id, $upload_token)) {
            wp_send_json_error('Érvénytelen munkamenet.');
        }

        if (empty($_FILES['audio'])) {
            wp_send_json_error('No audio file');
        }

        if (!isset($_FILES['audio']['size']) || (int) $_FILES['audio']['size'] <= 0) {
            wp_send_json_error('Érvénytelen hangfájl.');
        }

        if ((int) $_FILES['audio']['size'] > self::AUDIO_UPLOAD_MAX_BYTES) {
            wp_send_json_error('A hangfájl túl nagy.');
        }

        $audio_validation = $this->validate_audio_upload_file($_FILES['audio']);
        if (is_wp_error($audio_validation)) {
            wp_send_json_error($audio_validation->get_error_message());
        }

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $audio_file = $_FILES['audio'];
        $audio_ext = strtolower(pathinfo($audio_file['name'], PATHINFO_EXTENSION));
        if (empty($audio_ext)) {
            $audio_ext = 'mp3';
        }
        $audio_file['name'] = 'photowooshop_audio_' . uniqid('', true) . '.' . sanitize_file_name($audio_ext);

        $upload = $this->run_with_photowooshop_upload_dir(function () use ($audio_file) {
            return wp_handle_upload($audio_file, array(
                'test_form' => false,
                'mimes' => array(
                    'mp3' => 'audio/mpeg',
                    'wav' => 'audio/wav',
                    'ogg' => 'audio/ogg',
                    'm4a' => 'audio/mp4',
                    'aac' => 'audio/aac',
                ),
            ));
        });

        if (isset($upload['error'])) {
            wp_send_json_error($upload['error']);
        }

        wp_send_json_success(array('url' => $upload['url']));
    }

    private function collect_referenced_material_urls()
    {
        $statuses = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed');
        $offset = 0;
        $chunk_size = 100;
        $references = array();

        while (true) {
            $orders = wc_get_orders(array(
                'limit' => $chunk_size,
                'offset' => $offset,
                'status' => $statuses,
                'orderby' => 'date',
                'order' => 'DESC',
            ));

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    $montage_url = $item->get_meta('_photowooshop_montage_url') ?: $item->get_meta('Egyedi Montázs URL');
                    if (!empty($montage_url)) {
                        $references[$montage_url] = true;
                    }

                    $audio_url = $item->get_meta('_photowooshop_audio_url') ?: $item->get_meta('Hangfájl');
                    if (!empty($audio_url)) {
                        $references[$audio_url] = true;
                    }

                    $individual = $item->get_meta('_photowooshop_individual_images');
                    if (!empty($individual)) {
                        $urls = json_decode($individual, true);
                        if (is_array($urls)) {
                            foreach ($urls as $url) {
                                if (!empty($url)) {
                                    $references[$url] = true;
                                }
                            }
                        }
                    }
                }
            }

            if (count($orders) < $chunk_size) {
                break;
            }

            $offset += $chunk_size;
        }

        return $references;
    }

    private function build_upload_url_from_path($absolute_path, $uploads)
    {
        $base_dir = wp_normalize_path($uploads['basedir']);
        $normalized_path = wp_normalize_path($absolute_path);
        if (strpos($normalized_path, $base_dir) !== 0) {
            return '';
        }

        $relative = ltrim(substr($normalized_path, strlen($base_dir)), '/');
        return trailingslashit($uploads['baseurl']) . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function resolve_material_url($url)
    {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return '';
        }

        $uploads = wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return $url;
        }

        $base_url = trailingslashit($uploads['baseurl']);
        $base_dir = trailingslashit($uploads['basedir']);

        if (strpos($url, $base_url) === 0) {
            $relative = ltrim(substr($url, strlen($base_url)), '/');
            $abs_path = $base_dir . $relative;
            if (file_exists($abs_path)) {
                return $url;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        $basename = $path ? basename($path) : basename($url);
        $basename = rawurldecode($basename);
        $basename = sanitize_file_name($basename);
        if ($basename === '') {
            return $url;
        }

        $candidate = $base_dir . self::UPLOAD_SUBDIR . '/' . $basename;
        if (file_exists($candidate)) {
            return $base_url . self::UPLOAD_SUBDIR . '/' . $basename;
        }

        $fallback = $this->find_migrated_file_url($base_dir, $base_url, $basename);
        if (!empty($fallback)) {
            return $fallback;
        }

        return $url;
    }

    private function find_migrated_file_url($base_dir, $base_url, $basename)
    {
        $target_dir = trailingslashit($base_dir) . self::UPLOAD_SUBDIR . '/';
        if (!is_dir($target_dir)) {
            return '';
        }

        $stem = pathinfo($basename, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if ($stem === '') {
            return '';
        }

        $patterns = array();
        if (!empty($ext)) {
            $patterns[] = $target_dir . $stem . '-*.' . $ext;
            $patterns[] = $target_dir . $stem . '*.' . $ext;
        }
        $patterns[] = $target_dir . $stem . '*';

        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if (empty($matches) || !is_array($matches)) {
                continue;
            }

            foreach ($matches as $match) {
                if (!is_file($match)) {
                    continue;
                }

                return trailingslashit($base_url) . self::UPLOAD_SUBDIR . '/' . basename($match);
            }
        }

        return '';
    }

    public function cleanup_orphan_uploads()
    {
        $report = array(
            'status' => 'started',
            'started_at' => current_time('mysql'),
            'finished_at' => '',
            'scanned_files' => 0,
            'matched_prefix' => 0,
            'older_than_cutoff' => 0,
            'referenced_skipped' => 0,
            'deleted_files' => 0,
            'delete_errors' => 0,
        );

        if (!function_exists('wc_get_orders')) {
            $report['status'] = 'skipped_woocommerce_missing';
            $report['finished_at'] = current_time('mysql');
            update_option(self::CLEANUP_REPORT_OPTION, $report, false);
            return;
        }

        $uploads = wp_upload_dir();
        $scope_dir = trailingslashit($uploads['basedir']) . self::UPLOAD_SUBDIR;
        if (empty($uploads['basedir']) || !is_dir($scope_dir)) {
            $report['status'] = 'skipped_upload_dir_missing';
            $report['finished_at'] = current_time('mysql');
            update_option(self::CLEANUP_REPORT_OPTION, $report, false);
            return;
        }

        $cutoff_timestamp = time() - (DAY_IN_SECONDS * self::CLEANUP_AGE_DAYS);
        $references = $this->collect_referenced_material_urls();

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($scope_dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $report['scanned_files']++;

                $filename = $file->getFilename();
                if (strpos($filename, 'photowooshop_') !== 0 && strpos($filename, 'photowooshop_audio_') !== 0) {
                    continue;
                }

                $report['matched_prefix']++;

                if ($file->getMTime() > $cutoff_timestamp) {
                    continue;
                }

                $report['older_than_cutoff']++;

                $file_path = $file->getPathname();
                $file_url = $this->build_upload_url_from_path($file_path, $uploads);
                if (!empty($file_url) && isset($references[$file_url])) {
                    $report['referenced_skipped']++;
                    continue;
                }

                if (@unlink($file_path)) {
                    $report['deleted_files']++;
                } else {
                    $report['delete_errors']++;
                }
            }
        } catch (Throwable $e) {
            $report['status'] = 'failed_scan_exception';
            $report['error_message'] = sanitize_text_field($e->getMessage());
            $report['finished_at'] = current_time('mysql');
            update_option(self::CLEANUP_REPORT_OPTION, $report, false);
            return;
        }

        $report['status'] = 'completed';
        $report['finished_at'] = current_time('mysql');
        update_option(self::CLEANUP_REPORT_OPTION, $report, false);
    }

    public function ajax_sync_session()
    {
        check_ajax_referer('photowooshop_nonce', 'nonce');

        if (!$this->check_rate_limit('sync_session', 60, 60)) {
            wp_send_json_error('Túl sok kérés, próbáld újra később.');
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error('Invalid product');
        }

        $upload_token = isset($_POST['upload_token']) ? sanitize_text_field($_POST['upload_token']) : '';
        if (!$this->verify_upload_token($product_id, $upload_token)) {
            wp_send_json_error('Érvénytelen munkamenet.');
        }

        $data = array(
            'montage_url' => isset($_POST['montage_url']) ? sanitize_text_field($_POST['montage_url']) : '',
            'individual_images' => isset($_POST['individual_images']) ? json_decode(stripslashes($_POST['individual_images']), true) : array(),
            'text' => isset($_POST['text']) ? sanitize_text_field($_POST['text']) : '',
            'audio_url' => isset($_POST['audio_url']) ? sanitize_text_field($_POST['audio_url']) : '',
        );

        if (function_exists('WC') && WC()->session) {
            WC()->session->set('photowooshop_temp_data_' . $product_id, $data);
            wp_send_json_success('Sync success');
        } else {
            wp_send_json_error('WC Session not available');
        }
    }

    public function get_item_data($item_data, $cart_item)
    {
        if (isset($cart_item['photowooshop_image'])) {
            $item_data[] = array(
                'name' => esc_html__('Egyedi Montázs', 'photowooshop'),
                'value' => '<a href="' . esc_url($cart_item['photowooshop_image']) . '" target="_blank"><img src="' . esc_attr($cart_item['photowooshop_image']) . '" style="max-width: 100px; display:block; margin-top:5px; border:1px solid #ddd;" /></a>',
            );
        }

        if (isset($cart_item['photowooshop_text'])) {
            $item_data[] = array(
                'name' => esc_html__('Egyedi Szöveg', 'photowooshop'),
                'value' => esc_html($cart_item['photowooshop_text']),
            );
        }

        if (isset($cart_item['photowooshop_audio'])) {
            $item_data[] = array(
                'name' => esc_html__('Hangfájl', 'photowooshop'),
                'value' => '<a href="' . esc_url($cart_item['photowooshop_audio']) . '" target="_blank">' . basename($cart_item['photowooshop_audio']) . '</a>',
            );
        }

        if (isset($cart_item['photowooshop_audio_consent']) && $cart_item['photowooshop_audio_consent'] === 'yes') {
            $item_data[] = array(
                'name' => esc_html__('Hangfájl felhasználás jóváhagyva', 'photowooshop'),
                'value' => esc_html__('Igen', 'photowooshop'),
            );
        }

        return $item_data;
    }

    public function add_order_item_meta($item, $cart_item_key, $values, $order)
    {
        if (isset($values['photowooshop_image'])) {
            $item->add_meta_data(esc_html__('Egyedi Montázs URL', 'photowooshop'), $values['photowooshop_image'], true);
            $item->add_meta_data('_photowooshop_montage_url', $values['photowooshop_image'], true);
        }
        if (isset($values['photowooshop_text'])) {
            $item->add_meta_data(esc_html__('Egyedi Szöveg', 'photowooshop'), $values['photowooshop_text'], true);
            $item->add_meta_data('_photowooshop_text', $values['photowooshop_text'], true);
        }
        if (isset($values['photowooshop_audio'])) {
            $item->add_meta_data(esc_html__('Hangfájl', 'photowooshop'), $values['photowooshop_audio'], true);
            $item->add_meta_data('_photowooshop_audio_url', $values['photowooshop_audio'], true);
        }
        if (isset($values['photowooshop_audio_consent']) && $values['photowooshop_audio_consent'] === 'yes') {
            $item->add_meta_data(esc_html__('Hangfájl felhasználás jóváhagyva', 'photowooshop'), 'Igen', true);
            $item->add_meta_data('_photowooshop_audio_consent', 'yes', true);
        }
        if (isset($values['photowooshop_individual_images'])) {
            $item->add_meta_data('_photowooshop_individual_images', json_encode($values['photowooshop_individual_images']), true);
            // Keep labeled ones for display
            foreach ($values['photowooshop_individual_images'] as $idx => $url) {
                $item->add_meta_data(sprintf(esc_html__('Egyedi Kép %s', 'photowooshop'), $idx), $url, true);
            }
        }
    }

    private function get_order_materials($order)
    {
        $materials = array('images' => array(), 'audio' => '');
        foreach ($order->get_items() as $item) {
            // Main montage
            $montage = $item->get_meta('_photowooshop_montage_url') ?: $item->get_meta('Egyedi Montázs URL');
            if ($montage) {
                $materials['images'][] = $this->resolve_material_url($montage);
            }

            // Audio
            $audio = $item->get_meta('_photowooshop_audio_url') ?: $item->get_meta('Hangfájl');
            if ($audio) {
                $materials['audio'] = $this->resolve_material_url($audio);
            }

            // Individual images (try new JSON first, fallback to old key search)
            $individual = $item->get_meta('_photowooshop_individual_images');
            if ($individual) {
                $urls = json_decode($individual, true);
                if (is_array($urls)) {
                    foreach ($urls as $url) {
                        $resolved = $this->resolve_material_url($url);
                        if (!empty($resolved)) {
                            $materials['images'][] = $resolved;
                        }
                    }
                }
            } else {
                foreach ($item->get_meta_data() as $meta) {
                    if (strpos($meta->key, 'Egyedi Kép') !== false) {
                        $resolved = $this->resolve_material_url($meta->value);
                        if (!empty($resolved)) {
                            $materials['images'][] = $resolved;
                        }
                    }
                }
            }
        }
        return $materials;
    }

    public function ajax_get_order_details()
    {
        check_ajax_referer('photowooshop_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Invalid order');
        }

        try {
            $materials = $this->get_order_materials($order);
        } catch (Throwable $e) {
            wp_send_json_error('Az anyagok feldolgozása sikertelen.');
        }

        // Fetch custom text from first item that has it
        $text = '';
        foreach ($order->get_items() as $item) {
            $text = $item->get_meta('_photowooshop_text') ?: $item->get_meta('Egyedi Szöveg');
            if ($text)
                break;
        }

        // Separate main montage and individual images for better UI handling
        $montage = '';
        $individual = array();

        foreach ($order->get_items() as $item) {
            $m = $item->get_meta('_photowooshop_montage_url') ?: $item->get_meta('Egyedi Montázs URL');
            if ($m) {
                $montage = $this->resolve_material_url($m);
            }

            $indiv = $item->get_meta('_photowooshop_individual_images');
            if ($indiv) {
                $urls = json_decode($indiv, true);
                if (is_array($urls)) {
                    foreach ($urls as $url) {
                        $resolved = $this->resolve_material_url($url);
                        if (!empty($resolved)) {
                            $individual[] = $resolved;
                        }
                    }
                }
            } else {
                foreach ($item->get_meta_data() as $meta) {
                    if (strpos($meta->key, 'Egyedi Kép') !== false) {
                        $resolved = $this->resolve_material_url($meta->value);
                        if (!empty($resolved)) {
                            $individual[] = $resolved;
                        }
                    }
                }
            }
        }

        wp_send_json_success(array(
            'montage' => $montage,
            'individual' => array_unique($individual),
            'text' => $text,
            'audio' => $materials['audio']
        ));
    }

    public function ajax_download_zip()
    {
        check_ajax_referer('photowooshop_admin_nonce', 'nonce');

        if (!current_user_can('manage_options'))
            wp_die('Access denied');

        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order)
            wp_die('Invalid order');

        try {
            $materials = $this->get_order_materials($order);
        } catch (Throwable $e) {
            wp_die('Order materials processing failed');
        }

        $zip = new ZipArchive();
        $zip_name = 'order_' . $order_id . '_materials.zip';
        $zip_path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $zip_name;

        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            wp_die('Could not create ZIP');
        }

        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        $base_path = $upload_dir['basedir'];

        $file_idx = 1;
        foreach ($materials['images'] as $url) {
            $rel_path = str_replace($base_url, '', $url);
            $abs_path = $base_path . $rel_path;
            if (file_exists($abs_path)) {
                $zip->addFile($abs_path, 'image_' . ($file_idx++) . '.jpg');
            }
        }

        if ($materials['audio']) {
            $rel_path = str_replace($base_url, '', $materials['audio']);
            $abs_path = $base_path . $rel_path;
            if (file_exists($abs_path)) {
                $zip->addFile($abs_path, 'audio_' . basename($abs_path));
            }
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename=' . $zip_name);
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        unlink($zip_path);
        exit;
    }

    public function ajax_delete_materials()
    {
        check_ajax_referer('photowooshop_admin_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Access denied');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order)
            wp_send_json_error('Invalid order');

        $materials = $this->get_order_materials($order);
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        $base_path = $upload_dir['basedir'];

        // Delete files
        foreach ($materials['images'] as $url) {
            $rel_path = str_replace($base_url, '', $url);
            $abs_path = $base_path . $rel_path;
            if (file_exists($abs_path))
                unlink($abs_path);
        }
        if ($materials['audio']) {
            $rel_path = str_replace($base_url, '', $materials['audio']);
            $abs_path = $base_path . $rel_path;
            if (file_exists($abs_path))
                unlink($abs_path);
        }

        // Remove meta from order items
        foreach ($order->get_items() as $item) {
            $item->delete_meta_data('Egyedi Montázs URL');
            $item->delete_meta_data('Hangfájl');
            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, 'Egyedi Kép') !== false) {
                    $item->delete_meta_data($meta->key);
                }
            }
            $item->save();
        }

        wp_send_json_success();
    }

    private function repair_order_materials_meta($order, &$updated, &$unchanged)
    {
        foreach ($order->get_items() as $item) {
            $item_changed = false;

            $single_keys = array(
                '_photowooshop_montage_url',
                'Egyedi Montázs URL',
                '_photowooshop_audio_url',
                'Hangfájl',
            );

            foreach ($single_keys as $key) {
                $old_value = $item->get_meta($key, true);
                if (empty($old_value) || !is_string($old_value)) {
                    continue;
                }

                $resolved = $this->resolve_material_url($old_value);
                if (!empty($resolved) && $resolved !== $old_value) {
                    $item->update_meta_data($key, $resolved);
                    $updated++;
                    $item_changed = true;
                } else {
                    $unchanged++;
                }
            }

            $json_urls = $item->get_meta('_photowooshop_individual_images', true);
            if (!empty($json_urls)) {
                $decoded = json_decode($json_urls, true);
                if (is_array($decoded)) {
                    $json_changed = false;
                    foreach ($decoded as $i => $url) {
                        if (!is_string($url) || $url === '') {
                            continue;
                        }
                        $resolved = $this->resolve_material_url($url);
                        if (!empty($resolved) && $resolved !== $url) {
                            $decoded[$i] = $resolved;
                            $updated++;
                            $json_changed = true;
                            $item_changed = true;
                        } else {
                            $unchanged++;
                        }
                    }
                    if ($json_changed) {
                        $item->update_meta_data('_photowooshop_individual_images', wp_json_encode($decoded));
                    }
                }
            }

            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, 'Egyedi Kép') === false) {
                    continue;
                }
                $old_value = (string) $meta->value;
                if ($old_value === '') {
                    continue;
                }
                $resolved = $this->resolve_material_url($old_value);
                if (!empty($resolved) && $resolved !== $old_value) {
                    $item->update_meta_data($meta->key, $resolved);
                    $updated++;
                    $item_changed = true;
                } else {
                    $unchanged++;
                }
            }

            if ($item_changed) {
                $item->save();
            }
        }
    }

    public function ajax_repair_order_materials()
    {
        check_ajax_referer('photowooshop_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($order_id <= 0) {
            wp_send_json_error('Invalid order');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
        }

        $updated = 0;
        $unchanged = 0;

        $this->repair_order_materials_meta($order, $updated, $unchanged);

        wp_send_json_success(array(
            'updated' => $updated,
            'unchanged' => $unchanged,
        ));
    }
}

register_activation_hook(__FILE__, array('Photowooshop', 'activate'));
register_deactivation_hook(__FILE__, array('Photowooshop', 'deactivate'));

Photowooshop::get_instance();
