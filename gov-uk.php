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

	function insert_content( $attr ) {

		extract( shortcode_atts( array(
			'url' => ''
		 ), $attr ) );

		if ( empty($url) ) {
			// forgot to reference a particular URL? let's just pretend that didn't happen.
			return;
		}
		
		$ch = curl_init();

		$url = untrailingslashit( $url ); // in case url has a slash on the end

		$url = parse_url( $url, PHP_URL_PATH );

		if (
			isset( $_GET['govuk'] ) &&
			strpos( $url, rawurldecode( $_GET['govuk'] ) ) == 0
			// do we have a url in the query string and does it start withe the attribute of the shortcode?
		) {
			$url = "https://www.gov.uk" . rawurldecode( $_GET['govuk'] );
			if( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
				// we got post data - better post it on to gov.uk
				$postdata = "";
				foreach( $_POST as $name => $value ) {
					if( is_array( $value ) ) {
						foreach( $value as $subname => $subvalue ) {
							$postdata .= $name . "[" . $subname . "]=" . rawurlencode( $subvalue ) . "&";
						}
					} else {
						$postdata .= $name . "=" . rawurlencode( $value ) . "&";
					}
				}
				$postdata = rtrim( $postdata, '&' );
				curl_setopt( $ch, CURLOPT_POST, count( $_POST ) );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
			}
		} else {
			$url = "https://www.gov.uk" . $url;
		}

		$path = parse_url( $url, PHP_URL_PATH );
		$url = str_replace( $path, $path . ".json", $url );
		/*
			fancy way of sticking .json on the url while maintaining the query string.
			this is going to have issues with any path that exists in the domain.
			####BAD CODE
		*/

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

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
		
		} else {
		
			// unrecognised content type, don't know what to do with it, bail out!
			return;
		
		}

	}

}

add_shortcode( 'govuk', array( 'govuk', 'insert_content' ) );

?>