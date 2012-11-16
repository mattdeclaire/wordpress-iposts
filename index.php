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
		add_action('post_submitbox_misc_actions', array($this, 'publish_options'));
		add_action('save_post', array($this, 'save'));
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
		$tip = __("Is this post about an iOS app? Enter the iOS app ID here, and you can have information automattically imported into this post for you.  Once you enter an ID, more publishing options will become available.", 'iposts');
		$options = array(
			'screenshots' => __("Add screenshots to gallery", 'iposts'),
			'meta-data' => __("Set meta data", 'iposts'),
			'categories' => __("Set categories", 'iposts'),
			'icon' => __("Set icon as featured image", 'iposts'),
		);

		wp_nonce_field(plugin_basename(__FILE__), 'iposts_nonce');
		?>

		<p>
			<label><?=__("App ID", 'iposts')?></label>
			<input type="text" name="iposts[app_id]" value="<?=esc_attr($app_id)?>" />
			<a href="#" class="iposts-tip" title="<?=esc_attr($tip)?>">?</a>
		</p>

		<p id="iposts-publish-options">
			<?php foreach ($options as $option => $text): ?>
				<input type="checkbox" name="iposts[<?=$option?>]" id="iposts-<?=$option?>" checked />
				<label for="iposts-<?=$option?>"><?=$text?></label><br />
			<?php endforeach; ?>
		</p>

		<?php
	}

	function enqueue($hook)
	{
		if ($hook != 'post.php') return;
		wp_register_script('iposts', IPOSTS_PLUGIN_URL.'iposts.js', array('jquery'), IPOSTS_VERSION);
		wp_enqueue_script('iposts');
	}

	function save($post_id)
	{
		if (wp_is_post_revision($post_id)) return $post_id;
		if (!isset($_REQUEST['iposts_nonce'])) return $post_id;
		if (!wp_verify_nonce($_REQUEST['iposts_nonce'], plugin_basename(__FILE__))) return $post_id;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return $post_id;
		if (!current_user_can('edit_post', $post_id)) return $post_id;
		if (!isset($_REQUEST['iposts'])) return $post_id;

		$options = wp_parse_args($_REQUEST['iposts'], array(
			'app_id' => false,
			'meta-data' => false,
			'categories' => false,
			'icon' => false,
			'screenshots' => false,
		));

		$app_id = trim($options['app_id']);

		$results = $this->app_search($app_id);

		if (!$results) {
			// TODO: Display error to user
			return $post_id;
		}

		$app_meta = $results[0];
		$post = get_post($post_id);

		if ($options['app-meta']) {
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

		if ($options['categories']) {
			wp_set_post_terms($post_id, $app_meta->genres);
		}

		if ($options['icon'] && $attachment_id = $this->import_photo(
			$app_meta->artworkUrl512,
			"$post->post_title app icon",
			$post_id,
			"app-icon-$post_id-"
		)) {
			set_post_thumbnail($post_id, $attachment_id);
		}

		if ($options['screenshots']) {
			foreach ($app_meta->screenshotUrls as $ndx => $iphone_screenshot) {
				$this->import_photo(
					$iphone_screenshot,
					"$post->post_title iPhone screenshot ".($ndx+1),
					$post_id,
					"app-iphone-screenshot-$post_id-"
				);
			}

			foreach ($app_meta->ipadScreenshotUrls as $ndx => $ipad_screenshot) {
				$this->import_photo(
					$ipad_screenshot,
					"$post->post_title iPad screenshot ".($ndx+1),
					$post_id,
					"app-ipad-screenshot-$post_id-"
				);
			}
		}

		return $post_id;
	}

	function app_search($search)
	{
		$params = wp_parse_args($params, array(
		));

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