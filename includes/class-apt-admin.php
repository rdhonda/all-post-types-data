<?php
class APT_Admin
{
    public function init()
    {
        add_action('admin_menu', array($this, 'add_menu_item'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_menu_item()
    {
        add_menu_page(
            'All Post Types Data',
            'Post Types Data',
            'manage_options',
            'all-post-types-data',
            array($this, 'display_data'),
            'dashicons-list-view',
            30
        );
    }

    public function enqueue_admin_scripts($hook)
    {
        if ('toplevel_page_all-post-types-data' !== $hook) {
            return;
        }

        wp_enqueue_style('apt-admin-style', APT_PLUGIN_URL . 'assets/css/apt-admin-style.css', array(), '1.0');
        wp_enqueue_script('apt-admin-script', APT_PLUGIN_URL . 'assets/js/apt-admin-script.js', array('jquery'), '1.0', true);
        wp_localize_script('apt-admin-script', 'aptData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('apt_nonce')
        ));
    }


    private function get_custom_fields($post_type)
    {
        global $wpdb;
        $query = "
            SELECT DISTINCT meta_key
            FROM $wpdb->postmeta pm
            JOIN $wpdb->posts p ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key NOT LIKE '\_%'
        ";
        $results = $wpdb->get_col($wpdb->prepare($query, $post_type));
        return $results;
    }

    public function display_data()
    {
        global $wpdb;

        $posts_per_page = -1; // Number of posts to display per page
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $selected_post_type = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'post';
        $selected_blog_id = isset($_GET['blog_id']) ? intval($_GET['blog_id']) : get_current_blog_id();

        // New filter variables
        $taxonomy_filters = isset($_GET['taxonomy']) ? $_GET['taxonomy'] : array();
        $custom_field_key = isset($_GET['custom_field_key']) ? sanitize_text_field($_GET['custom_field_key']) : '';
        $custom_field_value = isset($_GET['custom_field_value']) ? sanitize_text_field($_GET['custom_field_value']) : '';

        echo '<div class="wrap">';
        echo '<h1>All Post Types Data</h1>';

        // Multisite blog selection
        if (is_multisite()) {
            $blogs = get_sites();
            echo '<form method="get" action="" style="margin-bottom: 10px;">';
            echo '<input type="hidden" name="page" value="all-post-types-data">';
            echo '<label>Blog: </label>';
            echo '<select name="blog_id">';
            foreach ($blogs as $blog) {
                $blog_details = get_blog_details($blog->blog_id);
                echo '<option value="' . $blog->blog_id . '" ' . selected($selected_blog_id, $blog->blog_id, false) . '>' . esc_html($blog_details->blogname) . '</option>';
            }
            echo '</select>';
            echo '<input type="submit" class="button" value="Switch Blog">';
            echo '</form>';

            // Switch to the selected blog
            switch_to_blog($selected_blog_id);
        }

        // Get all post types from the database with their counts
        $post_types_query = $wpdb->prepare(
            "SELECT post_type, COUNT(*) as count 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish'
            -- AND post_type <> 'acf-field-group'
            -- AND post_type <> 'acf-field'
            -- AND post_type <> 'acf'
            -- AND post_type <> 'eo_booking_form'
            GROUP BY post_type"
        );
        $post_types_with_count = $wpdb->get_results($post_types_query, OBJECT_K);

        // Display filter form
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="all-post-types-data">';
        echo '<input type="hidden" name="blog_id" value="' . esc_attr($selected_blog_id) . '">';

        // Post Type dropdown with count
        echo '<div style="margin-bottom: 10px;">';
        echo '<label>Post Type: </label>';
        echo '<select name="post_type">';
        foreach ($post_types_with_count as $post_type => $data) {
            $post_type_obj = get_post_type_object($post_type);
            $post_type_name = $post_type_obj ? $post_type_obj->labels->name : $post_type . ' (Unregistered)';
            $count = $data->count;
            echo '<option value="' . esc_attr($post_type) . '" ' . selected($selected_post_type, $post_type, false) . '>'
                . esc_html($post_type_name) . ' (' . esc_html($count) . ')'
                . '</option>';
        }
        echo '</select>';
        echo '</div>';

        $taxonomies = get_object_taxonomies($selected_post_type, 'objects');
        foreach ($taxonomies as $taxonomy) {
            // Check if the taxonomy is actually used by posts of this type
            $terms = get_terms(array(
                'taxonomy' => $taxonomy->name,
                'hide_empty' => true,
                'object_ids' => get_posts(array(
                    'post_type' => $selected_post_type,
                    'fields' => 'ids',
                    'posts_per_page' => -1,
                )),
            ));

            if (!empty($terms) && !is_wp_error($terms)) {
                echo '<div style="margin-bottom: 10px;">';
                echo '<label>' . esc_html($taxonomy->label) . ': </label>';
                echo '<select name="taxonomy[' . esc_attr($taxonomy->name) . ']">';
                echo '<option value="">All ' . esc_html($taxonomy->label) . '</option>';
                foreach ($terms as $term) {
                    $selected = isset($taxonomy_filters[$taxonomy->name]) && $taxonomy_filters[$taxonomy->name] == $term->slug ? 'selected' : '';
                    echo '<option value="' . esc_attr($term->slug) . '" ' . $selected . '>' . esc_html($term->name) . '</option>';
                }
                echo '</select>';
                echo '</div>';
            }
        }

        // Custom field filter
        $custom_fields = $this->get_custom_fields($selected_post_type);
        echo '<div style="margin-bottom: 10px;">';
        echo '<label>Custom Field: </label>';
        echo '<select name="custom_field_key">';
        echo '<option value="">Select Custom Field</option>';
        foreach ($custom_fields as $field) {
            echo '<option value="' . esc_attr($field) . '" ' . selected($custom_field_key, $field, false) . '>' . esc_html($field) . '</option>';
        }
        echo '</select>';
        echo '<input type="text" name="custom_field_value" placeholder="Custom Field Value" value="' . esc_attr($custom_field_value) . '">';
        echo '</div>';

        echo '<input type="submit" class="button" value="Apply Filters">';
        echo '</form>';

        // Export button
        echo '<div style="margin-top: 20px;">';
        echo '<button id="export-csv" class="button" ' .
            'data-post-type="' . esc_attr($selected_post_type) . '" ' .
            'data-blog-id="' . esc_attr($selected_blog_id) . '"' .
            '>Export Current View to CSV</button>';
        echo '</div>';

        // Display posts
        $args = array(
            'post_type' => $selected_post_type,
            'posts_per_page' => $posts_per_page,
            'paged' => $current_page,
            'post_status' => 'publish'
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
        $total_posts = $query->found_posts;
        $total_pages = ceil($total_posts / $posts_per_page);

        echo '<table class="widefat">';
        echo '<thead><tr><th>Featured Image</th><th>ID</th><th>Title</th><th>Permalink</th><th>Taxonomies</th><th>Custom Fields</th><th>Status</th><th>Date</th></tr></thead>';
        echo '<tbody>';
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post = get_post();
                $this->display_post_row($post, $selected_post_type);
            }
        } else {
            echo '<tr><td colspan="8">No posts found.</td></tr>';
        }
        echo '</tbody></table>';

        wp_reset_postdata();

        // Pagination
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $current_page
            ));
            echo '</div></div>';
        }

        echo '</div>'; // Close .wrap
    }
    private function display_post_row($post, $post_type, $level = 0)
    {
        $indent = str_repeat('— ', $level);
        echo '<tr>';
        echo '<td>' . $this->get_featured_image($post->ID) . '</td>';
        echo '<td>' . esc_html($post->ID) . '</td>';
        echo '<td>' . $indent . '<a href="#" class="toggle-content" data-post-id="' . esc_attr($post->ID) . '" data-blog-id="' . get_current_blog_id() . '">' . esc_html($post->post_title) . '</a></td>';
        echo '<td><a href="' . esc_url(get_permalink($post->ID)) . '" target="_blank">View</a></td>';
        echo '<td>' . $this->get_post_taxonomies($post->ID, $post_type) . '</td>';
        echo '<td>' . $this->get_post_custom_fields($post->ID) . '</td>';
        echo '<td>' . esc_html($post->post_status) . '</td>';
        echo '<td>' . esc_html($post->post_date) . '</td>';
        echo '</tr>';
        echo '<tr class="content-row" id="content-' . esc_attr($post->ID) . '" style="display:none;"><td colspan="7"></td></tr>';
    }


    private function get_post_taxonomies($post_id, $post_type)
    {
        $taxonomies = APT_Ajax_Handler::get_post_taxonomies_json($post_id, $post_type);
        $taxonomies = json_decode($taxonomies, true);
        return $this->display_listing($taxonomies);
    }

    private function get_post_custom_fields($post_id)
    {
        $custom_fields = APT_Ajax_Handler::get_post_custom_fields_json($post_id);
        $custom_fields = json_decode($custom_fields, true);
        return $this->display_listing($custom_fields);
    }

    private function display_listing($data)
    {
        $output = '<ul>';
        foreach ($data as $key => $values) {
            if (is_array($values)) {
                $output .= '<li><strong>' . esc_html($key) . ':(Array)</strong><ol>';
                foreach ($values as $value) {
                    $output .= '<li>' . esc_html($value) . '</li>';
                }
                $output .= '</ol></li>';
            } else {
                $output .= '<li><strong>' . esc_html($key) . ':</strong> ' . esc_html($values) . '</li>';
            }
        }
        $output .= '</ul>';
        return $output;
    }



    private function get_featured_image($post_id)
    {
        if (has_post_thumbnail($post_id)) {
            $image = wp_get_attachment_image_src(get_post_thumbnail_id($post_id), 'thumbnail');
            if ($image) {
                return '<img src="' . esc_url($image[0]) . '" width="50" height="50" alt="Featured Image" />';
            }
        }
        return '—';
    }
}
