<?php
/*
Plugin Name: Gov-UK
Plugin URI: http://saulcozens.co.uk/wordpress/gov.uk
Description: provides a shortcode for including gov.uk content into a page.
Version: 0.1alpha
Author: Saul Cozens
Author URI: http://saulcozens.co.uk/
Text Domain: govuk
*/


class govuk {
	
	/**
	 * Handles the HTTP request to gov.uk
	 *
	 * @param string $url 
	 * @return string|object Either the body of the response, or a WP_Error object
	 */
	static function get( $url ) {
		$request_args = array();
		// Cope with people entering the domain on $url
		$url = str_replace( 'https://www.gov.uk', '', $url );

		if (
			isset( $_GET['govuk'] ) &&
			strpos( $url, rawurldecode( $_GET['govuk'] ) ) == 0
			// do we have a url in the query string and does it start with the attribute of the shortcode?
		) {
			$url = trailingslashit( "https://www.gov.uk" ) . ltrim( rawurldecode( $_GET['govuk'] ), '/' );
			if( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
				// we got post data - better post it on to gov.uk
				$request_args[ 'method' ] = 'POST';
				$request_args[ 'body' ] = $_POST;
				
			}
		} else {
			$url = trailingslashit( "https://www.gov.uk" ) . ltrim( $url, '/' );
		}

		// Avoid trailing slashes…
		$path = parse_url( $url, PHP_URL_PATH );
		$path = rtrim( $path, '/' );
		$url = str_replace( $path, $path . ".json", $url );
		$url = rtrim( $url, '/' );
		/*
			fancy way of sticking .json on the url while maintaining the query string.
			this is going to have issues with any path that exists in the domain.
			####BAD CODE
		*/
		
		$request_args[ 'redirection' ] = 0;
		
		// Check if we've cached this request. I know you're not supposed to cache HTTP POST
		// requests, but five minutes won't hurt and most of this stuff is decision trees, 
		// which (as they are indempotent) should arguably use GET anyway. :)
		// FIXME: Perhaps some special cases at a later date?
		// Note we are constructing a cache key from all the args, including POSTed fields
		$cache_key = md5( $url . serialize( $request_args ) );
		if ( $cache = get_transient( $cache_key ) ) {
			error_log( "SW: Got from cache" );
			return $cache;
		}
		error_log( "SW: NOT got from cache" );
		
		// Handle a redirection ourselves, to avoid cURL/WP bug #17490
		// http://core.trac.wordpress.org/attachment/ticket/17490/
		// N.B. This will only handle one redirection
		$response = wp_remote_request( $url, $request_args );
		if ( is_wp_error( $response ) )
			return $response;

		if ( 
			( 301 == $response[ 'response' ][ 'code' ] || 302 == $response[ 'response' ][ 'code' ] )
			&& $response[ 'headers' ][ 'location' ] 
			) {
			$response = wp_remote_get( $response[ 'headers' ][ 'location' ] );
		}

		// Cache it in a transient for five minutes
		set_transient( $cache_key, $response[ 'body' ], 5*60 );

		return $response[ 'body' ];
	}

	/**
	 * Called by the WP shortcode kit to return the
	 * HTML that the shortcode will be replaced by.
	 *
	 * @param string $attr 
	 * @return string Some HTML (or a null string)
	 */
	static function insert_content( $attr ) {

		extract( shortcode_atts( array(
			'url' => ''
		 ), $attr ) );
	
		if ( empty($url) ) {
			// forgot to reference a particular URL? let's just pretend that didn't happen.
			return;
		}
		
		$response = self::get( $url );

		// Check for errors in the HTTP response, e.g. timeouts
		if ( is_wp_error( $response ) ) {
			// If the user can edit the post, let's show them a
			// meaningful error message.
			if ( current_user_can( 'edit_posts', get_the_ID() ) )
				return '<p><strong>ERROR:</strong> ' . $response->get_error_message() . '</p>';
			return; // Unprivileged users get nuffink.
		}
		
		$govuk_json = json_decode( $response );
	
		require_once( 'simplehtmldom/simple_html_dom.php' );
	
		if ( $html = str_get_html( $govuk_json->html_fragment ) ) {
	
			foreach( $html->find( "[href]" ) as $e ) {
				$host = parse_url( $e->href, PHP_URL_HOST );
				if( ( $host == '' ) || ( $host == 'www.gov.uk' ) ) {
					$e->href = add_query_arg( "govuk", urlencode( $e->href ), get_permalink() );
				}
			}
			foreach( $html->find( "[action]" ) as $e ) {
				$host = parse_url( $e->href, PHP_URL_HOST );
				if( ( $host == '' ) || ( $host == 'www.gov.uk' ) ) {
					$e->action = add_query_arg( "govuk", urlencode( $e->action ), get_permalink() );
				}
			}
	
			// Credit and licence
			$html .= '<div class="credit">' . sprintf( __('Information supplied by <a href="%1$s">gov.uk</a>. Republished under the terms of the <a href="%2$s">Open Government Licence</a>.','govuk'), 'http://gov.uk/', 'http://www.nationalarchives.gov.uk/doc/open-government-licence/' ) . '</div>';
			
			// wraps output in a .govuk class, for easier CSS targeting
			$output = '<div class="govuk">';
			$output .= $html;
			$output .= '</div><!-- end .govuk -->';
	
			return $output;
		
		} elseif ( $govuk_json->type == "guide" ) {
		
			// Needs a way to integrate gov.uk's markdown-derived mark-up language
			// See https://github.com/alphagov/govspeak
			
			$html = '<h1 class="title">' . $govuk_json->title . '</h1>';
			$html .= '<div class="overview">' . $govuk_json->overview . '</div>';
			
			if ( $vidurl = $govuk_json->video_url ) {
				global $wp_embed;
				if ( strpos($vidurl,'youtube') > 0 ) {
					$atts = array(); // not even sure we need this
					$html .= '<div class="video">';
					$html .= $wp_embed->shortcode( $atts, $vidurl );
					$html .= '<div class="video_summary">' . $govuk_json->video_summary . '</div>';
					$html .= '</div>';
				} else {
					// depends what other video hosting they use
				}
			}
			
			foreach ( $govuk_json->parts as $part ) {
				$html .= '<h2 class="part-title">' . $part->table->title . '</h2>';
				$html .= '<div class="part-body">' . $part->table->body . '</div>';
			}
			
			// will then need to turn relative gov.uk URLs into absolute
			// possibly combine with function(s) above?
			
			// Credit and licence
			$html .= '<div class="credit">' . sprintf( __('Information supplied by <a href="%1$s">gov.uk</a>. Republished under the terms of the <a href="%2$s">Open Government Licence</a>.','govuk'), 'http://gov.uk/', 'http://www.nationalarchives.gov.uk/doc/open-government-licence/' ) . '</div>';
	
			// wraps output in a .govuk class, for easier CSS targeting
			$output = '<div class="govuk">';
			$output .= $html;
			$output .= '</div><!-- end .govuk -->';
	
			return $output;
		
		}

		// unrecognised content type, don't know what to do with it, bail out!
		return;
	}

}

add_shortcode( 'govuk', array( 'govuk', 'insert_content' ) );

?>