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

    public function display_data()
    {
        global $wpdb;

        $posts_per_page = 20; // Number of posts to display per page
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';

        // Multisite blog selection
        $current_blog_id = get_current_blog_id();
        $selected_blog_id = isset($_GET['blog_id']) ? intval($_GET['blog_id']) : $current_blog_id;

        echo '<div class="wrap">';
        echo '<h1>All Post Types Data (Including Unregistered)</h1>';

        // Display blog selection dropdown for multisite
        if (is_multisite()) {
            $blogs = get_sites();
            echo '<form method="get" action="">';
            echo '<input type="hidden" name="page" value="all-post-types-data">';
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

        // Get all post types from the database
        $post_types = $wpdb->get_col("SELECT DISTINCT post_type FROM {$wpdb->posts}");

        // Display tabs
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($post_types as $post_type) {
            $post_type_obj = get_post_type_object($post_type);
            $post_type_name = $post_type_obj ? $post_type_obj->labels->name : $post_type . ' (Unregistered)';
            $active_class = ($active_tab === $post_type) ? 'nav-tab-active' : '';
            echo '<a href="?page=all-post-types-data&tab=' . esc_attr($post_type) . '&blog_id=' . $selected_blog_id . '" class="nav-tab ' . $active_class . '">' . esc_html($post_type_name) . '</a>';
        }
        echo '</h2>';

        // Display content for active tab
        if ($active_tab) {
            $total_posts = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                $active_tab
            ));

            $total_pages = ceil($total_posts / $posts_per_page);
            $offset = ($current_page - 1) * $posts_per_page;

            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_status, post_date FROM {$wpdb->posts} 
                WHERE post_type = %s AND post_status = 'publish'
                ORDER BY post_date DESC
                LIMIT %d OFFSET %d",
                $active_tab,
                $posts_per_page,
                $offset
            ));

            $post_type_obj = get_post_type_object($active_tab);
            $post_type_name = $post_type_obj ? $post_type_obj->labels->name : $active_tab . ' (Unregistered)';

            echo '<h3>' . esc_html($post_type_name) . '</h3>';

            // Add export to CSV button
            echo '<button id="export-csv" class="button" data-post-type="' . esc_attr($active_tab) . '" data-blog-id="' . get_current_blog_id() . '">Export to CSV</button>';

            echo '<table class="widefat">';
            echo '<thead><tr><th>Featured Image</th><th>ID</th><th>Title</th><th>Permalink</th><th>Taxonomies</th><th>Status</th><th>Date</th></tr></thead>';
            echo '<tbody>';
            $this->display_posts_hierarchical($posts, $active_tab, 0, 0);
            echo '</tbody></table>';

            // Pagination
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(array(
                    'base' => add_query_arg(array('paged' => '%#%', 'tab' => $active_tab, 'blog_id' => $selected_blog_id)),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $current_page
                ));
                echo '</div></div>';
            }
        } else {
            echo '<p>Select a post type tab to view data.</p>';
        }

        // Switch back to the current blog if we switched
        if (is_multisite() && $selected_blog_id !== $current_blog_id) {
            restore_current_blog();
        }

        echo '</div>';
    }

    private function display_posts_hierarchical($posts, $post_type, $parent = 0, $level = 0)
    {
        foreach ($posts as $post) {
            if (@$post->post_parent == $parent) {
                $indent = str_repeat('— ', $level);
                echo '<tr>';
                echo '<td>' . $this->get_featured_image($post->ID) . '</td>';
                echo '<td>' . esc_html($post->ID) . '</td>';
                echo '<td>' . $indent . '<a href="#" class="toggle-content" data-post-id="' . esc_attr($post->ID) . '" data-blog-id="' . get_current_blog_id() . '">' . esc_html($post->post_title) . '</a></td>';
                echo '<td><a href="' . esc_url(get_permalink($post->ID)) . '" target="_blank">View</a></td>';
                echo '<td>' . $this->get_post_taxonomies($post->ID, $post_type) . '</td>';
                echo '<td>' . esc_html($post->post_status) . '</td>';
                echo '<td>' . esc_html($post->post_date) . '</td>';
                echo '</tr>';
                echo '<tr class="content-row" id="content-' . esc_attr($post->ID) . '" style="display:none;"><td colspan="7"></td></tr>';

                // Recursively display child posts
                $this->display_posts_hierarchical($posts, $post_type, $post->ID, $level + 1);
            }
        }
    }


    private function get_post_taxonomies($post_id, $post_type)
    {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $taxonomy_terms = array();

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy->name);
            if ($terms && !is_wp_error($terms)) {
                $term_names = array_map(function ($term) {
                    return esc_html($term->name);
                }, $terms);
                $taxonomy_terms[] = '<strong>' . esc_html($taxonomy->label) . ':</strong> ' . implode(', ', $term_names);
            }
        }

        return !empty($taxonomy_terms) ? implode('<br>', $taxonomy_terms) : 'No taxonomies';
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
