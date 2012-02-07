<?php
/*
Plugin Name: Gov-UK
Plugin URI: http://saulcozens.co.uk/wordpress/gov.uk
Description: provides a shortcode for inlcuding gov.uk content into a page.  
Version: 0.1alpha
Author: Saul Cozens
Author URI: http://saulcozens.co.uk/

*/




class govuk {
	function insert_content($attr) {
		extract(shortcode_atts(array(
			'url' => 'https://gov.uk/'
			), $attr));

		$ch = curl_init();
			
		$url=parse_url($url, PHP_URL_PATH);

		if(isset($_GET['govuk']) && strpos($url,rawurldecode($_GET['govuk']))==0) { // do we have a url in the query string and does it start withe the attribute of the shortcode?
			$url="https://www.gov.uk".rawurldecode($_GET['govuk']);
			if($_SERVER['REQUEST_METHOD'] == 'POST') { // we got post data - better post it on to gov.uk
				$postdata="";
				foreach($_POST as $name => $value) {
					if(is_array($value)) {
						foreach($value as $subname => $subvalue) {
							$postdata.=$name."[".$subname."]=".rawurlencode($subvalue)."&";
						}
					} else {
						$postdata.=$name."=".rawurlencode($value)."&";
					}
				}
				$postdata=rtrim($postdata,'&');
				curl_setopt($ch,CURLOPT_POST,count($_POST));
				curl_setopt($ch,CURLOPT_POSTFIELDS,$postdata);
			}
		} else {
			$url="https://www.gov.uk".$url;
		}
		
		$url=str_replace(parse_url($url, PHP_URL_PATH),parse_url($url, PHP_URL_PATH).".json",$url); //fancy way of sticking .json on the url while maintaining the query string.  this is going to have issues with any path that exists in the domain.  ####BAD CODE
		
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		$response=curl_exec($ch);
		curl_close($ch);

		$govuk_json=json_decode($response);
		require_once('simplehtmldom/simple_html_dom.php');
		$govuk_html_fragment=str_get_html($govuk_json->html_fragment);
		foreach($govuk_html_fragment->find("[href]") as $e) {
			if((parse_url($e->href,PHP_URL_HOST)=='') || (parse_url($e->href,PHP_URL_HOST)=='www.gov.uk')) {
				$e->href= add_query_arg("govuk",urlencode($e->href),get_permalink());
			}
		}
		foreach($govuk_html_fragment->find("[action]") as $e) {
			if((parse_url($e->href,PHP_URL_HOST)=='') || (parse_url($e->href,PHP_URL_HOST)=='www.gov.uk')) {
				$e->action= add_query_arg("govuk",urlencode($e->action),get_permalink());
			}
		}

		return $govuk_html_fragment;
	}

}


add_shortcode('govuk',array('govuk','insert_content'));

?>