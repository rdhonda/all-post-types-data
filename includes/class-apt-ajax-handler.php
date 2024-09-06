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
        $taxonomy_filters = isset($_POST['taxonomy_filters']) ? $_POST['taxonomy_filters'] : array();
        $custom_field_key = isset($_POST['custom_field_key']) ? sanitize_text_field($_POST['custom_field_key']) : '';
        $custom_field_value = isset($_POST['custom_field_value']) ? sanitize_text_field($_POST['custom_field_value']) : '';
        $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;

        if (!$post_type) {
            wp_send_json_error('Invalid post type');
        }

        if (is_multisite()) {
            switch_to_blog($blog_id);
        }

        try {
            $csv_content = $this->generate_csv_content($post_type, $taxonomy_filters, $custom_field_key, $custom_field_value, $paged);

            if (is_multisite()) {
                restore_current_blog();
            }

            wp_send_json_success(array('csv_content' => $csv_content));
        } catch (Exception $e) {
            if (is_multisite()) {
                restore_current_blog();
            }
            wp_send_json_error('Error generating CSV: ' . $e->getMessage());
        }
    }

    private function generate_csv_content($post_type, $taxonomy_filters, $custom_field_key, $custom_field_value, $paged)
    {
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => -1, // Get all posts
            'post_status' => 'publish',
            'paged' => $paged
        );

        // Add taxonomy queries
        if (!empty($taxonomy_filters)) {
            $tax_query = array('relation' => 'AND');
            foreach ($taxonomy_filters as $taxonomy => $term) {
                if (!empty($term)) {
                    $tax_query[] = array(
                        'taxonomy' => $taxonomy,
                        'field' => 'slug',
                        'terms' => $term
                    );
                }
            }
            $args['tax_query'] = $tax_query;
        }

        // Add custom field query
        if (!empty($custom_field_key) && !empty($custom_field_value)) {
            $args['meta_query'] = array(
                array(
                    'key' => $custom_field_key,
                    'value' => $custom_field_value,
                    'compare' => 'LIKE'
                )
            );
        }

        $query = new WP_Query($args);
        $posts = $query->posts;

        if (empty($posts)) {
            throw new Exception('No posts found matching the criteria.');
        }

        $csv_data = array();
        $csv_data[] = array('URL', 'Image URL', 'Title', 'Content', 'Post Type', 'Taxonomies', 'Custom Fields');

        foreach ($posts as $post) {
            $image_url = get_the_post_thumbnail_url($post->ID, 'full') ?: '';
            $post_url = get_permalink($post->ID);
            $taxonomies_json = self::get_post_taxonomies_json($post->ID, $post_type);
            $taxonomies_array = json_decode($taxonomies_json, true);
            // Extract post_type and remove it from taxonomies
            $adjusted_post_type = isset($taxonomies_array['post_type']) ? $taxonomies_array['post_type'] : $post->post_type;
            unset($taxonomies_array['post_type']);

            $taxonomies = wp_json_encode($taxonomies_array);
            $custom_fields = self::get_post_custom_fields_json($post->ID);


            $csv_data[] = array(
                $post_url,
                $image_url,
                $post->post_title,
                $post->post_content,
                $adjusted_post_type,
                $taxonomies,
                $custom_fields
            );
        }

        return $this->array_to_csv($csv_data);
    }

    private function array_to_csv($data)
    {
        $output = fopen('php://temp', 'r+');
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        return $csv_content;
    }

    public static function get_post_taxonomies_json($post_id, $post_type)
    {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $taxonomy_data = array();
        $has_people_type = false;

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy->name);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $remapped = self::remap_taxonomy($taxonomy->name, $term->name);
                    $taxonomy_data[$remapped[0]][] = $remapped[1];
                    if ($remapped[0] === 'people_type') {
                        $has_people_type = true;
                    } else if (stripos($term->name, 'Courses') !== false) {
                        $is_course = true;
                    } else if (stripos($term->name, 'Publications') !== false) {
                        $is_publication = true;
                    } else if (stripos($term->name, 'Projects') !== false) {
                        $is_project = true;
                    }
                }
            }
        }

        // Remove duplicates and implode values
        foreach ($taxonomy_data as $key => $values) {
            $taxonomy_data[$key] = implode(', ', array_unique($values));
        }

        // Set post_type based on taxonomy
        if (@$has_people_type) {
            $post_type = 'people';
        } elseif (@$is_course) {
            $post_type = 'course';
        } elseif (@$is_publication) {
            $post_type = 'publication';
        } elseif (@$is_project) {
            $post_type = 'project';
        } else {
            $post_type = 'page';
        }

        return wp_json_encode(array_merge(['post_type' => $post_type], $taxonomy_data));
    }

    public static function get_post_custom_fields_json($post_id)
    {
        $custom_fields = get_post_custom($post_id);
        $filtered_fields = array();

        foreach ($custom_fields as $key => $values) {
            if (
                reset($values)
                && substr($key, 0, 1) !== '_'
                && substr($key, 0, 3) !== 'kd_'
                && substr($key, 0, 4) !== 'sbg_'
                && substr($key, 0, 5) !== 'pyre_'
                && substr($key, 0, 6) !== 'avada_'
                && substr($key, 0, 7) !== 'fusion_'
            ) {
                $new_key = self::remap_custom_field_key($key);
                if ($new_key) {
                    $filtered_fields[$new_key] = self::format_custom_field_value($values, @$filtered_fields[$new_key]);
                }
            }
        }

        return wp_json_encode($filtered_fields);
    }

    public static function remap_taxonomy($key, $value)
    {
        $taxonomy_mapping = [
            'people_type' => [
                'Faculty' => 'Faculty',
                'Researchers' => 'Researchers',
                'Staff' => 'Staff',
                'PhD Students' => 'PhD Students',
                'Adjunct' => 'Adjunct',
                'Advisory Board' => 'Advisory Board',
            ],
            'pillar-cluster' => ['SUTD', 'ASD', 'EDP', 'ESD',  'ISTD', 'HASS', 'SMT', 'DAI']
        ];
        // Check for people_type mapping
        if (isset($taxonomy_mapping['people_type'][$value])) {
            return ['people_type', $taxonomy_mapping['people_type'][$value]];
        }

        // Check for pillar-cluster mapping
        foreach ($taxonomy_mapping['pillar-cluster'] as $pillar) {
            if (stripos($value, $pillar) !== false) {
                return ['pillar-cluster', $pillar];
            }
        }

        // If no mapping found, return the original key-value pair
        return [$key, $value];
    }

    public static function remap_custom_field_key($key)
    {
        $custom_field_mapping = array(
            'title' => 'title',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'telephone' => 'telephone',
            'research' => 'research',
            'research_interest' => 'research_interest',
            'research-methods' => 'research-methods',
            'research-applications' => 'research-applications',
            'istd_research' => 'istd_research',
            'epd_research' => 'epd_research',
            'designation' => 'designation',
            'company' => 'company',
            'company_links' => 'company_links',
            'company_designation' => 'company_designation',
            'website' => 'website',
            'qualification' => 'qualification',
            'room_number' => 'room_number',
            'position' => 'position',
            'school' => 'school',
            'university' => 'university',
        );
        return isset($custom_field_mapping[$key]) ? $custom_field_mapping[$key] : $key;
    }

    public static function format_custom_field_value($value, $field_data = [])
    {
        if (is_array($value) && count($value) === 1) {
            $value = $value[0];
        }

        $unserialized_value = maybe_unserialize($value);

        if (is_array($unserialized_value)) {
            // $unserialized_value = array_merge($field_data, $unserialized_value);
            // return wp_json_encode($unserialized_value);
            return $unserialized_value;
        } else {
            return $unserialized_value;
        }
    }
}
