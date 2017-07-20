<?php 
/*
Plugin Name: Awebon Plagiarism Checker With ProgressBar
Plugin URI:http://www.awebon.com/
version:1.0
author:Karthikeyan Balasubramanian
Author URI:http://karthik.awebon.com
	
Description:  Checks for plagiarism on submitting the post
*/


function awebon_plagiarism_admin_scripts() {
	
	wp_enqueue_script( 'jquery-ui-dialog' );
	wp_enqueue_script( 'jquery-ui-progressbar' );
	wp_register_style( 'awebon_plagiarism_jquery_ui_css', plugins_url('css/jquery-ui.css', __FILE__), false, '1.11.4' );
	wp_enqueue_style( 'awebon_plagiarism_jquery_ui_css' );
	wp_register_script('awebon_plagiarism_bootstrap_js', plugins_url('js/bootstrap.min.js', __FILE__),'', '3.3.6',true);
	wp_enqueue_script( "awebon_plagiarism_bootstrap_js" );
    wp_register_script('awebon_plagiarism_admin_js', plugins_url('js/awebon-plagiarism.js', __FILE__), array('jquery'), '1.0.0',true);
    $result_code = '200';
    $result_message = '';
	if(get_post_type() == 'post' && !empty(get_post_meta(get_the_ID(),'awebon_plagarism_results',true))){
		$result_code = '400';			
	  	$awebon_google_search_engine_id = get_option('awebon_google_search_engine_id');
			$awebon_google_api_key = get_option('awebon_google_api_key');
		if(!empty($awebon_google_search_engine_id) && !empty($awebon_google_api_key)){	
			$awebon_plagarism_results = get_post_meta(get_the_ID(),'awebon_plagarism_results',true);
			if(!empty($awebon_plagarism_results)){
				$result_message = translate('Your content has Plagiarism,so the article is in draft (or) Word count is less than '.get_option('awebon_plagiarism_min_words'));
			}else if(!empty($_SESSION['plagiarism_progress_percentage'])){
				$result_code = '300';
				$result_message = translate('Your content doesn\'t have any Plagiarism and it is published');
			}
		}else{
			$result_message = translate('Please set Google Search Engine ID and Google API Key');
		}
	}

    wp_localize_script( 'awebon_plagiarism_admin_js', 'awebon_plagiarism', array('result_code'=>$result_code,'result_message'=>$result_message) );

  	wp_enqueue_script( "awebon_plagiarism_admin_js" );
  	 wp_register_style( 'awebon_plagiarism_admin_css', plugins_url('css/awebon-plagiarism.css', __FILE__), false, '1.0.0' );
        wp_enqueue_style( 'awebon_plagiarism_admin_css' );
}

add_action( 'admin_enqueue_scripts', 'awebon_plagiarism_admin_scripts' );

add_filter( 'wp_insert_post_data' , 'awebon_plagiarism_check_plagiarism' , '99', 2 );

function awebon_plagiarism_check_plagiarism( $data , $postarr ) {

	if($data['post_type'] != 'post')
		return $data;
	if(!($data['post_status'] == 'draft' || $data['post_status'] == 'publish'))
		return $data;
  	
  	$awebon_google_search_engine_id = get_option('awebon_google_search_engine_id');
  	$awebon_google_api_key = get_option('awebon_google_api_key');

  	if(empty($awebon_google_search_engine_id) || empty($awebon_google_api_key))
  		return $data;

	$isPlagiarism = false;
	$content = $data['post_content'];
	$content = do_shortcode($content);
	$content = strip_tags($content);
	$chunks = awebon_plagiarism_slice_content($content);
	$search_result = array();
	$chunks_count = count($chunks);
	$progress_percent_per_chunk = 100/$chunks_count;
	$_SESSION['plagiarism_progress_percentage']=0;
	$current_session_id = session_id();	
	for ($i=0; $i < $chunks_count; $i++) {
		if(session_status() != PHP_SESSION_ACTIVE)
			session_start();
		$response = wp_remote_get( "https://www.googleapis.com/customsearch/v1?key=".$awebon_google_search_engine_id."&cx=".$awebon_google_api_key."&q=".urlencode($chunks[$i])."&fields=queries(request/totalResults)&exactTerms=".urlencode($chunks[$i]));
		$api_results = json_decode( wp_remote_retrieve_body( $response ),true);
		if(isset($api_results['error']) && !empty($api_results['error'])){
			$isPlagiarism = true;
			$search_result['error']=$api_results['error']['errors'][0]["message"];
			break;
		}else if($api_results['queries']['request'][0]["totalResults"] > 0){
			$isPlagiarism = true;
			$search_result[$chunks[$i]]=$api_results['queries']['request'][0]["totalResults"];
		}
		$_SESSION['plagiarism_progress_percentage']=round(($i+1)*$progress_percent_per_chunk);
		$current_session_id = session_id();
		session_write_close();
	}
	session_start();
	$awebon_plagiarism_min_words = intval(get_option('awebon_plagiarism_min_words'));
	if(str_word_count($content) < $awebon_plagiarism_min_words){
		$isPlagiarism = true;
		$search_result['word_count_error']='Word Count should be more than '.$awebon_plagiarism_min_words;
	}

	if($isPlagiarism){
		$_SESSION['search_results']=$search_result;
    	$data['post_status'] = 'draft';
	}else{
		$data['post_status'] = 'publish';
	}
    return $data;
}

function awebon_plagiarism_save_plagiarism_search_results( $post_id ) {

	if(get_the_ID() != $post_id) return;

	if(isset($_SESSION['search_results'])){
		$result = update_post_meta($post_id, 'awebon_plagarism_results', is_array($_SESSION['search_results'])?$_SESSION['search_results']:[]);
		unset($_SESSION['search_results']);
	}else{
		delete_post_meta($post_id, 'awebon_plagarism_results');
	}
}	

add_action( 'save_post', 'awebon_plagiarism_save_plagiarism_search_results' );

function awebon_plagiarism_slice_content($content){

	$n = intval(get_option('awebon_plagiarism_no_of_words'));
	$m = intval(get_option('awebon_plagiarism_offset_size'));

	$words = preg_split("/[\s]+/u", $content);

	$chunks = array();
	$num_words = count($words);
	$num_chunks = ceil($num_words / $m);
	for ($i=0;$i <= $num_words-$n;$i += $m) {
	  $key = implode(" ", array_slice($words, $i, $n));

	  if (str_word_count($key,0) >= $n) {
	    $chunks[] = $key;
	  }
	}
	return $chunks;
}

function awebon_plagiarism_add_plagiarism_meta_box() {
	add_meta_box('awebon_plagiarism_meta_box_id','Awebon Plagiarism Results','awebon_plagiarism_show_plagiarism_meta_box', 'post', 'advanced', 'high');
}

add_action('add_meta_boxes', 'awebon_plagiarism_add_plagiarism_meta_box');

function awebon_plagiarism_show_plagiarism_meta_box(){
	
	$awebon_plagarism_results = get_post_meta(get_the_ID(),'awebon_plagarism_results',true);
	if(!empty($awebon_plagarism_results)){
			echo '<b> Please correct the below sentences</b>';
		foreach ($awebon_plagarism_results as $key => $value) {
			if($key == 'error'){
				echo '<div class="awebon_plagiarism_row awebon_plagiarism_error">'.esc_html($key).' : '.esc_html($value).'</div>';
			}else if($key == 'word_count_error'){
				echo '<div class="awebon_plagiarism_row awebon_plagiarism_result">'.esc_html($value).'</div>';			
			}else{
				echo '<div class="awebon_plagiarism_row awebon_plagiarism_result">"'.esc_html($key).'" has <b>'.esc_html($value).'</b> copies on the web.</div>';
			}
		}
	}else if(!empty($_SESSION['plagiarism_progress_percentage'])){
		echo '<div class="awebon_plagiarism_row awebon_plagiarism_success">Your content is Plagiarism Free.</div>';
	}else{
		echo '<div class="awebon_plagiarism_row awebon_plagiarism_success">No Result</div>';
	}
	echo '<div id="awebon-plagarism-dialog" title="Plagarism Checker Progress">';
	  echo '<div class="awebon-plagarism-progress-label">Started checking...</div>';
	  echo '<div id="awebon-plagarism-progressbar"></div>';
	echo '</div>';

	/*echo '<div id="awebon-plagiarism-result-dialog" title="Error">';
	echo '  <p id="awebon-plagiarism-result-message"> Result Message';
	echo '  </p>';
	echo '</div>';*/

	echo '<div class="modal fade" id="awebon-plagiarism-result-dialog">';
	echo '  <div class="modal-dialog" role="document">';
	echo '    <div class="modal-content">';
	echo '      <div class="modal-header dialog-header-error">';
	echo '        <h4 class="modal-title"><span class="glyphicon glyphicon-warning-sign"></span> Error</h4>';
	echo '      </div>';
	echo '      <div class="modal-body">';
	echo '  		<p id="awebon-plagiarism-result-message"> Result Message';
	echo '  		</p>';
	echo '      </div>';
	echo '      <div class="modal-footer">';
	echo '        <button type="button" class="btn btn-primary" data-dismiss="modal">OK</button>';
	echo '      </div>';
	echo '    </div><!-- /.modal-content -->';
	echo '  </div><!-- /.modal-dialog -->';
	echo '</div><!-- /.modal -->';

	unset($_SESSION['plagiarism_progress_percentage']);
}


add_action( 'wp_ajax_plagiarism_progress_check', 'awebon_plagiarism_progress_check' );
function awebon_plagiarism_progress_check() {
	session_start();
    if ( isset($_SESSION['plagiarism_progress_percentage']) ) {
        echo esc_html($_SESSION['plagiarism_progress_percentage']);
    }else{
    	echo "0";
    }
   die();
}

function awebon_plagarism_settings_options(){ 
	$uid = get_current_user_id();

	if(isset($_POST['awebon_plagiarism_ispost']) && $_POST['awebon_plagiarism_ispost'] == 'true' && current_user_can('manage_options')) {
		check_admin_referer( 'awebon-plagiarism-settings');
	  if (isset($_POST['awebon_google_search_engine_id'])){
	    $awebon_google_search_engine_id = $_POST['awebon_google_search_engine_id'];
	    update_option('awebon_google_search_engine_id', sanitize_text_field($awebon_google_search_engine_id));
	  }		
	  if (isset($_POST['awebon_google_api_key'])){
	    $awebon_google_api_key = $_POST['awebon_google_api_key'];
	    update_option('awebon_google_api_key', sanitize_text_field($awebon_google_api_key));
	  }		
	  if (is_numeric($_POST['awebon_plagiarism_no_of_words'])){
	    $awebon_plagiarism_no_of_words = intval($_POST['awebon_plagiarism_no_of_words']);
		if ( !$awebon_plagiarism_no_of_words ) {
		  $awebon_plagiarism_no_of_words = 10;
		}	    
	    update_option('awebon_plagiarism_no_of_words', sanitize_text_field($awebon_plagiarism_no_of_words));
	  }		
	  if (is_numeric($_POST['awebon_plagiarism_offset_size'])){
	    $awebon_plagiarism_offset_size = intval($_POST['awebon_plagiarism_offset_size']);
		if ( !$awebon_plagiarism_offset_size ) {
		  $awebon_plagiarism_offset_size = 6;
		}	    
	    update_option('awebon_plagiarism_offset_size', sanitize_text_field($awebon_plagiarism_offset_size));
	  }
	  if (is_numeric($_POST['awebon_plagiarism_min_words'])){
	    $awebon_plagiarism_min_words = intval($_POST['awebon_plagiarism_min_words']);
		if ( !$awebon_plagiarism_min_words ) {
		  $awebon_plagiarism_min_words = 200;
		}	    
	    update_option('awebon_plagiarism_min_words', sanitize_text_field($awebon_plagiarism_min_words));
	  }	  
	}else{
	  $awebon_google_search_engine_id = get_option('awebon_google_search_engine_id');
	  $awebon_google_api_key = get_option('awebon_google_api_key');
	  $awebon_plagiarism_no_of_words = get_option('awebon_plagiarism_no_of_words');
	  $awebon_plagiarism_offset_size = get_option('awebon_plagiarism_offset_size');
	  $awebon_plagiarism_min_words = get_option('awebon_plagiarism_min_words');
	}
	?>

			<form name="awebon_plagiarism_admin_form" id="awebon_plagiarism_admin_form" method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>">
		    <?php wp_nonce_field('awebon-plagiarism-settings'); 
				$general_options = array(
				__('Google Search Engine ID') => '<input type="text" name="awebon_google_search_engine_id" value="'.esc_html($awebon_google_search_engine_id).'" size="100"> ',
				__('API Key') => '<input type="text" name="awebon_google_api_key" value="'.esc_html($awebon_google_api_key).'" size="75">',
				__('Number of words to break the sentence')  => '<input type="text" name="awebon_plagiarism_no_of_words" value="'.esc_html($awebon_plagiarism_no_of_words).'" size="10"> 
					<span class="description">'.__('Size of the phrases to be extracted').'</span>',
				__('Offset Size')   => '<input type="text" name="awebon_plagiarism_offset_size"      value="'.esc_html($awebon_plagiarism_offset_size).'" size="10"> 
					<span class="description">'.__("Size of the offset before the next phrase is chosen").'</span>',
				__('Minimum number of words')   => '<input type="text" name="awebon_plagiarism_min_words"      value="'.esc_html($awebon_plagiarism_min_words).'" size="10"> 
					<span class="description">'.__("Minimum number of words for the post").'</span>',
			);	    
			echo awebon_plagiarism_box_content(__('General Options'), $general_options);
			?>
		    <input type="hidden" name="awebon_plagiarism_ispost" value="true">
		    <p class="submit"><input type="submit" class="button-primary" name="Submit" value="<?php _e('Update Options') ?>" /></p>
<?php
}

function awebon_plagarism_settings_action(){
	$awebon_plagiarism_no_of_words = get_option('awebon_plagiarism_no_of_words');
	if (empty($awebon_plagiarism_no_of_words)){
		 update_option('awebon_plagiarism_no_of_words','10');
	}
	$awebon_plagiarism_offset_size = get_option('awebon_plagiarism_offset_size');
	if (empty($awebon_plagiarism_offset_size)){
		update_option('awebon_plagiarism_offset_size','6');
	}
	$awebon_plagiarism_min_words = get_option('awebon_plagiarism_min_words');
	if (empty($awebon_plagiarism_min_words)){
		update_option('awebon_plagiarism_min_words','200');
	}
		add_options_page(__("Awebon Plagiarism Checker"), __("Awebon Plagiarism Checker Settings"), 'manage_options', "awebon-plagiarism-admin", "awebon_plagarism_settings_options");
}

add_action('admin_menu','awebon_plagarism_settings_action');

function awebon_plagiarism_box_content ($title, $content) {
	if (is_array($content)) {
		$content_string = '<table>';
		foreach ($content as $name=>$value) {
			$content_string .= '<tr>
				<td style="width:250px; vertical-align: text-top;">'.__($name, 'menu-test' ).':</td>	
				<td>'.$value.'</td>
				</tr>';
		}
		$content_string .= '</table>';
	} else {
		$content_string = $content;
	}

	$out = '
		<div class="postbox">
			<h3>&nbsp;'.__($title, 'menu-test' ).'</h3>
			<div class="inside">'.$content_string.'</div>
		</div>
		';
	return $out;
}

function awebon_plagiarism_checker_settings_link($links) { 
  $settings_link = '<a href="options-general.php?page=awebon-plagiarism-admin">Settings</a>'; 
  array_unshift($links, $settings_link); 
  return $links; 
}

add_filter("plugin_action_links_awebon-plagiarism-checker/index.php", 'awebon_plagiarism_checker_settings_link' );

function awebon_plagiarism_checker_custom_admin_notice() { 
	  	$awebon_google_search_engine_id = get_option('awebon_google_search_engine_id');
		$awebon_google_api_key = get_option('awebon_google_api_key');
		if(empty($awebon_google_search_engine_id) || empty($awebon_google_api_key)){
	?>
		    <div class="notice notice-error is-dismissible">
		        <p><?php _e( 'Awebon Plagiarism Checker - Please set Engine ID and API key <a href="options-general.php?page=awebon-plagiarism-admin">Settings</a>', 'awebon_plagiarism_checker' ); ?></p>
		    </div>
<?php 
		}
	}
add_action('admin_notices', 'awebon_plagiarism_checker_custom_admin_notice');

?>