<?php

/*
Plugin Name: iPosts
Plugin URI: http://wordpress.org/extend/plugins/iposts/
Description: Import iOS app information into your posts, including icon and screenshots.
Author: Matt DeClaire
Version: 0.1
Author URI: http://declaire.com
*/

new iPosts;

define('IPOSTS_VERSION', '0.1');
define('IPOSTS_PLUGIN_URL', plugin_dir_url(__FILE__));

class iPosts {
	function __construct()
	{
		if (!session_id()) session_start();
		add_action('post_submitbox_misc_actions', array($this, 'publish_options'));
		add_action('save_post', array($this, 'save'));
		add_action('admin_notices', array($this, 'admin_notices'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));
	}

	function publish_options()
	{
		global $post;
		if (get_post_type($post) == 'post') {
			echo '<div class="misc-pub-section misc-pub-section-last" style="border-top: 1px solid #eee;">';
			$this->options($post);
			echo '</div>';
		}
	}

	function options($post)
	{
		$app_id = get_post_meta($post->ID, '_app_id', true);
		$tip = __("Is this post about an iOS app? Enter the iOS app ID here, and you can have information automattically imported into this post for you.  Once you enter an ID, more publishing options will become available.", 'iposts');
		$options = array(
			'icon' => __("Import app icon as featured image", 'iposts'),
			'screenshots' => __("Import app screenshots into gallery", 'iposts'),
			'meta' => __("Import app information into custom fields", 'iposts'),
			'tags' => __("Import app genres into tags", 'iposts'),
		);

		wp_nonce_field(plugin_basename(__FILE__), 'iposts_nonce');
		?>

		<label><?=__("App ID", 'iposts')?></label>
		<input type="text" name="iposts[app_id]" value="<?=esc_attr($app_id)?>" />
		<a href="#" class="iposts-tip" title="<?=esc_attr($tip)?>">?</a>

		<ul id="iposts-publish-options">
			<?php foreach ($options as $option => $text): ?>
				<li>
					<input type="checkbox" name="iposts[<?=$option?>]" id="iposts-<?=$option?>" checked />
					<label for="iposts-<?=$option?>"><?=$text?></label>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php
	}

	function enqueue($hook)
	{
		if (!in_array($hook, array('post.php', 'post-new.php'))) return;
		wp_register_script('iposts', IPOSTS_PLUGIN_URL.'iposts.js', array('jquery'), IPOSTS_VERSION);
		wp_enqueue_script('iposts');
	}

	function save($post_id)
	{
		if (wp_is_post_revision($post_id)) return;
		if (!isset($_REQUEST['iposts_nonce'])) return;
		if (!wp_verify_nonce($_REQUEST['iposts_nonce'], plugin_basename(__FILE__))) return;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!current_user_can('edit_post', $post_id)) return;
		if (!isset($_REQUEST['iposts'])) return;

		$options = wp_parse_args($_REQUEST['iposts'], array(
			'app_id' => false,
			'meta' => false,
			'tags' => false,
			'icon' => false,
			'screenshots' => false,
		));

		$app_id = trim($options['app_id']);

		if ($app_id == get_post_meta($post_id, '_app_id', true)) return;

		$results = $this->app_search($app_id);

		if (!$results) {
			$_SESSION['iposts']['save_post']['errors'][] = __("App ID $app_id not found", 'iposts');
			return;
		}

		$app_meta = $results[0];
		$post = get_post($post_id);

		// underscore field saved regardless of "save meta" option
		update_post_meta($post_id, '_app_id', $app_id);
		
		if ($options['meta'] && $app_meta) {
			update_post_meta($post_id, 'app_id', $app_id);
			update_post_meta($post_id, 'app_title', $app_meta->trackName);
			update_post_meta($post_id, 'app_author', $app_meta->artistName);
			update_post_meta($post_id, 'app_description', $app_meta->description);
			update_post_meta($post_id, 'app_price', floatval($app_meta->price));
			update_post_meta($post_id, 'app_version', $app_meta->version);
			update_post_meta($post_id, 'app_url', $app_meta->trackViewUrl);
			update_post_meta($post_id, 'app_vendor_url', $app_meta->sellerUrl);
			update_post_meta($post_id, 'app_release_date', strtotime($app_meta->releaseDate));
			update_post_meta($post_id, 'app_genres', $app_meta->genres);
			update_post_meta($post_id, 'app_rating', $app_meta->averageUserRatingForCurrentVersion);
			update_post_meta($post_id, 'app_rating_count', $app_meta->userRatingCount);
		}

		if ($options['tags']) {
			wp_set_post_terms($post_id, $app_meta->genres);
		}

		if ($options['icon']) {
			if ($app_meta->artworkUrl512 != get_post_meta(get_post_thumbnail_id($post_id), '_app_icon_original_url', true)) {
				$attachment_id = $this->import_photo(
					$app_meta->artworkUrl512,
					"$post->post_title app icon",
					$post_id,
					"app-icon-$post_id-"
				);

				if ($attachment_id) {
					set_post_thumbnail($post_id, $attachment_id);
					update_post_meta($attachment_id, '_app_icon_original_url', $app_meta->artworkUrl512);
				}
			}
		}

		if ($options['screenshots']) {
			$existing_screenhots = array();
			foreach (get_children(array(
				'post_parent' => $post_id,
				'post_type' => 'attachment',
				'post_mine_type' => 'image',
			)) as $screenshot) {
				if ($url = get_post_meta($screenshot->ID, '_app_screenshot_original_url', true)) {
					$existing_screenhots[] = $url;
				}
			}

			foreach (array(
				array(
					'app_meta_key' => 'screenshotUrls',
					'title' => __("iPhone screenshot", 'iposts'),
					'slug' => 'iphone-screenshot',
				),
				array(
					'app_meta_key' => 'ipadScreenshotUrls',
					'title' => __("iPad screenshot", 'iposts'),
					'slug' => 'ipad-screenshot',
				),
			) as $values) {
				extract($values);
				foreach ($app_meta->$app_meta_key as $ndx => $screenshot) {
					if (in_array($screenshot, $existing_screenhots)) continue;

					$attachment_id = $this->import_photo(
						$screenshot,
						"$post->post_title $title ".($ndx+1),
						$post_id,
						"app-$slug-$post_id-"
					);

					update_post_meta($attachment_id, '_app_screenshot_original_url', $screenshot);
				}
			}
		}
	}

	function admin_notices()
	{
		if (isset($_SESSION['iposts']['save_post']['errors'])) {
			foreach ($_SESSION['iposts']['save_post']['errors'] as $error) { ?>
				<div class="error">
					<p><?=$error?></p>
				</div>
			<?php }

			unset($_SESSION['iposts']['save_post']['errors']);
		}
	}

	function app_search($search)
	{
		$url = "https://itunes.apple.com/lookup?".http_build_query(array(
			'id' => $search,
			'country' => 'us',
			'media' => 'software',
		));

		if (!($response = wp_remote_get($url))) return false;
		if (wp_remote_retrieve_response_code($response) != '200') return false;
		if (!($json = wp_remote_retrieve_body($response))) return false;
		if (!($data = json_decode($json))) return false;

		return $data->results;
	}

	function import_photo($url, $title, $post_id = 0, $prefix = '')
	{
		$attachment = wp_upload_bits($prefix.basename($url), null, '');
		if ($attachment['error']) return false;

		stream_copy_to_stream(fopen($url, 'r'), fopen($attachment['file'], 'w+'));

		$filetype = wp_check_filetype($attachment['file'], null);

		$attach_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title' => $title,
				'post_content' => '',
				'post_status' => 'inherit',
			),
			$attachment['file'],
			$post_id
		);

		$attach_data = wp_generate_attachment_metadata($attach_id, $attachment['file']);
		wp_update_attachment_metadata($attach_id, $attach_data);

		return $attach_id;
	}
}