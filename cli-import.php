<?php

require_once('config.php');

cli_import_set_hostname();

if ( !file_exists( CLI_WP_ROOT_DIRECTORY . '/wp-load.php' ) ) {
	die( sprintf( "Please set CLI_WP_ROOT_DIRECTORY to the ABSPATH of your WordPress install. Could not find %s\n", CLI_WP_ROOT_DIRECTORY . '/wp-load.php' ) );
}

//define( 'WP_LOAD_IMPORTERS', false );
ob_start();

require_once( CLI_WP_ROOT_DIRECTORY . '/wp-load.php' );
require_once( ABSPATH . 'wp-admin/includes/admin.php' );

//define( 'WP_LOAD_IMPORTERS', true );
//require_once( dirname( __FILE__ ) . '/wordpress-importer.php' );

ob_end_clean();

set_time_limit( 0 );
ini_set( 'memory_limit', '512m' );

if (!class_exists( 'WP_Importer')) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if (file_exists( $class_wp_importer ))
		require_once $class_wp_importer;
}


class CLI_Import extends WP_Importer{
	public $args;
	protected $validate_args = array();
	protected $required_args = array();
	public $debug_mode = true;	

	protected $filename = __FILE__;

	/**
	 * Grab command line arguments,
	 * Initial import setup
	 */
	public function __construct() {
		// Grab command line agurments passed in
		$this->args = $this->get_cli_arguments();		
		parent::__construct();
	}

	/**
	 * Validate arguments, dispatch if a hostname imported
	 * Otherwise re-run with passed args
	 */
	public function init() {
		if ( !$this->validate_args() ) {
			$this->debug_msg( "Problems with arguments" );
			exit;
		} 
		if ( !empty( $this->args->import_hostname ) ) {
			$this->dispatch();
		} else {
			$this->debug_msg( "Initializing Import Environment" );
			$this->args->import_hostname = $this->blog_address;
			foreach( $this->args as $key => $value )
				if ( 'blog' == $key )
					$args[] = "--$key=" . (int) $value;
				else 
					$args[] = "--$key=" . escapeshellarg( $value );	

			$command = "php " . $this->filename . " " . implode( " ", (array) $args );
			$this->debug_msg( "execute: $command" );
			system( $command );
		}
	}
	
	public function debug_msg( $msg ) {
		$msg = date( "Y-m-d H:i:s : " ) . $msg;
		if ( $this->debug_mode ) 
			echo $msg . "\n";
		else 
			error_log( $msg );
	}
	
	
	/**
	 * Set required argument
	 *
	 * @param string $name 
	 * @param string $description 
	 */
	public function set_required_arg( $name, $description='' ) {
		$this->required_args[$name] = $description;
	}

	/**
	 * Set validation on argument
	 *
	 * @param string $name_match Name of argument
	 * @param string $value_match Callback for validation of argument, or regex to match against
	 * @param string $description Description of value
	 */
	public function set_argument_validation( $name_match, $value_match, $description='argument validation error' ) {
		$this->validate_args[] = array( 'name_match' => $name_match, 'value_match' => $value_match, 'description' => $description );
	}
	
	/**
	 * Validate required arguments. Validate values of other arguments
	 * Displays contextual help when appropriate
	 * 
	 * @see set_required_arg
	 * @see set_argument_validation
	 * @return bool true if all validation passes, false otherwise
	 */
	protected function validate_args() {
		$result = true;
		$this->debug_msg( "Validating arguments" );
		if ( empty( $_SERVER['argv'][1] ) && !empty( $this->required_args ) ) {
			$this->show_help();
			$result = false;
		} else {
			foreach( $this->required_args as $name => $description ) {
				if ( !isset( $this->args->$name) ) {
					$this->raise_required_argument_error( $name, $description );
					$result = false;
				}
			}
		}
		foreach( $this->validate_args as $validator ) {
			foreach( $this->args as $name => $value ) {
				$name_match_result = preg_match( $validator['name_match'], $name );
				if ( ! $name_match_result ) {
					continue;
				} else {
					$value_match_result = $this->dispatch_argument_validator( $validator['value_match'], $value );
					if ( ! $value_match_result ) {
						$this->raise_argument_error( $name, $value, $validator );
						$result = false;
						continue;
					}
				}
			}
		}

		return $result;
	}
	
	/**
	 * Run value validation on a given argument
	 *
	 * @see set_argument_validation
	 * @param string $match method that runs the validation, or regex to match against
	 * @param string $value Value of argument
	 * @return mixed typically boolean true/false for validation. Modified by validation methods
	 */
	protected function dispatch_argument_validator( $match, $value ) {
		$match_result = false;
		if ( is_callable( array( &$this, $match ) ) ) {
			$_match_result = call_user_func( array( &$this, $match ), $value );
		} else if ( is_callable( $match ) ) {
			$_match_result = call_user_func( $match, $value );
		} else {
			$_match_result = preg_match( $match, $value );
		}
		return $_match_result;
	}

	/**
	 * Echo validation error on argument
	 *
	 * @param string $name Name of the argument
	 * @param string $value Value passed in via command line
	 * @param string $validator Description of valid value
	 * @return void
	 */
	protected function raise_argument_error( $name, $value, $validator ) {
		printf( "Validation of %s with value %s failed: %s\n", $name, $value, $validator['description'] );
	}

	/**
	 * Echo required argument error
	 *
	 * @param string $name Name of argument
	 * @param string $description Description of valid value
	 * @return void
	 */
	protected function raise_required_argument_error( $name, $description ) {
		printf( "Argument --%s is required: %s\n", $name, $description );
	}
	
	/**
	 * Setup which blog is going to be used for the import
	 * Best used as a callback on argument validation
	 *
	 * Dies if unable to swap to the blog
	 * 
	 * @param int $blog_id 
	 * @return bool true if switched to blog
	 */
 	protected function cli_init_blog( $blog_id ) {
		if ( !is_numeric( $blog_id ) ) {
			$this->debug_msg( sprintf( "please provide the numeric blog_id for %s", $blog_id ) );
			die();
		}
		
		$home_url = str_replace( 'http://', '', get_home_url( $blog_id ) );
		$home_url = preg_replace( '#/$#', '', $home_url );
		$this->blog_address = array_shift( explode( "/", $home_url ) );

		if ( false <> $this->blog_address ) {
			$this->debug_msg( sprintf( "the blog_address we found is %s (%d)", $this->blog_address, $blog_id ) );
			$this->args->blog = $blog_id;
			switch_to_blog( (int) $blog_id );
			return true;
		} else {
			$this->debug_msg( sprintf( "could not get a blog_address for this blog_id: %s (%s)", var_export( $this->blog_address, true ), var_export( $blog_id, true ) ) );
			die();
		}
	}
	
	/**
	 * Set user via command line
	 * Needs to be set to a user with importing capabilities
	 * Best used as a callback on argument validation
	 *
	 * @param int $user_id
	 * @return int|bool user id if set properly, false otherwise
	 */
	protected function cli_set_user( $user_id ) {
		if ( is_numeric( $user_id ) ) {
			$user_id = (int) $user_id;
		} else {
			$user_id = (int) username_exists( $user_id );
		}
		if ( !$user_id || !wp_set_current_user( $user_id ) ) {
			return false;
		}

		$current_user = wp_get_current_user();
		return $user_id;
	}
	
	/**
	 * Get the command line arguments
	 *
	 * @return StdClass arguments
	 */
	protected function get_cli_arguments() {
		$_ARG = new StdClass;
		$argv = $_SERVER['argv'];
		array_shift( $argv );
		foreach ( $argv as $arg ) {
			if ( preg_match( '#--([^=]+)=(.*)#', $arg, $reg ) )
				$_ARG->$reg[1] = $reg[2];
			elseif( preg_match( '#-([a-zA-Z0-9])#', $arg, $reg ) )
				$_ARG->$reg[1] = 'true';
		}
		return $_ARG;
	}
	
	/**
	 * Needs to be overwritten by child class
	 */
	protected function dispatch() {}

	/**
	 * Needs to be overwritten by child class
	 */		
	protected function show_help() {}
	
}

function cli_import_set_hostname() {
	foreach ( $_SERVER['argv'] as $arg ) {
		if ( preg_match( '#--import_hostname=(.*)#', $arg, $reg ) ) {
			$_SERVER['HTTP_HOST'] = $reg[1];
			return;
		}
	}
	$_SERVER['HTTP_HOST'] = CLI_WP_DEFAULT_HOST;
}

?>