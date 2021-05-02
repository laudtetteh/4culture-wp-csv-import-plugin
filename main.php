<?php
ob_start();// Necessary for CSV generation
/**
 * Plugin Name: STF - TAR CSV TO DB
 * Plugin URI: https://www.studiotenfour.com/
 * Description: A tool to import/export CSV data for the Touring Art Roster
 * from SalesForce into the Database & vice-versa
 * Version: 1.0
 * Author: Laud Tetteh
 * Author URI: https://www.studiotenfour.com/
 * License: GPL2
*/

class stf_tar_csv_to_db {
    // Setup options variables
    protected $option_name = 'stf_tar_csv_to_db';  // Name of the options array
    protected $data = array(  // Default options values
        'jq_theme' => 'ui-lightness',
        'image_dir' => 'tar-csv-import',
        'failed_insert' => '',
        'failed_update' => '',
    );

    public function __construct() {
        $options = get_option($this->option_name);
        $wp_upload = wp_upload_dir();
        $basedir = $wp_upload['basedir'];
        $this->upload_dir = $basedir . '/tar-csv-import';
        require_once plugin_dir_path( __FILE__ ) . 'library/reports.php';

        if (is_admin()) {
            // Check if is admin. We can later update this to include other user roles
            add_action( 'admin_menu', array( $this, 'stf_tar_csv_to_db_register' ));
            register_activation_hook( __FILE__ , array($this, 'stf_tar_csv_to_db_activate')); // Add settings on plugin activation
        }
    }

    public function stf_get_export_conc($id) {
        $args = array('orderby' => 'name', 'order' => 'ASC', 'fields' => 'names');
        $_concentrations = wp_get_post_terms( $id, 'concentration', $args );
        if ( ($key = array_search('All Artists', $_concentrations)) !== false ) {
            unset($_concentrations[$key]);
            $__concentrations = array_values($_concentrations);
            $concentrations = implode('; ', $__concentrations);
        }

        return $concentrations;
    }

    public function stf_get_import_val($csvField, $csvData, $headerArray, $type='text') {

        $combined = array_combine($headerArray, array_values($csvData));

        if( array_key_exists($csvField, $combined) ) {

            $__value = $combined[$csvField];

            if( $type == 'array' ) {
                $__value = str_replace('; ', ', ', $__value);
                $value = explode(', ', $__value);
                if( 'TAR Genre' == $csvField )  {
                    array_push($value, 'All Artists');
                }
            } else {
                $value = $__value;
            }

            if( $type == 'array' ) {
                if( empty($value) ) {
                    return [];
                }
            } elseif( $type == 'text' ) {
                if( '' == $value ) {
                    return '';
                }
            }

            return $value;
        }

        return '';
    }

    public function stf_get_account_key($account_id) {
        if( !empty($account_id) ) {
            if( substr($account_id, -6) !== false ) {
                $account_key = substr($account_id, -6);

                return $account_key;
            }
        }

        return false;
    }

    public function stf_get_existing($account_id = '', $post_title = '') {
        global $wpdb;
        $existing_post = null;

        if( isset($_POST['find_post_by']) ) {
            $find_by = $_POST['find_post_by'];
        } else {
            $find_by = 'account_id';
        }

        $post_by_title = get_page_by_title($post_title, OBJECT, 'touring_arts');

        $post_id = $wpdb->get_results( "SELECT post_id FROM wpsprk_postmeta WHERE meta_key = 'account_id_new' AND meta_value = '$account_id'");

        $post_by_acctid = get_post($post_id);

        $posts = get_posts($args);
        if( ($find_by == 'post_title') && ($post_title != '') ) {
            $existing_post = $post_by_title;
        } elseif( ($find_by == 'account_id') && ($account_id != '') ) {
            if( !empty($posts) && is_object($post_by_acctid) ) {
                $existing_post = $post_by_acctid;
            } else {
                if( ($post_title != '') ) {
                    $existing_post = $post_by_title;
                }
            }
        }

        return $existing_post;
    }

    public function stf_get_img_url($account_key) {
        $tar_image_dir = $this->upload_dir;
        $image_name = $account_key . '_01.jpg';
        $image_url  = $tar_image_dir. '/' .$image_name; // Define the image URL here

        if( is_file($image_url) ) {
            return $image_url;
        }

        return false;
    }

    public function stf_set_image_caption($photo_credits='', $attach_id) {

        if( '' != $photo_credits && null != $attach_id ) {
            $image_meta = [
                'ID' => $attach_id,
                'post_excerpt' => $photo_credits,
            ];
            wp_update_post($image_meta);
        }

        return false;
    }

    public function stf_attach_image( $account_key, $post_id, $photo_credits='' ) {
        // Set featured image programmatically from url
        // https://stackoverflow.com/questions/41524931/how-to-set-featured-image-programmatically-from-url
        $upload_dir       = wp_upload_dir(); // Set upload folder
        $tar_image_dir    = ABSPATH . 'wp-content/uploads/tar-csv-import/';
        $image_name       = $account_key . '_01.jpg';
        $image_url        = $tar_image_dir . $image_name; // Define the image URL here

        $image_data       = file_get_contents($image_url); // Get image data
        $unique_file_name = wp_unique_filename( $upload_dir['path'], $image_name ); // Generate unique name
        $filename         = basename( $unique_file_name ); // Create image file name
        $attached         = get_the_post_thumbnail_url( $post_id ); // Get currently attached featured image
        $new = $upload_dir['url'] . '/' . $image_name; // Create file path for new image

        if( !$account_key || !$post_id ) {
            return false;

        } elseif( ($new == $attached) || !file_exists($image_url) ) {
            $_attach_id = attachment_url_to_postid($attached);
            $this->stf_set_image_caption($photo_credits, $_attach_id);
            return false;
        }

        // Check folder permission and define file location
        if( wp_mkdir_p( $upload_dir['path'] ) ) {
            $file = $upload_dir['path'] . '/' . $filename;

        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }


        // Create the image  file on the server
        file_put_contents( $file, $image_data );
        // Check image file type
        $wp_filetype = wp_check_filetype( $filename, null );

        $newtitletext = get_the_title($post_id);

        // Set attachment data
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name( $newtitletext ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        // Create the attachment
        $attach_id = wp_insert_attachment( $attachment, $file, $post_id );

        // Include image.php
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        // Define attachment metadata
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file );
        // Assign metadata to attachment
        wp_update_attachment_metadata( $attach_id, $attach_data );
        // Assign caption for newly-attached Featured Image

        $this->stf_set_image_caption($photo_credits, $attach_id);

        // And finally assign featured image to post
        if( set_post_thumbnail( $post_id, $attach_id ) !== true ) {
            return false;
        }

        return true;

    }

    public function stf_delete_img($account_key) {
        if( $this->stf_get_img_url($account_key) != false ) {
            $file = $this->stf_get_img_url($account_key);
            unlink($file);

            return true;
        }

        return false;
    }

    public function stf_create_tar_images_dir() {
        // Create TAR Image upload directory if none exists
        $upload_dir = $this->upload_dir;
        if (! is_dir($upload_dir)) {
           mkdir( $upload_dir, 0700 );

           return true;
        }

        return false;
    }

    public function stf_custom_upload_dir( $dir_data ) {
        // $dir_data already you might want to use
        $custom_dir = 'tar-csv-import';
        return [
            'path' => $dir_data[ 'basedir' ] . '/' . $custom_dir,
            'url' => $dir_data[ 'url' ] . '/' . $custom_dir,
            'subdir' => '/' . $custom_dir,
            'basedir' => $dir_data[ 'error' ],
            'error' => $dir_data[ 'error' ],
        ];
    }

    public function stf_upload_images($overwrite_img){
        // https://wordpress.stackexchange.com/questions/173197/upload-multiple-files-with-media-handle-upload
        $_alerts = [];
        $image_succ = 0;
        $image_fail = 0;
        $image_exist = 0;
        $message ='';
        $success = '';
        $error = '';

        if( 'POST' == $_SERVER['REQUEST_METHOD']  ) {
            $countfiles = count($_FILES['multi_file_upload']['name']);
            $process_image = false;

            if ( $_FILES['multi_file_upload']['name'][0] == "" ) {

                $_alerts[] = "no_files";

            } else {
                 // Looping all files
                for($i=0;$i<$countfiles;$i++){
                    $filename = $_FILES['multi_file_upload']['name'][$i];
                    // Upload file if new, or overwrite if exists & overwrite is set to true
                    if( file_exists($this->upload_dir.'/'.$filename ) && ($overwrite_img == false) ) {
                        $_alerts[] = "exists_skip";
                        $image_exist ++;
                        // continue;
                    } elseif( file_exists($this->upload_dir.'/'.$filename ) && ($overwrite_img == true) ) {
                        $_alerts[] = "exists_ovrwrt";
                        $image_exist ++;

                        $process_image = move_uploaded_file($_FILES['multi_file_upload']['tmp_name'][$i], $this->upload_dir.'/'.$filename);

                    } else {

                        $process_image = move_uploaded_file($_FILES['multi_file_upload']['tmp_name'][$i], $this->upload_dir.'/'.$filename);
                    }

                    if ( $process_image == true ) {
                        $_alerts[] = "success";
                        $image_succ ++;
                    } else {
                        $_alerts[] = "fail";
                        $image_fail ++;
                    }
                }
            }

            if( in_array('no_files', $_alerts ) ) {
                $error .= '<br />* Error: '.__('No valid files were selected for upload. See "Notes" tab for details.','stf_tar_csv_to_db').'<br />';
            } else {

                if( in_array('exists_ovrwrt', $_alerts ) ) {
                    $message .= '<br />* Alert: <strong>'. $image_exist .'</strong> '.__(' existing file(s) found. They were overwritten.','stf_tar_csv_to_db').'<br />';

                } elseif( in_array('exists_skip', $_alerts ) ) {
                    $message .= '<br />* Alert: <strong>'. $image_exist .'</strong> '.__(' existing file(s) found. They were skipped.','stf_tar_csv_to_db').'<br />';
                }

                if( in_array('fail', $_alerts ) && $image_exist == 0 ) {
                    $error .= '<br />* Error: '.__('There was a problem with the image upload operation. Please make sure the TAR Images directory exists, then try again. See "Notes" tab for details.','stf_tar_csv_to_db').'<br />';
                }

                if( in_array('fail', $_alerts ) && $image_succ > 0 && ($image_exist !== $image_fail) ) {
                    $error .= '<br />* <strong>'. $image_fail .'</strong> '.__('image(s) failed to upload.', 'stf_tar_csv_to_db');
                }

                if( in_array('success', $_alerts ) && $image_succ > 0 ) {
                    $success .= '<br />* Success!: '.__('<strong>'. $image_succ .'</strong> total image(s) uploaded successfully!','stf_tar_csv_to_db');
                }
            }
        }

        return [
            'message_info_style' => $message,
            'success_message' => $success,
            'error_message' => $error,
        ];
    }

    public function stf_exp_str_replace($in) {
        switch($in) {
            case "Negotiable":
                return "1";
                break;
            case "Not Negotiable":
                return "0";
                break;
            case "Educational Program Available":
                return "1";
                break;
            case "No Educational Program Available":
                return "0";
                break;
            case "":
                return "0";
                break;
        }
    }

    public function stf_imp_str_replace($in, $yes, $no) {
        if( false == $in ) {
            return $no;
        } elseif( true == $in ) {
            return $yes;
        }

        return '';
    }

    public function stf_get_photo_credits($post_id) {
        if( has_post_thumbnail($post_id) ) {
            $caption = get_post(get_post_thumbnail_id($post_id))->post_excerpt;

            if( !empty($caption) ) {
                return $caption;
            }
        }

        return '';
    }

    public function stf_get_so_widget_parts($pid) {
        $media = json_decode( get_post_meta($pid, 'media', true) );
        $widgets = $media->widgets;
        $fields = ['vimeo_id', 'track_id', 'youtube_id'];
        $parts = [];

        foreach( $widgets as $obj ) {
            foreach( $fields as $field ) {
                if( isset($obj->{$field}) && !empty($obj->{$field}) ) {
                    $mid = $obj->{$field};
                    $parts[$field] = $mid;
                }
            }
        }

        return $parts;
    }


    public function stf_get_media_id_exp($pid, $field) {
        $parts = $this->stf_get_so_widget_parts($pid);

        if( 'artist_vimeo' == $field ) {
            if( !array_key_exists('vimeo_id', $parts) ) {
                $mid = get_post_meta($pid, 'artist_vimeo', true);
            } elseif(array_key_exists('vimeo_id', $parts) ) {
                $mid = $parts['vimeo_id'];
            }
        } elseif( 'youtube_id' == $field ) {
            if( !array_key_exists('youtube_id', $parts) ) {
                $mid = get_post_meta($pid, 'artist_youtube_id_new', true);
            } elseif(array_key_exists('youtube_id', $parts) ) {
                $mid = $parts['youtube_id'];
            }
        } elseif( 'track_id' == $field ) {
            if( !array_key_exists('track_id', $parts) ) {
                $mid = get_post_meta($pid, 'artist_soundcloud_id_new', true);
            } elseif(array_key_exists('track_id', $parts) ) {
                $mid = $parts['track_id'];
            }
        } else {
            $mid = null;
        }

        if( $mid != null) {
            return $mid;
        }

        return false;
    }

    public function stf_get_video_url_exp($pid) {
        $parts = $this->stf_get_so_widget_parts($pid);
        $acf_vimeo_id = get_post_meta($pid, 'artist_vimeo', true);
        $acf_youtube_id = get_post_meta($pid, 'artist_youtube_id_new', true);
        $so_vimeo_id = $parts['vimeo_id'];
        $so_youtube_id = $parts['youtube_id'];
        $video_urls = [];

        if( array_key_exists('vimeo_id', $parts) && !empty($so_vimeo_id) ) {

            $mid = $so_vimeo_id;
            $video_urls['vm'] = "https://vimeo.com/" . $mid;

        } elseif( !array_key_exists('vimeo_id', $parts) && !empty($acf_vimeo_id) ) {

            $mid = $acf_vimeo_id;
            $video_urls['vm'] = "https://vimeo.com/" . $mid;

        }
        if( array_key_exists('youtube_id', $parts) && !empty($so_youtube_id) ) {

            $mid = $parts['youtube_id'];
            $video_urls['yt'] = "https://www.youtube.com/watch?v=" . $mid;

        } if( !array_key_exists('youtube_id', $parts) && !empty($acf_youtube_id) ) {

            $mid = $acf_youtube_id;
            $video_urls['yt'] = "https://www.youtube.com/watch?v=" . $mid;
        }

        if( !empty($video_urls) ) {
            return $video_urls;
        }

        return [];
    }

    public function stf_get_audio_url_exp($pid) {
        $parts = $this->stf_get_so_widget_parts($pid);
        $acf_sc_id = get_post_meta($pid, 'artist_soundcloud_id_new', true);
        $so_sc_id = $parts['track_id'];
        $audio_urls = [];

        if( array_key_exists('track_id', $parts) && !empty($so_sc_id) ) {

            $mid = $so_sc_id;
            $audio_urls['sc'] = "https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/" . $mid;

        } elseif( !array_key_exists('track_id', $parts)  && !empty($acf_sc_id) ) {

            $mid = $acf_sc_id;
            $audio_urls['sc'] = "https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/" . $mid;
        }

        if( !empty($audio_urls) ) {
            return $audio_urls;
        }

        return [];
    }

    // Generate TAR URL values for export
   public function stf_get_url_exp($pid, $pos) {
        $videos = $this->stf_get_video_url_exp($pid);
        $vcount = is_array($videos) ? count($videos) : 0;
        $audios = $this->stf_get_audio_url_exp($pid);
        $acount = is_array($audios) ? count($audios) : 0;

        if( $pos == 1 ) {

            if( $vcount > 0 ) {
                $key = array_key_first($videos);
                $result = $videos[$key];
            } elseif( $acount > 0 ) {
                $result = $audios['sc'];
            } else {
                $result = '';
            }

        } elseif( $pos == 2 ) {

            if( $vcount > 0 && $acount > 0) {
                $result = $audios['sc'];
            } elseif( $vcount > 1 && $acount = false) {
                $result = $videos['yt'];
            } else {
                $result = '';
            }
        }

        return $result;
   }

    public function stf_csv_export() {
        $timestamp = date("Y-m-d-H.i.s");
        $exp_filename = 'tar-wp-db-to-sf-' . $timestamp;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='. $exp_filename .'.csv');
        $num = 0;
        $profiles = [];
        global $wpdb;

        $results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}posts WHERE post_type = 'touring_arts'" );

        if($results) {
             foreach($results as $p) {
                $pid = intval($p->ID);
                $concentrations = $this->stf_get_export_conc($pid);
                $profiles[$num]['Account ID'] = get_post_meta($pid, 'account_id_new', true);
                $profiles[$num]['TAR Name'] = $p->post_title;
                $profiles[$num]['TAR Description'] = $p->post_content;
                $profiles[$num]['TAR Short Description'] = get_post_meta($pid, 'page_subhead', true);
                $profiles[$num]['TAR Performance Fee'] = get_post_meta($pid, 'artist_performance_fee_new', true);
                $profiles[$num]['TAR Negotiable'] = $this->stf_exp_str_replace( get_post_meta($pid, 'artist_fee_negotiable', true) );
                $profiles[$num]['TAR Discount'] = $this->stf_exp_str_replace( get_post_meta($pid, 'non_profit_discount_new', true) );
                $profiles[$num]['TAR Education Program'] = $this->stf_exp_str_replace( get_post_meta($pid, 'artist_ed_program_check', true) );
                $profiles[$num]['TAR Genre'] = $concentrations;
                $profiles[$num]['TAR Reviews'] = get_post_meta($pid, 'reviews', true);
                $profiles[$num]['TAR Booking Email'] = get_post_meta($pid, 'artist_email', true);
                $profiles[$num]['TAR Booking Phone'] = get_post_meta($pid, 'artist_phone', true);
                $profiles[$num]['Website'] = get_post_meta($pid, 'artist_website', true);
                $profiles[$num]['Artist Vimeo ID'] = $this->stf_get_media_id_exp($pid, 'artist_vimeo');
                $profiles[$num]['Artist YouTube ID'] = $this->stf_get_media_id_exp($pid, 'youtube_id');
                $profiles[$num]['Artist SoundCloud ID'] = $this->stf_get_media_id_exp($pid, 'track_id');
                $profiles[$num]['TAR URL 1'] = $this->stf_get_url_exp($pid, 1);
                $profiles[$num]['TAR URL 2'] = $this->stf_get_url_exp($pid, 2);
                $profiles[$num]['TAR Photo Credits'] = $this->stf_get_photo_credits($pid);
                $profiles[$num]['Sort By Keyword'] = get_post_meta($pid, 'sort_by_keyword', true);

                $num++;
            }
         }

        ob_end_clean();
        $output = fopen("php://output",'w') or die("Can't open php://output");
        fputcsv($output, ['Account ID', 'TAR Name', 'TAR Description', 'TAR Short Description', 'TAR Performance Fee', 'TAR Negotiable', 'TAR Discount', 'TAR Education Program', 'TAR Genre', 'TAR Reviews', 'TAR Booking Email', 'TAR Booking Phone', 'Website', 'Artist Vimeo ID', 'Artist YouTube ID', 'Artist SoundCloud ID', 'TAR URL 1', 'TAR URL 2', 'TAR Photo Credits', 'Sort By Keyword']);

        foreach($profiles as $profile) {
            fputcsv($output, $profile);
        }

        fclose($output) or die("Can't close php://output");
        exit();
    }


    public function stf_tar_csv_to_db_activate() {
        if( get_option($this->option_name) ) {
            update_option($this->option_name, $this->data);
        } else {
            add_option($this->option_name, $this->data);
        }

        $this->stf_create_tar_images_dir();
    }

    public function stf_update_import_status($name, $value) {
        $this->data[$name] = $value;
        if( get_option($this->option_name) ) {
            $update = update_option($this->option_name, $this->data);
        } else {
            $update = add_option($this->option_name, $this->data);
        }

        if( $update != false ) {
            return true;
        }

        return false;
    }

    public function stf_get_import_status($name) {
        if( get_option($this->option_name[$name]) ) {
            $results = get_option($this->option_name[$name]);

            return $results;
        }

        return false;
    }

    public function stf_tar_csv_to_db_register(){
        $stf_tar_csv_to_db_page = add_menu_page( __('TAR Roster CSV Settings','stf_tar_csv_to_db'), __('TAR Roster CSV Settings','stf_tar_csv_to_db'), 'manage_options', 'stf_tar_csv_to_db_menu_page', array( $this, 'stf_tar_csv_to_db_menu_page' )); // Add submenu page to "Settings" link in WP
        add_action( 'admin_print_scripts-' . $stf_tar_csv_to_db_page, array( $this, 'stf_tar_csv_to_db_admin_scripts' ) );  // Load our admin page scripts (our page only)
        add_action( 'admin_print_styles-' . $stf_tar_csv_to_db_page, array( $this, 'stf_tar_csv_to_db_admin_styles' ) );  // Load our admin page stylesheet (our page only)
    }

    public function stf_tar_csv_to_db_admin_scripts() {
        wp_enqueue_script('media-upload');  // For WP media uploader
        wp_enqueue_script('thickbox');  // For WP media uploader
        wp_enqueue_script('jquery-ui-tabs');  // For admin panel page tabs
        wp_enqueue_script('jquery-ui-dialog');  // For admin panel popup alerts
        wp_enqueue_script( 'stf_tar_csv_to_db', plugins_url( '/js/admin_page.js', __FILE__ ), array('jquery') );  // Apply admin page scripts
        wp_localize_script( 'stf_tar_csv_to_db', 'stf_tar_csv_to_db_pass_js_vars', array( 'ajax_image' => plugin_dir_url( __FILE__ ).'images/loading.gif', 'ajaxurl' => admin_url('admin-ajax.php') ) );
    }

    public function stf_tar_csv_to_db_admin_styles() {
        wp_enqueue_style('thickbox');  // For WP media uploader
        wp_enqueue_style('sdm_admin_styles', plugins_url( '/css/admin_page.css', __FILE__ ));  // Apply admin page styles

        // Get option for jQuery theme
        $options = get_option($this->option_name);
        $select_theme = isset($options['jq_theme']) ? $options['jq_theme'] : 'smoothness';
        ?>
        <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/<?php echo $select_theme; ?>/jquery-ui.css">
        <?php
    }


    //Delete all post meta except given type
    //https://wordpress.stackexchange.com/questions/211518/delete-all-post-meta-except-featured-imag
    public function delete_all_meta_except($post_types, $exclude) {
        $args = [
            'posts_per_page' => -1,
            'post_type' => [$post_types],
        ];
        $articles= get_posts( $args );

        foreach($articles as $article){
            if($article->post_type == 'attachment'){
                $myvals = get_post_meta($article->ID);
                foreach($myvals as $key=>$val)  {

                    if($key == '_wp_attached_file' || $key == '_wp_attachment_metadata'){} else {
                        delete_post_meta($article->ID, $key);

                    }
                }
            } else {
                $myvals = get_post_meta($article->ID);
                foreach($myvals as $key=>$val)  {
                    if($key != $exclude ){
                        delete_post_meta($article->ID, $key);
                    }
                }
            }
        }
    }

    public function stf_get_tar_posts($key, $value, $compare) {
        // Get all TAR posts that match the given meta query
        $tar_posts = [];
        $args = array(
            'post_type' => 'touring_arts',
            'numberposts' => -1,
            'meta_query' => array(
                array(
                   'key' => $key,
                   'value' => $value,
                   'compare' => $compare,
                )
            ),
        );
        $posts = get_posts( $args );

        foreach( $posts as $p ) {
            $link = admin_url( 'post.php?post='. $p->ID .'&action=edit' );
            $tar_posts[] = '<a href='. $link .'>'. get_the_title($p->ID) .'</a>';
        }

        return $tar_posts;
    }

    // Delete all posts of a given post type
    public function delete_all_posts_of_type($post_type) {
        $allposts= get_posts([
            'post_type' => $post_type,
            'numberposts'=> -1,
        ]);

        foreach($allposts as $eachpost) {
              wp_delete_post( $eachpost->ID, true );
        }
    }

    public function url_to_id($urls=[], $field) {
        $mid = [];

        foreach( $urls as $url ) {
            if( 'youtube' == $field ) {
                if( preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url, $match) ) {
                    $ytbid = $match[1];
                    $mid = ['youtube' => $ytbid];
                }

            } elseif( ('soundcloud' == $field) && (strpos($url, 'soundcloud.com/') !== false) ) {
                $pos = strrpos($url, '/');
                $smid = ( empty($url) || $pos === false ) ? $url : substr($url, $pos + 1);
                if( ctype_digit($smid) ) {
                    $mid = ['soundcloud' => $smid];
                }

            } elseif( ('vimeo' == $field) && (strpos($url, 'vimeo.com/') !== false) ) {
                if ( preg_match('%^https?:\/\/(?:www\.|player\.)?vimeo.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|video\/|)(\d+)(?:$|\/|\?)(?:[?]?.*)$%im', $url, $match) ) {
                    $vmid = $match[3];
                    $mid = ['vimeo' => $vmid];
                }
            }
        }

        return $mid;
    }

    public function stf_get_media_id($urls, $field) {
        $url_to_id = $this->url_to_id($urls, $field);

        if( !empty($url_to_id) && array_key_exists($field, $url_to_id) ) {
            $mid = $url_to_id[$field];

            return $mid;
        }

        return '';
    }

    public function stf_tar_csv_to_db_menu_page() {

        if(!current_user_can('manage_options')){
            wp_die('Error! Only site admin can perform this operation');
        }

        // Set variables
        global $wpdb;
        $error_message = '';
        $success_message = '';
        $message_info_style = '';
        $attach_images = isset($_POST['attach_images']) ? true : false;
        $del_img_after = isset($_POST['del_img_after']) ? true : false;
        $new_post_status = isset($_POST['new_post_status']) ? $_POST['new_post_status'] : 'draft';
        $overwrite_img = isset($_POST['overwrite_img']) ? true : false;

        // Export CSV file from TAR records in WP db
        if( isset($_POST['export_button']) ) {
            $this->stf_csv_export();
        }

        // Upload TAR Images downloaeded from SF
        if( isset($_POST['upload_button']) ) {
            if( !is_dir($this->upload_dir) ) {

                if( $this->stf_create_tar_images_dir() == false ) {
                    $error_message .='<br /> * Error: '.__('Couldn\'t find the TAR Images directory ("wp-content/uploads/tar-csv-import/"), and an attempt to create it failed. Please create it manually and try again.','stf_tar_csv_to_db');
                } else {
                    $upload_images = $this->stf_upload_images($overwrite_img);
                    $success_message .='<br /> * '.__('Couldn\'t find the TAR Images directory, so I created one before the upload.','stf_tar_csv_to_db');
                }

            } else {
                $upload_images = $this->stf_upload_images($overwrite_img);
            }

            if( $upload_images['message_info_style'] != '' ) {
                $message_info_style .= $upload_images['message_info_style'];
            }

            if( $upload_images['success_message'] != '' ) {
                $success_message .= $upload_images['success_message'];
            }

            if( $upload_images['error_message'] != '' ) {
                $error_message .= $upload_images['error_message'];
            }

        }

        // If button is pressed to "Import to DB"
        if (isset($_POST['import_button'])) {
            // If the "Select Input File" input field is empty
            if( empty($_POST['csv_file']) ) {
                $error_message .= '* '.__('No Input File was selected. Please enter an Input File.','stf_tar_csv_to_db').'<br />';
            }
            // Check that "Input File" has proper .csv file extension
            $ext = pathinfo($_POST['csv_file'], PATHINFO_EXTENSION);
            if( $ext !== 'csv' ) {
                $error_message .= '* '.__('The Input File does not contain the .csv file extension. Please choose a valid .csv file.','stf_tar_csv_to_db');
            }

            // If all fields are input; and file is correct .csv format; continue
            if( !empty($_POST['csv_file']) && ($ext === 'csv') ) {
                $csv_file = $_POST['csv_file'];
                // Get the number of columns from the hidden input field (re-auto-populated via jquery)
                $numColumns = $_POST['num_cols'];

                // Open the .csv file and get it's contents
                if(( $fh = @fopen($csv_file, 'r')) !== false) {
                    // Set variables
                    $profiles = [];
                    $too_many = '';  // Used to alert users if columns do not match
                    $row = 1;
                    $headers = false;

                    if (($handle = fopen($csv_file, "r")) !== false) {
                        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                            $line = [];
                            $num = count($data);
                            $row++;
                            if( !$headers ) {
                                $headers[] = $data;
                            } else {
                                for ($c=0; $c < $num; $c++) {
                                    $line[] = $data[$c];
                                }
                                $profiles[] = $line;
                            }
                        }

                        fclose($handle);
                    }

                    if( !empty($profiles) ) {
                        $insert_succ = 0;
                        $insert_fail = [];
                        $update_succ = 0;
                        $update_fail = [];
                        $attach_succ = 0;
                        $attach_del  = 0;

                        foreach( $profiles as $profile ) {

                            $account_id = $this->stf_get_import_val('Account ID', $profile, $headers[0]);
                            $tar_url_1 = $this->stf_get_import_val('TAR URL 1', $profile, $headers[0]);
                            $tar_url_2 = $this->stf_get_import_val('TAR URL 2', $profile, $headers[0]);
                            $vimeo_id = $this->stf_get_import_val('TAR Vimeo ID', $profile, $headers[0]);
                            $youtube_id = $this->stf_get_import_val('TAR YouTube ID', $profile, $headers[0]);
                            $soundcloud_id = $this->stf_get_import_val('TAR SoundCloud ID', $profile, $headers[0]);
                            $vimeo_video_id = ($vimeo_id != '') ? $vimeo_id : $this->stf_get_media_id([$tar_url_1, $tar_url_2], 'vimeo');
                            $youtube_video_id = ($youtube_id != '') ? $youtube_id : $this->stf_get_media_id([$tar_url_1, $tar_url_2], 'youtube');
                            $soundcloud_track_id = ($soundcloud_id != '') ? $soundcloud_id : $this->stf_get_media_id([$tar_url_1, $tar_url_2], 'soundcloud');
                            $conc_input = $this->stf_get_import_val('TAR Genre', $profile, $headers[0], 'array');
                            $tags_input = $this->stf_get_import_val('TAR Tags', $profile, $headers[0], 'array');
                            $post_title = $this->stf_get_import_val('TAR Name', $profile, $headers[0]);
                            $post_content = $this->stf_get_import_val('TAR Description', $profile, $headers[0]);
                            $negotiable = $this->stf_get_import_val('TAR Negotiable', $profile, $headers[0]);
                            $educ_prog = $this->stf_get_import_val('TAR Education Program', $profile, $headers[0]);
                            $existing_post = $this->stf_get_existing($account_id, $post_title);
                            $account_key = $this->stf_get_account_key($account_id);
                            $photo_credits = $this->stf_get_import_val('TAR Photo Credits', $profile, $headers[0]);
                            $custom_taxonomy = 'concentration';

                            $acf_fields = [
                                // Match ACF field keys with CSV column labels from SalesForce
                                // These field keys MUST match their respective ACF fields
                                'field_5cf97c553f889' => $account_id,
                                'field_58f84f9c4bf0a' => $vimeo_video_id,
                                'field_5cf97e57666c7' => $youtube_video_id,
                                'field_5cf97f68666cb' => $soundcloud_track_id,
                                'field_5cf97e6e666c8' => $tar_url_1,
                                'field_5cf97ead666c9' => $tar_url_2,
                                'field_5898ec36f07dd' => $this->stf_get_import_val('TAR Short Description', $profile, $headers[0]),
                                'field_58c6393d827a3' => $this->stf_get_import_val('TAR Reviews', $profile, $headers[0]),
                                'field_58c6437c89779' => $this->stf_get_import_val('TAR Booking Phone', $profile, $headers[0]),
                                'field_58c6439c8977a' => $this->stf_get_import_val('TAR Booking Email', $profile, $headers[0]),
                                'field_58c6393d82b26' => $this->stf_get_import_val('Website', $profile, $headers[0]),
                                'field_5cf97d89666c6' => $this->stf_get_import_val('TAR Discount', $profile, $headers[0]),
                                'field_50ab21b27c0b6' => $this->stf_imp_str_replace($negotiable, 'Negotiable', 'Not Negotiable'),
                                'field_5cf97d74666c5' => $this->stf_get_import_val('TAR Performance Fee', $profile, $headers[0]),
                                'field_591e1082e5f61' => $this->stf_imp_str_replace($educ_prog, 'Educational Program Available', 'No Educational Program Available'),
                                'field_5cf9f76635a60' => $this->stf_get_import_val('TAR Information Correct', $profile, $headers[0]),
                            ];

                            if( $existing_post != null ) {
                                $my_post = [
                                    'post_title' => $post_title ? $post_title : $existing_post->post_title,
                                    'post_content' => $post_content ? $post_content : $existing_post->post_content,
                                    'post_type' => 'touring_arts',
                                ];
                                $my_post['ID'] = $existing_post->ID;
                                $my_post['post_status'] = $existing_post->post_status;
                                $post_id = wp_update_post($my_post);

                                if( ($attached = get_the_post_thumbnail_url( $existing_post->ID )) !== false) {
                                    $attach_id = attachment_url_to_postid($attached);
                                }  else {
                                    $attach_id = null;
                                }

                                if( !is_wp_error($post_id) && ($post_id != 0) ) {
                                    // Update post meta
                                    foreach( $acf_fields as $acf_field => $value ) {
                                        if( $acf_field == ('field_5cf97e6e666c8' || 'field_5cf97ead666c9') ) {
                                            update_field( $acf_field, $value, $post_id );
                                        } elseif( $value != '' && !empty($value)  ) {
                                            update_field( $acf_field, $value, $post_id );
                                        }
                                    }

                                    if( $conc_input != '' ) {
                                        wp_set_object_terms( $my_post['ID'], $conc_input, $custom_taxonomy );
                                    }

                                    if( !empty($tags_input) ) {
                                        wp_set_post_tags( $my_post['ID'], $tags_input, true);
                                    }

                                    $update_succ ++;

                                    if ( $attach_images == true ) {
                                        if( ($this->stf_attach_image($account_key, $post_id, $photo_credits)) != false ) {
                                            $attach_succ ++;

                                            if( $del_img_after && $this->stf_delete_img($account_key) != false ) {
                                                $attach_del ++;
                                            }
                                        }
                                    } elseif( '' != $photo_credits && null != $attach_id ) {
                                        $this->stf_set_image_caption($photo_credits, $attach_id);
                                    }

                                } else {
                                    $update_fail[] = $account_id;
                                }

                            } else {
                                $my_post = [
                                    'post_title' => $post_title ? $post_title : '',
                                    'post_content' => $post_content ? $post_content : '',
                                    'post_type' => 'touring_arts',
                                ];
                                $my_post['post_status'] = $new_post_status;
                                $post_id = wp_insert_post( $my_post ); // Insert post

                                if( !is_wp_error($post_id) && $post_id != 0 ) {
                                    // Update post meta
                                    foreach( $acf_fields as $acf_field => $value ) {
                                        if( $value != '' && !empty($value) ) {
                                            update_field( $acf_field, $value, $post_id );
                                        }
                                    }

                                    if( $conc_input != '' ) {
                                        wp_set_object_terms( $post_id, $conc_input, $custom_taxonomy );
                                    }

                                    if( !empty($tags_input) ) {
                                        wp_set_post_tags( $my_post['ID'], $tags_input, true);
                                    }

                                    $insert_succ ++;

                                    if ( $attach_images == true ) {
                                        if( ($this->stf_attach_image($account_key, $post_id, $photo_credits)) != false ) {
                                            $attach_succ ++;

                                            if( $del_img_after && $this->stf_delete_img($account_key) != false ) {
                                                $attach_del ++;
                                            }
                                        }
                                    }

                                } else {
                                    if( $account_id && $post_title ) {
                                        $insert_fail[] = $account_id.'-'.$post_title;
                                    } elseif( $account_id && !$post_title ) {
                                        $insert_fail[] = $account_id;
                                    } elseif( !$account_id && $post_title ) {
                                         $insert_fail[] = $post_title;
                                    }
                                }
                            }
                        }

                        $update_fail_num = count($update_fail);
                        $insert_fail_num = count($insert_fail);

                        if( $update_fail_num > 0 ) {
                            $this->stf_update_import_status('failed_update', $update_fail);
                        }

                        if( $insert_fail_num > 0) {
                            $this->stf_update_import_status('failed_insert', $insert_fail);
                        }

                        if ( $update_succ > 0 || $insert_succ > 0 ) {
                            if( $update_succ > 0 ) {
                                $success_message .= '* '.__($update_succ . ' EXISTING WP record(s) updated successfully.','stf_tar_csv_to_db');
                            }

                            if( $insert_succ > 0 ) {
                                $success_message .='<br /> * '.__($insert_succ . ' NEW SF record(s) inserted successfully.','stf_tar_csv_to_db');
                            }
                        }

                        if ( $update_fail_num > 0 || $insert_fail_num > 0 ) {
                            if( $update_fail_num > 0 ) {
                                $error_message .= '* '.__($update_fail_num . ' record(s) failed the *update* operation.','stf_tar_csv_to_db');
                            }

                            if( $insert_fail_num > 0 ) {
                                $error_message .='<br />* '. __($insert_fail_num . ' record(s) failed the *insert* operation.','stf_tar_csv_to_db');
                            }
                        }

                        if ( $attach_images == true ) {
                            if ( $attach_succ > 0 ) {
                                $success_message .= '<br />* '. __($attach_succ . ' matching image(s) found in the TAR Images directory & assigned.','stf_tar_csv_to_db');
                            }
                        }

                        if ( $attach_images == true ) {
                            if ( $del_img_after && $attach_del > 0 ) {
                                $success_message .= '<br />* '. __($attach_del . ' matching image(s) in the TAR Images directory were deleted after successful attachment.','stf_tar_csv_to_db');
                            }
                        }
                    }
                }
                else {
                    $error_message .= '* '.__('No valid .csv file was found at the specified url. Please check the "Select Input File" field and ensure it points to a valid .csv file.','stf_tar_csv_to_db').'<br />';
                }
            }
        }

        // If there is a message - info-style
        if(!empty($message_info_style)) {
            echo '<div class="info_message_dismiss">';
            echo $message_info_style;
            echo '<br /><em>('.__('click to dismiss','stf_tar_csv_to_db').')</em>';
            echo '</div>';
        }

        // If there is an error message
        if(!empty($error_message)) {
            echo '<div class="error_message">';
            echo $error_message;
            echo '<br /><em>('.__('click to dismiss','stf_tar_csv_to_db').')</em>';
            echo '</div>';
        }

        // If there is a success message
        if(!empty($success_message)) {
            echo '<div class="success_message">';
            echo $success_message;
            echo '<br /><em>('.__('click to dismiss','stf_tar_csv_to_db').')</em>';
            echo '</div>';
        }
    ?>

        <div class="wrap">

            <h2><?php _e('4Culture Touring Arts Roster - CSV Import/Export','stf_tar_csv_to_db'); ?></h2>
            <p>This plugin allows you to import/export TAR profile data between SalesForce & WordPress in CSV file format.</p>
            <p><b>Uploading a new CSV will create new touring_arts posts and update existing ones with new information</b></p>
            <p><b>IMPORTANT: </b> Please backup the entire site before running any of these operations.</p>


            <div id="tabs">
                <ul>
                    <li><a href="#tabs-1" class="tab-link"><?php _e('Upload TAR Images','stf_tar_csv_to_db'); ?></a></li>
                    <li><a href="#tabs-2" class="tab-link"><?php _e('Import SF to WP','stf_tar_csv_to_db'); ?></a></li>
                    <li><a href="#tabs-3" class="tab-link"><?php _e('Export WP to SF','stf_tar_csv_to_db'); ?></a></li>
                    <li><a href="#tabs-4" class="tab-link"><?php _e('Notes','stf_tar_csv_to_db'); ?></a></li>
                    <li><a href="#tabs-5" class="tab-link"><?php _e('Reports','stf_tar_csv_to_db'); ?></a></li>

                </ul>

                <div id="tabs-1" class="tab">
                    <form id="stf_tar_csv_images_form" method="post" action="" enctype="multipart/form-data">
                        <table class="form-table">

                            <tr valign="top"><th scope="row"><?php _e('Upload Images:','stf_tar_csv_to_db'); ?></th>
                                <td>
                                    <input type="file" name="multi_file_upload[]" id="multi_file_upload" multiple>

                                    <br><?php _e('Upload images downloaded from SalesForce into "wp-content/uploads/tar-csv-import/".','stf_tar_csv_to_db'); ?>
                                </td>
                            </tr>
                            <tr valign="top"><th scope="row"><?php _e('Overwrite Existing:','stf_tar_csv_to_db'); ?></th>
                                <td>
                                    <input type="checkbox" name="overwrite_img" id="overwrite_img">

                                    <br><?php _e('If existing file found, overwrite it.','stf_tar_csv_to_db'); ?>
                                </td>
                            </tr>

                        </table>

                        <p class="submit">
                            <input id="upload_button" name="upload_button" type="submit" class="button-primary" value="<?php _e('Upload Images', 'stf_tar_csv_to_db') ?>" />
                        </p>
                    </form>
                </div> <!-- End tab 1 -->

                <div id="tabs-2" class="tab">

                    <form id="stf_tar_csv_to_db_form" method="post" action="">
                        <table class="form-table">

                            <tr valign="top"><th scope="row"><?php _e('Select Input File:','stf_tar_csv_to_db'); ?></th>
                                <td>
                                    <?php $repop_file = isset($_POST['csv_file']) ? $_POST['csv_file'] : null; ?>
                                    <?php $repop_csv_cols = isset($_POST['num_cols_csv_file']) ? $_POST['num_cols_csv_file'] : '0'; ?>
                                    <input id="csv_file" name="csv_file"  type="text" size="70" value="<?php echo $repop_file; ?>" />
                                    <input id="csv_file_button" type="button" value="Upload" />
                                    <input id="num_cols" name="num_cols" type="hidden" value="" />
                                    <input id="num_cols_csv_file" name="num_cols_csv_file" type="hidden" value="" />
                                    <br><?php _e('File must end with a .csv extension.','stf_tar_csv_to_db'); ?>
                                    <br><?php _e('Number of .csv file Columns:','stf_tar_csv_to_db'); echo ' '; ?><span id="return_csv_col_count"><?php echo $repop_csv_cols; ?></span>
                                </td>
                            </tr>
                            <tr valign="top"><th scope="row"><?php _e('Attach Images:','stf_tar_csv_to_db'); ?></th>
                                <td>
                                    <input id="attach_images" name="attach_images" type="checkbox" />
                                    <br><?php _e('If checked, the import tool will attempt to find and assign matching image(s) from the "wp-content/uploads/tar-csv-import/".','stf_tar_csv_to_db'); ?>
                                </td>

                            </tr>
                            <tr valign="top"><th scope="row"><?php _e('Delete Image After:','stf_tar_csv_to_db'); ?></th>
                                <td>
                                    <input id="del_img_after" name="del_img_after" type="checkbox" />
                                    <br><?php _e('If checked, every matching image found in "wp-content/uploads/tar-csv-import/" will be deleted upon successful attachment.','stf_tar_csv_to_db'); ?>
                                </td>
                            </tr>
                            <tr valign="top"><th scope="row"><?php _e('New Profile Status:','stf_tar_csv_to_db'); ?></th>
                                <td>
                                    <label>
                                        <input id="post_status_draft" name="new_post_status" type="radio" value="draft" checked="checked" /> Draft</label>
                                    <label>
                                        <input id="post_status_pub" name="new_post_status" type="radio" value="publish" /> Publish</label>
                                    <br><?php _e('Set all <strong> newly-created </strong>profiles to this status.','stf_tar_csv_to_db'); ?>
                                </td>
                            </tr>
                            <tr valign="top"><th scope="row"><?php _e('Find Existing Posts By:','stf_tar_csv_to_db'); ?></th>
                                <td>
                                    <label>
                                        <input id="account_id" name="find_post_by" type="radio" value="account_id" checked="checked" /> Account ID</label>
                                    <label>
                                        <input id="post_title" name="find_post_by" type="radio" value="post_title" /> Post Title</label>
                                    <br><?php _e('Identify existing posts by this value.','stf_tar_csv_to_db'); ?>
                                </td>
                            </tr>

                        </table>

                        <p class="submit">
                            <input id="import_button" name="import_button" type="submit" class="button-primary" value="<?php _e('Import to DB', 'stf_tar_csv_to_db') ?>" />
                        </p>
                    </form>
                </div> <!-- End tab 2 -->

                <div id="tabs-3" class="tab">

                    <form id="stf_tar_db_to_csv_form" method="post" action="">
                        <table class="form-table">

                            <tr valign="top">
                                <th scope="row">
                                    <p class="submit">
                                        <input id="export_button" name="export_button" type="submit" class="button-primary" value="<?php _e('Export Current DB', 'stf_tar_csv_to_db') ?>" />
                                    </p>
                                </th>
                                <td>
                                    <br><?php _e('Generate a CSV file from the TAR profiles in the WordPress Database.'); ?>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div> <!-- End tab 3 -->

                <div id="tabs-4" class="tab">
                    <?php _e('Action 1 (Upload TAR Images):','stf_tar_csv_to_db'); ?>
                    <ul>
                        <li><?php _e('You can upload images from SalesForce under the "Upload TAR Images Tab"','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('The directory (wp-content/uploads/tar-csv-import/) is created automatically when this plugin is first activated.','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('If it is accidentally deleted, you can create it again, manually.','stf_tar_csv_to_db'); ?></li>
                    </ul>
                    <br /><br />
                    <?php _e('Action 2 (Select Input File):','stf_tar_csv_to_db'); ?>
                    <ul>
                        <li><?php _e('The option will be used to locate the file to be used for execution.','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('A direct url to a .csv file may be entered into the text field.','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('Alternatively, the "Upload" button may be used to initiate the WordPress uploader and manager.','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('From here, the file can be uploaded from a computer or selected from the media library.','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('The "Number of .csv file Columns" will populate when the Input File field contains a valid .csv file.','stf_tar_csv_to_db'); ?></li>
                    </ul>
                    <br /><br />
                    <?php _e('Action 3 (Attach Images):','stf_tar_csv_to_db'); ?>
                    <ul>
                        <li><?php _e('Image matching will be done by comparing the 6 unique characters in image names with TAR Account IDs.','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('Example: an image named "1KDDir_01.jpg" will be set as the Featured Image for a profile with Account ID "0017000001KDDir" - if both exist.','stf_tar_csv_to_db'); ?></li>
                    </ul>
                    <br /><br />
                    <?php _e('Action 4 (Delete Image After):','stf_tar_csv_to_db'); ?>
                    <ul>
                        <li><?php _e('The TAR images directory serves a temporary holding location for SF images.','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('Deleting an image after it\'s been attached to a post keeps things clean.','stf_tar_csv_to_db'); ?></li>
                    </ul>
                    <br /><br />
                    <?php _e('Action 5 (Post Status):','stf_tar_csv_to_db'); ?>
                    <ul>
                        <li><?php _e('"Draft", if you want to review new profiles before publishing. "Publish" if you want all new profiles to become visible immediately.','stf_tar_csv_to_db'); ?></li>
                    </ul>
                    <br /><br />
                    <?php _e('Action 6 (Export WP to SF):','stf_tar_csv_to_db'); ?>
                    <ul>
                        <li><?php _e('Use this tool to backup current TAR records in the 4Culture DB.','stf_tar_csv_to_db'); ?></li>
                        <li><?php _e('Useful for importing the most up-to-date profile content into SalesForce','stf_tar_csv_to_db'); ?></li>
                    </ul>
                    <br /><br />
                </div> <!-- End tab 4 -->
                <div id="tabs-5" class="tab">
                    <p class="submit">
                        <input id="refresh_button" name="refresh_button" type="submit" class="refresh_button button-primary" value="<?php _e('Refresh Reports', 'stf_tar_csv_to_db') ?>"/>
                    </p>
                    </br></br>
                    <div class="details">

                        <?php if( empty($this->reports) ): ?>

                            <p>Nothing to show.</p>

                        <?php else: ?>

                            <?php foreach($this->reports as $heading => $rows ): ?>
                                <table class="reports-tb">
                                <tr>
                                    <th><?php echo $heading. ' (' . count($rows) . ')'; ?></th>
                                </tr>

                                <?php foreach($rows as $row): ?>
                                    <tr>
                                        <td><?php echo $row; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </table>
                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>
                    <br /><br />
                    <p class="submit">
                        <input id="refresh_button" name="refresh_button" type="submit" class="refresh_button button-primary" value="<?php _e('Refresh Reports', 'stf_tar_csv_to_db') ?>"/>
                    </p>
                </div> <!-- End tab 5 -->

            </div> <!-- End #tabs -->
        </div> <!-- End page wrap -->
        <!-- Alert invalid .csv file - jquery dialog -->
        <div id="dialog_csv_file" title="<?php _e('Invalid File Extension','stf_tar_csv_to_db'); ?>" style="display:none;">
            <p><?php _e('This is not a valid .csv file extension.','stf_tar_csv_to_db'); ?></p>
        </div>
        <?php
    }
}

$stf_tar_csv_to_db = new stf_tar_csv_to_db();
