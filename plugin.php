<?php
/*
Plugin Name: qTranslate Importer
Plugin URI: http://wpml.org/documentation/related-projects/qtranslate-importer/
Description: Imports qTranslate content to WPML, or just cleans up qTranslate meta tags
Version: 0.2
Author: OntheGoSystems
Author URI: http://wpml.org
Tags: #
*/
  

class QT_Importer{
    
    var $default_language;
    var $active_languages;
    var $url_mode;
    const BATCH_SIZE = 10;
        
    function __construct(){
        
        $this->default_language = get_option('qtranslate_default_language');
        $this->active_languages = get_option('qtranslate_enabled_languages');
        $this->url_mode         = get_option('qtranslate_url_mode');
        
        add_action('init', array($this, 'init'), 100);
        add_action('admin_menu', array($this, 'menu_setup'));
        
        add_action('wp_ajax_qt_import_ajx', array($this, 'import_ajx'));
        add_action('wp_ajax_qt_fix_links_ajx', array($this, 'fix_links_ajx'));
        add_action('wp_ajax_qt_clean_ajx', array($this, 'clean_ajx'));
        
        add_action('wp_ajax_qt_verify_htaccess', array($this, 'verify_htaccess_ajx'));
        
        add_filter('contextual_help', array($this, 'help'), 10, 3);
                
    }
    
    function init(){
        
        load_plugin_textdomain( 'qt-import', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
        
        if(isset($_POST['qt_download']) && $_POST['qt_download'] == wp_create_nonce('qt_download_redirects')){
            $this->php_redirects();
        }
        
        
        wp_enqueue_script('qtimport', plugins_url(basename(dirname(__FILE__))) . '/scripts.js');
        
    }
    
    function _set_progress($step, $value){
        $qtimport_status = get_option('_qt_import_status');
        $qtimport_status[$step] = $value;
        update_option('_qt_import_status', $qtimport_status);
    }
    
    function import_ajx(){
        global $wpdb;

        if(empty($_POST['qt_keepgoing'])){
            
            $qtimport_status = get_option('_qt_import_status');
            if(empty($qtimport_status['settings'])){
                $response['messages'][] = __('Copying settings to WPML.', 'qt-import');
                $this->map_wpml_settings();
                $this->_set_progress('settings', 1);
            }
            
            if(empty($qtimport_status['terms'])){
                $response['messages'][] = __('Importing terms.', 'qt-import');
                $this->_import_terms();
                $this->_set_progress('terms', 1);
            }
            
        }
        
        $response['messages'][] = __('Looking for previously imported posts.', 'qt-import');        
        //get posts 
        $processed_posts = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id 
            WHERE meta_key = '_qt_imported' AND p.post_type NOT IN ('attachment', 'nav_menu_item', 'revision')");
        $where = '';
        if($processed_posts){
            $where = " ID NOT IN(" . join(',' , $processed_posts) . ") AND ";
        }
        $posts = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts} p 
            WHERE {$where} post_title LIKE '<!--:%' AND p.post_type NOT IN ('attachment', 'nav_menu_item', 'revision')
            LIMIT " . self::BATCH_SIZE . "
        ");
        
        if($posts){
            $qt_import_batch = isset($_POST['qt_import_batch']) ? $_POST['qt_import_batch'] : 1;
            $response['messages'][] = sprintf(__('Importing posts batch #%d.', 'qt-import'), $qt_import_batch);        
            foreach($posts as $post_id){
                $this->process_post($post_id);
                $processed_posts[] = $post_id;
            }
            $response['messages'][] = sprintf(__('Finished import batch #%d. Imported %d posts.', 'qt-import'), $qt_import_batch, self::BATCH_SIZE);
        
            // Are there more?        
            $posts = $wpdb->get_col("
                SELECT ID FROM {$wpdb->posts} p 
                WHERE ID NOT IN(" . join(',' , $processed_posts) . ") AND post_title LIKE '<!--:%' AND p.post_type NOT IN ('attachment', 'nav_menu_item', 'revision')
                LIMIT 1
            ");
            if($posts){
                $response['messages'][] = __('Preparing next batch.', 'qt-import');        
                $response['keepgoing'] = 1;    
            }else{
                
                $this->_set_progress('posts', 1);
                
                $this->fix_hierarchy();
                
                $this->_set_progress('hierarchy', 1);
                
                $response['keepgoing'] = 0;
                //$response['keepgoing'] = 1;
                $response['messages'][] = __('Start fixing links.', 'qt-import');        
                //$response['next_operation'] = 'fix-links';
            }
        }else{
            $response['messages'][] = __('No posts to import.', 'qt-import');        
            $response['keepgoing'] = 0;
        }
        
        $response['messages'][] = '****************************************<br />';
        
       echo json_encode($response);
       exit; 
        
    }
    
    function fix_links_ajx(){
        global $wpdb;
        //get posts 
        $posts = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_qt_links_fixed'
            WHERE p.post_type NOT IN ('attachment', 'nav_menu_item', 'revision') AND m.meta_value IS NULL
            LIMIT " . self::BATCH_SIZE . "
        ");
        
        if($posts){
            $qt_lfix_batch = isset($_POST['qt_lfix_batch']) ? $_POST['qt_lfix_batch'] : 1;
            $response['messages'][] = sprintf(__('Fixing links: batch #%d.', 'qt-import'), $qt_lfix_batch);        
            foreach($posts as $post_id){
                $this->fix_links($post_id);
            }
            $response['messages'][] = sprintf(__('Finished links fixing batch #%d. Processed %d posts.', 'qt-import'), $qt_lfix_batch, self::BATCH_SIZE);
        
            // Are there more?        
            $posts = $wpdb->get_col("
                SELECT ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_qt_links_fixed'
                WHERE p.post_type NOT IN ('attachment', 'nav_menu_item', 'revision') AND m.meta_value IS NULL
                LIMIT 1
            ");
            if($posts){
                $response['messages'][] = __('Preparing next batch.', 'qt-import');        
                $response['keepgoing'] = 1;    
            }else{
                $response['keepgoing'] = 0;
                $response['messages'][] = __('Finished fixing links.', 'qt-import');        
                $this->_set_progress('links', 1);
                $response['redirects'] = $this->dump_redirects();
                $this->_set_progress('redirects', 1);
                $this->_set_progress('ALL_FINISHED', 1);
                
                
                
            }
        }else{
            $response['messages'][] = __('No posts need links to be fixed.', 'qt-import');        
            $response['keepgoing'] = 0;
        }
        
        $response['messages'][] = '****************************************<br />';
        
       echo json_encode($response);
       exit;         
        
    }
    
    function _import_terms(){
        global $wpdb, $sitepress;
        
        $term_translations = get_option('qtranslate_term_name');
        
        foreach($term_translations as $term){
            
            $default_term = $term[$this->default_language];
            // get term id
            $term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->terms} WHERE name = %s", $default_term));
            
            if(empty($term_id)) continue;
            
            
            // get all taxonomies
            $taxonomies = $wpdb->get_results($wpdb->prepare("SELECT term_taxonomy_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d ", $term_id));
            
            //printf("Processing %s (%d) <br />", $default_term, $term_id);
            //printf("Taxonomies %s<br />", print_r($taxonomies, 1));
            
            foreach($taxonomies as $taxonomy){
                
                if($taxonomy->taxonomy != 'post_tag' && $taxonomy->taxonomy != 'category' &&
                     !$sitepress->is_translated_taxonomy($taxonomy->taxonomy)) continue;
                
                //printf("&nbsp;Processing taxonomy  %s<br />", $taxonomy->taxonomy);
                
                $sitepress->set_element_language_details($taxonomy->term_taxonomy_id, 'tax_' . $taxonomy->taxonomy, null, $this->default_language);
                // get its trid
                $trid = $sitepress->get_element_trid($taxonomy->term_taxonomy_id, 'tax_' . $taxonomy->taxonomy);    
                
                
                //printf("&nbsp;Set trid %d<br />", $trid);
                
                foreach($this->active_languages as $lang){
                    
                    if(($lang != $this->default_language) && isset($term[$lang])){
                        
                        //printf("&nbsp;Adding %s translation<br />", $lang);    
                        if(icl_object_id($term_id, $taxonomy->taxonomy, false, $lang)) continue;
                        
                        $translation = $term[$lang];
                        $_POST['icl_trid'] = $trid;
                        $_POST['icl_tax_'.$taxonomy->taxonomy.'_language'] = $lang;
                        $tmp = wp_insert_term($translation, $taxonomy->taxonomy);                   
                        //printf("&nbsp;Added translation %s<br />", print_r($tmp, 1));
                        unset($_POST['icl_trid'], $_POST['icl_tax_'.$taxonomy->taxonomy.'_language']);
                    }
                }
                
            }
            
            //printf("<hr/>");
            
        }
        
        //adjust terms hierarchy
        $taxonomies = $wpdb->get_results("
            SELECT x.term_id, x.term_taxonomy_id, x.taxonomy, x.parent 
            FROM {$wpdb->term_taxonomy} x
            JOIN {$wpdb->prefix}icl_translations t ON x.term_taxonomy_id = t.element_id 
                WHERE t.element_type LIKE 'tax\\_%'
                    AND t.language_code = '" . $this->_lang_map($this->default_language) . "'
                    AND x.parent > 0
            ");
        
        foreach($taxonomies as $tax){
            foreach($this->active_languages as $lang){
                if($lang != $this->default_language){
                    
                    $trid = $sitepress->get_element_trid($tax->term_taxonomy_id, 'tax_' . $tax->taxonomy);
                    
                    if(empty($trid)){
                        $sitepress->set_element_language_details($tax->term_taxonomy_id, 'tax' . $tax->taxonomy, null, $this->_lang_map($this->default_language));
                        continue;
                    }
                    
                    $trans_id = icl_object_id($tax->term_id, $tax->taxonomy, false, $lang);
                    
                    if($trans_id){
                        $trans_parent = icl_object_id($tax->parent, $tax->taxonomy, false, $lang);
                        
                        if($trans_parent){
                            $wpdb->update($wpdb->term_taxonomy, array('parent' => $trans_parent), array('term_id' => $trans_id, 'taxonomy' => $tax->taxonomy));
                        }
                    }
                }
            }
        }
        
        $distinct_taxonomies = $wpdb->get_col("SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}");
        foreach($distinct_taxonomies as $tax){
            delete_option($tax . '_children');
        }
        
        
        
    }
        
    function menu_setup(){
        add_options_page(__('qTranslate Importer', 'qt-import'), __('qTranslate Importer', 'qt-import'), 'manage_options', 'qt-import', array($this, 'menu'));    
    }
    
    function menu(){
        ?>
        <div class="wrap">
            <div id="icon-tools" class="icon32"><br /></div>
            <h2><?php echo __('qTranslate Importer', 'qt-import') ?></h2>    

            <?php 
                $language_names = get_option('qtranslate_language_names');
                if(empty($language_names)){
                    ?>
                    <p class="error"><?php _e('Please save the qTranslate settings to the datbase first.', 'qt-import') ?></p><?php 
                    return;
                }
                foreach($this->active_languages as $code){
                    $active_languages[$code] = $language_names[$code];
                }
                unset($language_names);
            ?>
            
            <?php if(!defined('ICL_SITEPRESS_VERSION') || version_compare(ICL_SITEPRESS_VERSION, '2.0.5', '<')): ?>
                
                <?php if(get_option('_qt_importer_clean_has_run')): ?>
                
                    <div class="updated">
                        <p><?php _e('The clean up script has already run.', 'qt-import'); ?></p>
                    </div>    
                
                <?php else: ?>
                    <p class="error"><?php _e('WPML is not enabled on this site, so only one language can remain. Please choose which language to keep.', 'qt-import') ?></p>
                    
                    <form id="qt_clean_form" method="post">
                    <?php wp_nonce_field('qt_clean', 'qt_do_clean'); ?>
                    <p>
                        <?php _e('Keep this language:', 'qt-import'); ?>
                        <select name="language_keep">
                            <option value=""><?php _e('-- select --', 'qt-import'); ?></option>
                            <?php foreach($this->active_languages as $lang): ?>
                            <option value="<?php echo $lang ?>"><?php echo $active_languages[$lang] ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                    </p>
                    
                    <span id="qt_clean_confirm" style="display: none;">
                    <p><?php _e('IMPORTANT: ALL OTHER LANGUAGES WILL BE DELETED PERMANENTLY', 'qt-import') ?></p>
                    <p>
                        <label><input type="checkbox" name="confirm_delete" id="confirm_delete" value="1" />&nbsp;<?php 
                            printf(__("I understand that I'm about to delete all content in %s", 'qt-import'), 
                                '<span id="qt_language_removed"><i>['.__('select', 'qt-import').']</i></span>')?></label>
                    </p>
                    <p>     
                        <label><input type="checkbox" name="confirm_keep" id="confirm_keep" value="1" />&nbsp;<?php 
                            printf(__("I understand that this process will process all the content in my site and convert it from using qTranslate to single-language. Clear languages and leave only %s", 'qt-import'), '<span id="qt_language_kept"><i>['.__('select', 'qt-import').']</i></span>') ?></label>                        
                    </p>
                    
                    <p class="submit">
                        <input id="qt_clean_start" type="submit" class="button-primary" value="<?php _e('Clean', 'qt-import') ?>" disabled="disabled" />

                    &nbsp;<span id="qt_clean_working" style="display:none;"><?php _e('Working...', 'qt-import') ?></span>
                    <div id="qt_clean_status" style="max-height:360px;overflow: auto;font-size:10px;background-color: #eee;padding:5px;border:1px solid #ddd; margin-bottom: 5px;display:none;;"></div>
                        
                    </p>
                    
                    </span>
                    
                    </form>
                <?php endif; ?>
                
                <p><?php _e("If you're interested in keeping all languages, in a multilingual site, you'll need WPML. You can get it from here: <a href=\"http://wpml.org/purchase/\">WPML</a>", 'qt-import');?></p>
                
            <?php else: ?>            
                
                
                <p><?php _e('What is imported:', 'qt-import') ?></p>
                <ul style="list-style: disc;margin-left: 20px;">
                    <li><?php _e('Pages, posts, all the other custom post type and teh custom fields.', 'qt-import')?></li>
                    <li><?php _e('Tags, categories and the custom taxonomies.', 'qt-import')?></li>
                    <li><?php _e('qTranslate settings', 'qt-import')?></li>
                </ul>
                <p>
                    <?php _e('The links to the translated versions of the posts will change according to the new posts that will be created. Existing links inside posts will be fixed and, also, the last step of the import process will generate a list of permanent redirects in order not to break existing links in.', 'qt-import') ?>
                </p>
                <p>
                <?php printf(__('The following languages will be imported: %s', 'qt-import'), '<strong>' . join('</strong>, <strong>', $active_languages) . '</strong>'); ?>. 
                <?php printf(__('%s will be set as the default language.', 'qt-import'), '<strong>' . $active_languages[$this->default_language] . '</strong>'); ?>
                </p>

                <?php $qtimport_status = get_option('_qt_import_status'); ?>
                
                <?php if(!empty($qtimport_status) && empty($qtimport_status['ALL_FINISHED'])): ?>
                <?php $steps = array('settings' => __('Settings', 'qt-import'), 'terms' => __('Terms', 'qt-import'), 'posts' => __('Posts', 'qt-import'), 
                'hierarchy' => __('Hierarchy', 'qt-import'), 'links' => __('Links', 'qt-import'), 'redirects' => __('Redirects', 'qt-import')); ?>
                <div class="updated">
                <p><?php _e('The import process will be resumed. Things left to be imported:', 'qt-import')?></p>                
                <ul style="list-style: disc;margin-left: 20px;">
                    <?php foreach($steps as $step => $Step): if(empty($qtimport_status[$step])): ?>
                        <li><?php echo $Step ?></li>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                </div>
                <?php elseif(!empty($qtimport_status['ALL_FINISHED'])): ?>
                    <div class="updated">
                        <p><?php _e('The import script has already run.', 'qt-import'); ?></p>
                    </div>    
                <?php endif; ?>
                
                <?php if(empty($qtimport_status['ALL_FINISHED'])): ?>
                <p>     
                    <label><input type="checkbox" name="confirm_import" id="confirm_import" value="1" />&nbsp;
                        <?php _e('I understand that this process will process all the content in my site and convert it from using qTranslate to WPML' , 'qt-import'); ?></label>                         </p>
                <p>     
                    <label><input type="checkbox" name="confirm_dbbk" id="confirm_dbbk" value="1" />&nbsp;
                        <?php _e('I have created backup for my database' , 'qt-import'); ?></label>                         
                </p>
                <?php endif; ?>
                
                <input type="button" id="qt_import_start" value="<?php esc_attr_e('Start', 'qt-import') ?>" class="button-primary" disabled="disabled" />
                &nbsp;<span id="qt_import_working" style="display:none;"><?php _e('Working...', 'qt-import') ?></span>

                <div id="qt_import_status" style="max-height:360px;overflow: auto;font-size:10px;background-color: #eee;padding:5px;border:1px solid #ddd;margin-bottom:8px;display:none;"></div>                
                
                <div id="qt_import_redirects" <?php if(empty($qtimport_status['ALL_FINISHED'])): ?>style="display: none;"<?php endif; ?>>
                    <p><?php _e('Import completed, you should add rewrite rules to redirect incoming links to their new URLs.', 'qt-import')?></p>
                    <p><strong><?php _e('Option 1) Add rewrite rules to the .htaccess file', 'qt-import') ?></strong></p>
                    <p><?php _e("Copy the content of this box and add it to the .htaccess file at the root of your site's directory.", 'qt-import'); ?></p>
                    <textarea style="width:100%;height:260px;font-size:10px;background-color: #eee;padding:5px;border:1px solid #ddd; margin-bottom: 5px;"><?php echo $this->dump_redirects() ?></textarea><br />
                    <input id="qt_verify_htaccess" type="button" class="button-secondary" value="<?php _e('Verify my .htaccess file', 'qt-import')?>"/>
                    <span id="qt_verify_htaccess_yes" style="color:#677835; display: none;"><?php _e('Found!', 'qt-import') ?></span>
                    <span id="qt_verify_htaccess_no" style="color:#F01322; display: none;"><?php _e('Not found!', 'qt-import') ?></span>
                    
                    <p><strong><?php _e('Option 2) Use a PHP file with rewrite rules and add it to your theme.', 'qt-import') ?></strong></p>
                    <p><?php _e("Download a PHP file that includes a set of URL rewrite rules. You'll need to upload this file to your theme and include it from your theme's functions.php file.", 'qt-import'); ?></p>
                    <form method="post">
                    <?php wp_nonce_field('qt_download_redirects', 'qt_download')?>
                    <input id="qt_download_redirects" type="submit" class="button-secondary" value="<?php _e('Download PHP redirects file', 'qt-import')?>"/>
                    </form>
                    
                </div>
            
            <?php endif; ?>
            
            <br /><br /><br />
            <hr />
            <p><a href="http://wpml.org/?page_id=49021"><?php _e('qTranslate Importer Guide', 'qt-import')?></a></p>
            
        </div>        
        <?php
    }
    
    function help($contextual_help, $screen_id, $screen){
        if ($screen_id == 'settings_page_qt-import') {
            $contextual_help = '<a href="http://wpml.org/?page_id=49021">' . __('qTranslate Importer Guide', 'qt-import') .'</a>';
        }
        return $contextual_help;        
    }
    
    function _lang_map($code){
        switch($code){
            case 'zh': $code = 'zh-hans'; break;
        }
        
        return $code;
    }
    
    function clean_ajx(){
        global $wpdb;
        
        if(get_option('_qt_importer_clean_has_run')) return;
        
        //get posts 
        $processed_posts = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_qt_cleaned'");
        $where = '';
        if($processed_posts){
            $where = " ID NOT IN(" . join(',' , $processed_posts) . ") AND ";
        }
        $posts = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts} p 
            WHERE {$where} post_title LIKE '<!--:%' AND p.post_type NOT IN ('attachment', 'nav_menu_item', 'revision')
            LIMIT " . self::BATCH_SIZE . "
        ");
        
        $qt_clean_batch = isset($_POST['qt_clean_batch']) ? $_POST['qt_clean_batch'] : 1;
        
        if($posts){            
            $response['messages'][] = sprintf(__('Cleaning posts batch #%d.', 'qt-import'), $qt_clean_batch);        
            foreach($posts as $post_id){
                $this->clean_post($post_id, $_POST['lang']);
                $processed_posts[] = $post_id;
            }
            $response['messages'][] = sprintf(__('Finished clean batch #%d. Posts processed: %d.', 'qt-import'), $qt_clean_batch, self::BATCH_SIZE);
        
            // Are there more?        
            $posts = $wpdb->get_col("
                SELECT ID FROM {$wpdb->posts} p 
                WHERE ID NOT IN(" . join(',' , $processed_posts) . ") AND post_title LIKE '<!--:%'
                LIMIT 1
            ");
            if($posts){
                $response['messages'][] = __('Preparing next batch.', 'qt-import');        
                $response['keepgoing'] = 1;    
            }else{                
                $response['keepgoing'] = 0;
            }
        }else{
            if($qt_clean_batch > 1){
                $response['messages'][] = __('Finished.', 'qt-import');        
                update_option('_qt_importer_clean_has_run', 1);
            }else{
                $response['messages'][] = __('No posts to clean.', 'qt-import');        
            }
            
            $response['keepgoing'] = 0;
        }
        
        $response['messages'][] = '****************************************<br />';
        
       echo json_encode($response);
       exit; 
        
    }    
    
    function clean_all(){
        global $wpdb;
        
        
    }
    
    function clean_post($post_id, $language){
        global $wpdb;
        
        if(get_post_meta($post_id, '_qt_cleaned', true)) return;
        
        $post = get_post($post_id, ARRAY_A);
        
        if($post){
            $exp = explode('<!--:-->', $post['post_title']);
            foreach($exp as $e){
                if(trim($e)){
                    $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                    if($int){
                        $lang = $matches[1]; 
                        $langs[$lang]['title'] = $matches[2];
                    }
                }
            }
            
            $exp = explode('<!--more-->', $post['post_content']);
            $pc1 = $exp[0];
            $pc2 = isset($exp[1]) ?  $exp[1] : '';
            
            $exp = explode('<!--:-->', $pc1);
            foreach($exp as $e){
                if(trim($e)){
                    $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                    if($int){
                        $lang = $matches[1]; 
                        $langs[$lang]['content'] = $matches[2];
                    }
                }
            } 
            if($pc2){
                $exp = explode('<!--:-->', $pc2);
                foreach($exp as $e){
                    if(trim($e)){
                        $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                        if($int){
                            $lang = $matches[1]; 
                            $langs[$lang]['content'] .= '<!--more-->' . $matches[2];
                        }
                    }
                } 
            }
            
                   
            $exp = explode('<!--:-->', $post['post_excerpt']);
            foreach($exp as $e){
                if(trim($e)){
                    $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                    if($int){
                        $lang = $matches[1]; 
                        $langs[$lang]['excerpt'] = $matches[2];
                    }
                }
            }    
            
            $custom_fields = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d", $post_id));
            foreach($custom_fields as $cf){
                // only handle scalar values
                if(!is_serialized($cf->meta_value)){
                    
                    if(!preg_match('#<!--:([^-]+)-->#', $cf->meta_value)) continue;
                            
                    $exp = explode('<!--:-->', $cf->meta_value);
                    foreach($exp as $e){
                        if(trim($e)){
                            $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                            if($int){                                
                                $lang = $matches[1]; 
                                $langs[$lang]['custom_fields'][$cf->meta_key] = $matches[2];
                            }
                        }
                    }    
                }    
            }
            
        }        
        
        
        $post['post_title'] = $langs[$language]['title'];
        $post['post_content'] = isset($langs[$language]['content']) ? $langs[$language]['content'] : '';
        if(isset($langs[$language]['excerpt'])){
            $post['post_excerpt'] = $langs[$language]['excerpt'];    
        }                
        $_POST['post_title'] = $post['post_title'];
            
        $id = wp_update_post($post);
        update_post_meta($post['ID'], '_qt_cleaned', $lang);        
        
        if(!empty($langs[$language]['custom_fields'])){
            foreach($langs[$language]['custom_fields'] as $k=>$v){
                update_post_meta($id, $k, $v );
            }
        }
        
            
        return $id;
    }    
     
    function qtreset(){
        delete_option('qtranslate_language_names');
        delete_option('qtranslate_enabled_languages');
        delete_option('qtranslate_default_language');
        delete_option('qtranslate_flag_location');
        delete_option('qtranslate_flags');
        delete_option('qtranslate_locales');
        delete_option('qtranslate_na_messages');
        delete_option('qtranslate_date_formats');
        delete_option('qtranslate_time_formats');
        delete_option('qtranslate_use_strftime');
        delete_option('qtranslate_ignore_file_types');
        delete_option('qtranslate_url_mode');
        delete_option('qtranslate_detect_browser_language');
        delete_option('qtranslate_hide_untranslated');
        delete_option('qtranslate_auto_update_mo');
        delete_option('qtranslate_next_update_mo');
        delete_option('qtranslate_hide_default_language');
        delete_option('qtranslate_term_name');
    }
    
    function process_post($post_id){
        global $sitepress, $wpdb, $sitepress_settings;
        
        if(get_post_meta($post_id, '_qt_imported', true)) return;
        
        $post = get_post($post_id, ARRAY_A);
        
        $translatable_tax = $sitepress->get_translatable_taxonomies(true, $post['post_type']);
        
        if($post){
            $exp = explode('<!--:-->', $post['post_title']);
            foreach($exp as $e){
                if(trim($e)){
                    $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                    if($int){
                        $lang = $matches[1]; 
                        $langs[$lang]['title'] = $matches[2];
                    }
                }
            }
            
            $exp = explode('<!--more-->', $post['post_content']);
            $pc1 = $exp[0];
            $pc2 = isset($exp[1]) ?  $exp[1] : '';
            
            $exp = explode('<!--:-->', $pc1);
            foreach($exp as $e){
                if(trim($e)){
                    $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                    if($int){
                        $lang = $matches[1]; 
                        $langs[$lang]['content'] = $matches[2];
                    }
                }
            } 
            if($pc2){
                $exp = explode('<!--:-->', $pc2);
                foreach($exp as $e){
                    if(trim($e)){
                        $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                        if($int){
                            $lang = $matches[1]; 
                            $langs[$lang]['content'] .= '<!--more-->' . $matches[2];
                        }
                    }
                } 
            }
            
                   
            $exp = explode('<!--:-->', $post['post_excerpt']);
            foreach($exp as $e){
                if(trim($e)){
                    $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                    if($int){
                        $lang = $matches[1]; 
                        $langs[$lang]['excerpt'] = $matches[2];
                    }
                }
            }    
            
            $custom_fields = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d", $post_id));
            foreach($custom_fields as $cf){
                // only handle scalar values
                if(!is_serialized($cf->meta_value)){
                    
                    if(preg_match('#<!--:([^-]+)-->#', $cf->meta_value)){
                            
                        $exp = explode('<!--:-->', $cf->meta_value);
                        foreach($exp as $e){
                            if(trim($e)){
                                $int = preg_match('#<!--:([a-z]{2})-->(.*)#ims', $e, $matches);        
                                if($int){                                
                                    $lang = $matches[1]; 
                                    $langs[$lang]['custom_fields'][$cf->meta_key] = $matches[2];
                                }
                            }
                        }    
                        
                    }else{
                        // copying all the other custom fields
                        foreach($this->active_languages as $lang){
                            if($this->default_language != $lang){
                                $langs[$lang]['custom_fields'][$cf->meta_key] = $cf->meta_value;
                            }
                        }
                    }
                    
                }    
            }
            
            //echo $post_id . "------------------------"; 
            
            // put the default language in front
            $active_languages = array($this->default_language) + array_diff($this->active_languages, array($this->default_language));
            
            foreach($active_languages as $language){
                
                //echo $language . "------------------------";
                
                if(empty($langs[$language]['title'])) break;
                
                $post['post_title'] = $langs[$language]['title'];
                $post['post_content'] = isset($langs[$language]['content']) ? $langs[$language]['content'] : '';
                if(isset($langs[$language]['excerpt'])){
                    $post['post_excerpt'] = $langs[$language]['excerpt'];    
                }                
                $_POST['icl_post_language'] = $this->_lang_map($language);                                    
                $_POST['post_title'] = $post['post_title'];
                
                global $iclTranslationManagement;
                if(!empty($iclTranslationManagement)){
                    remove_action('save_post', array($iclTranslationManagement, 'save_post_actions'), 11, 2); 
                }
                
                if($language == $this->default_language){
                    //print_r($post);
                    $id = wp_update_post($post);
                    update_post_meta($post['ID'], '_qt_imported', 'original');
                }else{
                    $_POST['icl_translation_of'] = $post['ID'];                    
                    $post_copy = $post;
                    
                    unset($post_copy['ID'], $post_copy['post_name'], $post_copy['post_parent'], 
                            $post_copy['guid'], $post_copy['comment_count'], $post_copy['ancestors']);
                    
                    if(isset($sitepress_settings['sync_page_parent'])) $icl_sync_page_parent = $sitepress_settings['sync_page_parent'];
                    $iclsettings['sync_page_parent'] = 0;
                    $sitepress->save_settings($iclsettings);
                        
                    $id = wp_insert_post($post_copy);
                    
                    if(isset($sitepress_settings['sync_page_parent'])) $iclsettings['sync_page_parent'] = $icl_sync_page_parent;
                    $sitepress->save_settings($iclsettings);
                    
                    update_post_meta($id, '_qt_imported', 'from-' . $post['ID']);
                    
                    unset($_POST['icl_translation_of'], $_POST['post_title'], $_POST['icl_post_language']);
                    
                    // fix terms
                    foreach($translatable_tax as $tax){
                        $terms = wp_get_object_terms($post['ID'], $tax);
                        
                        if($terms){
                            $translated_terms = array();
                            foreach($terms as $term){
                                $translated_term = icl_object_id($term->term_id, $tax, false, $language);
                                if($translated_term){
                                    $translated_terms[] = intval($translated_term);
                                }
                            }

                            wp_set_object_terms($id, $translated_terms, $tax, false);
                        }
                    }
                    
                    if($post['post_status'] == 'publish'){
                        $_qt_redirects_map = get_option('_qt_redirects_map');
                        
                        $original_url = get_permalink($post['ID']);
                        if($this->url_mode==1){
                            $glue = false === strpos($original_url, '?') ? '?' : '&';
                            $original_url .= $glue . 'lang=' . $language; 
                        }elseif($this->url_mode==2){
                            $original_url = str_replace(home_url(), rtrim(home_url(), '/') . '/' . $language, $original_url);
                        }elseif($this->url_mode==2){
                            $parts = parse_url(home_url());
                            $original_url = str_replace($parts['host'], $language . '.' . $parts['host'], $original_url);
                        }
                        
                        $_qt_redirects_map[$original_url] = get_permalink($id);
                        update_option('_qt_redirects_map', $_qt_redirects_map);
                    }
                        
                    
                } 
                
                if(!empty($langs[$language]['custom_fields'])){
                    foreach($langs[$language]['custom_fields'] as $k=>$v){
                        update_post_meta($id, $k, $v );
                    }
                }
                
            }

        }    
    }
    
    function fix_hierarchy(){
        global $wpdb, $sitepress;
        
        $original_posts = $wpdb->get_results("
            SELECT p.ID, p.post_parent, p.post_type 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
            WHERE p.post_parent > 0 AND m.meta_key = '_qt_imported' AND m.meta_value='original' 
        ");
        foreach($original_posts as $post){
            $trid = $sitepress->get_element_trid($post->ID, 'post_' . $post->post_type);
            $post_translations = $sitepress->get_element_translations($trid, 'post_' . $post->post_type);
            
            foreach($post_translations as $translation){
                if(!$translation->original){
                    $translated_parent = icl_object_id($post->post_parent, $post->post_type, false, $translation->language_code);
                    if($translated_parent){
                        $wpdb->update($wpdb->posts, array('post_parent' => $translated_parent), array('ID' => $translation->element_id));
                    }        
                }
            }
        }
    }
    
    function map_wpml_settings(){
        global $sitepress, $sitepress_settings, $wpdb;
        $active_languages = $sitepress->get_active_languages();
        
        $sitepress->set_active_languages(array_map(array($this, '_lang_map'), $this->active_languages));
        
        if(empty($sitepress_settings['default_categories'])){
            $blog_default_cat = get_option('default_category');
            $blog_default_cat_tax_id = $wpdb->get_var("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} 
                WHERE term_id='{$blog_default_cat}' AND taxonomy='category'");
            $default_categories = array($this->_lang_map($this->default_language) => $blog_default_cat_tax_id);
        }else{
            $default_categories = $sitepress->get_default_categories();    
        }
        
        foreach($this->active_languages as $l){
            $lang = $this->_lang_map($l);
            
            if(!isset($default_categories[$lang])){
                $default_cat = get_option('default_category');             
                $default_cat_tax_id = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} 
                    WHERE term_id=%d AND taxonomy='category'", $default_cat));   
                    
                $default_category_trid = $sitepress->get_element_trid($default_cat_tax_id, 'tax_category');
                $translated_category = icl_object_id($default_cat, 'category', false, $lang);
                
                if(empty($translated_category)){
                       $sitepress->switch_locale($lang);
                       $translated_category_name  = __('Uncategorized', 'sitepress');
                       $sitepress->switch_locale();
                       $_POST['icl_trid'] = $default_category_trid;
                       $_POST['icl_tax_category_language'] = $lang;
                       $tmp = wp_insert_term($translated_category_name, 'category');                   
                       $default_categories[$lang] = $tmp['term_taxonomy_id'];                   
                       $iclsettings['default_categories'] = $default_categories;
                       $sitepress->save_settings($iclsettings);
                }
                
            }    
        }
        
        $sitepress->set_default_language($this->_lang_map($this->default_language));
        
        if(get_option('qtranslate_detect_browser_language')){
            $iclsettings['automatic_redirect'] = true;
        }
        
        if(get_option('qtranslate_hide_untranslated')){
            $iclsettings['icl_lso_link_empty'] = true;            
        }
        
        if(get_option('qtranslate_url_mode') == 2){
            $iclsettings['language_negotiation_type'] = 1;    
        }elseif(get_option('qtranslate_url_mode') == 3){
            $iclsettings['language_negotiation_type'] = 2;    
            foreach($qt_enabled_languages as $lang){
                $exp = explode(',', $_SERVER['HTTP_HOST']);
                if(count($exp > 2)){
                    $exp = array_reverse(array_shift(array_reverse($exp)));
                }
                $https = $_SERVER['HTTPS'] == 'on' ? 's' : '';                
                $language_domains[$lang] = 'http' . $https . '://' . $lang . '.' . join('.', $exp);
            }
            $iclsettings['language_domains'] =  $language_domains;
        }else{
            $iclsettings['language_negotiation_type'] = 3;    
        }
        
        $iclsettings['existing_content_language_verified'] = 1;
        $iclsettings['setup_wizard_step'] = 3;
        $iclsettings['setup_complete'] = 1;
        
        $sitepress->save_settings($iclsettings);
        
    }
    
    function _get_lang_from_url($url, &$qt_lang = null ){        
        if($this->url_mode == 1){
            if(preg_match('#[\?&]lang=([a-z-]+)#', $url, $matches)){
                $lang = $this->_lang_map($matches[1]);
            }
        }elseif($this->url_mode == 2){
            if(preg_match('#[/]?([^/]+)/#', str_replace(home_url(), '', $url), $matches)){
                $original_language = $matches[1];
                $lang = $this->_lang_map($original_language);
            }            
        }elseif($this->url_mode == 3){
            $parts = parse_url($url);
            list($lang) = explode('.', $parts['host']);
            if(!empty($lang)){
                $lang = $this->_lang_map($lang);
            }
        }
        
        return $lang;        
    }
    
    function fix_links($post_id){
        global $wpdb;
        
        $post = get_post($post_id);
        
        $changed = false;
        
        $home_url = str_replace("?", "\?", home_url());
        $int1  = preg_match_all('@<a([^>]*)href="(('.rtrim($home_url,'/').')?/([^"^>]+))"([^>]*)>@i',$post->post_content,$alp_matches1);        
        $int2 = preg_match_all('@<a([^>]*)href=\'(('.rtrim($home_url,'/').')?/([^\'^>]+))\'([^>]*)>@i',$post->post_content,$alp_matches2);        
        
        
        if($this->url_mode == 3){
            $active_languages = $this->active_languages;
            foreach($active_languages as $lang){
                if($lang != $this->default_language){
                    $parts = parse_url($home_url);
                    $_home_url = $parts['scheme'] . '://' . $lang . '.' . $parts['host'] . $parts['path'];
                    $int_e[] = preg_match_all('@<a([^>]*)href="(('.rtrim($_home_url,'/').')?/([^"^>]+))"([^>]*)>@i',$post->post_content,$alp_matches1_e[]);        
                    $int_e[] = preg_match_all('@<a([^>]*)href=\'(('.rtrim($_home_url,'/').')?/([^\'^>]+))\'([^>]*)>@i',$post->post_content,$alp_matches2_e[]);        
                }
            }
            
            if(!empty($int_e)){
                for($j = 0; $j < count($int_e); $j++){
                    for($i = 0; $i < 6; $i++){
                        $alp_matches_e[$j][$i] = array_merge((array)$alp_matches1_e[$j][$i], (array)$alp_matches2_e[$j][$i]); 
                    }        
                }                
            }
            
        }
        
        for($i = 0; $i < 6; $i++){
            $alp_matches[$i] = array_merge((array)$alp_matches1[$i], (array)$alp_matches2[$i]); 
            if(!empty($alp_matches_e)){
                for($j = 0; $j<count($alp_matches_e); $j++){
                    $alp_matches[$i] = array_merge($alp_matches[$i], $alp_matches_e[$j][$i]);                     
                }                
            }
        }        
        
        if(!empty($alp_matches[2])){
            foreach($alp_matches[2] as $found_url){
                
                $language = $this->_get_lang_from_url($found_url); 
                
                if($this->url_mode == 2){ // strip language off in order to get the base url                    
                    $found_post_id = url_to_postid(str_replace(rtrim($home_url, '/') . '/' . $language, rtrim($home_url, '/') , $found_url));
                }elseif($QT_Importer->url_mode == 3){ // strip language off in order to get the base url                    
                    $found_post_id = url_to_postid(preg_replace('#^http(s?)://([^.]+)\.(.+)$#', 'http$1://' . '$3', $found_url));
                }else{
                    $found_post_id = url_to_postid($found_url);    
                }
                
                if(empty($found_post_id)) continue;
                
                $found_post = $wpdb->get_row($wpdb->prepare("SELECT ID, post_type FROM {$wpdb->posts} WHERE ID=%d", $found_post_id));
                
                $translated_id = icl_object_id($found_post_id, $found_post->post_type, false, $language);
                
                if($translated_id){
                    $translated_url = get_permalink($translated_id);                
                    $post->post_content = str_replace($found_url, $translated_url, $post->post_content);
                    $changed = true;                
                }
                
            }
            
        }


        if($changed){
            $wpdb->update($wpdb->posts, array('post_content' => $post->post_content), array('ID' => $post->ID));
        }
        
        update_post_meta($post_id, '_qt_links_fixed', 1);
       
    }
    
    function dump_redirects(){
        $_qt_redirects_map = get_option('_qt_redirects_map');
        $home_url = home_url();
        
        $out = "";
        
        if(is_array($_qt_redirects_map)){
            
            foreach($_qt_redirects_map as $from => $to){
                if($this->url_mode == 1){
                    $parts = parse_url($from);
                    $from_path = str_replace($home_url, '', $from);
                    $from_path = preg_replace('@[\?&]+' . $parts['query'] .'@', '', $from_path);
                    $from_path  = str_replace(array('.', '?', '*', '+'), array('\.', '\?', '\*', '\+'), $from_path);
                    
                    preg_match("@&?lang=([a-z-]+)&?@", $parts['query'], $m);
                    if(!empty($m[0])){
                        $out .= sprintf("RewriteCond %%{QUERY_STRING} %s \n", $m[0]);
                        $out .= sprintf("RewriteRule ^%s %s [L,R=301]\n\r", ltrim($from_path, '/'), $to);
                    }
                    
                }elseif($this->url_mode == 2){
                
                    $from  = str_replace($home_url, '', $from);
                    //$from  = preg_replace('@^https?://([^/]+)@', '', $from);
                    
                    $from  = str_replace(array('.', '?', '*', '+'), array('\.', '\?', '\*', '\+'), $from);
                    $out .= sprintf("RewriteRule ^%s %s [L,R=301]\n", ltrim($from, '/'), $to);                 
                                
                }elseif($this->url_mode == 3){
                    $language = $this->_get_lang_from_url($from);
                    
                    $from_path = str_replace(preg_replace('#^http(s?)://([^/]+)/(.*)$#', 'http$1://' . $language  . '.$2/$3', $home_url), '', $from);                    
                    $from_path  = str_replace(array('.', '?', '*', '+'), array('\.', '\?', '\*', '\+'), $from_path);

                    $parts = parse_url($from);                    
                    $parts2 = parse_url($to);
                    
                    if($parts['host'] != $parts2['host']){
                        $out .= sprintf("RewriteCond %%{HTTP_HOST} %s \n", $parts['host']);
                        $out .= sprintf("RewriteRule ^%s %s [L,R=301]\n", ltrim($from_path, '/'), $to);
                    }
                }                
            }
        }
        
        if(!empty($out)) $out = '#qt-importer redirects start. keep this line for validation' . "\n" . $out . '#qt-importer redirects end.' . "\n";
        
        return $out;        
        
    }
    
    function verify_htaccess_ajx(){
        $htaccess = file_get_contents(ABSPATH . '/.htaccess');
        
        echo json_encode(array('found' => intval(strpos($htaccess, '#qt-importer redirects start.') !== false)));
        exit;
        
    }
    function php_redirects(){
        
        $_qt_redirects_map = get_option('_qt_redirects_map');
        
        $file  = '<?php' . PHP_EOL;
        $file .= '// This file contains redirects or urls used in qTranslate and that were changed when imported content in WPML.' . PHP_EOL;
        $file .= '// It needs to be included from the theme\'s functions.php file.' . PHP_EOL. PHP_EOL;
        
        $file .= "add_action('template_redirect', 'qt_importer_301_redirects');" . PHP_EOL;
        $file .= 'function qt_importer_301_redirects(){' . PHP_EOL;
                    
        foreach($_qt_redirects_map as $from => $to){
            $from = str_replace(home_url(), '', $from);
            $file .= '  $redirects[\'' . $from . '\'] = \'' .$to . '\';' .  PHP_EOL;
        }
        
        $file .= '  if(is_404()){' . PHP_EOL;                
        $file .= '      if(isset($redirects[$_SERVER[\'REQUEST_URI\']])){' . PHP_EOL;
        $file .= '          wp_redirect($redirects[$_SERVER[\'REQUEST_URI\']], 301);' . PHP_EOL;
        $file .= '      }' . PHP_EOL;
        $file .= '  }' . PHP_EOL;
        
        $file .= '}' . PHP_EOL;
        
        if(ini_get('zlib.output_compression'))
            ini_set('zlib.output_compression', 'Off');
        
        header("Content-Type: text/plain"); 
        header("Content-Disposition: attachment; filename=qt-importer-redirects.php;");
        header("Content-Transfer-Encoding: binary");
        header('Accept-Ranges: bytes');        
        
        header("Cache-control: private");
        header('Pragma: private');
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");        
        
        header("Content-Length: ". strlen($file));
        
        
        echo $file;
        exit(0);
        
        
    }
    
    
}  

$QT_Importer = new QT_Importer;

?>
