<?php
/**
 * Plugin Name: ai Post Extender by Prefr.co
 * Description: ai Post Extender by Prefr.co is a WordPress plugin that enhances post content using the ChatGPT API if the content is below a specified minimum word count. This plugin is designed to help you improve your posts automatically by generating more content.
 * Version: 1.0
 * Author: Prefr.co
 * url: https://prefr.co/
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Register settings
function pe_register_settings() {
    add_option('pe_api_key', '');
    add_option('pe_min_word_count', 300);
    add_option('pe_enabled_post_types', ['post']);
    add_option('pe_extra_prompt', '');
    
    register_setting('pe_options_group', 'pe_api_key');
    register_setting('pe_options_group', 'pe_min_word_count');
    register_setting('pe_options_group', 'pe_enabled_post_types');
    register_setting('pe_options_group', 'pe_extra_prompt');
}
add_action('admin_init', 'pe_register_settings');

// Create settings page
function pe_create_menu() {
    add_menu_page('Post Extender', 'Post Extender', 'manage_options', 'pe-settings', 'pe_settings_page');
}
add_action('admin_menu', 'pe_create_menu');

// Settings page HTML
function pe_settings_page() {
    ?>
    <div class="wrap">
        <h1>Post Extender Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('pe_options_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">ChatGPT API Key</th>
                    <td><input type="text" name="pe_api_key" value="<?php echo get_option('pe_api_key'); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Minimum Word Count</th>
                    <td><input type="number" name="pe_min_word_count" value="<?php echo get_option('pe_min_word_count'); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable for Post Types</th>
                    <td>
                        <select multiple name="pe_enabled_post_types[]" style="width: 100%;">
                            <?php 
                            $post_types = get_post_types(['public' => true], 'objects');
                            $enabled_types = get_option('pe_enabled_post_types');
                            foreach ($post_types as $type) {
                                echo '<option value="' . $type->name . '"' . (in_array($type->name, $enabled_types) ? ' selected' : '') . '>' . $type->label . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Extra Text for Prompt</th>
                    <td><textarea name="pe_extra_prompt" rows="5" cols="50"><?php echo get_option('pe_extra_prompt'); ?></textarea></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Check word count on save
function pe_check_word_count($post_id) {
    // Avoid auto-saves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    // Get post type and content
    $post_type = get_post_type($post_id);
    $post_content = get_post_field('post_content', $post_id);
    
    $enabled_post_types = get_option('pe_enabled_post_types');
    if (!in_array($post_type, $enabled_post_types)) return;

    // Count words
    $word_count = str_word_count(strip_tags($post_content));
    $min_word_count = get_option('pe_min_word_count');

    // If below minimum, send to ChatGPT
    if ($word_count < $min_word_count) {
        $api_key = get_option('pe_api_key');
        $extra_prompt = get_option('pe_extra_prompt');
        $enhanced_content = pe_get_enhanced_content($post_content, $api_key, $extra_prompt);
        
        // Update post content
        if ($enhanced_content) {
            remove_action('save_post', 'pe_check_word_count'); // Prevent infinite loop
            wp_update_post(['ID' => $post_id, 'post_content' => $enhanced_content]);
            add_action('save_post', 'pe_check_word_count'); // Re-add action
        }
    }
}
add_action('save_post', 'pe_check_word_count');

// Function to call ChatGPT API
function pe_get_enhanced_content($content, $api_key, $extra_prompt) {
    $prompt = "Increase the length to at least {$min_word_count} words:\n\n" . $content . "\n\n" . $extra_prompt;
    $url = 'https://api.openai.com/v1/chat/completions';
    
    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 1500,
        ]),
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response));
    if (isset($body->choices[0]->message->content)) {
        return $body->choices[0]->message->content;
    }

    return false;
}