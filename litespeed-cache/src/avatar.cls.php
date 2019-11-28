<?php
/**
 * The avatar cache class
 *
 * @since 		3.0
 * @package    	LiteSpeed
 * @subpackage 	LiteSpeed/inc
 * @author     	LiteSpeed Technologies <info@litespeedtech.com>
 */
namespace LiteSpeed ;

defined( 'WPINC' ) || exit ;

class Avatar extends Base
{
	protected static $_instance ;

	const TYPE_GENERATE = 'generate' ;

	private $_conf_cache_ttl ;
	private $_tb ;

	private $_avatar_realtime_gen_dict = array() ;
	protected $_summary;

	/**
	 * Init
	 *
	 * @since  1.4
	 * @access protected
	 */
	protected function __construct()
	{
		if ( ! Conf::val( Base::O_DISCUSS_AVATAR_CACHE ) ) {
			return ;
		}

		Log::debug2( '[Avatar] init' ) ;

		// Create table
		$this->_tb = Data::get_instance()->tb( 'avatar' ) ;

		$this->_conf_cache_ttl = Conf::val( Base::O_DISCUSS_AVATAR_CACHE_TTL ) ;

		add_filter( 'get_avatar_url', array( $this, 'crawl_avatar' ) ) ;

		$this->_summary = self::get_summary();
	}

	/**
	 * Check if need db table or not
	 *
	 * @since 3.0
	 * @access public
	 */
	public static function need_db()
	{
		if ( Conf::val( Base::O_DISCUSS_AVATAR_CACHE ) ) {
			return true ;
		}

		return false ;
	}
	/**
	 * Get gravatar URL from DB and regenarate
	 *
	 * @since  3.0
	 * @access public
	 */
	public function serve_satic( $md5 )
	{
		global $wpdb ;

		Log::debug( '[Avatar] is avatar request' ) ;

		if ( strlen( $md5 ) !== 32 ) {
			Log::debug( '[Avatar] wrong md5 ' . $md5 ) ;
			return ;
		}

		$q = "SELECT url FROM $this->_tb WHERE md5=%s" ;
		$url = $wpdb->get_var( $wpdb->prepare( $q, $md5 ) ) ;

		if ( ! $url ) {
			Log::debug( '[Avatar] no matched url for md5 ' . $md5 ) ;
			return ;
		}

		$url = $this->_generate( $url ) ;

		wp_redirect( $url ) ;
		exit ;
	}

	/**
	 * Localize gravatar
	 *
	 * @since  3.0
	 * @access public
	 */
	public function crawl_avatar( $url )
	{
		if ( ! $url ) {
			return $url ;
		}

		// Check if its already in dict or not
		if ( ! empty( $this->_avatar_realtime_gen_dict[ $url ] ) ) {
			Log::debug2( '[Avatar] already in dict [url] ' . $url ) ;

			return $this->_avatar_realtime_gen_dict[ $url ] ;
		}

		$realpath = $this->_realpath( $url ) ;
		if ( file_exists( $realpath ) && time() - filemtime( $realpath ) <= $this->_conf_cache_ttl ) {
			Log::debug2( '[Avatar] cache file exists [url] ' . $url ) ;
			return $this->_rewrite( $url ) ;
		}

		if ( ! strpos( $url, 'gravatar.com' ) ) {
			return $url ;
		}

		// Send request
		if ( ! empty( $this->_summary[ 'curr_request' ] ) && time() - $this->_summary[ 'curr_request' ] < 300 ) {
			Log::debug2( '[Avatar] Bypass generating due to interval limit [url] ' . $url ) ;
			return $url ;
		}

		// Generate immediately
		$this->_avatar_realtime_gen_dict[ $url ] = $this->_generate( $url ) ;

		return $this->_avatar_realtime_gen_dict[ $url ] ;
	}

	/**
	 * Check if there is a cache folder
	 *
	 * @since  3.0
	 * @access public
	 */
	public static function has_cache()
	{
		return is_dir( LITESPEED_STATIC_DIR . '/avatar' ) ;
	}

	/**
	 * make cache folder
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _mkdir()
	{
		mkdir( LITESPEED_STATIC_DIR . '/avatar', 0755, true ) ;
	}

	/**
	 * Read last time generated info
	 *
	 * @since  3.0
	 * @access public
	 */
	public function queue_count()
	{
		global $wpdb ;
		$q = "SELECT count(*) FROM $this->_tb WHERE dateline<" . ( time() - $this->_conf_cache_ttl ) ;
		return $wpdb->get_var( $q ) ;
	}

	/**
	 * Get the final URL of local avatar
	 *
	 * Check from db also
	 *
	 * @since  3.0
	 */
	private function _rewrite( $url )
	{
		return LITESPEED_STATIC_URL . '/avatar/' . md5( $url ) ;
	}

	/**
	 * Generate realpath of the cache file
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _realpath( $url )
	{
		return LITESPEED_STATIC_DIR . '/avatar/' . md5( $url ) ;
	}

	/**
	 * Delete file-based cache folder
	 *
	 * @since  3.0
	 * @access public
	 */
	public function rm_cache_folder()
	{
		if ( file_exists( LITESPEED_STATIC_DIR . '/avatar' ) ) {
			File::rrmdir( LITESPEED_STATIC_DIR . '/avatar' ) ;
		}

		// Clear avatar summary
		self::save_summary( array() ) ;

		Log::debug2( '[Avatar] Cleared avatar queue' ) ;
	}

	/**
	 * Cron generation
	 *
	 * @since  3.0
	 * @access public
	 */
	public static function cron( $force = false )
	{
		global $wpdb ;

		$_instance = self::get_instance();
		if ( ! $_instance->queue_count() ) {
			Log::debug( '[Avatar] no queue' ) ;
			return ;
		}

		// For cron, need to check request interval too
		if ( ! $force ) {
			if ( ! empty( $_instance->_summary[ 'curr_request' ] ) && time() - $_instance->_summary[ 'curr_request' ] < 300 ) {
				Log::debug( '[Avatar] curr_request too close' ) ;
				return ;
			}
		}

		$q = "SELECT url FROM $_instance->_tb WHERE dateline < %d ORDER BY id DESC LIMIT %d" ;
		$q = $wpdb->prepare( $q, array( time() - $_instance->_conf_cache_ttl, apply_filters( 'litespeed_avatar_limit', 30 ) ) ) ;

		$list = $wpdb->get_results( $q ) ;
		Log::debug( '[Avatar] cron job [count] ' . count( $list ) ) ;

		foreach ( $list as $v ) {
			Log::debug( '[Avatar] cron job [url] ' . $v->url ) ;

			$_instance->_generate( $v->url );
		}
	}

	/**
	 * Remote generator
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _generate( $url )
	{
		global $wpdb ;

		// Record the data

		$file = $this->_realpath( $url ) ;

		// Update request status
		$this->_summary[ 'curr_request' ] = time() ;
		self::save_summary() ;

		// Generate
		if ( ! self::has_cache() ) {
			$this->_mkdir() ;
		}
		$response = wp_remote_get( $url, array( 'timeout' => 180, 'stream' => true, 'filename' => $file ) ) ;

		Log::debug( '[Avatar] _generate [url] ' . $url ) ;

		// Parse response data
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message() ;
			file_exists( $file ) && unlink( $file ) ;
			Log::debug( '[Avatar] failed to get: ' . $error_message ) ;
			return $url ;
		}

		// Save summary data
		$this->_summary[ 'last_spent' ] = time() - $this->_summary[ 'curr_request' ] ;
		$this->_summary[ 'last_request' ] = $this->_summary[ 'curr_request' ] ;
		$this->_summary[ 'curr_request' ] = 0 ;
		self::save_summary() ;

		// Update DB
		$md5 = md5( $url ) ;
		$q = "UPDATE $this->_tb SET dateline=%d WHERE md5=%s" ;
		$existed = $wpdb->query( $wpdb->prepare( $q, array( time(), $md5 ) ) ) ;
		if ( ! $existed ) {
			$q = "INSERT INTO $this->_tb SET url=%s, md5=%s, dateline=%d" ;
			$wpdb->query( $wpdb->prepare( $q, array( $url, $md5, time() ) ) ) ;
		}

		Log::debug( '[Avatar] saved avatar ' . $file ) ;

		return $this->_rewrite( $url ) ;
	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  3.0
	 * @access public
	 */
	public static function handler()
	{
		$instance = self::get_instance() ;

		$type = Router::verify_type() ;

		switch ( $type ) {
			case self::TYPE_GENERATE :
				self::cron( true ) ;
				break ;

			default:
				break ;
		}

		Admin::redirect() ;
	}

}