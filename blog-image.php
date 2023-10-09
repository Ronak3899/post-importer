<?php

// blog-image.php

function blog_import_images($item, $post_id)
{
    $featured_image_url = $item['_embedded']['wp:featuredmedia']['0']['source_url'];
    if ($featured_image_url) {
        // Set the featured image for the existing post
        $filename = basename($featured_image_url);
        $existing_attachment = get_page_by_title($filename, OBJECT, 'attachment');
        if ($existing_attachment) {
            $attachment_id = $existing_attachment->ID;
        } else {
            // Upload and attach the image
            $attachment_id=image_uploader($featured_image_url, $post_id);
        }
        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
}

function replace_images_from_content($post_id, $item)
{
    $doc = new DOMDocument();
    $doc->loadHTML($item['content']['rendered']);
    $imgTags = $doc->getElementsByTagName('img');
    foreach ($imgTags as $imgTag) {
        $imgSrc = $imgTag->getAttribute('src');
        if ($imgSrc) {
            // Check if the image already exists in the media library
            $filename = basename($imgSrc);
            $existing_attachment = get_page_by_title($filename, OBJECT, 'attachment');

            if ($existing_attachment) {
                // If the image already exists, set the attachment ID
                $attachment_id = $existing_attachment->ID;
            } else {
                $attachment_id=image_uploader($imgSrc, $post_id);
            }

            if ($attachment_id) {
                $imgTag->setAttribute('src', wp_get_attachment_url($attachment_id));
            }
        }
    }
    return $doc->saveHTML();
}

function image_uploader($imgSrc, $post_id)
{
    $image_data = file_get_contents($imgSrc);
    $upload_dir = wp_upload_dir();
    $filename = basename($imgSrc);
    $upload_filename = wp_unique_filename($upload_dir['path'], $filename);
    $upload_file = wp_upload_bits($upload_filename, null, $image_data);

    if (!$upload_file['error']) {
        $attachment = array(
            'post_mime_type' => $upload_file['type'],
            'post_title' => sanitize_file_name($upload_filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        $attachment_id = wp_insert_attachment($attachment, $upload_file['file'], $post_id);

        if (!is_wp_error($attachment_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload_file['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);
        } else {
            blog_importer_add_logs($post_id . 'Image:<a href=' . $imgSrc . '></a> Error uploading image: ' . $attachment_id->get_error_message());
        }
    } else {
        blog_importer_add_logs($post_id . 'Image:<a href=' . $imgSrc . '></a> Error uploading image: ' . $upload_file['error']);
    }
    return $attachment_id;
}
