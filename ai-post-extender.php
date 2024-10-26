<?php
/**
 * Plugin Name: ai Post Extender by Prefr.co
 * Description: ai Post Extender by Prefr.co is a WordPress plugin that enhances post content using the ChatGPT API if the content is below a specified minimum word count. This plugin is designed to help you improve your posts automatically by generating more content.
 * Version: 1.0.2
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
// GitHub Updater
if ( ! class_exists( 'GitHubUpdater' ) ) {
    class GitHubUpdater {
        private $slug;
        private $version;
        private $repo;
        private $uri;
        private $basename;
        private $api_url;

        public function __construct( $repo, $slug, $version, $uri ) {
            $this->repo = $repo;
            $this->slug = $slug;
            $this->version = $version;
            $this->uri = $uri;

            // Plugin basename
            $this->basename = plugin_basename( __FILE__ );

            // API URL
            $this->api_url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';

            // Add filters
            add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
            add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
            add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 2 );
        }

        public function check_for_update( $transient ) {
            if ( empty( $transient->checked ) ) {
                return $transient;
            }

            $response = wp_remote_get( $this->api_url, [
                'headers' => [
                    'User-Agent' => 'WordPress',
                ],
            ]);

            if ( is_wp_error( $response ) ) {
                return $transient; // Error getting response
            }

            $body = json_decode( wp_remote_retrieve_body( $response ) );

            if ( ! empty( $body->tag_name ) && version_compare( $this->version, $body->tag_name, '<' ) ) {
                $transient->response[ $this->basename ] = (object) [
                    'slug' => $this->slug,
                    'plugin' => $this->basename,
                    'new_version' => $body->tag_name,
                    'url' => $this->uri,
                    'package' => $body->zipball_url,
                ];
            }

            return $transient;
        }

        public function plugin_info( $false, $action, $response ) {
            if ( isset( $response->slug ) && $response->slug === $this->slug ) {
                $response->sections = [
                    'description' => 'This is the description of your plugin.',
                    'changelog' => 'Changelog information goes here.',
                ];
                return $response;
            }
            return $false;
        }

        public function clear_cache( $upgrader, $options ) {
            if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
                delete_site_transient( 'update_plugins' );
            }
        }
    }
}

// Initialize the updater
new GitHubUpdater( 'jkullar/ai-post-extender', 'ai-post-extender', '1.0', 'https://github.com/jkullar/ai-post-extender' );

// Hook to process posts created or updated via REST API
add_action('rest_after_insert_post', 'pe_process_rest_api_post', 10, 2);

function pe_process_rest_api_post($post, $request) {
    // Check if content processing is enabled for this post type
    $enabled_post_types = get_option('pe_enabled_post_types', ['post']);
    if (!in_array($post->post_type, $enabled_post_types)) {
        return;
    }

    // Get minimum word count
    $min_word_count = get_option('pe_min_word_count', 300);

    // Get the content and check word count
    $content = $post->post_content;
    $word_count = str_word_count(strip_tags($content));

    // If content is below the minimum word count, send it to ChatGPT for enhancement
    if ($word_count < $min_word_count) {
        $api_key = get_option('pe_api_key');
        $extra_prompt = get_option('pe_extra_prompt', '');

        $enhanced_content = pe_get_enhanced_content($content, $api_key, $extra_prompt);
        if ($enhanced_content) {
            // Update the post with the enhanced content
            wp_update_post([
                'ID' => $post->ID,
                'post_content' => $enhanced_content,
            ]);
        }
    }
}
