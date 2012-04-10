<?php

if(file_exists(dirname(__file__).'/local-config.php')) {
	include_once(dirname(__file__).'/local-config.php');
}

@define( 'CLI_WP_ROOT_DIRECTORY', dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ); // you need to adjust this to your path eventually
@define( 'CLI_WP_DEFAULT_HOST', 'wp_trunk' ); // set this to the default wordpress domain that's used for initialization when --import_hostname is omitted

@define( 'WP_IMPORTING', true );
@define( 'WP_DEBUG', true );


?>