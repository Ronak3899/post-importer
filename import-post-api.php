<?php

// import-post-api.php
function import_posts_from_api($page)
{
    include plugin_dir_path(__FILE__) . 'blog-image.php';
    if ($page) {
        blog_importer_add_logs('Batch-' . $page . ' Importing.');
    }
    $website_url = get_option('blog_importer_website_url');
    $post_type = get_option('blog_importer_post_type');
    $website_post_type = get_option('blog_importer_website_post_type');
    $post_types_api_url = $website_url . '/wp-json/wp/v2/types/' . $website_post_type;
    $rest_api_post_type = wp_safe_remote_get($post_types_api_url);

    if (!is_wp_error($rest_api_post_type)) {
        $rest_api_post_type_response = json_decode(wp_remote_retrieve_body($rest_api_post_type), true);
    }

    $api_url = $rest_api_post_type_response['_links']['wp:items'][0]['href']  . '?_embed&per_page=10&page=' . $page;
    if (empty($api_url) || empty($post_type) || empty($website_post_type)) {
        return 'API URL and Custom Post Type must be configured in settings.';
    }

    $request_args = array(
        'timeout' => 20,
    );
    $response = wp_safe_remote_get($api_url, $request_args);
    if (is_wp_error($response)) {
        blog_importer_add_logs('Error fetching data from the API: ' . $response->get_error_message());
        return 'Error fetching data from the API: ' . $response->get_error_message();
    }
    $data = mb_convert_encoding(json_decode(wp_remote_retrieve_body($response), true), 'HTML-ENTITIES', 'UTF-8');
    // $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data) || empty($data)) {
        blog_importer_add_logs('No data found in the API response.');
        return 'No data found in the API response.';
    }

    foreach ($data as $item) {
        if (is_array($item) && isset($item['title']) && is_array($item['title']) && isset($item['title']['rendered'])) {
            $post_title = $item['title']['rendered'];
        } else {
            continue;
        }

        if (isset($item['_embedded']['wp:term'])) {
            $taxonomy_terms = array();

            foreach ($item['_embedded']['wp:term'] as $term_group) {
                foreach ($term_group as $term) {
                    $taxonomy_name = $term['taxonomy'];
                    $term_name = $term['name'];
                    $wp_term = get_term_by('name', $term_name, $taxonomy_name);

                    if ($wp_term) {
                        $taxonomy_terms[$taxonomy_name][] = $wp_term->term_id;
                    } else {
                        // Create a new term if it doesn't exist
                        $term_args = array(
                            'slug' => sanitize_title($term_name),
                        );

                        $new_term = wp_insert_term($term_name, $taxonomy_name, $term_args);

                        if (!is_wp_error($new_term) && isset($new_term['term_id'])) {
                            $taxonomy_terms[$taxonomy_name][] = $new_term['term_id'];
                        }
                    }
                }
            }
        }

        $author_data = $item['_embedded']['author'][0];
        $author_name = !empty($author_data['name']) ? $author_data['name'] : get_userdata(1)->display_name;
        $author_slug = !empty($author_data['slug']) ? $author_data['slug'] : get_userdata(1)->user_nicename;
        $author_url = !empty($author_data['link']) ? $author_data['link'] : get_userdata(1)->user_url;
        $author_description = !empty($author_data['description']) ? $author_data['description'] : get_userdata(1)->description;
        $author_avatar_urls = !empty($author_data['avatar_urls']) ? $author_data['avatar_urls'] : get_avatar_data(1);

        // Check if the author exists by slug
        $existing_author = get_user_by('slug', $author_slug);

        if (!$existing_author) {
            // Create a new author
            $new_author = wp_insert_user(array(
                'user_login' => $author_slug,
                'user_nicename' => $author_slug,
                'display_name' => $author_name,
                'user_url' => $author_url,
                'description' => $author_description,
                'user_pass' => wp_generate_password(),
                'role' => 'author',
            ));

            if (!is_wp_error($new_author)) {
                // Set the author's avatar if available
                if (!empty($author_avatar_urls)) {
                    foreach ($author_avatar_urls as $size => $avatar_url) {
                        update_user_meta($new_author, 'user_avatar_' . $size, $avatar_url);
                    }
                }
            }
        }

        // Check if a post with the same title already exists
        $existing_post = get_page_by_title($post_title, 'OBJECT', $post_type);
        if ($existing_post) {
            $post_id = $existing_post->ID;
            if (!has_post_thumbnail($post_id)) {
                blog_import_images($item, $post_id);
            }
            if (empty($existing_post->post_date)) {
                $post_date = strtotime($item['date']);
                if ($post_date !== false) {
                    $post_data = array(
                        'ID' => $post_id,
                        'post_date' => date('Y-m-d H:i:s', $post_date),
                        'post_author' => get_user_by('login', $author_slug)->ID, // Set the author
                    );
                    wp_update_post($post_data);
                }
            }
            // Assign categories and tags
            foreach ($taxonomy_terms as $taxonomy_name => $term_ids) {
                wp_set_post_terms($post_id, $term_ids, $taxonomy_name, false);
            }
            continue;
        }

        if (!empty($item['content']['rendered'])) {
            $post_content = replace_images_from_content($post_id, $item);
        }
        $post_status = $item['status'];
        $post_slug = $item['slug'];
        $post_excerpt = $item['excerpt']['rendered'];
        $post_data = array(
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_status' => $post_status,
            'post_type' => $post_type,
            'post_excerpt' => $post_excerpt,
            'post_author' => get_user_by('login', $author_slug)->ID,
            'post_name' => $post_slug,
        );
        $post_date = strtotime($item['date']);
        if ($post_date !== false) {
            $post_data['post_date'] = date('Y-m-d H:i:s', $post_date);
        }
        $post_id = wp_insert_post($post_data);
        $post_content = '';

        if (!is_wp_error($post_id)) {
            blog_import_images($item, $post_id);
            // Assign categories and tags
            foreach ($taxonomy_terms as $taxonomy_name => $term_ids) {
                wp_set_post_terms($post_id, $term_ids, $taxonomy_name, false);
            }
        } else {
            blog_importer_add_logs($post_id . '->Error creating post: ' . $post_id->get_error_message());
            wp_send_json_error($post_id . '->Error creating post: ' . $post_id->get_error_message());
        }
    }
    return 'Posts imported successfully from the API.';
}

function get_total_pages_from_api($args)
{
    $website_url = get_option('blog_importer_website_url');
    $website_post_type = get_option('blog_importer_website_post_type');
    $post_types_api_url = $website_url . '/wp-json/wp/v2/types/' . $website_post_type;
    $rest_api_post_type = wp_safe_remote_get($post_types_api_url);

    if (!is_wp_error($rest_api_post_type)) {
        $rest_api_post_type_response = json_decode(wp_remote_retrieve_body($rest_api_post_type), true);
    }

    $api_url = $rest_api_post_type_response['_links']['wp:items'][0]['href']  . '?_embed&per_page=10';
    $request_args = array(
        'timeout' => 20,
    );
    $response = wp_safe_remote_get($api_url, $request_args);
    if (is_wp_error($response)) {
        return 0;
    }
    $headers = wp_remote_retrieve_headers($response);
    if ($args == "totalpages") {
        if (isset($headers['x-wp-totalpages'])) {
            $total_pages = intval($headers['x-wp-totalpages']);
            return $total_pages;
        }
    }
    if ($args == "totalposts") {
        if (isset($headers['x-wp-total'])) {
            $total_posts = intval($headers['x-wp-total']);
            return $total_posts;
        }
    }
    return 0;
}

function check_post_type_has_data($post_slug)
{
    $website_url = get_option('blog_importer_website_url');
    $post_types_api_url = $website_url . '/wp-json/wp/v2/types/' . $post_slug;
    $rest_api_post_type = wp_safe_remote_get($post_types_api_url);
    if (!is_wp_error($rest_api_post_type)) {
        $rest_api_post_type_response = json_decode(wp_remote_retrieve_body($rest_api_post_type), true);
    }
    $api_url = $rest_api_post_type_response['_links']['wp:items'][0]['href'];
    $request_args = array(
        'timeout' => 20,
    );
    $response = wp_safe_remote_get($api_url, $request_args);

    if (empty(json_decode(wp_remote_retrieve_body($response), true))) {
        return false;
    }
    if (is_wp_error($response)) {
        return false;
    }
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 404) {
        return false;
    }
    return true;
}

function get_missing_taxonomies($post_slug)
{
    $website_url = get_option('blog_importer_website_url');
    $blog_importer_post_type = get_option('blog_importer_post_type');
    $post_types_api_url = $website_url . '/wp-json/wp/v2/types/' . $post_slug;
    $rest_api_post_type = wp_safe_remote_get($post_types_api_url);
    if (!is_wp_error($rest_api_post_type)) {
        $rest_api_post_type_response = json_decode(wp_remote_retrieve_body($rest_api_post_type), true);
        if (isset($rest_api_post_type_response['taxonomies'])) {
            $taxonomies = $rest_api_post_type_response['taxonomies'];
            $blog_importer_post_type_texonomy=get_object_taxonomies($blog_importer_post_type);
            $missing_taxonomies = array();
            foreach ($taxonomies as $taxonomy) {
                if (!in_array($taxonomy, $blog_importer_post_type_texonomy)) {
                    $missing_taxonomies[] = $taxonomy;
                }
            }
            $missing_taxonomies_string = implode(',', $missing_taxonomies);
            return empty($missing_taxonomies) ? false : $missing_taxonomies_string;
        }
    }
    return false;
}
