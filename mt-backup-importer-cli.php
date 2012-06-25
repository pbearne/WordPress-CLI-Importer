<?php

if (!class_exists('CLI_Import')) {
	include_once('cli-import.php');
}

/**
 * Command Line importer for moveable type backups
 * 
 * Differences between base backup importer (2012/04/09):
 *  - Additional Assets places in upload directory, not import
 *  - Remove HTML from status output, use command line
 *  - Support command line arguments
 *  - Fix issue with UTF_8 requirement of libxml
 *  - Catch libxml errors
 *  - Fix issue with only one category being imported
 *  - Support different import directory for easier automation on MS installs
 * 		- Not just /upload_dir/import
 * 
 * Based on MT_Backup_Importer http://wordpress.org/extend/plugins/movable-type-backup-importer/
 *
 */
class MT_Backup_Importer_CLI extends CLI_Import{
	
	// Used in CLI_Import to specify command
	protected $filename = __FILE__;
	
	// MT_Backup_Importer
	protected $_siteurl;
	protected $_path;
	protected $_autosizing = false;
	protected $_content_width_upscale = 500;
	protected $_content_width = 550;
	protected $_slug_separator = '-'; 
	protected $_broken = array();
	protected $_uploadurl = '';
	
	function __construct() {		
		// Grab command line arguments
		parent::__construct();
	}
	
	protected function dispatch() {
		$this->debug_msg('Starting Import');
		$this->import();			
	}
		
	function import() {

        // set time limit to unlimited
		set_time_limit(0);
		
		// allow iframes and stuff
		// credits: http://wordpress.org/support/topic/bypass-sanitize_post-from-wp_insert_post
		kses_remove_filters();
		
		// disable automatic shortlink generation (because of performance reasons)
		define('DISABLE_SHORTLINK', true);
		
		// disable too much xml warnings
		if (function_exists('libxml_use_internal_errors')) {
			libxml_use_internal_errors(true);
		}
		
		// let's show the user some progress (to keep him happy, while importing)
		ob_implicit_flush(true);
		
		$directory = wp_upload_dir();
		$import_dir = $this->args->import_dir;
		$basedir = $directory['basedir'] . '/import/';
		$this->_path = trailingslashit($directory['path']);
		$this->_uploadurl = trailingslashit($directory['url']);
		
		// this is the path to the first backup file, but we need
		// to look for other chunks as well

		$backup = end(explode('/',$this->args->backup));
		
		$basebackup = substr($backup, 0, -5);
		$chunks = glob($import_dir . $basebackup . '*');
		$chunk_count = sizeof($chunks);

		// initialize mappings
		$mappings = array(
			'user' => array(), 
			'post' => array(),
			'assets' => array(),
			'tags' => array(),
			'categories' => array()
		);

		// process every single chunk file
		for ($i = 1; $i <= $chunk_count; $i++) {
			$chunk_file = $import_dir . $basebackup . $i . '.xml';
			if (is_file($chunk_file)) {
				$this->_import_mt_backup_file($import_dir, basename($chunk_file), $mappings);
			}
		}
	}
	
	function _import_mt_backup_file($basedir, $backup, &$mappings) {
		
		// cleanup xml file before import
		$content = file_get_contents($basedir . $backup);
		
		// remove bad characters
		$content = str_replace(array("", "", '&#176'), array("","", '&#176;'), $content);
		
		// remove unused log entries
		$content = preg_replace("%(<log.*?>.*?<\/log.*?>)%is", '', $content);
		
		// Force UTF8		
		$content = iconv('ISO-8859-1', 'UTF-8//TRANSLIT', $content);
		$content = self::_strip_invalid_xml($content);
		
		$content = self::_fix_incorrect_html_entities($content);
		
		// write temporary clean xml file
		file_put_contents($basedir . 'clean-' . $backup, $content);
		unset($content);
		
		// start import
		$this->_import_mt($basedir, 'clean-' . $backup, $mappings);
		
	}

	function _import_mt($path, $backup, &$mappings) {
		$backupxml = $path . $backup;
		$this->debug_msg('Importing'.$backupxml);
		if (is_file($backupxml)) {
			libxml_use_internal_errors(true);
			$xml = simplexml_load_file($backupxml);
			if (!$xml) {
				$errors = libxml_get_errors();

			    foreach ($errors as $error) {
					$this->debug_msg('ERROR: Unable to Parse '.$backupxml.', libxml error code '.$error->code);
			    }

			    libxml_clear_errors();
			}
			$nodes = $xml->children();
			foreach ($nodes as $name => $node) {
				switch ($name) {
					case 'author': $this->_import_mt_user($path, $node, $mappings); break;
					case 'blog': $this->_import_mt_blog($path, $node, $mappings); break;
					case 'entry': $this->_import_mt_post($path, $node, $mappings); break;
					case 'image': $this->_import_mt_image($path, $node, $mappings); break;
					case 'file': $this->_import_mt_file($path, $node, $mappings); break;
					case 'tag': $this->_import_mt_tag($path, $node, $mappings); break;
					case 'placement': $this->_import_mt_placement($path, $node, $mappings); break;
					case 'category': $this->_import_mt_category($path, $node, $mappings); break;
					case 'objecttag': $this->_import_mt_objecttag($path, $node, $mappings); break;
					case 'objectasset': $this->_import_mt_objectasset($path, $node, $mappings); break;
					case 'comment': $this->_import_mt_comment($path, $node, $mappings); break;
					// case 'trackback': $this->_import_mt_trackback($path, $node, $mappings); break;
				}
			}
		}
	}
	
	function _import_mt_blog($path, $blog, &$mappings) {
		$this->_siteurl = $blog->attributes()->site_url;
	}

	function _import_mt_user($path, $user, &$mappings) {

		$id = (string) $user->attributes()->id;
		$email = (string) $user->attributes()->email;
		$username = (string) $user->attributes()->name;
		$nickname = (string) $user->attributes()->nickname;
		$status = (string) $user->attributes()->status; // 1 = Active, 2 = Inactive
		$password = (string) $user->attributes()->password;
		$password = strlen($password) > 0 ? $password : wp_generate_password();
		$created = (string) $user->attributes()->created_on;
		$role = $status == 'subscriber'; //1 ? 'editor' : 'subscriber';

		$data = array(
			'user_pass' => $password,
			'user_login' => $username,
			'user_email' => strtolower($email),
			'display_name' => $nickname,
			'user_registered' => date('Y-m-d H:i:s', strtotime($created)),
			'role' => $role
		);
		
		// Users with "ldaprename" prefix are not handled correctly
		if (substr($username,0,10) == 'ldaprename') {
			$user_id = username_exists(substr($username,10));
			if ($user_id !== null) {
				// user already exists, perfect, so nothing to do here, except updating our mapping
				// array for later use.
				$mappings['user'][(string)$id] = $user_id;
				return;
			} else {
                // If the username is just "ldaprename", we kick it
                if (strlen($username) > 10) {
				    $data['user_login'] = strtolower(substr($username,10));
                } else {
                    return;
                }
			}
		}
		
		// if there is still no password, set a random one.
		// ldap user don't have passwords set, so we generate a random one to not
		// allow them to enter if something goes wrong with the ldap connection.
		if (trim($data['user_pass']) == '' || trim($data['user_pass']) == '(none)') {
			$data['user_pass'] = wp_generate_password();
		}

		$data = apply_filters('mtbi_pre_insert_user', $data);
		$username = $data['user_login'];

		// check if user already exists
		$user_id = username_exists($username);
		if (!$user_id) {
			$user_id = wp_insert_user($data);
		}
		
		$blog_id = $this->args->blog;
		
		$user = new WP_User($user_id);
		if (!get_user_meta($user_id, 'primary_blog', true)) {
			update_user_meta($user_id, 'primary_blog', $blog_id);
			$details = get_blog_details($blog_id);
			update_user_meta($user_id, 'source_domain', $details->domain);
		}

		$user->set_role($role);
		wp_cache_delete( $user_id, 'users' );
				
		// add mapping
		$mappings['user'][(string)$id] = $user_id;
		
	}

	function _import_mt_asset($path, $asset, &$mappings) {

		$id = (string) $asset->attributes()->id;
		$filename = (string) $asset->attributes()->file_name;
		$mimetype = (string) $asset->attributes()->mime_type;
		$label = (string) $asset->attributes()->label;
		$parent = (string) $asset->attributes()->parent;
		$entry_id = (string) $asset->attributes()->entry_id;
		$title = strlen($label) > 0 ? $label : $filename;
		$author = (string) $asset->attributes()->created_by;
		$created_on = date('Y-m-d H:i:s', strtotime($asset->attributes()->created_on));

		// Grab file URL for backup fetching and replacement
		$base_url = (string) $asset->attributes()->url;
		$old_file_url = false;
		if (!empty($base_url)) {
			$old_file_url = str_replace('%r/', trailingslashit($this->_siteurl), $base_url);
			// File url needs to contain start with some form of http
			if (strpos(strtolower($old_file_url), 'http') != 0) {
				$old_file_url = trailingslashit($this->_siteurl).ltrim($old_file_url, '/');
			}
		}
		
		// Skip, if it's a thumbnail
		if ($parent > 0) {
			return;
		}
		
		$attachment = array(
		     'post_content' => '',
		     'post_status' => 'inherit',
			 'post_author' => isset($mappings['user'][$author]) ? $mappings['user'][$author] : null,
			 'post_date' => $created_on,
		);
		
		// Grab the mime type from the file extension if it doesnt exist
		if (!empty($mimetype)) {
			$attachment['post_mime_type'] = $mimetype;
		}
		else if ($info = wp_check_filetype($filename)) {
			$attachment['post_mime_type'] = $info['type'];
		}
		
		$title = preg_replace('/\.[^.]+$/', '', $title);
		if (!empty($title)) {
			$attachment['post_title'] = $title;
		}
		
		$attachment = apply_filters('mtbi_pre_insert_attachment', $attachment);
		
		if (!($attach_id = post_exists($attachment['post_title'], '', $attachment['post_date']))) {
		
			// This is really how they are exported in the zip backup
			$filename = $id.'-'.$filename;
		
			// Move the file into the uploads directory for this site
			$directory = wp_upload_dir();
			if (file_exists(trailingslashit(WP_CONTENT_DIR).'imports/'.$filename)) {
				rename(trailingslashit(WP_CONTENT_DIR).'imports/'.$filename, $this->_path.$filename);
				$filepath = $this->_path.$filename;
			}
			else if (!empty($old_file_url)){
				// Get the file from URLs
				$filepath = $this->_download_broken_url($old_file_url);
			}

			if (!empty($filepath)) {
				$attach_id = wp_insert_attachment($attachment, $filepath, null);
				require_once(ABSPATH . 'wp-admin/includes/image.php');
				$attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
				wp_update_attachment_metadata($attach_id, $attach_data);
				$mappings['assets'][$id] = array('id' => $attach_id);
			}
		}
		
		// Keep track of assets, even if it has already been imported and replace the content of posts
		if (!empty($attach_id) && !is_wp_error($attach_id) && !empty($mappings['assets'][$id])) {
			$new_url = wp_get_attachment_url($attach_id);
			// Only set mapping data if there is a new and old url.
			if (!empty($old_file_url) && !empty($new_url)) {
				$mappings['assets'][$id]['new_url'] = $new_url;
				$mappings['assets'][$id]['old_url'] = $old_file_url;
				// Replace the old URL with the new URL in the content
				$this->_replace_content_asset($old_file_url, $new_url);
			}			
		}
	}
	
	function _import_mt_image($path, $post, &$mappings) {
		$this->_import_mt_asset($path, $post, $mappings);
	}
	
	function _import_mt_file($path, $post, &$mappings) {
		$this->_import_mt_asset($path, $post, $mappings);
	}

	function _import_mt_post($path, $post, &$mappings) {

		$id = $post->attributes()->id;
		$allow_comments = $post->attributes()->allow_comments;
		$allow_pings = $post->attributes()->allow_pings;
		$atom_id = $post->attributes()->atom_id;
		$author_id = (string) $post->attributes()->author_id;
		$authored_on = date('Y-m-d H:i:s', strtotime($post->attributes()->authored_on));
		$basename = $post->attributes()->basename;
		$comment_count = $post->attributes()->comment_count;
		$class = $post->attributes()->class;
		$created_by = $post->attributes()->created_by;
		$created_on = date('Y-m-d H:i:s', strtotime($post->attributes()->created_on));
		$authored_on = date('Y-m-d H:i:s', strtotime($post->attributes()->authored_on));
		$modified_by = $post->attributes()->modified_by;
		$modified_on = date('Y-m-d H:i:s', strtotime($post->attributes()->modified_on));
		$ping_count = $post->attributes()->ping_count;
		$status = $post->attributes()->status;
		$title = $post->attributes()->title;
		$weeknumber = $post->attributes()->weeknumber;
		$content = (string) $post->text;
		$search = array();
		$replace = array();
		$preg_search = array();
		$preg_replace = array();

        // support for extended content
        if ('' != trim($post->text_more)) {
            $extended = (string) $post->text_more;
            $content = $content . "\n<!--more-->\n" . $extended;
        }

		if (strlen($content) > 0) {
			// replace image tags
			$pattern = '/<img.*?src="(.*?)"(.*?)>/is';
			$content = preg_replace_callback($pattern, array('self', '_replace_mt_image'), $content);
			
			// replace links
			$pattern = '/<a.*?href="(.*?)".*?>(.*?)<\/a>/is';
			$content = preg_replace_callback($pattern, array('self', '_replace_mt_link'), $content);
		}

		$post = new StdClass;
		$post->post_content = $content;
		$post->post_author = isset($mappings['user'][$author_id]) ? $mappings['user'][$author_id] : null;
		$post->post_title = $title;
		$post->post_name = str_replace('_', $this->_slug_separator, $basename);
		$post->post_status = $status == 2 ? 'publish' : null; // TODO
		$post->post_modified = $modified_on;
		$post->post_modified_gmt = get_gmt_from_date($modified_on);
		$post->post_date = $authored_on;
		$post->post_date_gmt = get_gmt_from_date($authored_on);
		$post->ping_status = $allow_pings == 1 ? true : false;
		$post->comment_status = $allow_comments == 1 ? 'open' : 'closed';
		
		// Tell Wordpress that the content is already filtered to allow embed, iframe, etc.
		// Credits to: http://pp19dd.com/2010/06/unfiltered-wp_insert_post/
		$post->filter = true;
        $post_id = $this->_save_post($post);
        $mappings['posts'][(string)$id] = $post_id;

	}

	function _import_mt_tag($path, $tag, &$mappings) {

		$id = $tag->attributes()->id;
		$n8d_id = $tag->attributes()->n8d_id;
		$name = $tag->attributes()->name;
		
		$name = apply_filters('mtbi_tag_name', $name);
		
		$mappings['tags'][(string)$id] = (string)$name;

	}

	function _import_mt_objecttag($path, $tag, &$mappings) {

		$id = $tag->attributes()->id;
		$object_datasource = $tag->attributes()->object_datasource;
		$object_id = (string) $tag->attributes()->object_id;
		$tag_id = (string) $tag->attributes()->tag_id;

		if ($object_datasource == 'entry') {
			wp_add_post_tags($mappings['posts'][$object_id], $mappings['tags'][$tag_id]);
		}

	}

	function _import_mt_category($path, $category, &$mappings) {

		$id = $category->attributes()->id;
		$allow_pings = $category->attributes()->allow_pings;
		$author_id = $category->attributes()->author_id;
		$basename = $category->attributes()->basename;
		$created_by = $category->attributes()->created_by;
		$created_on = $category->attributes()->created_on;
		$label = $category->attributes()->label;
		$modified_on = $category->attributes()->modified_on;
		$parent = $category->attributes()->parent;

		$data = array(
		  'cat_name' => $label,
		  'category_nicename' => $basename
		);
		$data = apply_filters('mtbi_category_data', $data);
		
		$category = get_category_by_slug($basename);
		if (!$category) {
            $wp_error = array();
			$cat_id = wp_insert_category($data, $wp_error);
		} else {
			$cat_id = $category->cat_ID;
		}
		$mappings['categories'][(string)$id] = intval($cat_id);

	}

	/**
	 * Import MT formatted categories to posts
	 * These are typically at the end of the file
	 *
	 * @param string $path 
	 * @param string $placement placement array
	 * @param string $mappings 
	 * @return void
	 */
	function _import_mt_placement($path, $placement, &$mappings) {
		$id = $placement->attributes()->id;
		$category_id = (string) $placement->attributes()->category_id;
		$post_id = (string) $placement->attributes()->entry_id;
		$is_primary = $placement->attributes()->is_primary; // 1 = true, 0 = false
		
		// Dont append categories if the current category is 'uncategorized'
		$append = true;
		$current_categories = wp_get_object_terms($mappings['posts'][$post_id], 'category', array('fields' => 'names'));
		if ((empty($current_categories)) || (is_array($current_categories) && !empty($current_categories[0]) && $current_categories[0] == 'Uncategorized')) {
			$append = false;
		}
				
		wp_set_object_terms($mappings['posts'][$post_id], array($mappings['categories'][$category_id]),  'category', $append);
	}

	function _import_mt_objectasset($path, $objectasset, &$mappings) {
		$id = (string) $objectasset->attributes()->id;
		$asset_id = (string) $objectasset->attributes()->asset_id;
		$embedded = (string) $objectasset->attributes()->embedded;
		$object_ds = (string) $objectasset->attributes()->object_ds;
		$object_id = (string) $objectasset->attributes()->object_id;
		if ($object_ds == 'entry') {
			wp_update_post(array('ID' => $mappings['assets'][$asset_id]['id'], 'post_parent' => $mappings['posts'][$object_id]));
		}
	}

	function _import_mt_comment($path, $comment, &$mappings) {

		$id = (string) $comment->attributes()->id;
		$author = (string) $comment->attributes()->author;
		$created_on = date('Y-m-d H:i:s', strtotime($comment->attributes()->created_on));
		$entry_id = (string) $comment->attributes()->entry_id;
		$email = (string) $comment->attributes()->email;
		$url = (string) $comment->attributes()->url;
		$ip = (string) $comment->attributes()->ip;
		$junk_status = (string) $comment->attributes()->junk_status; // 1 = OK
		$last_moved_on = date('Y-m-d H:i:s', strtotime($comment->attributes()->last_moved_on));
		$visible = (string) $comment->attributes()->visible;
		$text = (string) $comment->text;

		if ($junk_status == 1) {
			$comment = array();
			$comment['comment_author'] = $author;
			$comment['comment_author_email'] = $email;
			$comment['comment_date'] = $created_on;
			$comment['comment_status'] = 'open';
			$comment['comment_author_IP'] = $ip;
			$comment['comment_author_url'] = $url;
			$comment['comment_content'] = $text;

			$comment = add_magic_quotes($comment);
			if (!comment_exists($comment['comment_author'], $comment['comment_date'])) {
				$comment['comment_post_ID'] = $mappings['posts'][$entry_id];
				$comment = wp_filter_comment($comment);
				$comment = apply_filters('mtbi_pre_insert_comment', $comment);
				
				wp_insert_comment($comment);
			}
		}

	}

	function _import_mt_trackback($path, $trackback, &$mappings) {

		$id = (string) $trackback->attributes()->id;
		$created_by = (string) $trackback->attributes()->created_by;
		$created_on = date('Y-m-d H:i:s', strtotime($trackback->attributes()->created_on));
		$entry_id = (string) $trackback->attributes()->entry_id;
		$title = (string) $trackback->attributes()->email;
		$url = (string) $trackback->attributes()->url;
		$modified_on = date('Y-m-d H:i:s', strtotime($trackback->attributes()->modified_on));
		$is_disabled = (string) $trackback->attributes()->is_disabled;
		$text = (string) $trackback->description;

		$ping = array();
		$ping['comment_type'] = 'trackback';
		$ping['comment_author'] = $mappings['users'][$created_by];
		$ping['comment_date'] = $created_on;
		$ping['ping_status'] = 'open';
		$ping['comment_author_url'] = $url;
		$ping['comment_content'] = "<strong>{$ping['title']}</strong>\n\n{$ping['comment_content']}";

		$ping = add_magic_quotes($ping);
		if (!comment_exists($ping['comment_author'], $ping['comment_date'])) {
			$ping['comment_post_ID'] = $mappings['posts'][$entry_id];
			$ping = wp_filter_comment($ping);
			wp_insert_comment($ping);
		}

	}

    function _download_broken_url($url) {		
		$filename = 'i-' . md5($url) . '-' . urldecode(basename($url));
		if (!is_file($this->_uploadurl . $filename)) {
			$this->debug_msg('Downloading broken resource: '.$url);
			$content = @file_get_contents($url);
			if ($content !== FALSE) {
				file_put_contents($this->_path . '/' . $filename, $content);
				return $this->_uploadurl . $filename;
			}
		} else {
			return $this->_uploadurl . $filename;
		}
		return null;
	}

    function _replace_mt_image($image) {
		$siteurl_length = strlen($this->_siteurl);
		if (substr($image[1], 0, $siteurl_length) == $this->_siteurl) {
			$filename = urldecode(basename($image[1]));
			$originals = glob($this->_path . '/*-' . $filename);

			// try to get original width and define new sizing
			preg_match_all('/width="(\d+)" height="(\d+)"/is', $image[2], $matches);
			$sizing = '';
			if (sizeof($matches[1]) > 1) {
				$width = $matches[1][0];
				$height = $matches[1][1];
				$sizing = ' width="' . $width . '" height="' . $height . '"';
			}

			if (sizeof($originals) > 0) {
				$new = basename($originals[0]);
				if ($this->_autosizing) {
					list($width, $height, $type, $attr) = getimagesize($originals[0]);
					if ($width >= $this->_content_width_upscale) {
						$height = round($this->_content_width/($width/$height));
						$sizing = ' width="' . $this->_content_width . '" height="' . $height . '"';
					}
				}
				$replace = $this->_uploadurl . $new;
				return '<img src="' . $replace. '" alt="' . $new . '"' . $sizing . ' />';
			} else {
				$url = $this->_download_broken_url($image[1]);
				if ($url === null) {
					$this->debug_msg('BROKEN:' . $image[1]);
                    $this->_broken[] = array('type' => 'image', 'url' => $image[1]);
				} else {
					return '<img src="' . $url. '" alt="' . basename($url) . '"' . $sizing . ' />';
				}
			}
		}
		return $image[0];
	}

	/**
	 * Replaces URLs in Links with the new one. If the link points to an HTML popup with an
	 * image (guessed), it will be removed. If the link points to image of the current domain,
	 * but it doesn't exist, the script tries to download the image and returns the correct
	 * link.
	 *
	 * @param $link Link URL
	 * @return string corrected link markup
	 */
	function _replace_mt_link($link) {
		// Only care about /uploads files and /assets
		// Other linked content should just be other posts. 
		// Files not in assets_c or upload should have been defined in XML doc
		if (strpos($link[1], '/assets_c/') !== false || strpos($link[1], '/upload/') !== false) {
			$siteurl_length = strlen($this->_siteurl);
			if (substr($link[1], 0, $siteurl_length) == $this->_siteurl) {
				$filename = urldecode(basename($link[1]));
				$originals = glob($this->_path . '/*-' . $filename);
				if (sizeof($originals) > 0) {
					$new = basename($originals[0]);
					$replace = $this->_uploadurl . $new;
					if (strpos($link[1], 'assets_c') > 0 && substr($new,-5) == '.html') {
						return $link[2];
					} else {
						return '<a href="' . $replace . '">' . $link[2] . '</a>';
					}
				} else {
					$replace = str_replace($this->_siteurl, get_bloginfo('url') . '/', $link[1]);
					if (substr($replace, -5) == '.html' || strpos(basename($link[1]), '.') === false) {
						return '<a href="' . $replace . '">' . $link[2] . '</a>';
					}
				
					// Some blogs tend to do the above with .php, attempt to guess the URL .jpg
					// Some cleanup will/is done after import as a separate script
					if (strpos($link[1], '.php') !== false) {
						$jpg_url = str_replace('.php', '.jpg.', $link[1]);
						$headers = @get_headers($jpg_url);
						if ($headers && preg_match("|200|", $headers[0])) {
							$link[1] = $jpg_url;
						}
					}
					$url = $this->_download_broken_url($link[1]);
					if ($url === null) {
						$this->debug_msg('BROKEN: '.$link[1]);
	                    $this->_broken[] = array('type' => 'link', 'url' => $link[1]);
					} else {
						return '<a href="' . $url . '">' . $link[2] . '</a>';
					}
				}
			}
		}
		return $link[0];

	}

	function _save_post(&$post) {
		$post = get_object_vars($post);
		$post = add_magic_quotes($post);
		$post = (object) $post;

		$post = apply_filters('mtbi_pre_insert_post', $post);
		if ( $post_id = post_exists($post->post_title, '', $post->post_date) ) {
			$this->debug_msg('Post \''.stripslashes($post->post_title).'\' already exists.');
		} else {
			$this->debug_msg('Importing post \''.stripslashes($post->post_title).'\'');
			// Pass by reference, assignement for sanity
			$post_id = wp_insert_post($post);
			if (is_wp_error($post_id)) {
				$this->debug_msg('ERROR: '.$post_id->get_error_message());
				return $post_id;
			}
		}
		return $post_id;
	}
	
	function _get_blog_list() {
		global $wpdb;
		$blogs = $wpdb->get_results($wpdb->prepare("
			SELECT blog_id, domain, path 
			FROM $wpdb->blogs 
			WHERE 
				public = '1' AND 
				archived = '0' AND 
				mature = '0' AND 
				spam = '0' AND 
				deleted = '0' 
			ORDER BY registered DESC"
			), ARRAY_A
		);
	}
	
	function _replace_content_asset($new_url, $old_url) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare("
				UPDATE $wpdb->posts
				SET `post_content` = REPLACE(`post_content`, %s, %s)",
				$old_url,
				$new_url
			)
		);
	}
	
	/**
	 * Removes invalid XML characters for simpleXML Parsing
	 * 
	 * @author http://stackoverflow.com/users/394435/jhong
	 * @see http://stackoverflow.com/a/3466049 
	 */
	protected static function _strip_invalid_xml($value) {
		$ret = '';
		$current;
		if (empty($value)) {
    		return $ret;
 		}

		$length = strlen($value);
		for ($i=0; $i < $length; $i++) {
			$current = ord($value{$i});
			if (($current == 0x9) ||
				($current == 0xA) ||
				($current == 0xD) ||
				(($current >= 0x20) && ($current <= 0xD7FF)) ||
				(($current >= 0xE000) && ($current <= 0xFFFD)) ||
				(($current >= 0x10000) && ($current <= 0x10FFFF))) {
					$ret .= chr($current);
			}
			else {
				$ret .= ' ';
			}
		}
		return $ret;
	}
	
	/**
	 * Fix incorrect html entities if a bad data set is received
	 *
	 * @param string $content 
	 * @return string Content
	 */
	protected static function _fix_incorrect_html_entities($content) {
		// fixes anthing like &#176C which may be ok in browsers but rejected by XML parsers
		// needs to be &#176;C
		if (preg_match_all('/(&#[0-9]+)(.)/', $content, $matches)) {
			if (is_array($matches[1]) && is_array($matches[2])) {
				foreach ($matches[1] as $key => $value) {
					if ($matches[2][$key] != ';') {
						$content = str_replace($value.$matches[2][$key], $value.';'.$matches[2][$key], $content);
					}
				}
			}
		}
		
		// XML Specific
		if (preg_match_all('/(&#x22B8)(.)/', $content, $matches)) {
			if (is_array($matches[1]) && is_array($matches[2])) {
				foreach ($matches[1] as $key => $value) {
					if ($matches[2][$key] != ';') {
						$content = str_replace($value.$matches[2][$key], $value.';'.$matches[2][$key], $content);
					}
				}
			}
		}
		
		return $content;
	}
}

$importer = new MT_Backup_Importer_CLI;

// Required Args
$importer->set_required_arg( 'blog', 'Blog ID of the blog you like to import to' );
$importer->set_required_arg( 'backup', 'Backup to import from' );
$importer->set_required_arg( 'import_dir', 'Import directory' );
//MT_Backup_Importer_CLI will handle the argument validation (file exists check)

// Setup validation
$importer->set_argument_validation( '#^blog$#', 'cli_init_blog', 'blog invalid' );

// Runs validation, dispatcher
$importer->init();

?>
