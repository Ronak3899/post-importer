<?php
/*
Plugin Name: Post Importer
Description: Import posts or any custom post type from an external website using REST API.
Version: 1.0
Author: Ronak Hapaliya
*/

require_once plugin_dir_path(__FILE__) . './wp-background-processing-master/wp-background-processing.php';
class API_Request_Process extends WP_Background_Process
{
    protected $action = 'blog_importer_bg_processing';

    protected function task($page)
    {
        import_posts_from_api($page);
        $page++;
        $total_page = get_total_pages_from_api('totalpages');
        if ($page <= $total_page) {
            return $page;
        }
        if ($page > $total_page) {
            blog_importer_add_logs('All posts have been imported.');
            $this->complete();
        }
    }
}

function start_api_request_processing()
{
    $api_process = new API_Request_Process();
    if (isset($_POST['blog_importer_import'])) {
        $initial_page = 1;
        $api_process->push_to_queue($initial_page);
        $api_process->save()->dispatch();
    }
    if (isset($_POST['blog_importer_cancel'])) {
        $api_process->cancel();
        blog_importer_add_logs('You have cancelled importing data.');
    }
}


function is_import_in_progress()
{
    $api_process = new API_Request_Process();
    return $api_process->is_processing();
}
function is_import_cancelled()
{
    $api_process = new API_Request_Process();
    return $api_process->is_cancelled();
}
add_action('init', 'start_api_request_processing');

register_activation_hook(__FILE__, 'blog_importer_create_table');

function blog_importer_create_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'blog_importer_logs';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        bi_logs text NOT NULL,
        timestamp timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Function to add data to the database table.
function blog_importer_add_logs($data)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'blog_importer_logs';

    $wpdb->insert(
        $table_name,
        array(
            'bi_logs' => $data,
        )
    );
}
function blog_importer_menu()
{
    add_menu_page(
        'Post Importer Settings',
        'Post Importer',
        'manage_options',
        'blog-importer-settings',
        'blog_importer_settings_page'
    );
    add_submenu_page(
        'blog-importer-settings',
        'Import Logs',
        'Import Logs',
        'manage_options',
        'blog-importer-logs',
        'blog_importer_logs_page'
    );
}
add_action('admin_menu', 'blog_importer_menu');

function blog_importer_settings_page()
{    ?>
    <div class="wrap">
        <h2>Post Importer Settings</h2>
        <?php 
        if (isset($_POST['blog_importer_import'])) {
            echo '<div class="updated"><p>Posts import has been started..! <a href="' . get_admin_url() . 'admin.php?page=blog-importer-logs">Click here</a> for more details.</p></div>';
        }
        $website_url = get_option('blog_importer_website_url');
        $post_type = get_option('blog_importer_post_type');
    
        $settings_saved = isset($_POST['blog_importer_save']);
    
        if ($settings_saved) {
            update_option('blog_importer_website_url', sanitize_text_field($_POST['website_url']));
            update_option('blog_importer_post_type', sanitize_text_field($_POST['post_type']));
            update_option('blog_importer_website_post_type', sanitize_text_field($_POST['website_post_type']));
            $total_posts = get_total_pages_from_api('totalposts');
            $total_page = get_total_pages_from_api('totalpages');
            echo ' <div class="notice notice-info is-dismissible"><p>Author&apos;s email, first name, last name and nickname can not be imported. That you need to set manually.</p></div>';
            if ($total_page > 0 && $total_posts > 0) {
                $timeMessage = format_import_time($total_page, $total_posts);
                echo '<div class="notice notice-success is-dismissible"><p>' . $timeMessage . ' to import all posts.<br> Click on Import Posts button to start import.</p></div>';
            }
            $missing_texonomy=get_missing_taxonomies($_POST['website_post_type']);
            if($missing_texonomy){
                echo ' <div class="notice notice-warning is-dismissible"><p>Your website doesn&apos;t have '.$missing_texonomy.' as registered taxonomy with post type '.$_POST['post_type'].'. Please register it before importing data to avoid data lose.</p></div>';
            }
            $website_url = sanitize_text_field($_POST['website_url']);
            $post_type = sanitize_text_field($_POST['post_type']);
        }
        $post_types_api_url = $website_url . '/wp-json/wp/v2/types';
        $post_types_response = wp_safe_remote_get($post_types_api_url);
    
        if (!is_wp_error($post_types_response)) {
            $post_types_data = json_decode(wp_remote_retrieve_body($post_types_response), true);
        }
    ?>
        <form method="post" action="">
            <label for="website_url">Website URL:</label>
            <br>
            <input type="text" id="website_url" name="website_url" value="<?php echo esc_attr($website_url); ?>" style="width: 33%;" placeholder="Enter your website's url" required>
            <br>
            <span id="url_validation_message" style="color: red;"></span>
            <br>
            <?php if (!empty($post_types_data) && check_post_type_has_data('post')) { ?>
                <label for="post_type">Select Post Type From You Want To Import Data:</label>
                <br>
                <select id="post_type" class="checkPostType" name="website_post_type" style="width: 100%;">
                    <?php

                    foreach ($post_types_data as $post_type_data) {
                        $excluded_post_types = array(
                            'page',
                            'feedback',
                            'attachment',
                            'nav_menu_item',
                            'wp_block',
                            'wp_template',
                            'wp_template_part',
                            'wp_navigation',
                        );

                        if ($post_type_data && !in_array($post_type_data['slug'], $excluded_post_types)) {
                            if (check_post_type_has_data($post_type_data['slug'])) {
                                $selected_post_type = get_option('blog_importer_website_post_type');
                                $selected = ($selected_post_type === $post_type_data['slug']) ? 'selected' : '';
                                echo '<option value="' . esc_attr($post_type_data['slug']) . '" ' . $selected . '>' . esc_html($post_type_data['name']) . '</option>';
                            }
                        }
                    }
                    ?>
                </select>
                <br><br>

                <label for="post_type">Select Post Type In Which You Want To Import Data:</label>
                <br>
                <?php
                $post_types = get_post_types(array('public' => true), 'objects');
                ?>
                <select id="post_type" name="post_type" style="width: 100%;" required>
                    <?php
                    foreach ($post_types as $post_type_obj) {
                        // Exclude specific post types
                        $excluded_post_types = array(
                            'page',
                            'feedback',
                            'attachment',
                            'nav_menu_item',
                            'wp_block',
                            'wp_template',
                            'wp_template_part',
                            'wp_navigation',
                        );

                        if (!in_array($post_type_obj->name, $excluded_post_types)) {
                            $selected = ($post_type === $post_type_obj->name) ? 'selected' : '';
                            echo '<option value="' . esc_attr($post_type_obj->name) . '" ' . $selected . '>' . esc_html($post_type_obj->label) . '</option>';
                        }
                    }
                    ?>
                </select>
                <br>
                <br>
            <?php
            } else {
                if ($website_url) {
                    echo '<div class="error"><p>Website has no data to import..!</p></div>';
                }
            }
            ?>
            <?php if (!$settings_saved) { ?>
                <button type="submit" name="blog_importer_save" class="button button-primary">Save Settings</button>
            <?php } else { ?>
                <button type="submit" name="blog_importer_save" class="button button-primary">Save Settings</button>
                <?php
                $total_posts = get_total_pages_from_api('totalposts');
                $total_page = get_total_pages_from_api('totalpages');
                if ($total_page > 0 && $total_posts > 0) { ?>
                    <button type="submit" name="blog_importer_import" class="button button-primary">Import Posts</button>
                <?php } ?>
            <?php }
            if (is_import_in_progress() && !is_import_cancelled()) {
                echo '<button type="submit" name="blog_importer_cancel" class="button button-secondary">Cancel Import</button>';
            } ?>
        </form>
    </div>
<?php
}

function blog_importer_logs_page()
{

    global $wpdb;
    $table_name = $wpdb->prefix . 'blog_importer_logs';

    if (isset($_POST['blog_importer_clear_logs'])) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="updated"><p>Logs cleared.</p></div>';
    }

    $results = $wpdb->get_results("SELECT * FROM $table_name");

    echo '<div class="wrap">';
    echo '<h2>Post Import Logs</h2>';

    if (!empty($results)) {

        echo '<table border=1 style="border-collapse: collapse;margin-bottom:20px;margin-top:10px;">';
        echo '<tr><th>ID</th><th>Date & Time</th><th>Log Details</th></tr>';

        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($row->timestamp) . '</td>';
            echo '<td style="overflow:s">' . esc_html($row->bi_logs) . '</td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '<form method="post" action="">';
        echo '<button type="submit" name="blog_importer_clear_logs" class="button button-primary">Clear Logs</button>';
        echo '</form>';
    } else {
        echo '<p>No logs found.</p>';
    }

    echo '</div>';
}

function format_import_time($total_page, $total_posts)
{
    $totalMinutes = $total_page * 5;
    $totalHours = floor($totalMinutes / 60);
    $remainingMinutes = $totalMinutes % 60;

    $timeMessage = 'Total ' . $total_posts . ' ' . $_POST['website_post_type'] . ' will be imported in ' . $total_page . ' batches.';

    if ($totalHours > 0) {
        $timeMessage .= ' It will take approximately ' . $totalHours . ' hour';
        if ($totalHours > 1) {
            $timeMessage .= 's';
        }

        if ($remainingMinutes > 0) {
            $timeMessage .= ' and ' . $remainingMinutes . ' minute';
            if ($remainingMinutes > 1) {
                $timeMessage .= 's';
            }
        }
    } else {
        $timeMessage .= ' It will take approximately ' . $totalMinutes . ' minute';
        if ($totalMinutes > 1) {
            $timeMessage .= 's';
        }
    }

    return $timeMessage;
}
include plugin_dir_path(__FILE__) . 'import-post-api.php';

function blog_importer_enqueue_custom_scripts()
{
    wp_enqueue_script('custom-qrcode', plugins_url('/blog-importer.js', __FILE__), array('jquery'), '1.0.0', true);
}
add_action('admin_enqueue_scripts', 'blog_importer_enqueue_custom_scripts');
