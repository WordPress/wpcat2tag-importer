<?php
/*
Plugin Name: Categories to Tags Converter Importer
Plugin URI: https://wordpress.org/extend/plugins/wpcat2tag-importer/
Description: Convert existing categories to tags or tags to categories, selectively.
Author: wordpressdotorg
Author URI: https://wordpress.org/
Version: 0.6.3
License: GPL version 2 or later - https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

/* == Todo ==
 * - ensure following expected behaviour in all cases... what is expected behaviour? remove+delete the old cat/tag/format?
 * - cache cleaning (think wp_delete_term does most, if not all)
 * - more UI cleanup (indent for child cats, what should convert to selectors look like?, ...)
 * - re-introduce select all option (old button was ugly)
 * - somehow use list tables? for: probably looks better, against: poss. bulky code
 */

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * Categories to Tags Converter Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class WP_Categories_to_Tags extends WP_Importer {
	var $all_categories = array();
	var $all_tags = array();
	var $hybrids_ids = array();

	function header( $current_tab ) {
		if ( ! current_user_can('manage_categories') )
			wp_die( __( 'Cheatin&#8217; uh?', 'wpcat2tag-importer' ) );

		$tabs = array(
			'cats' => array( 'label' => __( 'Categories', 'wpcat2tag-importer' ), 'url' => admin_url( 'admin.php?import=wpcat2tag&tab=cats' ) ),
			'tags' => array( 'label' => __( 'Tags', 'wpcat2tag-importer' ), 'url' => admin_url( 'admin.php?import=wpcat2tag&tab=tags' ) )
		);

		if ( function_exists( 'set_post_format' ) )
			$tabs['formats'] = array( 'label' => __( 'Formats', 'wpcat2tag-importer' ), 'url' => admin_url( 'admin.php?import=wpcat2tag&tab=formats' ) ); ?>

		<div class="wrap">
		<?php
			if ( version_compare( get_bloginfo( 'version' ), '3.8.0', '<' ) ) {
				screen_icon();
			}
		?>
		<h2><?php _e( 'Categories, Tags and Formats Converter', 'wpcat2tag-importer' ); ?></h2>
		<h3 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $tab => $info ) :
			$class = ($tab == $current_tab) ? ' nav-tab-active' : ''; ?>
			<a href="<?php echo $info['url']; ?>" class="nav-tab<?php echo $class; ?>"><?php echo esc_html( $info['label'] ); ?></a>
		<?php endforeach; ?>
		</h3>
<?php
	}

	function footer() {
		echo '</div>';
	}

	function populate_cats() {
		$categories = get_categories( array('get' => 'all') );
		foreach ( $categories as $category ) {
			$this->all_categories[] = $category;
			if ( term_exists( $category->slug, 'post_tag' ) )
				$this->hybrids_ids[] = $category->term_id;
		}
	}

	function populate_tags() {
		$tags = get_terms( array('post_tag'), array('get' => 'all') );
		foreach ( $tags as $tag ) {
			$this->all_tags[] = $tag;
			if ( term_exists( $tag->slug, 'category' ) )
				$this->hybrids_ids[] = $tag->term_id;
		}
	}

	function categories_tab() {
		if ( isset($_POST['cats_to_convert']) ) {
			$this->convert_categories();
			return;
		}

		$this->populate_cats();
		$cat_num = count( $this->all_categories );

		if ( $cat_num > 0 ) {
			echo '<div class="narrow">';
			echo '<p>' . __('Hey there. Here you can selectively convert existing categories to tags. To get started, check the categories you wish to be converted, then click the Convert button.', 'wpcat2tag-importer') . '</p>';
			echo '<p>' . __('Keep in mind that if you convert a category with child categories, the children become top-level orphans.', 'wpcat2tag-importer') . '</p>';
			echo '</div>';
			$this->categories_form();
		} else {
			echo '<p>'.__('You have no categories to convert!', 'wpcat2tag-importer').'</p>';
		}
	}

	function categories_form() {
		$hier = _get_term_hierarchy( 'category' );
		echo '<form name="catlist" id="catlist" action="admin.php?import=wpcat2tag&amp;tab=cats" method="post">';
		wp_nonce_field( 'import-cat2tag' );
		echo '<ul>';

		foreach ( $this->all_categories as $category ) {
			$category = sanitize_term( $category, 'category', 'display' );

			if ( (int) $category->parent == 0 ) {
				echo '<li><label><input type="checkbox" name="cats_to_convert[]" value="' . intval($category->term_id) . '" /> ' . esc_html($category->name) . " ({$category->count})</label>";

				if ( in_array( intval($category->term_id),  $this->hybrids_ids ) )
					echo ' <a href="#note"> * </a>';

				if ( isset($hier[$category->term_id]) )
					$this->_category_children($category, $hier);

				echo '</li>';
			}
		}

		echo '</ul>';

		if ( ! empty($this->hybrids_ids) )
			echo '<p><a name="note"></a>' . __('* This category is also a tag. Converting it will add that tag to all posts that are currently in the category.', 'wpcat2tag-importer') . '</p>';

		if ( current_theme_supports( 'post-formats' ) ) :
			$post_formats = get_theme_support( 'post-formats' );
			if ( is_array( $post_formats[0] ) ) : ?>
<p><?php _e( 'Convert categories to:', 'wpcat2tag-importer' ); ?><br />
<label><input type="radio" name="convert_to" value="tags" checked="checked" /> <?php _e( 'Tags', 'wpcat2tag-importer' ); ?></label><br />
<label><input type="radio" name="convert_to" value="format" /> <?php _e( 'Post Format', 'wpcat2tag-importer' ); ?></label>
	<select name="post_format">
	<?php foreach ( $post_formats[0] as $format ) : ?>
		<option value="<?php echo esc_attr( $format ); ?>"><?php echo esc_html( get_post_format_string( $format ) ); ?></option>
	<?php endforeach; ?>
	</select>
</p>
<?php endif; endif; ?>

		<p class="submit"><input type="submit" name="submit" class="button" value="<?php esc_attr_e( 'Convert Categories', 'wpcat2tag-importer' ); ?>" /></p>
		</form><?php
	}

	function tags_tab() {
		if ( isset($_POST['tags_to_convert']) ) {
			$this->convert_tags();
			return;
		}

		$this->populate_tags();
		$tags_num = count( $this->all_tags );

		if ( $tags_num > 0 ) {
			echo '<div class="narrow">';
			echo '<p>' . __('Here you can selectively convert existing tags to categories. To get started, check the tags you wish to be converted, then click the Convert button.', 'wpcat2tag-importer') . '</p>';
			echo '<p>' . __('The newly created categories will still be associated with the same posts.', 'wpcat2tag-importer') . '</p>';
			echo '</div>';
			$this->tags_form();
		} else {
			echo '<p>'.__('You have no tags to convert!', 'wpcat2tag-importer').'</p>';
		}
	}

	function tags_form() { ?>
<form name="taglist" id="taglist" action="admin.php?import=wpcat2tag&amp;tab=tags" method="post">
<?php wp_nonce_field( 'import-cat2tag' ); ?>
<ul>

<?php	foreach ( $this->all_tags as $tag ) { ?>
	<li><label><input type="checkbox" name="tags_to_convert[]" value="<?php echo intval($tag->term_id); ?>" /> <?php echo esc_html($tag->name) . ' (' . $tag->count . ')'; ?></label><?php if ( in_array( intval($tag->term_id),  $this->hybrids_ids ) ) echo ' <a href="#note"> * </a>'; ?></li>
<?php	} ?>
</ul>

<?php	if ( ! empty($this->hybrids_ids) )
			echo '<p><a name="note"></a>' . __('* This tag is also a category. When converted, all posts associated with the tag will also be in the category.', 'wpcat2tag-importer') . '</p>'; ?>

<?php if ( current_theme_supports( 'post-formats' ) ) :
	$post_formats = get_theme_support( 'post-formats' );
	if ( is_array( $post_formats[0] ) ) : ?>
<p><?php _e( 'Convert tags to:', 'wpcat2tag-importer' ); ?><br />
<label><input type="radio" name="convert_to" value="cats" checked="checked" /> <?php _e( 'Categories', 'wpcat2tag-importer' ); ?></label><br />
<label><input type="radio" name="convert_to" value="format" /> <?php _e( 'Post Format', 'wpcat2tag-importer' ); ?></label>
	<select name="post_format">
	<?php foreach ( $post_formats[0] as $format ) : ?>
		<option value="<?php echo esc_attr( $format ); ?>"><?php echo esc_html( get_post_format_string( $format ) ); ?></option>
	<?php endforeach; ?>
	</select>
</p>
<?php endif; endif; ?>

<p class="submit"><input type="submit" name="submit" class="button" value="<?php esc_attr_e( 'Convert Tags', 'wpcat2tag-importer' ); ?>" /></p>
</form>

<?php }

	function formats_tab() {
		if ( isset($_POST['post_formats']) ) {
			$this->convert_formats();
			return;
		}

		$formats = get_terms( array('post_format'), array('get'=>'all') );
		$format_count = count( $formats );

		if ( $format_count > 0 ) { ?>
			<form action="admin.php?import=wpcat2tag&amp;tab=formats" method="post">
			<?php wp_nonce_field( 'import-cat2tag' ); ?>

			<ul>
			<?php foreach ( $formats as $format ) :
				$slug = substr( $format->slug, 12 ); ?>
				<li><label><input type="checkbox" name="post_formats[]" value="<?php echo intval($format->term_id); ?>" /> <?php echo esc_html( get_post_format_string($slug) ) . " ({$format->count})"; ?></label></li>
			<?php endforeach; ?>
			</ul>

			<p><?php _e( 'Convert formats to:', 'wpcat2tag-importer' ); ?><br />
			<label><input type="radio" name="convert_to" value="cat" checked="checked" /> <?php _e( 'Category', 'wpcat2tag-importer' ); ?></label><br />
			<label><input type="radio" name="convert_to" value="tag" /> <?php _e( 'Tag', 'wpcat2tag-importer' ); ?></label></p>
			<input type="text" name="convert_to_slug" value="" />
			<p class="submit"><input type="submit" name="submit" class="button" value="<?php esc_attr_e( 'Convert Formats', 'wpcat2tag-importer' ); ?>" /></p>
			</form><?php
		} else {
			echo '<p>' . __( 'You have no posts set to a specific format.', 'wpcat2tag-importer' ) . '</p>';
		}
	}

	function convert_formats() {
		check_admin_referer( 'import-cat2tag' );

		if ( ! is_array($_POST['post_formats']) || empty($_POST['convert_to_slug']) || ('cat' != $_POST['convert_to'] && 'tag' != $_POST['convert_to']) ) {
			echo '<div class="narrow">';
			echo '<p>' . sprintf( __('Uh, oh. Something didn&#8217;t work. Please <a href="%s">try again</a>.', 'wpcat2tag-importer'), admin_url('admin.php?import=wpcat2tag&amp;tab=formats') ) . '</p>';
			echo '</div>';
			return;
		}

		$convert_to = 'tag' == $_POST['convert_to'] ? 'post_tag' : 'category';
		if ( ! $term_info = term_exists( $_POST['convert_to_slug'], $convert_to ) )
			$term_info = wp_insert_term( $_POST['convert_to_slug'], $convert_to );

		if ( is_wp_error($term_info) ) {
			echo '<p>' . $term_info->get_error_message() . ' ';
			printf( __( 'Please <a href="%s">try again</a>.', 'wpcat2tag-importer' ), 'admin.php?import=wpcat2tag&amp;tab=cats' ) . "</p>\n";
			return;
		}

		echo '<ul>';

		foreach ( $_POST['post_formats'] as $format_id ) {
			$format_id = (int) $format_id;
			$format = get_term( $format_id, 'post_format' );
			if ( ! $format ) {
				echo '<li>' . sprintf( __( 'Post format #%d doesn&#8217;t exist!', 'wpcat2tag-importer' ), $format_id ) . "</li>\n";
			} else {
				$slug = substr( $format->slug, 12 );
				echo '<li>' . sprintf( __( 'Converting format <strong>%s</strong> ... ', 'wpcat2tag-importer' ), get_post_format_string( $slug ) );

				$this->_convert_term( array( 'term_id' => $format->term_id, 'taxonomy' => 'post_format', 'term_taxonomy_id' => $format->term_taxonomy_id ), $term_info['term_taxonomy_id'] );

				echo __( 'Converted successfully.', 'wpcat2tag-importer' ) . "</li>\n";
			}
		}

		echo '</ul>';
		echo '<p>' . sprintf( __( 'We&#8217;re all done here, but you can always <a href="%s">convert more</a>.', 'wpcat2tag-importer' ), admin_url( 'admin.php?import=wpcat2tag&amp;tab=formats' ) ) . '</p>';
	}

	function _category_children( $parent, $hier ) {
		echo '<ul>';

		foreach ( $hier[$parent->term_id] as $child_id ) {
			$child = get_category($child_id);

			echo '<li><label><input type="checkbox" name="cats_to_convert[]" value="'. intval($child->term_id) .'" /> ' . esc_html($child->name) . " ({$child->count})</label>";

			if ( in_array( intval($child->term_id), $this->hybrids_ids ) )
				echo ' <a href="#note"> * </a>';

			if ( isset($hier[$child->term_id]) )
				$this->_category_children($child, $hier);

			echo '</li>';
		}

		echo '</ul>';
	}

	function convert_categories() {
		global $wpdb;

		check_admin_referer( 'import-cat2tag' );

		if ( ! is_array($_POST['cats_to_convert']) ) {
			echo '<div class="narrow">';
			echo '<p>' . sprintf(__('Uh, oh. Something didn&#8217;t work. Please <a href="%s">try again</a>.', 'wpcat2tag-importer'), 'admin.php?import=wpcat2tag&amp;tab=cats') . '</p>';
			echo '</div>';
			return;
		}

		$default = get_option( 'default_category' );

		if ( ! isset($_POST['convert_to']) || 'format' != $_POST['convert_to'] ) {
			$convert_to = 'post_tag';
		} else {
			$convert_to = 'post_format';
			$term_info = $this->_get_format_info( sanitize_key($_POST['post_format']) );
			if ( is_wp_error($term_info) ) {
				echo '<div class="narrow"><p>';
				echo $term_info->get_error_message() . ' ';
				printf( __( 'Please <a href="%s">try again</a>.', 'wpcat2tag-importer' ), 'admin.php?import=wpcat2tag&amp;tab=cats' );
				echo '</p></div>';
				return;
			}
		}

		echo '<ul>';

		foreach ( $_POST['cats_to_convert'] as $cat_id ) {
			$cat_id = (int) $cat_id;
			$category = get_term( $cat_id, 'category' );
			if ( ! $category ) {
				echo '<li>' . sprintf( __( 'Category #%d doesn&#8217;t exist!', 'wpcat2tag-importer' ), $cat_id ) . "</li>\n";
			} else {
				echo '<li>' . sprintf( __( 'Converting category <strong>%s</strong> ... ', 'wpcat2tag-importer' ), esc_html($category->name) );

				if ( 'post_tag' == $convert_to ) {
					if ( ! $term_info = term_exists( $category->slug, 'post_tag' ) )
						$term_info = wp_insert_term( $category->name, 'post_tag', array( 'description' => $category->description ) );

					if ( is_wp_error($term_info) ) {
						echo $term_info->get_error_message() . "</li>\n";
						continue;
					}
				}

				// if this is the default category then leave it in place and just add the new tag/format
				if ( $default == $category->term_id ) {
					$posts = get_objects_in_term( $category->term_id, 'category' );
					foreach ( $posts as $post ) {
						$values[] = $wpdb->prepare( "(%d, %d, 0)", $post, $term_info['term_taxonomy_id'] );
						clean_post_cache( $post );
					}

					$wpdb->query( "INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES " . join(',', $values) );
					$wpdb->update( $wpdb->term_taxonomy, array( 'count' => $category->count ), array( 'term_id' => $term_info['term_id'], 'taxonomy' => $convert_to ) );
				// otherwise just convert it
				} else {
					$this->_convert_term( array( 'term_id' => $category->term_id, 'taxonomy' => 'category', 'term_taxonomy_id' => $category->term_taxonomy_id ), $term_info['term_taxonomy_id'] );
				}

				echo __( 'Converted successfully.', 'wpcat2tag-importer' ) . "</li>\n";
			}
		}

		echo '</ul>';
		echo '<p>' . sprintf( __( 'We&#8217;re all done here, but you can always <a href="%s">convert more</a>.', 'wpcat2tag-importer' ), admin_url( 'admin.php?import=wpcat2tag&amp;tab=cats' ) ) . '</p>';
	}

	function convert_tags() {
		check_admin_referer( 'import-cat2tag' );

		if ( ! is_array($_POST['tags_to_convert']) ) {
			echo '<div class="narrow">';
			echo '<p>' . sprintf(__('Uh, oh. Something didn&#8217;t work. Please <a href="%s">try again</a>.', 'wpcat2tag-importer'), 'admin.php?import=wpcat2tag&amp;tab=tags') . '</p>';
			echo '</div>';
			return;
		}

		if ( ! isset($_POST['convert_to']) || 'format' != $_POST['convert_to'] ) {
			$convert_to = 'category';
		} else {
			$convert_to = 'post_format';
			$term_info = $this->_get_format_info( sanitize_key($_POST['post_format']) );
			if ( is_wp_error($term_info) ) {
				echo '<div class="narrow"><p>';
				echo $term_info->get_error_message() . ' ';
				printf( __( 'Please <a href="%s">try again</a>.', 'wpcat2tag-importer' ), 'admin.php?import=wpcat2tag&amp;tab=tags' );
				echo '</p></div>';
				return;
			}
		}

		echo '<ul>';

		foreach ( $_POST['tags_to_convert'] as $tag_id ) {
			$tag_id = (int) $tag_id;
			$tag = get_term( $tag_id, 'post_tag' );
			if ( ! $tag ) {
				echo '<li>' . sprintf( __( 'Tag #%d doesn&#8217;t exist!', 'wpcat2tag-importer' ), $tag_id ) . "</li>\n";
			} else {
				echo '<li>' . sprintf( __( 'Converting tag <strong>%s</strong> ... ', 'wpcat2tag-importer' ), esc_html($tag->name) );

				if ( 'category' == $convert_to ) {
					if ( ! $term_info = term_exists( $tag->slug, 'category' ) )
						$term_info = wp_insert_term( $tag->name, 'category', array( 'description' => $tag->description ) );

					if ( is_wp_error($term_info) ) {
						echo $term_info->get_error_message() . "</li>\n";
						continue;
					}
				}

				$this->_convert_term( array( 'term_id' => $tag->term_id, 'taxonomy' => 'post_tag', 'term_taxonomy_id' => $tag->term_taxonomy_id ), $term_info['term_taxonomy_id'] );

				echo __( 'Converted successfully.', 'wpcat2tag-importer' ) . "</li>\n";
			}
		}

		echo '</ul>';
		echo '<p>' . sprintf( __( 'We&#8217;re all done here, but you can always <a href="%s">convert more</a>.', 'wpcat2tag-importer' ), admin_url( 'admin.php?import=wpcat2tag&amp;tab=tags' ) ) . '</p>';
	}

	/**
	 * Convert all term relationships to a new term, delete the old term if possible.
	 *
	 * The old term will not be deleted if it's the default category or if it's a part
	 * of any other taxonomies.
	 *
	 * @param array $from term_id, taxonomy and term_taxonomy_id of the term+taxonomy pair converting from
	 * @param int $to_ttid The term_taxonomy_id of the term+taxonomy pair we are converting to
	 */
	function _convert_term( $from, $to_ttid ) {
		global $wpdb;

		// transfer all the term relationships
		$wpdb->update( $wpdb->term_relationships, array( 'term_taxonomy_id' => $to_ttid ), array( 'term_taxonomy_id' => $from['term_taxonomy_id'] ) );

		// remove the old term
		wp_delete_term( $from['term_id'], $from['taxonomy'] );
	}

	function _get_format_info( $format ) {
		if ( current_theme_supports( 'post-formats' ) && ! empty( $format ) ) {
			$post_formats = get_theme_support( 'post-formats' );
			if ( is_array( $post_formats ) ) {
				$post_formats = $post_formats[0];
				if ( ! in_array( $format, $post_formats ) )
					return new WP_Error( 'invalid_format', sprintf( __( 'Bad post format %s.', 'wpcat2tag-importer' ), esc_html($format) ) );
			}
		} else {
			return new WP_Error( 'invalid_format', __( 'Either your theme does not support post formats or you supplied an invalid format.', 'wpcat2tag-importer' ) );
		}

		$format = 'post-format-' . $format;
		if ( ! $term_info = term_exists( $format, 'post_format' ) )
			$term_info = wp_insert_term( $format, 'post_format' );

		return $term_info;
	}

	function init() {
		if ( ! current_user_can( 'manage_categories' ) )
			wp_die( __( 'Cheatin&#8217; uh?', 'wpcat2tag-importer' ) );

		$tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'cats';
		$this->header( $tab );

		if ( 'cats' == $tab )
			$this->categories_tab();
		else if ( 'tags' == $tab )
			$this->tags_tab();
		else if ( 'formats' == $tab && function_exists( 'set_post_format' ) )
			$this->formats_tab();

		$this->footer();
	}
}

$wp_cat2tag_importer = new WP_Categories_to_Tags();
register_importer('wpcat2tag', __('Categories and Tags Converter', 'wpcat2tag-importer'), __('Convert existing categories to tags or tags to categories, selectively.', 'wpcat2tag-importer'), array(&$wp_cat2tag_importer, 'init'));

} // class_exists( 'WP_Importer' )

function wpcat2tag_importer_init() {
    load_plugin_textdomain( 'wpcat2tag-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'wpcat2tag_importer_init' );
