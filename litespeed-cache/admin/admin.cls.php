<?php
/**
 * The admin-panel specific functionality of the plugin.
 *
 *
 * @since      1.0.0
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/admin
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */

if ( ! defined( 'WPINC' ) ) {
	die ;
}

class LiteSpeed_Cache_Admin
{
	private static $_instance ;
	private $__cfg ;// cfg instance
	private $display ;

	/**
	 * Initialize the class and set its properties.
	 * Run in hook `after_setup_theme` when is_admin()
	 *
	 * @since    1.0.0
	 */
	private function __construct()
	{
		// Define LSCWP_MU_PLUGIN if is mu-plugins
		if ( defined( 'WPMU_PLUGIN_DIR' ) && dirname( LSCWP_DIR ) == WPMU_PLUGIN_DIR ) {
			define( 'LSCWP_MU_PLUGIN', true ) ;
		}

		// Additional litespeed assets on admin display
		// Also register menu
		$this->display = LiteSpeed_Cache_Admin_Display::get_instance() ;

		$this->__cfg = LiteSpeed_Cache_Config::get_instance() ;

		// initialize admin actions
		add_action( 'admin_init', array( $this, 'admin_init' ) ) ;
		// add link to plugin list page
		add_filter( 'plugin_action_links_' . LSCWP_BASENAME, array( $this->display, 'add_plugin_links' ) ) ;

		if ( defined( 'LITESPEED_ON' ) ) {
			// register purge_all actions
			$purge_all_events = LiteSpeed_Cache::config( LiteSpeed_Cache_Config::O_PURGE_HOOK_ALL ) ;

			// purge all on upgrade
			if ( LiteSpeed_Cache::config( LiteSpeed_Cache_Config::O_PURGE_ON_UPGRADE ) ) {
				$purge_all_events[] = 'upgrader_process_complete' ;
				$purge_all_events[] = 'admin_action_do-plugin-upgrade' ;
			}
			foreach ( $purge_all_events as $event ) {
				// Don't allow hook to update_option bcos purge_all will cause infinite loop of update_option
				if ( in_array( $event, array( 'update_option' ) ) ) {
					continue ;
				}
				add_action( $event, 'LiteSpeed_Cache_Purge::purge_all' ) ;
			}
			// add_filter( 'upgrader_pre_download', 'LiteSpeed_Cache_Purge::filter_with_purge_all' ) ;
		}
	}

	/**
	 * Callback that initializes the admin options for LiteSpeed Cache.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function admin_init()
	{
		load_plugin_textdomain(LiteSpeed_Cache::PLUGIN_NAME, false, 'litespeed-cache/lang/') ;

		$this->_proceed_admin_action() ;

		// Terminate if user doesn't have the access to settings
		if( is_network_admin() ) {
			$capability = 'manage_network_options' ;
		}
		else {
			$capability = 'manage_options' ;
		}
		if ( ! current_user_can($capability) ) {
			return ;
		}

		// Save setting from admin settings page
		// NOTE: cli will call `validate_plugin_settings` manually. Cron activation doesn't need to validate

		// Add privacy policy
		// @since 2.2.6
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( LiteSpeed_Cache::PLUGIN_NAME, LiteSpeed_Cache_Doc::privacy_policy() ) ;
		}

		do_action( 'litspeed_after_admin_init' ) ;

		// If setting is set to on, try to activate cache func
		if ( defined( 'LITESPEED_ON_IN_SETTING' ) ) {
			// check if WP_CACHE is defined and true in the wp-config.php file.
			// This is not required by our cache func, so leave it to trigger by admin
			if ( ! defined('WP_CACHE') || ! WP_CACHE ) {
				$add_var = LiteSpeed_Cache_Config::wp_cache_var_setter(true) ;
				if ( $add_var !== true ) {
					LiteSpeed_Cache_Admin_Display::add_error($add_var) ;
				}
			}

			// check management action
			if ( defined('WP_CACHE') && WP_CACHE ) {
				$this->check_advanced_cache() ;
			}

			// step out if adv_cache can't write
			if ( ! defined( 'LITESPEED_ON' ) ) {
				return ;
			}

		}

		LiteSpeed_Cache_Control::set_nocache( 'Admin page' ) ;

		if ( LiteSpeed_Cache_Router::esi_enabled() ) {
			add_action( 'in_widget_form', array( $this->display, 'show_widget_edit' ), 100, 3 ) ;
			add_filter( 'widget_update_callback', 'LiteSpeed_Cache_Admin_Settings::validate_widget_save', 10, 4 ) ;
		}
	}

	/**
	 * Run litespeed admin actions
	 *
	 * @since 1.1.0
	 */
	private function _proceed_admin_action()
	{
		// handle actions
		switch (LiteSpeed_Cache_Router::get_action()) {

			// Save htaccess
			case LiteSpeed_Cache::ACTION_SAVE_HTACCESS:
				LiteSpeed_Cache_Admin_Rules::get_instance()->htaccess_editor_save() ;
				break ;

			case LiteSpeed_Cache_Router::ACTION_SAVE_SETTINGS :
				LiteSpeed_Cache_Admin_Settings::get_instance()->save( $_POST ) ;
				break ;


			// Save network settings
			case LiteSpeed_Cache::ACTION_SAVE_SETTINGS_NETWORK:
				LiteSpeed_Cache_Admin_Settings::get_instance()->network_save( $_POST ) ;
				break ;

			default:
				break ;
		}

	}

	/**
	 * Check to make sure that the advanced-cache.php file is ours.
	 * If it doesn't exist, try to make it ours.
	 *
	 * If it is not ours and the config is set to check, output an error.
	 *
	 * @since 1.0.11
	 * @access private
	 */
	private function check_advanced_cache()
	{
		$capability = is_network_admin() ? 'manage_network_options' : 'manage_options' ;
		if ( defined( 'LSCACHE_ADV_CACHE' ) || ! current_user_can( $capability ) ) {
			if ( ! LiteSpeed_Cache::config( LiteSpeed_Cache_Config::O_UTIL_CHECK_ADVCACHE ) ) {
				// If it exists because I added it at runtime, try to create the file anyway.
				// Result does not matter.
				LiteSpeed_Cache_Activation::try_copy_advanced_cache() ;// not sure why do this but doesn't matter
			}
			return ;
		}

		if ( LiteSpeed_Cache_Activation::try_copy_advanced_cache() ) {
			return ;
		}

		if ( is_multisite() && ( ! is_network_admin() || ! current_user_can('manage_network_options')) ) {
			$third = __('If this is the case, the network admin may uncheck "Check Advanced Cache" in LiteSpeed Cache Advanced settings.', 'litespeed-cache') ;
		}else {
			$third = __('If this is the case, please uncheck "Check Advanced Cache" in LiteSpeed Cache Advanced settings.', 'litespeed-cache') ;
		}
		$msg = __('LiteSpeed has detected another plugin using the "Advanced Cache" file.', 'litespeed-cache') . ' '
			. __('LiteSpeed Cache does work with other optimization plugins, but only if functionality is not duplicated. Only one full-page cache may be activated.', 'litespeed-cache') . ' '
			. $third
			. ' <a href="https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:customizations:multi-cache-plugins" target="_blank">'
				. __( 'Learn More', 'litespeed-cache' )
			. '</a>' ;

		$this->display->add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_YELLOW, $msg) ;
	}

	/**
	 * Clean up the input string of any extra slashes/spaces.
	 *
	 * @since 1.0.4
	 * @access public
	 * @param string $input The input string to clean.
	 * @return string The cleaned up input.
	 */
	public static function cleanup_text( $input )
	{
		if ( is_array( $input ) ) {
			return array_map( 'LiteSpeed_Cache_Admin::cleanup_text', $input ) ;
		}

		return stripslashes( trim( $input ) ) ;
	}

	/**
	 * After a LSCWP_CTRL action, need to redirect back to the same page
	 * without the nonce and action in the query string.
	 *
	 * @since 1.0.12
	 * @access public
	 * @global string $pagenow
	 */
	public static function redirect( $url = false )
	{
		global $pagenow ;
		$qs = '' ;
		if ( ! $url ) {
			if ( ! empty( $_GET ) ) {
				if ( isset( $_GET[ LiteSpeed_Cache_Router::ACTION_KEY ] ) ) {
					unset( $_GET[ LiteSpeed_Cache_Router::ACTION_KEY ] ) ;
				}
				if ( isset( $_GET[ LiteSpeed_Cache_Router::NONCE_NAME ] ) ) {
					unset( $_GET[ LiteSpeed_Cache_Router::NONCE_NAME ] ) ;
				}
				if ( ! empty( $_GET ) ) {
					$qs = '?' . http_build_query( $_GET ) ;
				}
			}
			if ( is_network_admin() ) {
				$url = network_admin_url( $pagenow . $qs ) ;
			}
			else {
				$url = admin_url( $pagenow . $qs ) ;
			}
		}

		wp_redirect( $url ) ;
		exit() ;
	}

	/**
	 * Get the current instance object.
	 *
	 * @since 1.1.0
	 * @access public
	 * @return Current class instance.
	 */
	public static function get_instance()
	{
		if ( ! isset(self::$_instance) ) {
			self::$_instance = new self() ;
		}

		return self::$_instance ;
	}
}