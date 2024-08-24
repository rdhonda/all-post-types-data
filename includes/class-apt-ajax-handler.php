<?php

class APT_Ajax_Handler
{
    public function init()
    {
        add_action('wp_ajax_apt_get_post_details', array($this, 'get_post_details'));
        add_action('wp_ajax_apt_export_csv', array($this, 'export_csv'));
    }

    public function get_post_details()
    {
        check_ajax_referer('apt_nonce', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $blog_id = isset($_POST['blog_id']) ? intval($_POST['blog_id']) : get_current_blog_id();

        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }

        if (is_multisite()) {
            switch_to_blog($blog_id);
        }

        $post = get_post($post_id);

        if (!$post) {
            if (is_multisite()) {
                restore_current_blog();
            }
            wp_send_json_error('Post not found');
        }

        $content = wpautop($post->post_content);

        $custom_fields = get_post_custom($post_id);
        $custom_fields_html = '<h4>Custom Fields:</h4><ul>';
        foreach ($custom_fields as $key => $values) {
            if (substr($key, 0, 1) !== '_') { // Exclude hidden fields
                $custom_fields_html .= '<li><strong>' . esc_html($key) . ':</strong> ' . esc_html(implode(', ', $values)) . '</li>';
            }
        }
        $custom_fields_html .= '</ul>';

        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        $taxonomy_html = '<h4>Taxonomies:</h4>';
        if (!empty($taxonomies)) {
            $taxonomy_html .= '<ul>';
            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post_id, $taxonomy->name);
                if ($terms && !is_wp_error($terms)) {
                    $term_names = array_map(function ($term) {
                        return esc_html($term->name);
                    }, $terms);
                    $taxonomy_html .= '<li><strong>' . esc_html($taxonomy->label) . ':</strong> ' . implode(', ', $term_names) . '</li>';
                }
            }
            $taxonomy_html .= '</ul>';
        } else {
            $taxonomy_html .= '<p>No taxonomies associated with this post type.</p>';
        }

        if (is_multisite()) {
            restore_current_blog();
        }

        wp_send_json_success(array(
            'content' => $content,
            'custom_fields' => $custom_fields_html,
            'taxonomies' => $taxonomy_html
        ));
    }

    public function export_csv()
    {
        check_ajax_referer('apt_nonce', 'nonce');

        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
        $blog_id = isset($_POST['blog_id']) ? intval($_POST['blog_id']) : get_current_blog_id();

        if (!$post_type) {
            wp_send_json_error('Invalid post type');
        }

        if (is_multisite()) {
            switch_to_blog($blog_id);
        }

        $csv_content = $this->generate_csv_content($post_type);

        if (is_multisite()) {
            restore_current_blog();
        }

        wp_send_json_success(array('csv_content' => $csv_content));
    }

    private function generate_csv_content($post_type)
    {
        $posts = get_posts(array(
            'post_type' => $post_type,
            'numberposts' => -1,
        ));

        $csv_data = array();
        $csv_data[] = array('Image URL', 'Post URL', 'Title', 'Content', 'Taxonomies', 'Custom Fields');

        foreach ($posts as $post) {
            $image_url = get_the_post_thumbnail_url($post->ID, 'full') ?: '';
            $post_url = get_permalink($post->ID);
            $taxonomies = $this->get_post_taxonomies_json($post->ID, $post_type);
            $custom_fields = $this->get_post_custom_fields_json($post->ID);

            $csv_data[] = array(
                $image_url,
                $post_url,
                $post->post_title,
                $post->post_content, 
                $taxonomies,
                $custom_fields
            );
        }

        $csv_content = '';
        foreach ($csv_data as $row) {
            $csv_content .= implode(',', array_map(array($this, 'csv_escape'), $row)) . "\n";
        }

        return $csv_content;
    }

    private function get_post_taxonomies_json($post_id, $post_type)
    {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $taxonomy_data = array();

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy->name);
            if ($terms && !is_wp_error($terms)) {
                $taxonomy_data[$taxonomy->label] = wp_list_pluck($terms, 'name');
            }
        }

        return wp_json_encode($taxonomy_data);
    }

    private function get_post_custom_fields_json($post_id)
    {
        $custom_fields = get_post_custom($post_id);
        $filtered_fields = array();

        foreach ($custom_fields as $key => $values) {
            if (substr($key, 0, 1) !== '_') { // Exclude hidden fields
                $filtered_fields[$key] = $values;
            }
        }

        return wp_json_encode($filtered_fields);
    }

    private function csv_escape($value)
    {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        $value = str_replace('"', '""', $value);
        return '"' . $value . '"';
    }
}
