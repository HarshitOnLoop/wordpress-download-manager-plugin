<?php
if (!defined('ABSPATH')) {
    exit;
}

class CDM_Resource_Manager {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cdm_resources';
    }

    /**
     * Post type slug of the "Snapshots" custom post type.
     * Tries "snapshot", then "snapshots", then any registered post type labelled Snapshot(s).
     * Override with: add_filter('cdm_snapshot_post_type', fn() => 'your_slug');
     */
    public static function snapshot_post_type() {
        $found = '';
        foreach (array('snapshot', 'snapshots') as $slug) {
            if (post_type_exists($slug)) {
                $found = $slug;
                break;
            }
        }
        if ($found === '') {
            foreach (get_post_types(array('show_ui' => true), 'objects') as $slug => $object) {
                $labels = array(
                    strtolower(isset($object->labels->name) ? $object->labels->name : ''),
                    strtolower(isset($object->labels->singular_name) ? $object->labels->singular_name : ''),
                );
                if (in_array('snapshots', $labels, true) || in_array('snapshot', $labels, true)) {
                    $found = $slug;
                    break;
                }
            }
        }
        if ($found === '') {
            $found = 'snapshot';
        }
        return sanitize_key(apply_filters('cdm_snapshot_post_type', $found));
    }

    /**
     * All Snapshot posts (id + title), alphabetical, for the admin picker.
     */
    public static function snapshots() {
        return get_posts(array(
            'post_type' => self::snapshot_post_type(),
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ));
    }

    public static function all($status = null) {
        global $wpdb;
        $table = self::table();
        if ($status) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY name ASC", $status));
        }
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");
    }

    public static function get_by_slug($slug) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE slug = %s LIMIT 1", sanitize_title($slug)));
    }

    public static function get_by_page_id($page_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE page_id = %d ORDER BY id ASC LIMIT 1",
            absint($page_id)
        ));
    }

    public static function slug_exists($slug, $exclude_id = 0) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE slug = %s AND id != %d LIMIT 1",
            sanitize_title($slug),
            absint($exclude_id)
        ));
    }

    public static function page_is_assigned($page_id, $exclude_id = 0) {
        global $wpdb;
        if (!$page_id) {
            return false;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE page_id = %d AND page_id != 0 AND id != %d LIMIT 1",
            absint($page_id),
            absint($exclude_id)
        ));
    }

    public static function insert($data) {
        global $wpdb;
        $now = current_time('mysql');
        return $wpdb->insert(self::table(), array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['slug']),
            'page_id' => absint($data['page_id']),
            'download_url' => esc_url_raw($data['download_url']),
            'etsy_url' => esc_url_raw(isset($data['etsy_url']) ? $data['etsy_url'] : ''),
            'status' => $data['status'] === 'inactive' ? 'inactive' : 'active',
            'description' => sanitize_textarea_field(isset($data['description']) ? $data['description'] : ''),
            'created_at' => $now,
            'updated_at' => $now,
        ), array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'));
    }

    public static function update_resource($id, $data) {
        global $wpdb;
        return $wpdb->update(self::table(), array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['slug']),
            'page_id' => absint($data['page_id']),
            'download_url' => esc_url_raw($data['download_url']),
            'etsy_url' => esc_url_raw(isset($data['etsy_url']) ? $data['etsy_url'] : ''),
            'status' => $data['status'] === 'inactive' ? 'inactive' : 'active',
            'description' => sanitize_textarea_field(isset($data['description']) ? $data['description'] : ''),
            'updated_at' => current_time('mysql'),
        ), array('id' => absint($id)), array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'), array('%d'));
    }

    public static function delete_resource($id) {
        global $wpdb;
        return $wpdb->delete(self::table(), array('id' => absint($id)), array('%d'));
    }
}
