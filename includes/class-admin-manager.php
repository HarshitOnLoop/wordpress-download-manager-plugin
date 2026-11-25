<?php
if (!defined('ABSPATH')) {
    exit;
}

class CDM_Admin_Manager {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
        add_action('admin_post_cdm_save_resource', array(__CLASS__, 'save_resource'));
        add_action('admin_post_cdm_delete_resource', array(__CLASS__, 'delete_resource'));
        add_action('admin_post_cdm_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_cdm_export_logs', array(__CLASS__, 'export_logs'));
        add_action('admin_post_cdm_test_webhook', array(__CLASS__, 'test_webhook'));
    }

    public static function admin_assets() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'cdm-') === false) {
            return;
        }
        wp_enqueue_style('cdm-admin', CDM_PLUGIN_URL . 'assets/admin.css', array(), CDM_VERSION);
        if (strpos($screen->id, 'cdm-form-settings') !== false) {
            wp_enqueue_style('cdm-front-preview', CDM_PLUGIN_URL . 'assets/frontend.css', array(), CDM_VERSION);
            wp_enqueue_script('cdm-admin', CDM_PLUGIN_URL . 'assets/admin.js', array(), CDM_VERSION, true);
        }
        if (strpos($screen->id, 'cdm-resources') !== false) {
            wp_enqueue_script('cdm-admin-resources', CDM_PLUGIN_URL . 'assets/admin-resources.js', array(), CDM_VERSION, true);
        }
    }

    public static function menu() {
        add_menu_page('Download Manager', 'Download Manager', 'manage_options', 'cdm-dashboard', array(__CLASS__, 'overview_page'), 'dashicons-download', 30);
        add_submenu_page('cdm-dashboard', 'Overview', 'Overview', 'manage_options', 'cdm-dashboard', array(__CLASS__, 'overview_page'));
        add_submenu_page('cdm-dashboard', 'Resources', 'Resources', 'manage_options', 'cdm-resources', array(__CLASS__, 'resources_page'));
        add_submenu_page('cdm-dashboard', 'Form Settings', 'Form Settings', 'manage_options', 'cdm-form-settings', array(__CLASS__, 'form_settings_page'));
        add_submenu_page('cdm-dashboard', 'Webhook Settings', 'Webhook Settings', 'manage_options', 'cdm-webhook-settings', array(__CLASS__, 'webhook_settings_page'));
        add_submenu_page('cdm-dashboard', 'Restrictions', 'Restrictions', 'manage_options', 'cdm-restrictions', array(__CLASS__, 'restrictions_page'));
        // The page slug stays "cdm-logs" so existing bookmarks keep working.
        add_submenu_page('cdm-dashboard', 'Form Submissions', 'Form Submissions', 'manage_options', 'cdm-logs', array(__CLASS__, 'logs_page'));
    }

    private static function top() {
        echo '<div class="wrap cdm-admin"><h1>Custom Download Manager</h1>';
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($page !== 'cdm-webhook-settings' && !CDM_Webhook_Manager::is_configured()) {
            echo '<div class="notice notice-warning inline"><p><strong>No webhook set up yet.</strong> Form submissions cannot be delivered until you add a webhook URL under <a href="' . esc_url(admin_url('admin.php?page=cdm-webhook-settings')) . '">Webhook Settings</a>.</p></div>';
        }
    }

    private static function admin_notice() {
        if (isset($_GET['cdm_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Saved successfully.</p></div>';
        }
        if (isset($_GET['cdm_webhook_ok'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Test sent. Your webhook answered with a success status.</p></div>';
        }
        if (isset($_GET['cdm_webhook_failed'])) {
            $reason = get_transient('cdm_last_webhook_error');
            echo '<div class="notice notice-error"><p>The test webhook could not be delivered.' . ($reason ? ' Reason: ' . esc_html($reason) : '') . ' Check the URL and that the receiving service is listening.</p></div>';
        }
        $error = isset($_GET['cdm_error']) ? sanitize_key($_GET['cdm_error']) : '';
        if ($error === 'duplicate') {
            echo '<div class="notice notice-error"><p>That resource ID is already in use. Choose a unique ID.</p></div>';
        } elseif ($error === 'page_assigned') {
            echo '<div class="notice notice-error"><p>That snapshot already has a resource assigned. Assign each snapshot only one resource when using the automatic shortcode.</p></div>';
        } elseif ($error === 'bad_snapshot') {
            echo '<div class="notice notice-error"><p>Please choose a post of the Snapshot type, or leave the field empty.</p></div>';
        } elseif ($error === 'etsy_url') {
            echo '<div class="notice notice-error"><p>Enter a valid link beginning with http:// or https://, or leave the link blank.</p></div>';
        } elseif ($error !== '') {
            echo '<div class="notice notice-error"><p>Please enter a resource name, unique ID, and valid download URL.</p></div>';
        }
    }

    /* ---------- small form helpers ---------- */

    private static function val($s, $key) {
        return isset($s[$key]) ? $s[$key] : '';
    }

    private static function text_input($s, $key, $class = 'regular-text', $placeholder = '') {
        return '<input id="cdm-' . esc_attr($key) . '" type="text" name="settings[' . esc_attr($key) . ']" value="' . esc_attr(self::val($s, $key)) . '" class="' . esc_attr($class) . '"' . ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '') . '>';
    }

    private static function check_input($s, $key, $label = '') {
        return '<label><input id="cdm-' . esc_attr($key) . '" type="checkbox" name="settings[' . esc_attr($key) . ']" value="1" ' . checked(!empty($s[$key]), true, false) . '>' . ($label !== '' ? ' ' . esc_html($label) : '') . '</label>';
    }

    private static function color_input($s, $key) {
        return '<input id="cdm-' . esc_attr($key) . '" type="color" name="settings[' . esc_attr($key) . ']" value="' . esc_attr(self::val($s, $key)) . '">';
    }

    private static function number_input($s, $key, $min, $max, $unit = '') {
        return '<input id="cdm-' . esc_attr($key) . '" type="number" name="settings[' . esc_attr($key) . ']" value="' . esc_attr(self::val($s, $key)) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" class="small-text"> ' . esc_html($unit);
    }

    private static function select_input($s, $key, $options) {
        $html = '<select id="cdm-' . esc_attr($key) . '" name="settings[' . esc_attr($key) . ']">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . selected(self::val($s, $key), $value, false) . '>' . esc_html($label) . '</option>';
        }
        return $html . '</select>';
    }

    private static function textarea_input($s, $key, $rows = 4, $class = 'large-text') {
        return '<textarea id="cdm-' . esc_attr($key) . '" name="settings[' . esc_attr($key) . ']" rows="' . absint($rows) . '" class="' . esc_attr($class) . '">' . esc_textarea(self::val($s, $key)) . '</textarea>';
    }

    private static function row($label, $control, $description = '', $for = '') {
        return '<tr><th scope="row">' . ($for !== '' ? '<label for="cdm-' . esc_attr($for) . '">' . esc_html($label) . '</label>' : esc_html($label)) . '</th><td>' . $control .
            ($description !== '' ? '<p class="description">' . wp_kses($description, array('code' => array(), 'strong' => array())) . '</p>' : '') . '</td></tr>';
    }

    private static function form_open($group, $id = '') {
        echo '<form' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . ' method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cdm_save_settings');
        echo '<input type="hidden" name="action" value="cdm_save_settings"><input type="hidden" name="group" value="' . esc_attr($group) . '">';
    }

    /* ---------- pages ---------- */

    public static function overview_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cdm_requests';
        $resource_table = $wpdb->prefix . 'cdm_resources';
        $day_start = new DateTimeImmutable('today', wp_timezone());
        $today = $day_start->format('Y-m-d H:i:s');
        $next = $day_start->modify('+1 day')->format('Y-m-d H:i:s');
        $stats = array(
            'Total Submissions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'Successful' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE request_status='approved'"),
            'Blocked (limit reached)' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE request_status='blocked'"),
            'Failed Webhook Deliveries' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE email_status='failed'"),
            'Downloads Today' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE request_status='approved' AND submitted_at >= %s AND submitted_at < %s", $today, $next)),
            'Unique Emails' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT email) FROM {$table}"),
            'Total Resources' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$resource_table}"),
        );
        $recent = $wpdb->get_results("SELECT * FROM {$table} ORDER BY submitted_at DESC, id DESC LIMIT 10");

        self::top();
        echo '<div class="cdm-stats-grid">';
        foreach ($stats as $label => $value) {
            echo '<div class="cdm-stat-card"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';

        echo '<div class="cdm-admin-card cdm-wide-card"><div class="cdm-card-head"><h2>Recent form submissions</h2><a class="button" href="' . esc_url(admin_url('admin.php?page=cdm-logs')) . '">View all submissions</a></div>';
        self::submissions_table($recent, true);
        echo '</div>';

        echo '<div class="cdm-admin-card cdm-quickstart"><h2>Quick start</h2><ol>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=cdm-resources')) . '"><strong>Resources</strong></a> &ndash; add a resource with its download URL (optionally assign a Snapshot).</li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=cdm-form-settings')) . '"><strong>Form Settings</strong></a> &ndash; choose fields, colors, text, and add your own CSS with a live preview.</li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=cdm-webhook-settings')) . '"><strong>Webhook Settings</strong></a> &ndash; add the webhook URL that receives every submission, then send a test.</li>';
        echo '<li>Paste <code>[custom_download_form]</code> into a page or Snapshot (Shortcode block) &ndash; or <code>[custom_download_form resource="your-id"]</code>. For a popup use <code>[custom_download_button resource="your-id" text="Download"]</code>.</li>';
        echo '</ol></div>';
        echo '</div>';
    }

    public static function resources_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::top();
        self::admin_notice();
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        global $wpdb;
        $resource = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CDM_Resource_Manager::table() . " WHERE id=%d", $edit_id)) : null;
        echo '<div class="cdm-resource-intro"><h2>' . ($resource ? 'Edit Resource' : 'Add Resource') . '</h2><p>Set the free download link and, optionally, a matching Etsy listing to offer when a visitor reaches the daily free-download limit.</p></div>';
        echo '<form class="cdm-admin-card cdm-resource-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cdm_save_resource');
        echo '<input type="hidden" name="action" value="cdm_save_resource"><input type="hidden" name="id" value="' . esc_attr($resource ? $resource->id : 0) . '">';
        echo '<table class="form-table"><tbody>';
        foreach (array(
            'name' => array('Resource Name', $resource ? $resource->name : ''),
            'slug' => array('Unique Resource Slug/ID', $resource ? $resource->slug : ''),
            'download_url' => array('Download URL', $resource ? $resource->download_url : ''),
            'etsy_url' => array('Etsy Listing URL (optional)', $resource && isset($resource->etsy_url) ? $resource->etsy_url : ''),
            'page_id' => array('Associated Snapshot (optional)', $resource ? $resource->page_id : ''),
        ) as $key => $field) {
            echo '<tr><th><label for="res-' . esc_attr($key) . '">' . esc_html($field[0]) . '</label></th><td>';
            if ($key === 'page_id') {
                echo self::snapshot_combobox(absint($field[1]));
                echo '<p class="description">Search and pick a Snapshot. Only Snapshot posts are listed. The form shortcode on that Snapshot then uses this resource automatically.</p>';
            } else {
                $input_type = in_array($key, array('download_url', 'etsy_url'), true) ? 'url' : 'text';
                $required = in_array($key, array('name', 'slug', 'download_url'), true) ? ' required' : '';
                echo '<input class="regular-text" id="res-' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($field[1]) . '" type="' . esc_attr($input_type) . '"' . $required . '>';
                if ($key === 'etsy_url') {
                    echo '<p class="description">This button appears with the daily-limit message and opens your Etsy listing.</p>';
                }
            }
            echo '</td></tr>';
        }
        echo '<tr><th>Status</th><td><select name="status"><option value="active"' . selected($resource ? $resource->status : 'active', 'active', false) . '>Active</option><option value="inactive"' . selected($resource ? $resource->status : 'active', 'inactive', false) . '>Inactive</option></select></td></tr>';
        echo '<tr><th>Description</th><td><textarea name="description" class="large-text" rows="4">' . esc_textarea($resource ? $resource->description : '') . '</textarea></td></tr>';
        echo '</tbody></table><p><button class="button button-primary">Save Resource</button>';
        if ($resource) {
            echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=cdm-resources')) . '">Cancel</a>';
        }
        echo '</p></form><p class="cdm-shortcode-help">For the easiest setup, assign a Snapshot to each resource and put <code>[custom_download_form]</code> inside that Snapshot. Or use <code>[custom_download_form resource="resource-id"]</code> to select a resource explicitly.</p><h2>Resources</h2>';
        echo '<div class="cdm-admin-card cdm-resource-table"><table class="wp-list-table widefat fixed striped"><thead><tr><th>Name</th><th>Resource ID / Form Shortcode</th><th>Snapshot</th><th>Status</th><th>Free Download URL</th><th>Etsy Offer</th><th>Actions</th></tr></thead><tbody>';
        foreach (CDM_Resource_Manager::all() as $item) {
            $page_title = $item->page_id ? get_the_title($item->page_id) : '—';
            $status_class = $item->status === 'active' ? 'cdm-status-active' : 'cdm-status-inactive';
            echo '<tr><td><strong>' . esc_html($item->name) . '</strong></td><td><code>' . esc_html($item->slug) . '</code><br><code>[custom_download_form resource="' . esc_html($item->slug) . '"]</code></td><td>' . esc_html($page_title) . '</td><td><span class="cdm-status ' . esc_attr($status_class) . '">' . esc_html(ucfirst($item->status)) . '</span></td><td><a href="' . esc_url($item->download_url) . '" target="_blank" rel="noopener noreferrer">Open link</a></td><td>';
            if (!empty($item->etsy_url)) {
                echo '<a class="cdm-etsy-admin-link" href="' . esc_url($item->etsy_url) . '" target="_blank" rel="noopener noreferrer">View Etsy listing</a>';
            } else {
                echo '<span class="cdm-muted">Not set</span>';
            }
            echo '</td><td>';
            echo '<a href="' . esc_url(add_query_arg(array('page' => 'cdm-resources', 'edit' => $item->id), admin_url('admin.php'))) . '">Edit</a> ';
            $delete_url = wp_nonce_url(admin_url('admin-post.php?action=cdm_delete_resource&id=' . $item->id), 'cdm_delete_resource');
            echo '<a href="' . esc_url($delete_url) . '" onclick="return confirm(\'Delete this resource?\')">Delete</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * Searchable single-select of Snapshot posts (vanilla JS in assets/admin-resources.js).
     */
    private static function snapshot_combobox($selected_id) {
        $type = CDM_Resource_Manager::snapshot_post_type();
        $items = array();
        $selected_label = '';
        $has_selected = false;
        foreach (CDM_Resource_Manager::snapshots() as $post) {
            $title = $post->post_title !== '' ? $post->post_title : '(no title)';
            if ($post->post_status !== 'publish') {
                $title .= ' — ' . ucfirst($post->post_status);
            }
            $items[] = array('id' => (int) $post->ID, 'label' => $title);
            if ((int) $post->ID === $selected_id) {
                $selected_label = $title;
                $has_selected = true;
            }
        }
        // Keep an older assignment (e.g. a regular page) visible so saving does not silently remove it.
        if ($selected_id && !$has_selected) {
            $legacy = get_the_title($selected_id);
            $selected_label = ($legacy !== '' ? $legacy : '#' . $selected_id) . ' (not a Snapshot)';
            array_unshift($items, array('id' => $selected_id, 'label' => $selected_label));
        }

        $uid = 'cdm-combo-list';
        $html = '<div class="cdm-combo" data-cdm-combo>';
        $html .= '<input type="hidden" id="res-page_id" name="page_id" value="' . esc_attr($selected_id) . '">';
        $html .= '<div class="cdm-combo-field"><input type="text" class="cdm-combo-input regular-text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr($uid) . '" autocomplete="off" placeholder="' . esc_attr__('Search Snapshots…', 'custom-download-manager') . '" value="' . esc_attr($selected_label) . '">';
        $html .= '<button type="button" class="cdm-combo-toggle" aria-label="' . esc_attr__('Show all Snapshots', 'custom-download-manager') . '" tabindex="-1"><span class="dashicons dashicons-arrow-down-alt2"></span></button></div>';
        $html .= '<ul class="cdm-combo-list" id="' . esc_attr($uid) . '" role="listbox" hidden>';
        $html .= '<li role="option" class="cdm-combo-option" data-value="0" data-label="">' . esc_html('— No Snapshot assigned —') . '</li>';
        foreach ($items as $item) {
            $html .= '<li role="option" class="cdm-combo-option" data-value="' . esc_attr($item['id']) . '" data-label="' . esc_attr($item['label']) . '">' . esc_html($item['label']) . ' <span class="cdm-combo-id">#' . esc_html($item['id']) . '</span></li>';
        }
        $html .= '<li class="cdm-combo-empty" hidden>' . esc_html__('No Snapshots match your search.', 'custom-download-manager') . '</li>';
        $html .= '</ul></div>';
        if (!post_type_exists($type)) {
            $html .= '<p class="description" style="color:#b32d2e">The "' . esc_html($type) . '" post type was not found on this site. If your Snapshots use a different slug, tell the plugin with the <code>cdm_snapshot_post_type</code> filter.</p>';
        }
        return $html;
    }

    public static function save_resource() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('cdm_save_resource');
        $id = absint(isset($_POST['id']) ? $_POST['id'] : 0);
        $slug = sanitize_title(wp_unslash(isset($_POST['slug']) ? $_POST['slug'] : ''));
        $name = sanitize_text_field(wp_unslash(isset($_POST['name']) ? $_POST['name'] : ''));
        $url = esc_url_raw(wp_unslash(isset($_POST['download_url']) ? $_POST['download_url'] : ''));
        $etsy_url_input = isset($_POST['etsy_url']) ? trim(wp_unslash($_POST['etsy_url'])) : '';
        $etsy_url = esc_url_raw($etsy_url_input);
        $back = admin_url('admin.php?page=cdm-resources');
        if ($slug === '' || $name === '' || $url === '') {
            wp_safe_redirect(add_query_arg('cdm_error', 'missing', $back));
            exit;
        }
        if ($etsy_url_input !== '' && (
            $etsy_url === '' ||
            !in_array(wp_parse_url($etsy_url, PHP_URL_SCHEME), array('http', 'https'), true)
        )) {
            wp_safe_redirect(add_query_arg('cdm_error', 'etsy_url', $back));
            exit;
        }
        if (CDM_Resource_Manager::slug_exists($slug, $id)) {
            wp_safe_redirect(add_query_arg('cdm_error', 'duplicate', $back));
            exit;
        }
        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        if ($page_id) {
            $current_page_id = 0;
            if ($id) {
                global $wpdb;
                $current_page_id = (int) $wpdb->get_var($wpdb->prepare('SELECT page_id FROM ' . CDM_Resource_Manager::table() . ' WHERE id = %d', $id));
            }
            // Only Snapshot posts can be newly assigned; an existing older assignment may stay as it is.
            if (get_post_type($page_id) !== CDM_Resource_Manager::snapshot_post_type() && $page_id !== $current_page_id) {
                wp_safe_redirect(add_query_arg('cdm_error', 'bad_snapshot', $back));
                exit;
            }
        }
        if (CDM_Resource_Manager::page_is_assigned($page_id, $id)) {
            wp_safe_redirect(add_query_arg('cdm_error', 'page_assigned', $back));
            exit;
        }
        $data = array(
            'name' => $name,
            'slug' => $slug,
            'page_id' => $page_id,
            'download_url' => $url,
            'etsy_url' => $etsy_url,
            'status' => isset($_POST['status']) ? sanitize_key($_POST['status']) : 'active',
            'description' => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
        );
        if ($id) {
            CDM_Resource_Manager::update_resource($id, $data);
        } else {
            CDM_Resource_Manager::insert($data);
        }
        wp_safe_redirect(add_query_arg('cdm_saved', '1', $back));
        exit;
    }

    public static function delete_resource() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('cdm_delete_resource');
        CDM_Resource_Manager::delete_resource(absint(isset($_GET['id']) ? $_GET['id'] : 0));
        wp_safe_redirect(add_query_arg('cdm_saved', '1', admin_url('admin.php?page=cdm-resources')));
        exit;
    }

    /**
     * Generic settings page used by the Email and Restrictions pages.
     * $rows is an array of pre-built <tr> rows.
     */
    private static function simple_settings_page($title, $group, $rows, $after = '') {
        self::top();
        self::admin_notice();
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<div class="cdm-admin-card">';
        self::form_open($group);
        echo '<table class="form-table"><tbody>' . implode('', $rows) . '</tbody></table><button class="button button-primary">Save Settings</button></form></div>';
        echo $after;
        echo '</div>';
    }

    public static function form_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = CDM_Form_Manager::settings();
        self::top();
        self::admin_notice();

        echo '<p class="cdm-resource-intro">Customize what the form asks for, how it looks, and what it says. The preview on the right updates as you type. Press <strong>Save Changes</strong> to publish to your site.</p>';
        echo '<div class="cdm-builder">';
        echo '<div class="cdm-builder-main">';
        self::form_open('form', 'cdm-builder-form');

        // 1. Content.
        echo '<div class="cdm-admin-card"><h2>1. Text &amp; messages</h2><table class="form-table"><tbody>';
        echo self::row('Form title', self::text_input($s, 'form_title') . '<br>' . self::check_input($s, 'show_title', 'Show title'), '', 'form_title');
        echo self::row('Form description', self::textarea_input($s, 'form_description', 3) . '<br>' . self::check_input($s, 'show_description', 'Show description'), '', 'form_description');
        echo self::row('Submit button text', self::text_input($s, 'submit_text'), '', 'submit_text');
        echo self::row('Success message', self::text_input($s, 'success_message', 'large-text'), '', 'success_message');
        echo self::row('Missing fields message', self::text_input($s, 'missing_fields_message', 'large-text'), '', 'missing_fields_message');
        echo self::row('Invalid email message', self::text_input($s, 'invalid_email_message', 'large-text'), '', 'invalid_email_message');
        echo self::row('Submission failed message', self::text_input($s, 'email_error_message', 'large-text'), '', 'email_error_message');
        echo self::row('Inactive resource message', self::text_input($s, 'inactive_message', 'large-text'), '', 'inactive_message');
        echo self::row('Etsy offer text', self::text_input($s, 'etsy_offer_text', 'large-text'), 'Shown above the Etsy button when a visitor hits the daily limit (only for resources with an Etsy URL).', 'etsy_offer_text');
        echo self::row('Etsy button text', self::text_input($s, 'etsy_button_text'), '', 'etsy_button_text');
        echo '</tbody></table></div>';

        // 2. Fields.
        echo '<div class="cdm-admin-card"><h2>2. Form fields</h2><p class="description">Turn fields on or off, make them required, and change their labels. Email is always required so you know who submitted the form.</p>';
        echo '<table class="widefat striped cdm-fields-table"><thead><tr><th>Field</th><th>Show</th><th>Required</th><th>Label</th><th>Placeholder</th></tr></thead><tbody>';
        foreach (CDM_Form_Manager::fields() as $key => $config) {
            echo '<tr><td><strong>' . esc_html(ucfirst($key)) . '</strong></td>';
            if ($config['locked']) {
                echo '<td><input type="checkbox" checked disabled></td><td><input type="checkbox" checked disabled></td>';
            } else {
                echo '<td>' . self::check_input($s, $key . '_enabled') . '</td><td>' . self::check_input($s, $key . '_required') . '</td>';
            }
            echo '<td>' . self::text_input($s, $key . '_label', 'regular-text') . '</td>';
            echo '<td>' . ($config['type'] === 'checkbox' ? '<span class="cdm-muted">Label may contain a link: &lt;a href="..."&gt;Privacy Policy&lt;/a&gt;</span>' : self::text_input($s, $key . '_placeholder', 'regular-text')) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        // 3. Design.
        echo '<div class="cdm-admin-card"><h2>3. Design</h2>';
        echo '<p class="cdm-presets"><strong>Start from a preset:</strong> ';
        foreach (array('modern' => 'Modern', 'minimal' => 'Minimal', 'dark' => 'Dark', 'soft' => 'Soft', 'bold' => 'Bold') as $preset => $label) {
            echo '<button type="button" class="button" data-cdm-preset="' . esc_attr($preset) . '">' . esc_html($label) . '</button> ';
        }
        echo '</p><table class="form-table"><tbody>';
        echo self::row('Button / accent color', self::color_input($s, 'primary_color'), '', 'primary_color');
        echo self::row('Form background', self::color_input($s, 'bg_color'), '', 'bg_color');
        echo self::row('Text color', self::color_input($s, 'text_color'), '', 'text_color');
        echo self::row('Border color', self::color_input($s, 'border_color'), '', 'border_color');
        echo self::row('Input background', self::color_input($s, 'input_bg'), '', 'input_bg');
        echo self::row('Corner radius', self::number_input($s, 'radius', 0, 60, 'px'), 'Applies to inputs, buttons and the form box.', 'radius');
        echo self::row('Inner spacing', self::number_input($s, 'padding', 8, 80, 'px'), '', 'padding');
        echo self::row('Maximum width', self::number_input($s, 'max_width', 280, 1000, 'px'), '', 'max_width');
        echo self::row('Text size', self::number_input($s, 'font_size', 12, 22, 'px'), '', 'font_size');
        echo self::row('Shadow', self::select_input($s, 'shadow', array('none' => 'None', 'soft' => 'Soft', 'strong' => 'Strong')), '', 'shadow');
        echo self::row('Text alignment', self::select_input($s, 'align', array('left' => 'Left', 'center' => 'Center')), '', 'align');
        echo self::row('Button style', self::select_input($s, 'button_style', array('solid' => 'Solid', 'outline' => 'Outline')) . '<br>' . self::check_input($s, 'btn_full', 'Full-width button'), '', 'button_style');
        echo '</tbody></table></div>';

        // 4. Sending progress bar.
        echo '<div class="cdm-admin-card"><h2>4. Sending progress bar</h2>';
        echo '<p class="description">When the visitor clicks the button, a progress bar with your text is shown. <strong>The data is sent to your webhook only when the bar finishes.</strong> The form is checked first, so nobody waits on an incomplete form.</p>';
        echo '<table class="form-table"><tbody>';
        echo self::row('Progress bar', self::check_input($s, 'progress_enabled', 'Enable'), '', 'progress_enabled');
        echo self::row('Duration', self::number_input($s, 'progress_seconds', 1, 600, 'seconds'), 'How long the bar runs before the data is sent.', 'progress_seconds');
        echo self::row('Text', self::text_input($s, 'progress_text', 'large-text'), 'Example: <code>Sending your download link...</code> You can also use <code>{seconds}</code> (seconds left) and <code>{percent}</code>.', 'progress_text');
        echo self::row('Bar color', self::color_input($s, 'progress_bar_color'), '', 'progress_bar_color');
        echo self::row('Bar height', self::number_input($s, 'progress_bar_height', 4, 30, 'px'), '', 'progress_bar_height');
        echo self::row('Progress bar custom CSS', self::textarea_input($s, 'progress_custom_css', 6, 'large-text code'), 'Classes: <code>.cdm-progress</code> <code>.cdm-progress-text</code> <code>.cdm-progress-bar</code> <code>.cdm-progress-fill</code>. Example: <code>.cdm-progress-text { font-size: 18px; }</code>', 'progress_custom_css');
        echo '</tbody></table></div>';

        // 5. Custom CSS.
        echo '<div class="cdm-admin-card"><h2>5. Custom CSS</h2>';
        echo '<p class="description">Add your own CSS for anything the options above do not cover. It is added after the plugin styles, so it wins. Only selectors that contain a plugin class (<code>.cdm-…</code>) are kept; <code>@media</code> and <code>@keyframes</code> are supported.</p>';
        echo self::textarea_input($s, 'form_custom_css', 12, 'large-text code');
        echo '<details class="cdm-css-help"><summary>Available classes &amp; examples</summary><p>';
        foreach (array('.cdm-inline', '.cdm-form', '.cdm-title', '.cdm-description', '.cdm-field', '.cdm-field label', '.cdm-field input', '.cdm-field textarea', '.cdm-field-name', '.cdm-field-email', '.cdm-field-phone', '.cdm-field-company', '.cdm-field-message', '.cdm-field-consent', '.cdm-required', '.cdm-submit', '.cdm-submit:hover', '.cdm-message', '.cdm-success', '.cdm-error', '.cdm-limit-offer', '.cdm-etsy-button', '.cdm-open-popup', '.cdm-modal-panel', '.cdm-modal-overlay', '.cdm-modal-close', '.cdm-progress', '.cdm-progress-text', '.cdm-progress-bar', '.cdm-progress-fill') as $class) {
            echo '<code>' . esc_html($class) . '</code> ';
        }
        echo '</p><pre>.cdm-inline { border-width: 2px; border-style: dashed; }
.cdm-title { font-family: Georgia, serif; letter-spacing: -.5px; }
.cdm-field input { padding: 16px; }
.cdm-submit { background: linear-gradient(90deg,#7c3aed,#ec4899); text-transform: uppercase; }
@media (max-width: 600px) { .cdm-inline { margin: 0; } }</pre>';
        echo '<p class="description">Need a different look for one form only? Add a class in the shortcode: <code>[custom_download_form class="my-form"]</code> then style <code>.my-form.cdm-form .cdm-submit</code> &mdash; include a <code>cdm-</code> class in the selector.</p></details>';
        echo '</div>';

        echo '<p><button type="submit" class="button button-primary button-hero">Save Changes</button></p>';
        echo '</form></div>';

        // Preview column.
        echo '<aside class="cdm-builder-side"><div class="cdm-admin-card cdm-preview-card"><div class="cdm-preview-head"><h2>Live preview</h2><button type="button" id="cdm-progress-demo" class="button">Play progress demo</button> <button type="submit" form="cdm-builder-form" class="button button-primary">Save Changes</button></div>';
        echo '<style id="cdm-preview-custom"></style>';
        echo '<div class="cdm-preview-bg"><div id="cdm-preview-stage" class="cdm-inline" style="' . esc_attr(CDM_Form_Manager::design_vars($s)) . '">';
        echo CDM_Form_Manager::render('preview', array('preview' => true));
        echo '</div></div><p class="description">The preview is not clickable. Saved CSS is cleaned for safety, so a few rules may differ on your site.</p></div>';
        echo '<div class="cdm-admin-card"><h2>Shortcodes</h2><p><code>[custom_download_form]</code><br><span class="description">Form for the page&rsquo;s assigned resource.</span></p><p><code>[custom_download_form resource="my-id" title="Get the guide" button="Get the PDF" class="my-form"]</code></p><p><code>[custom_download_button resource="my-id" text="Download"]</code><br><span class="description">Button that opens the form in a popup.</span></p></div>';
        echo '</aside>';
        echo '</div></div>';
    }

    public static function webhook_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = CDM_Form_Manager::settings();
        $sample = CDM_Webhook_Manager::build_payload('download_request', array(
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+1 555 123 4567',
            'company' => 'Acme Inc.',
            'message' => 'Hello!',
            'consent' => '1',
        ), (object) array('slug' => 'my-resource', 'name' => 'My Resource', 'download_url' => 'https://example.com/file.pdf', 'etsy_url' => '', 'page_id' => 123), 42, '203.0.113.10');

        $rows = array(
            self::row('Webhook URL', self::text_input($s, 'webhook_url', 'large-text', 'https://hooks.example.com/...'), 'Every successful form submission is sent here as a JSON <code>POST</code>. Works with Zapier, Make, n8n, Pipedream or your own endpoint. It must answer with a 2xx status.', 'webhook_url'),
            self::row('Secret (optional)', self::text_input($s, 'webhook_secret', 'regular-text'), 'If set, each request carries an <code>X-CDM-Signature</code> header: <code>sha256=</code> + HMAC-SHA256 of the raw body using this secret.', 'webhook_secret'),
            self::row('Timeout', self::number_input($s, 'webhook_timeout', 3, 30, 'seconds'), 'How long to wait for your webhook before the submission counts as failed.', 'webhook_timeout'),
        );
        $after = '<div class="cdm-admin-card"><h2>Send a test</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' .
            wp_nonce_field('cdm_test_webhook', '_wpnonce', true, false) .
            '<input type="hidden" name="action" value="cdm_test_webhook">' .
            '<p><button class="button">Send Test Webhook</button></p>' .
            '<p class="description">Save your changes first. The test sends the sample below with <code>"event": "test"</code> and is not stored in Form Submissions.</p></form></div>';
        $after .= '<div class="cdm-admin-card"><h2>Payload sent for each submission</h2><pre class="cdm-code">' . esc_html(wp_json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>' .
            '<p class="description">No email is sent by this plugin. Use <code>resource.download_url</code> in your automation if you want to deliver the file.</p></div>';
        self::simple_settings_page('Webhook Settings', 'webhook', $rows, $after);
    }

    public static function restrictions_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = CDM_Form_Manager::settings();
        $rows = array(
            self::row('Download limit', self::number_input($s, 'daily_limit', 1, 1000), 'Successful downloads allowed per email address / IP per day (or per window below).', 'daily_limit'),
            self::row('Limit window (hours)', self::number_input($s, 'limit_window_hours', 0, 720, 'hours'), 'Count downloads over a rolling window of this many hours instead of the calendar day. Example: limit 2 + window 6 = max 2 downloads per 6 hours. <strong>0</strong> keeps the original per-day behaviour. Use <code>{hours}</code> in the limit message.', 'limit_window_hours'),
            self::row('Allowed hours', self::check_input($s, 'active_hours_enabled', 'Only accept submissions during these hours') . '<br>' .
                '<input id="cdm-active_start" type="time" name="settings[active_start]" value="' . esc_attr($s['active_start']) . '"> to <input id="cdm-active_end" type="time" name="settings[active_end]" value="' . esc_attr($s['active_end']) . '">',
                'Uses your WordPress timezone (Settings &rarr; General). Overnight ranges such as 22:00 to 06:00 work. Exempt IPs ignore this.', 'active_start'),
            self::row('Outside-hours message', self::text_input($s, 'inactive_hours_message', 'large-text'), 'Use <code>{start}</code> and <code>{end}</code>.', 'inactive_hours_message'),
            self::row('Email-based restriction', self::check_input($s, 'email_restriction', 'Enable'), '', 'email_restriction'),
            self::row('IP-based restriction', self::check_input($s, 'ip_restriction', 'Enable'), '', 'ip_restriction'),
            self::row('IP exemptions', self::text_input($s, 'ip_exemptions', 'large-text'), 'Comma or space separated. These IPs are never limited (useful for testing).', 'ip_exemptions'),
            self::row('Trusted reverse proxy IPs', self::text_input($s, 'trusted_proxy_ips', 'large-text'), 'Only fill this in if your site is behind a proxy/CDN you control.', 'trusted_proxy_ips'),
            self::row('Data retention', self::number_input($s, 'retention_days', 1, 3650, 'days'), 'Form submissions older than this are deleted automatically.', 'retention_days'),
            self::row('Limit message', self::text_input($s, 'limit_message', 'large-text'), 'Use <code>{limit}</code> for the number and <code>{hours}</code> for the window.', 'limit_message'),
            self::row('Uninstall', self::check_input($s, 'delete_data_on_uninstall', 'Delete all plugin data (resources, form submissions, settings) when the plugin is deleted'), '', 'delete_data_on_uninstall'),
        );
        self::simple_settings_page('Download Restrictions', 'restrictions', $rows);
    }

    /* ---------- saving ---------- */

    private static function checkbox_keys($group) {
        $map = array(
            'form' => array('show_title', 'show_description', 'btn_full', 'name_enabled', 'name_required', 'phone_enabled', 'phone_required', 'company_enabled', 'company_required', 'message_enabled', 'message_required', 'consent_enabled', 'consent_required', 'progress_enabled'),
            'restrictions' => array('email_restriction', 'ip_restriction', 'delete_data_on_uninstall', 'active_hours_enabled'),
            'webhook' => array(),
        );
        return isset($map[$group]) ? $map[$group] : array();
    }

    private static function sanitize_setting($key, $value, $defaults) {
        $colors = array('progress_bar_color', 'primary_color', 'bg_color', 'text_color', 'border_color', 'input_bg');
        $ranges = array(
            'daily_limit' => array(1, 1000),
            'limit_window_hours' => array(0, 720),
            'progress_seconds' => array(1, 600),
            'progress_bar_height' => array(4, 30),
            'retention_days' => array(1, 3650),
            'webhook_timeout' => array(3, 30),
            'radius' => array(0, 60),
            'padding' => array(8, 80),
            'max_width' => array(280, 1000),
            'font_size' => array(12, 22),
        );
        $enums = array(
            'align' => array('left', 'center'),
            'shadow' => array('none', 'soft', 'strong'),
            'button_style' => array('solid', 'outline'),
        );

        if (in_array($key, $colors, true)) {
            $color = sanitize_hex_color($value);
            return $color ? $color : $defaults[$key];
        }
        if (isset($ranges[$key])) {
            return min($ranges[$key][1], max($ranges[$key][0], absint($value)));
        }
        if (isset($enums[$key])) {
            return in_array($value, $enums[$key], true) ? $value : $defaults[$key];
        }
        if ($key === 'webhook_url') {
            $url = esc_url_raw(trim($value));
            return CDM_Webhook_Manager::valid_url($url) ? $url : '';
        }
        if ($key === 'form_custom_css' || $key === 'progress_custom_css') {
            return CDM_Style_Manager::sanitize_custom_css($value, 'form');
        }
        if ($key === 'active_start' || $key === 'active_end') {
            $value = trim((string) $value);
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $defaults[$key];
        }
        if ($key === 'consent_label') {
            return wp_kses($value, CDM_Form_Manager::allowed_label_html());
        }
        if (in_array($key, array('form_description'), true)) {
            return sanitize_textarea_field($value);
        }
        return sanitize_text_field($value);
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('cdm_save_settings');

        $group = isset($_POST['group']) ? sanitize_key($_POST['group']) : '';
        $defaults = CDM_Installer::defaults();
        $existing = get_option('cdm_settings', array());
        if (!is_array($existing)) {
            $existing = array();
        }
        $existing = array_merge($defaults, $existing);
        $submitted = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : array();
        $checkboxes = self::checkbox_keys($group);

        foreach ($submitted as $key => $value) {
            $key = sanitize_key($key);
            if (is_array($value) || !array_key_exists($key, $defaults) || in_array($key, $checkboxes, true)) {
                continue;
            }
            $existing[$key] = self::sanitize_setting($key, $value, $defaults);
        }
        // Unchecked checkboxes are not submitted, so reset only the ones that belong to the saved page.
        foreach ($checkboxes as $checkbox) {
            $existing[$checkbox] = !empty($submitted[$checkbox]) ? 1 : 0;
        }

        update_option('cdm_settings', $existing);

        $back = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=cdm-dashboard');
        $back = remove_query_arg(array('cdm_saved', 'cdm_webhook_ok', 'cdm_webhook_failed', 'cdm_error'), $back);
        wp_safe_redirect(add_query_arg('cdm_saved', '1', $back));
        exit;
    }

    public static function test_webhook() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('cdm_test_webhook');
        $back = admin_url('admin.php?page=cdm-webhook-settings');
        $payload = CDM_Webhook_Manager::build_payload('test', array(
            'name' => 'Test User',
            'email' => 'test@example.com',
            'consent' => '1',
        ), (object) array('slug' => 'test-resource', 'name' => 'Test Resource', 'download_url' => home_url('/'), 'etsy_url' => '', 'page_id' => 0), 0, '');
        if (CDM_Webhook_Manager::send($payload)) {
            delete_transient('cdm_last_webhook_error');
            wp_safe_redirect(add_query_arg('cdm_webhook_ok', '1', $back));
        } else {
            set_transient('cdm_last_webhook_error', CDM_Webhook_Manager::$last_error, 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('cdm_webhook_failed', '1', $back));
        }
        exit;
    }

    /* ---------- logs ---------- */

    private static function extra_summary($json) {
        $data = $json ? json_decode($json, true) : array();
        if (!is_array($data) || !$data) {
            return '';
        }
        $parts = array();
        foreach ($data as $key => $value) {
            $value = $key === 'consent' ? 'yes' : $value;
            $parts[] = '<strong>' . esc_html(ucfirst($key)) . ':</strong> ' . esc_html(wp_trim_words($value, 18));
        }
        return implode('<br>', $parts);
    }

    /**
     * Shared table of submissions (Overview = compact, Form Submissions = full).
     */
    private static function submissions_table($rows, $compact = false) {
        $names = array();
        foreach (CDM_Resource_Manager::all() as $item) {
            $names[$item->slug] = $item->name;
        }
        $cols = $compact
            ? array('Name', 'Email', 'Resource', 'Date/Time', 'Status', 'Webhook')
            : array('Name', 'Email', 'Details', 'IP', 'Resource', 'Date/Time', 'Status', 'Webhook');
        echo '<div class="cdm-table-scroll"><table class="wp-list-table widefat striped cdm-submissions"><thead><tr>';
        foreach ($cols as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . count($cols) . '">No submissions found.</td></tr>';
        }
        foreach ($rows as $row) {
            $status_class = 'cdm-status-' . sanitize_html_class($row->request_status);
            $resource_cell = isset($names[$row->resource_id])
                ? esc_html($names[$row->resource_id]) . '<br><code>' . esc_html($row->resource_id) . '</code>'
                : '<code>' . esc_html($row->resource_id) . '</code>';
            $webhook_cell = '<span class="cdm-status cdm-status-' . sanitize_html_class($row->email_status) . '">' . esc_html(ucfirst($row->email_status)) . '</span>';
            if (!empty($row->email_error)) {
                $webhook_cell .= '<br><span class="cdm-muted">' . esc_html($row->email_error) . '</span>';
            }
            echo '<tr><td>' . esc_html($row->name) . '</td><td>' . esc_html($row->email) . '</td>';
            if (!$compact) {
                echo '<td>' . self::extra_summary($row->extra_data) . '</td><td>' . esc_html($row->ip_address) . '</td>';
            }
            echo '<td>' . $resource_cell . '</td><td>' . esc_html($row->submitted_at) . '</td>';
            echo '<td><span class="cdm-status ' . esc_attr($status_class) . '">' . esc_html(ucfirst($row->request_status)) . '</span></td><td>' . $webhook_cell . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Reads and validates search / filter / sort / paging values from a request array.
     */
    private static function submission_filters($src) {
        $date = function ($key) use ($src) {
            $value = isset($src[$key]) ? sanitize_text_field(wp_unslash($src[$key])) : '';
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $value;
            }
            return '';
        };
        $orderby = isset($src['orderby']) ? sanitize_key($src['orderby']) : 'date';
        if (!in_array($orderby, array('date', 'name', 'email', 'resource', 'status'), true)) {
            $orderby = 'date';
        }
        $order = isset($src['order']) && strtolower($src['order']) === 'asc' ? 'asc' : 'desc';
        $per_page = isset($src['per_page']) ? absint($src['per_page']) : 25;
        if (!in_array($per_page, array(10, 25, 50, 100), true)) {
            $per_page = 25;
        }
        return array(
            's' => isset($src['s']) ? sanitize_text_field(wp_unslash($src['s'])) : '',
            'request_status' => isset($src['request_status']) ? sanitize_key($src['request_status']) : '',
            'resource' => isset($src['resource']) ? sanitize_title(wp_unslash($src['resource'])) : '',
            'date_from' => $date('date_from'),
            'date_to' => $date('date_to'),
            'orderby' => $orderby,
            'order' => $order,
            'per_page' => $per_page,
            'paged' => max(1, absint(isset($src['paged']) ? $src['paged'] : 1)),
        );
    }

    /**
     * Builds the WHERE clause (already prepared) for the submission filters.
     */
    private static function submission_where($f) {
        global $wpdb;
        $where = array('1=1');
        $args = array();
        if ($f['s'] !== '') {
            $like = '%' . $wpdb->esc_like($f['s']) . '%';
            $where[] = '(name LIKE %s OR email LIKE %s OR ip_address LIKE %s OR resource_id LIKE %s OR extra_data LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        if ($f['request_status'] !== '') {
            $where[] = 'request_status = %s';
            $args[] = $f['request_status'];
        }
        if ($f['resource'] !== '') {
            $where[] = 'resource_id = %s';
            $args[] = $f['resource'];
        }
        if ($f['date_from'] !== '') {
            $where[] = 'submitted_at >= %s';
            $args[] = $f['date_from'] . ' 00:00:00';
        }
        if ($f['date_to'] !== '') {
            $where[] = 'submitted_at <= %s';
            $args[] = $f['date_to'] . ' 23:59:59';
        }
        $sql = implode(' AND ', $where);
        return $args ? $wpdb->prepare($sql, $args) : $sql;
    }

    private static function submission_order_sql($f) {
        $columns = array(
            'date' => 'submitted_at',
            'name' => 'name',
            'email' => 'email',
            'resource' => 'resource_id',
            'status' => 'request_status',
        );
        $direction = $f['order'] === 'asc' ? 'ASC' : 'DESC';
        return $columns[$f['orderby']] . ' ' . $direction . ', id ' . $direction;
    }

    /**
     * WordPress-style pager: item count, first / previous, "Page X of Y", next / last.
     */
    private static function pager($total, $f, $position) {
        $pages = max(1, (int) ceil($total / $f['per_page']));
        $current = min($f['paged'], $pages);
        $args = array(
            'page' => 'cdm-logs',
            's' => $f['s'],
            'request_status' => $f['request_status'],
            'resource' => $f['resource'],
            'date_from' => $f['date_from'],
            'date_to' => $f['date_to'],
            'orderby' => $f['orderby'],
            'order' => $f['order'],
            'per_page' => $f['per_page'],
        );
        $args = array_filter($args, function ($value) {
            return $value !== '';
        });
        $url = function ($page) use ($args) {
            return admin_url('admin.php?' . http_build_query(array_merge($args, array('paged' => $page)), '', '&'));
        };
        $link = function ($label, $page, $enabled, $title) use ($url) {
            return $enabled
                ? '<a class="button" href="' . esc_url($url($page)) . '" title="' . esc_attr($title) . '"><span aria-hidden="true">' . $label . '</span><span class="screen-reader-text">' . esc_html($title) . '</span></a> '
                : '<span class="button disabled" aria-hidden="true">' . $label . '</span> ';
        };
        echo '<div class="tablenav ' . esc_attr($position) . '"><div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html(number_format_i18n($total)) . ' ' . ($total === 1 ? 'item' : 'items') . '</span>';
        if ($pages > 1) {
            echo '<span class="pagination-links">';
            echo $link('&laquo;', 1, $current > 1, 'First page');
            echo $link('&lsaquo;', $current - 1, $current > 1, 'Previous page');
            echo '<span class="paging-input">Page ' . esc_html($current) . ' of <span class="total-pages">' . esc_html($pages) . '</span></span> ';
            echo $link('&rsaquo;', $current + 1, $current < $pages, 'Next page');
            echo $link('&raquo;', $pages, $current < $pages, 'Last page');
            echo '</span>';
        }
        echo '</div></div>';
    }

    public static function logs_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cdm_requests';
        $f = self::submission_filters($_GET);
        $sql_where = self::submission_where($f);

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$sql_where}");
        $pages = max(1, (int) ceil($total / $f['per_page']));
        $f['paged'] = min($f['paged'], $pages);
        $offset = ($f['paged'] - 1) * $f['per_page'];
        $order_sql = self::submission_order_sql($f);
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$order_sql} LIMIT " . (int) $f['per_page'] . ' OFFSET ' . (int) $offset);

        self::top();
        echo '<h2 class="cdm-page-title">Form Submissions</h2>';
        echo '<p class="cdm-resource-intro">Everything visitors submitted through your download forms. Search, filter, sort and export the data here.</p>';

        // Search / filter / sort bar.
        echo '<form method="get" class="cdm-admin-card cdm-wide-card cdm-filter-bar"><input type="hidden" name="page" value="cdm-logs">';
        echo '<div class="cdm-filter-grid">';
        echo '<label class="cdm-filter-search">Search<input type="search" name="s" value="' . esc_attr($f['s']) . '" placeholder="Name, email, phone, company, IP, resource…"></label>';
        echo '<label>Status<select name="request_status"><option value="">All statuses</option>';
        foreach (array('approved' => 'Approved', 'blocked' => 'Blocked', 'failed' => 'Failed', 'pending' => 'Pending') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($f['request_status'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Resource<select name="resource"><option value="">All resources</option>';
        foreach (CDM_Resource_Manager::all() as $item) {
            echo '<option value="' . esc_attr($item->slug) . '"' . selected($f['resource'], $item->slug, false) . '>' . esc_html($item->name) . '</option>';
        }
        echo '</select></label>';
        echo '<label>From<input type="date" name="date_from" value="' . esc_attr($f['date_from']) . '"></label>';
        echo '<label>To<input type="date" name="date_to" value="' . esc_attr($f['date_to']) . '"></label>';
        echo '<label>Sort by<select name="orderby">';
        foreach (array('date' => 'Date', 'name' => 'Name', 'email' => 'Email', 'resource' => 'Resource', 'status' => 'Status') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($f['orderby'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Order<select name="order"><option value="desc"' . selected($f['order'], 'desc', false) . '>Newest / Z → A</option><option value="asc"' . selected($f['order'], 'asc', false) . '>Oldest / A → Z</option></select></label>';
        echo '<label>Per page<select name="per_page">';
        foreach (array(10, 25, 50, 100) as $option) {
            echo '<option value="' . esc_attr($option) . '"' . selected($f['per_page'], $option, false) . '>' . esc_html($option) . '</option>';
        }
        echo '</select></label>';
        echo '</div><p class="cdm-filter-actions"><button class="button button-primary">Apply</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=cdm-logs')) . '">Reset</a></p></form>';

        // Export (respects the current search and filters).
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cdm-export-form">';
        wp_nonce_field('cdm_export_logs');
        echo '<input type="hidden" name="action" value="cdm_export_logs">';
        foreach (array('s', 'request_status', 'resource', 'date_from', 'date_to', 'orderby', 'order') as $key) {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($f[$key]) . '">';
        }
        echo '<button class="button">Export CSV' . ($total ? ' (' . esc_html(number_format_i18n($total)) . ')' : '') . '</button></form>';

        self::pager($total, $f, 'top');
        self::submissions_table($rows, false);
        self::pager($total, $f, 'bottom');
        echo '</div>';
    }

    private static function csv_cell($value) {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    public static function export_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('cdm_export_logs');
        global $wpdb;
        $table = $wpdb->prefix . 'cdm_requests';
        $f = self::submission_filters($_POST);
        $sql_where = self::submission_where($f);
        $order_sql = self::submission_order_sql($f);
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$order_sql}", ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=cdm-form-submissions.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Name', 'Email', 'Phone', 'Company', 'Message', 'Consent', 'IP', 'Resource', 'Submitted', 'Request Status', 'Webhook Status', 'Webhook Error'));
        foreach ($rows as $row) {
            $extra = !empty($row['extra_data']) ? json_decode($row['extra_data'], true) : array();
            $extra = is_array($extra) ? $extra : array();
            fputcsv($output, array_map(array(__CLASS__, 'csv_cell'), array(
                $row['name'],
                $row['email'],
                isset($extra['phone']) ? $extra['phone'] : '',
                isset($extra['company']) ? $extra['company'] : '',
                isset($extra['message']) ? $extra['message'] : '',
                isset($extra['consent']) ? 'yes' : '',
                $row['ip_address'],
                $row['resource_id'],
                $row['submitted_at'],
                $row['request_status'],
                $row['email_status'],
                $row['email_error'],
            )));
        }
        fclose($output);
        exit;
    }
}
