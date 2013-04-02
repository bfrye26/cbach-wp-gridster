<?php
/*
Plugin Name: 			Gridster
Plugin URI:       https://github.com/carstingaxion/cbach-wp-gridster
Description:      Gridster is a WordPress plugin that makes building intuitive draggable layouts from elements spanning multiple columns. You can even dynamically resize, add and remove elements from the grid, as edit the elements content inline.
Author:      			Carsten Bach
Version: 					1.2
Author URI:    		http://carsten-bach.de
*/


if( ! class_exists( 'cbach_wpGridster' ) ) {
    class cbach_wpGridster {
       
       
        
      	/**
      	 *   For easier overriding we declared some keys here as well as our
      	 *   settings-tabs array which is populated when registering settings
      	 *            
      	 */


        /**
         *   Basename of this file.
         *
         *   @see   __construct()
         *   @type  string
         */
        protected $base_name = '';

        
        /**
         *   Current Screen in 'wp-admin'
         *
         *   @see   __construct()
         *   @type  object
         */
        protected $current_screen = '';        
        
        
        /**
         *   Plugin-Version
         *
         *   @used  when enqueuing scripts & styles
         *   @type  string
         */      	
        protected $version = '1.1';
        
        
        /**
         *   gridster.js-Version
         *
         *   @used  when enqueuing scripts & styles
         *   @type  string
         */      	
        protected $gridster_version = '0.1.0';       
                  
        
        /**
         *   Use minified js & css or not
         *
         *   @see   __construct()         
         *   @type  string
         */         
        protected $minified_js_files = '';    
        protected $minified_css_files = '';        
             
        /**
         *   Internal prefix for creating CSS classes and ids
         *
         *   @type  string
         */         
        protected $prefix = 'gridster_';


        /**
         *   Nonce
         *
         *   @used  when for verification on save actions
         *   @type  string
         */      	
        protected $nonce = 'gridster_nonce'; 
        
                
        private $cpt_gridster = 'gridster';
        private $gridster_shortcode = 'gridster';
        
        private $default_settings_slug = 'gridster_default_options';
        private $default_settings_name = 'gridster_default_options'; 


        /**
         *   Our Tabs on the settings page
         *
         *   @see   load_settings()
         *   @type  array
         */
      	protected $plugin_settings_tabs = array();
        

        /**
         *   Our Tabs on the settings page
         *
         *   @see   load_post_settings()
         *   @type  array
         */
      	protected $post_settings = array();           
          

        /**
         *   Load Scripts & styles only when shortcode in use
         *
         *   @type  bool
         */
      	protected $shortcode_used = false; 
        
        
        /**
         *   Dynamic filter values to get best fitting images inside widget
         *
         *   @type  array
         */
      	protected $thumbnail_filter_dimensions = array(); 
        
        
        
        /**
         *  Construct the CLASS
         *  
         */  
      	public function __construct() {

            // Used by some fn, i.e. add_settings_link() later.
            $this->base_name = plugin_basename( __FILE__ ); 
            
            // wether to use concetenated scripts & styles or the developer versions
            $this->minified_js_files = ( defined('SCRIPT_DEBUG') && constant('SCRIPT_DEBUG') ) ? '' : 'min.';
            $this->minified_css_files = ( defined('SCRIPT_DEBUG') && constant('SCRIPT_DEBUG') ) ? '' : 'min.';
            
            //Hook up to the init action
        		add_action( 'init', array( &$this, 'init' ) );
      
            // Register Post_type staging
            add_action( 'init', array( &$this, 'gridster_register_as_posttype' ) ); 

		        // get settings
            add_action( 'init', array( &$this, 'load_settings' ) );
            
            // check if there are any existing gridster posts
            add_action( 'init', array( &$this, 'have_gridster_posts' ) );
        }
      
      
      	/**
      	 *   Run during the activation of the plugin
      	 *   
      	 *   @since    1.0                  
      	 *   
      	 */                  
      	public function activate() {
            
            // init our new post_types and taxonomies to whitelist new permalink structures
            $this->gridster_register_as_posttype();
            
            // init first plugin options
#            update_option( $this->default_settings_name, $this->get_default_settings() );
      	}
      	
        
        
        /**
      	 *   Run during the deactivation of the plugin
      	 *   
      	 *   @since                    
      	 *   
      	 */                  
      	public function deactivate() {
            

      	} 
        
        
             
        /**
      	 *   Run during the uninstallation of the plugin
      	 *   
      	 *   Delete all 'gridster' posts, its post_meta and plugin options 
      	 *   
      	 *   @since    1.0                                    
      	 *   
      	 */                  
      	public function uninstall() {
            
            // important: check if the file is the one that was registered with the uninstall hook (function)
            if ( __FILE__ != WP_UNINSTALL_PLUGIN )
                return;
            
            $all_gridsters = get_posts( array( 
                'posts_per_page'=> -1,
                'post_type' => $this->cpt_gridster,
                'post_status' => 'any'
            ) );
            
            // delete all gridster-posts and related post metas 
            foreach( $all_gridsters as $gridster ) {
             
                delete_post_meta( $gridster->ID, '_gridster_layout' );
                delete_post_meta( $gridster->ID, '_gridster_query_posts_not_in' );
                delete_post_meta( $gridster->ID, '_gridster_dimensions' );                                
                wp_delete_post( $gridster->ID, $force_delete = true );
            }
            
            // delete plugin options
            delete_option( $this->default_settings_name );
      	}      
      
      
      
      	/*
      	*		Run during the initialization of Wordpress
      	*		
      	*    @since    1.0                
      	*/
      	public function init() {

            if ( is_admin() ) {

                // Setup localization, we need this in 'wp-admin' only
              	load_plugin_textdomain( 'cbach-wp-gridster', false, dirname( $this->base_name ) . '/languages' );

                // Append Stylesheet(s) to WP BackEnd
                add_action( 'admin_print_styles', array( &$this, 'admin_css' ) );

                // Append JavaScript(s) to WP BackEnd
                add_action( 'admin_head', array( &$this, 'admin_js' ) );  

                // Add columns to gridster list
                add_filter( 'manage_edit-gridster_columns', array( &$this, 'gridster_column_header_function' ) );                

                // Add content to gridster columns
                add_filter( 'manage_gridster_posts_custom_column',  array( &$this, 'gridster_populate_rows_function' ), 10, 2 );
                      
                // Make new gridster columns sortable
                add_filter( 'manage_edit-gridster_sortable_columns', array( &$this, 'gridster_sortable_columns' ) );

		            // Add copy-able shortcode to "publish"-meta_box of post.php and edit.php
                add_action( 'post_submitbox_misc_actions', array( &$this, 'add_shortcode_to_publish_metabox' ), 999 );

                // Add Settings Page
                add_action( 'admin_menu', array( &$this, 'admin_menu' ) );                
                
                // whitelist plugin options
            		add_action( 'admin_init', array( &$this, 'settings_register_general' ) );
                
                // redirect to edit.php to post-new.php, when there are no existing gridster posts
            		add_action( 'admin_init', array( &$this, 'redirect_edit_to_post_new_when_no_posts_exist' ) );                
                
                // get current screen object as early as possible
                add_action( 'current_screen', array( &$this, 'current_screen' ) );
               
                // save gridster post_metas 
                add_action( 'save_post', array( &$this, 'save_post' ) );
                
                // dlete gridster post_metas on post deletion 
                add_action( 'delete_post', array( &$this, 'delete_post' ) );                
                
                // show customized "updated" messages
                add_filter( 'post_updated_messages', array( &$this, 'post_updated_messages' ) );                          
        
                // add HTML attribute of "autocomplete='off'" to form element on post.php and edit.php
                add_action( 'post_edit_form_tag', array( &$this, 'post_edit_form_tag' ) );
                 
		            // load TinyMCE Plugin to replace gridster shortcode with graphical zone
                add_filter('mce_external_plugins', array( &$this, 'mce_external_plugins' ));
                
                // load TinyMCE CSS to style the graphical shortcode 
                add_filter('tiny_mce_before_init', array( &$this, 'tiny_mce_before_init' ) );                       
                
                // add Shortcode-Button to TinyMCE 
                add_filter('mce_buttons', array( &$this, 'mce_buttons' ) );
                
                // load transaltions for TinyMCE Plugin
                add_filter( 'mce_external_languages', array( &$this, 'mce_external_languages' ) );

                // modify post_types usable as gridster-widgets
                add_filter( 'gridster_post_types_as_widget_blocks', array( &$this, 'filter_gridster_post_types_as_widget_blocks' ) );
                
                // Display a Settings link on the main Plugins page
                add_filter( 'plugin_action_links_' . $this->base_name, array( &$this, 'plugin_action_links'), 10 );
                
                // Add additional links to plugin-description-section
                add_filter( 'plugin_row_meta', array( &$this, 'plugin_row_meta' ), 10, 2 );               

                // AJAX callback to get templated HTML by $post->ID as gridster-widegt        
                add_action( 'wp_ajax_ajax_gridster_get_post', array( &$this, 'ajax_gridster_get_post' ) );
                
                // AJAX callback for TinyMCE modal, initiated by Button
                add_action('wp_ajax_ajax_gridster_shortcode_update_modal', array( &$this, 'ajax_gridster_shortcode_update_modal' ) );

                // AJAX callback for TinyMCE modal, initiated by Button
                add_action('wp_ajax_ajax_get_posts_by_type_widget_block', array( &$this, 'ajax_get_posts_by_type_widget_block' ) );


                add_action('wp_ajax_ajax_get_textile_markup_for_jeditable', array( &$this, 'ajax_get_textile_markup_for_jeditable' ) );
            } else {
                
                // Render gridster Shortcode
                add_shortcode( $this->gridster_shortcode, array( &$this, 'shortcode_render_gridster' ) ); 
                
                // load stylesheets
                add_action( 'wp_footer', array( &$this, 'print_css' ) );                
                
                // add scripts to frontend
                add_action( 'wp_footer', array( &$this, 'print_js' ) );
                
                // add body_class
                add_filter( 'body_class', array( &$this, 'body_class' ), 100, 1);
            
            }
            
            // apply image size filter to all AJAX requests        
            add_filter( 'post_thumbnail_size', array( &$this, 'filter_image_size_on_ajax_request' ) );            
            
      	}
       

            
        /**
         *  Adds a Stylesheet to the WP BE
         *  
         *  @since    1.0                  
         *  
         */                          
        public function admin_css () {

            $deps = array();
            
            // get plugin base styles, for metaboxes, admin options and so on
            wp_register_style( $this->prefix.'admin_css', plugins_url( '/css/gridster_admin.css', __FILE__ ), $deps, $this->version );
            wp_enqueue_style( $this->prefix.'admin_css' );
            $deps[] = $this->prefix.'admin_css';
            
            // get gridster styles for our workbench
            if ( $this->current_screen->base == 'post' && $this->current_screen->post_type == $this->cpt_gridster ) {
                wp_register_style( 'jquery-ui-base-css', plugins_url( '/css/jquery-ui/jquery-ui-base.'.$this->minified_css_files.'css', __FILE__ ), $deps, '1.0' );         
                wp_enqueue_style( 'jquery-ui-base-css' );
                $deps[] = 'jquery-ui-base-css'; 

                wp_register_style( $this->prefix.'lib_css', plugins_url( '/css/gridster/jquery.gridster.'.$this->minified_css_files.'css', __FILE__ ), $deps, $this->gridster_version );
                wp_enqueue_style( $this->prefix.'lib_css' );            
            }   
        }



        /**
         *  Adds JavaScript to the WP BE
         *  
         */                          
        public function admin_js () {
            
            // prepare settings and global vars, so it can be used in JS 
            $localize = array(
              'ajaxNonce' => wp_create_nonce( $this->nonce ),
              'ajaxUrl' => admin_url( '/admin-ajax.php' ),
              
              'textMoveHandle' => esc_attr__( 'Move', 'cbach-wp-gridster' ),              
              'textDelete' => esc_attr__( 'Delete', 'cbach-wp-gridster' ),
              'textAjaxLoadProblem' => __( 'There was a problem loading your content, please try again.', 'cbach-wp-gridster' ),
              'textAjaxNothingFound' => __( 'Nothing found.', 'cbach-wp-gridster'),
              'textMaximumContentWidth' => esc_attr__( 'Maximum content width defined in your current theme by the variable $content_width.', 'cbach-wp-gridster' ),
              
              'JeditableToolTip' => esc_attr__( 'Click to edit', 'cbach-wp-gridster' ),
              'JeditableCancel' => esc_attr__( 'Cancel', 'cbach-wp-gridster' ),              
              'JeditableOk' => esc_attr__( 'OK', 'cbach-wp-gridster' ),
              
              'widget_margin_x'  =>  $this->post_settings['dimensions']['widget_margin_x'],
              'widget_margin_y'  =>  $this->post_settings['dimensions']['widget_margin_y'],
              'widget_base_width' => $this->post_settings['dimensions']['widget_base_width'],
              'widget_base_height' => $this->post_settings['dimensions']['widget_base_height'],
#              'extra_rows' => $this->post_settings['dimensions']['extra_rows'],
#              'extra_cols' => $this->post_settings['dimensions']['extra_cols'],
              'min_cols' => $this->post_settings['dimensions']['min_cols'], 
#              'min_rows' => $this->post_settings['dimensions']['min_rows'],
#              'max_size_x' => $this->post_settings['dimensions']['max_size_x'],
#              'max_size_y' => $this->post_settings['dimensions']['max_size_y'],                              
            );            
            
            // default dependencies for loading our script files
            $deps = array('jquery', 'jquery-ui-draggable', 'jquery-ui-droppable', 'jquery-ui-resizable' );
           
            // Register our Scripts
            if ( $this->current_screen->base == 'post' && $this->current_screen->post_type == $this->cpt_gridster ) {
                
                // gridster lib
#                wp_register_script( $this->prefix.'lib_js', plugins_url( '/js/gridster/jquery.gridster.'.$this->minified_js_files.'js', __FILE__ ), $deps, $this->gridster_version );
                wp_register_script( $this->prefix.'lib_js', plugins_url( '/js/gridster/jquery.gridster.with-extras.'.$this->minified_js_files.'js', __FILE__ ), $deps, $this->gridster_version );                
                wp_enqueue_script( $this->prefix.'lib_js' );
                $deps[] = $this->prefix.'lib_js';  
                
                // jeditable for inline editing of widget content
                wp_register_script( 'jquery_jeditable_js', plugins_url( '/js/jeditable/jquery.jeditable.'.$this->minified_js_files.'js', __FILE__ ), $deps, '1.7.1' );
                wp_enqueue_script( 'jquery_jeditable_js' );
                $deps[] = 'jquery_jeditable_js';          
                                      
                // jeditable autogrow extension
                wp_register_script( 'jquery_jeditable_autogrow_extension_js', plugins_url( '/js/jeditable/jquery.jeditable.autogrow.js', __FILE__ ), $deps, '1.7.1' );
                wp_enqueue_script( 'jquery_jeditable_autogrow_extension_js' );
                $deps[] = 'jquery_jeditable_autogrow_extension_js';    
                              
                // autogrow Plugin used for <textarea>a from jeditable 
                wp_register_script( 'jquery_autogrow_js', plugins_url( '/js/jeditable/jquery.autogrow.js', __FILE__ ), $deps, '1.2.2' );
                wp_enqueue_script( 'jquery_autogrow_js' );
                $deps[] = 'jquery_autogrow_js';   

                // plugin base script
                wp_register_script( $this->prefix.'admin_js', plugins_url( '/js/gridster_admin.js', __FILE__ ), $deps, $this->version );
                wp_enqueue_script( $this->prefix.'admin_js' );
                wp_localize_script( $this->prefix.'admin_js', $this->prefix.'admin', $localize );        
            }

        }
        
      
        
        /**
         *  Adds JavaScript to the frontend
         *  
         */                          
        public function print_js () {
            
            // only when Shortcode is used
            if ( $this->shortcode_used !== true )
                return;

            // prepare settings and global vars, so it can be used in JS 
            $localize = array(
              'layout' => $this->post_settings['layout'],
              'widget_margin_x'  =>  $this->post_settings['dimensions']['widget_margin_x'],
              'widget_margin_y'  =>  $this->post_settings['dimensions']['widget_margin_y'],
              'widget_base_width' => $this->post_settings['dimensions']['widget_base_width'],
              'widget_base_height' => $this->post_settings['dimensions']['widget_base_height'],
#              'extra_rows' => $this->post_settings['dimensions']['extra_rows'],
#              'extra_cols' => $this->post_settings['dimensions']['extra_cols'],
              'min_cols' => $this->post_settings['dimensions']['min_cols'], 
#              'min_rows' => $this->post_settings['dimensions']['min_rows'],
#              'max_size_x' => $this->post_settings['dimensions']['max_size_x'],
#              'max_size_y' => $this->post_settings['dimensions']['max_size_y'],                              
            ); 

            // default dependencies for loading our script files
            wp_enqueue_script( 'jquery' );            
            $deps = array('jquery' );
           
            // gridster lib
            wp_register_script( $this->prefix.'lib_js', plugins_url( '/js/gridster/jquery.gridster.'.$this->minified_js_files.'js', __FILE__ ), $deps, $this->gridster_version );
            wp_enqueue_script( $this->prefix.'lib_js' );
            $deps[] = $this->prefix.'lib_js';                

            // plugin base script
            wp_register_script( $this->prefix.'frontend_js', plugins_url( '/js/gridster_frontend.js', __FILE__ ), $deps, $this->version );
            wp_enqueue_script( $this->prefix.'frontend_js' );            
            wp_localize_script( $this->prefix.'frontend_js', $this->prefix.'frontend', $localize );        

        } 
        
        
        
        /**
         *  Load CSS for frontend
         *  
         *  @since    1.0
         *  
         */
        public function print_css () {
        
            // only when Shortcode is used
            if ( $this->shortcode_used !== true )
                return;        

            // default dependencies for loading our style files
            $deps = array();
            
            wp_register_style( $this->prefix.'frontend_css', plugins_url( '/css/gridster_frontend.css', __FILE__ ), $deps, $this->version );
            wp_enqueue_style( $this->prefix.'frontend_css' );
        }                 
        
        
                                           
/***************************************************************************************************************************************************************************************************
 *
 *  CUSTOM POST_TYPES and CUSTOM TAXONOMIES
 *  
 ***************************************************************************************************************************************************************************************************/



        /**
         *  Register Custom-Post-Type "gridster"
         */       
        public function gridster_register_as_posttype() {

            // Register post_type
            $gridster_labels = array( 
                'name' => __( 'Gridster', 'cbach-wp-gridster' ),
                'singular_name' => __( 'Gridster', 'cbach-wp-gridster' ),
                'menu_name' => _x( 'Gridster', 'menu name', 'cbach-wp-gridster' ),                
                'all_items' => __( 'All Gridster', 'cbach-wp-gridster' ),
                'add_new' => _x( 'Add New', 'add new gridster', 'cbach-wp-gridster' ),
                'add_new_item' => __( 'Add New Gridster', 'cbach-wp-gridster' ),
                'edit_item' => __( 'Edit Gridster', 'cbach-wp-gridster' ),
                'new_item' => __( 'New Gridster', 'cbach-wp-gridster' ),
                'view_item' => __( 'View Gridster', 'cbach-wp-gridster' ),
                'search_items' => __( 'Search Gridster', 'cbach-wp-gridster' ),
                'not_found' => __( 'No Gridster found', 'cbach-wp-gridster' ),
                'not_found_in_trash' => __( 'No Gridster found in Trash', 'cbach-wp-gridster' ),
                'parent_item_colon' => __( 'Parent Gridster:', 'cbach-wp-gridster' ),

            );

            $gridster_args = array( 
                'labels' => $gridster_labels,
                'description' => __( 'Content arranged within a grid, powered by gridster.js', 'cbach-wp-gridster' ),
                'public' => false,                
                'exclude_from_search' => true,
                'publicly_queryable' => true,
                'show_ui' => true,
                'show_in_nav_menus' => false,
                'show_in_menu' => true,
                'show_in_admin_bar' => true,
                'menu_position' => 20,  
                #'menu_icon' => plugins_url( '', __FILE__ ),
                #'capability_type' => 'post',
                #'capabilities' => array(),
                #'map_meta_cap' => false,
                'hierarchical' => false,                  
#                'supports' => array( 'title', 'revisions', 'author' ),
                'supports' => array( 'title', 'author' ),
                'register_meta_box_cb' =>  array( &$this, 'add_meta_boxes' ),   
                #'taxonomies' => array(),                
                'has_archive' => false,
                #'permalink_epmask' => '',                                         
                'rewrite' => false, 
                'query_var' => true,  
                'can_export' => true,
            );
            register_post_type( $this->cpt_gridster, $gridster_args );
        }



        /**
         *  Use custom "updated messages"
         *  labeled for Gridsters and without theese anoying Preview-Links
         *  
         *  @since    1.0
         *  
         *  @used     from function save_post() and added dynamically
         *  
         *  @see      http://wordpress.stackexchange.com/a/29254
         *  
         *  @param    array   updated messages for all post_types
         *  
         *  @return   array   new updated messages for all post_types, including gridster                                    
         *  
         */                                                                       
        public function post_updated_messages ( $messages ) {
            
            global $post, $post_ID;
            
            $messages[$this->cpt_gridster] = array(
                0 => '', // Unused. Messages start at index 1.
                1 => _x( 'Gridster updated.', 'post_updated message', 'cbach-wp-gridster' ),
                2 => __( 'Custom field updated.', 'cbach-wp-gridster' ),
                3 => __( 'Custom field deleted.', 'cbach-wp-gridster' ),
                4 => _x( 'Gridster updated.', 'post_updated message', 'cbach-wp-gridster' ),
                5 => isset($_GET['revision']) ? sprintf( __( 'Gridster restored to revision from %s', 'cbach-wp-gridster' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
                6 => _x( 'Gridster published.', 'post_updated message', 'cbach-wp-gridster' ),
                7 => __( 'Gridster saved.', 'cbach-wp-gridster'),
                8 => _x( 'Gridster submitted.', 'post_updated message', 'cbach-wp-gridster' ),
                9 => sprintf( _x( 'Gridster scheduled for: <strong>%1$s</strong>.', 'post_updated message', 'cbach-wp-gridster'), date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ) ),
                10 => _x( 'Gridster draft updated.', 'post_updated message', 'cbach-wp-gridster' ),
            );
            return $messages;
        }



        /**
         *  Redirects edit.php to post-new.php if no gridster posts exist
         *  
         *  @since    1.1
         *  
         */                                            
        public function redirect_edit_to_post_new_when_no_posts_exist ( ) {
        
            global $pagenow;
            
            // ok, we do have some posts saved yet
            if( $this->default_settings['have_gridster_posts'] )
                return;
        
            // Check current admin page
            if( $pagenow == 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] == $this->cpt_gridster ){
                wp_redirect( admin_url('/post-new.php?post_type=' . $this->cpt_gridster, 'http'), 302 );
                exit;
            } 
        }
        
        
        
/***************************************************************************************************************************************************************************************************
 *
 *  ADMIN UI
 *  
 ***************************************************************************************************************************************************************************************************/



        /**
         *  Add Rows to columns of CPT list
         *  
         *  @since    1.0                  
         *  
         *  @params   array   pre-defined Columns of this post_type
         *           
         *  @return   array   new populated Columns of this post_type                                  
         */   
        public function gridster_column_header_function( $columns ) {
            
            $columns = $this->array_insert( $columns, array( 'shortcode' => __( 'Shortcode', 'cbach-wp-gridster' ) ), 2 );
            return $columns;
        }    

        
        
        /**
         *  Populate new columns with content
         *  
         *  @since    1.0                  
         *  
         *  @params   string   name of current Column
         *  @params   int      ID of looped $post         
         */           
        public function gridster_populate_rows_function( $column, $post_id ) {
    
          	switch( $column ) {
                     		
                case 'shortcode' :
                    echo '<input type="text" class="'.$this->prefix.'shortcode-in-list-table" value="['.$this->gridster_shortcode.' id=&quot;'.$post_id.'&quot; title=&quot;'.get_the_title($post_id).'&quot;]" readonly="readonly" onfocus="this.select();">';
                    break;                    
                    
            }
        }
        
        
                
        /**
         *  Make these columns sortable
         *  
         *  @since    1.0                  
         *  
         *  @return   array   all sortable columns                 
         */         
        public function gridster_sortable_columns() {
            return array(
              'title'      => 'title',
              'author'     => 'author',
              'date'       => 'date',                                   
            );
        }



    		/**
    		 *	Show Shortcode inside the "publish"-meta_box
    		 *
    		 *  @since    1.0
    		 */
    		function add_shortcode_to_publish_metabox() {
    		    global $post;
            
            if ( $this->cpt_gridster != get_post_type( $post->ID ) )
                return;
                
    		    echo '<div class="misc-pub-section ' . $this->prefix . 'shortcode-copy-section" >'. __('Shortcode').': ';
            echo '<input type="text" class="'.$this->prefix.'shortcode-in-list-table" value="['.$this->gridster_shortcode.' id=&quot;'.$post->ID.'&quot; title=&quot;'.get_the_title($post->ID).'&quot;]" readonly="readonly" onfocus="this.select();">';    		    
    		    echo '</div>';
    		}

    
    
        /**
         *  Init all metaboxes
         *  
         */                          
        public function add_meta_boxes () {
            
            // add "Workbench" meta box to gridster post_types
	          add_meta_box( 
                'gridster_workbench_metabox', 
                __( 'Gridster', 'cbach-wp-gridster' ), 
                array( &$this, 'gridster_workbench_meta_box' ), 
                $this->cpt_gridster, 
                'normal', 
                'high'
            );
            
            // add "Options" meta box to gridster post_types
	          add_meta_box( 
                'gridster_options_metabox', 
                __( 'Gridster - Layout options', 'cbach-wp-gridster' ), 
                array( &$this, 'gridster_options_meta_box' ), 
                $this->cpt_gridster, 
                'side', 
                'default'
            );
            // add CSS classes filter for workbench-metabox            
            add_filter( 'postbox_classes_gridster_gridster_workbench_metabox', array( &$this, 'postbox_classes_post_gridster_workbench_metabox' ) );
        }
        
        
        
        /**
         *  Add CSS class to metabox
         *  
         *  by default, set this to a 2-columns layout,
         *  whta will be changed by JS on resizing the workbench
         *  
         *  @since    1.0
         *  
         *  @param    array     all CSS classes applied to this metabox
         *  
         *  @return   array     all updated CSS classes
         *  
         */                                                                                                           
        function postbox_classes_post_gridster_workbench_metabox( $classes ) {
            // In order to ensure we don't duplicate classes, we should
            // check to make sure it's not already in the array 
            if( !in_array( 'two-columns', $classes ) )
                $classes[] = 'two-columns';
            return $classes;
        }
        
        
        
        /**
         *  Render "Gridster Workbench" meta_box content
         *  
         *  @since    1.0                  
         *  
         *  @params   object   current $post
         *  @params   array    metaboxes ID, title, callback, and callback-arguments
         *              
         */
        public function gridster_workbench_meta_box ( $post, $meta_box ) {

            global $content_width;
            
            // Use nonce for verification
            wp_nonce_field( $this->base_name, $this->nonce );

            // this stores all our used post->IDs, used as gridster widgets
            $query_posts_not_in = get_post_meta( $post->ID, '_gridster_query_posts_not_in', true );
            echo '<input type="hidden" id="'.$this->prefix.'query_posts_not_in" name="'.$this->prefix.'query_posts_not_in" value="'.esc_attr($query_posts_not_in).'" />';
  
            // store gridster layout
            $gridster_layout = get_post_meta( $post->ID, '_gridster_layout', true );
            echo '<input type="hidden" id="'.$this->prefix.'layout" name="'.$this->prefix.'layout" value="'.esc_attr($gridster_layout).'" size="100"/>';            
            
            // render gridster work-bench
            echo '<div id="'.$this->prefix.'workbench_wrap">';
            
                // this is our gridster workbench
                echo '<div id="'.$this->prefix.'workbench" class="gridster"><ul data-content_width="' . $content_width . '">';
                echo '</ul></div> <!-- // end div#'.$this->prefix.'workbench -->';
                
                // add loader
                echo '<div id="'.$this->prefix.'load-wrap"><div id="'.$this->prefix.'loader">'.
                         '<p class="howto">' . 
                             '<img class="waiting" src="'. esc_url( admin_url( 'images/wpspin_light.gif' ) ) . '" alt="" />'.
                             __( 'Your content is being prepared as a gridster widget.', 'cbach-wp-gridster' ) . 
                         '</p>'.
                     '</div></div>';
                     
                // some fallback information for disabled JavaScript
                echo '<noscript><div class="error">' . 
                    sprintf( 
                        __( 'This application is usable only with <a href="%1$s" target="_blank">JavaScript</a> enabled. <a href="%2$s"  target="_blank" title="How to enable Javascript in your browser">Give it a try</a>!', 'cbach-wp-gridster' ),
                            'http://wikipedia.org/wiki/Javascript',
                            'http://www.enable-javascript.com/'
                    ) . '</div></noscript>';                
                            
            echo '</div> <!-- // end div#'.$this->prefix.'workbench_wrap -->';             

            // render widget-blocks to work with
            echo '<div id="'.$this->prefix.'content_blocks">';
            
                // all post_types
                foreach ( (array) $this->get_post_types_as_widget_blocks() as $post_type ) {
                    $pt = get_post_type_object( $post_type );
                    
                    echo '<div id="'.$this->prefix.'post_type-'.$pt->name.'-widget-block" class="'.$this->prefix.'post_type-widget-block '.$this->prefix.'widget-block" data-post_type="'.$pt->name.'">';
                        echo '<div class="handlediv" title="' . esc_attr__('Click to toggle') . '"><br></div>';
                        echo '<h3 class=""><span>'.$pt->labels->name.'</span></h3>';
                        echo '<div class="inside"></div>';
                        echo '<span class="spinner"></span>';
                    echo '</div> <!-- // end div#'.$this->prefix.'post_type-'.$pt->name.'-widget-block -->';  
                 
                }
                
                // @todo 
                // add widget_blocks for all taxonomies
                
            
            echo '</div> <!-- // end div#'.$this->prefix.'content_blocks -->';
        }
        
        

        /**
         *  Render "Gridster Options" meta_box content
         *  
         *  @since    1.0                  
         *  
         *  @params   object   current $post
         *  @params   array    metaboxes ID, title, callback, and callback-arguments
         *              
         */
        public function gridster_options_meta_box ( $post, $meta_box ) {

            echo '<p class="howto">' . __( 'Override the default options for this gridster.', 'cbach-wp-gridster' ) . '</p>';

            // all general settings, we'll let the editor overwrite 
            $plugable_options = array(
                'widget_margin_x' => $this->post_settings['dimensions']['widget_margin_x'],
                'widget_margin_y' => $this->post_settings['dimensions']['widget_margin_y'],                
                'widget_base_width' => $this->post_settings['dimensions']['widget_base_width'],                
                'widget_base_height' => $this->post_settings['dimensions']['widget_base_height'],
            );
            
            foreach ( $plugable_options as $name => $value ) {
                // description of label element
                switch ( $name ) {
                    case 'widget_margin_x' :
                        $label = __( 'horizontal margin', 'cbach-wp-gridster' );
                        break;
                    case 'widget_margin_y' :
                        $label = __( 'vertical margin', 'cbach-wp-gridster' );
                        break;
                    case 'widget_base_width' :
                        $label = __( 'widgets base width', 'cbach-wp-gridster' );
                        break;
                    case 'widget_base_height' :
                        $label = __( 'widgets base height', 'cbach-wp-gridster' );
                        break;
                }
                // element id for input and label
                $id =  esc_attr( $this->prefix . 'dimensions-'.$name );
                // name of option to save the value to
                $name = esc_attr( $this->prefix . 'dimensions['.$name.']' );
                // escape field value
                $value = esc_attr( $value );
                echo '<p><label for="'.$id.'" class="short-text-integer">'.$label;
                echo '<input type="number" id="'.$id.'" name="'.$name.'" value="'.$value.'" class="short-text short-text-integer alignright" /></label></p>';              
            }  
        }
        

                
        /**
         *  Get list of all post_types used as widget-blocks
         *  
         *  @since    1.0
         *  
         *  @return   array   of post_type names, in the form array( 'post_type_name' => 'post_type_name' )
         *  
         */                                                              
        private function get_post_types_as_widget_blocks () {
        
            $args = array(
                'public' => true,
                '_builtin' => false,
            );
            $args = apply_filters( 'gridster_get_post_types_as_widget_blocks_args', $args );
            $post_types = get_post_types( $args );
            
            return apply_filters( 'gridster_post_types_as_widget_blocks', $post_types );
        }
        
        
        
/***************************************************************************************************************************************************************************************************
 *
 *  SETTINGS
 *  
 ***************************************************************************************************************************************************************************************************/

        protected function get_default_settings () {
            return array(
              'version' => $this->version,
              'have_gridster_posts' => false,
              'widget_margin_x'  =>  10,
              'widget_margin_y'  =>  10,
              'widget_base_width' => 150,
              'widget_base_height' => 150,
#              'extra_rows' => 0,
#              'extra_cols' => 0,
              'min_cols' => 1,
#              'min_rows' => 15,
#              'max_size_x' => 6,
#              'max_size_y' => 6, 
#              'resizable_aspect_ratio' => false,  
                                                 
          	);
        }



        /**
         *  Check for the existence of any gridster posts
         *  and sets proper option to default_settings         
         *  
         *  @used in 
         *  - redirect_edit_to_post_new_when_no_posts_exist() 
         *  - and admin_menu()
         *  
         *  @since    1.1
         *  
         */                                                              
        public function have_gridster_posts ( ) {
        
            // ok, we do have some posts saved yet
            if( $this->default_settings['have_gridster_posts'] )
                return;
                
            // look for existing posts
            $have_posts = get_posts( array( 'post_type' => $this->cpt_gridster, 'posts_per_page' => -1, 'post_status' => 'any' ) );
            
            // update plugin options, if we've posts
            if ( !empty( $have_posts ) ) {
                $this->default_settings['have_gridster_posts'] = true;
                update_option( $this->default_settings_name, $this->default_settings );
            }
        }



        /**
         *   Loads general settings 
         *   
         *   from the database into their respective arrays. 
         *   Uses array_merge to merge with default values if they're missing.
         *   
         *   @since    1.0    
         *   
         *   @todo     improve fn(), like http://wordpress.stackexchange.com/a/49797
         *   @todo     maybe add check for required WP Version, like http://www.presscoders.com/2011/11/deactivate-wordpress-plugin-automatically/                                
         */
        public function load_settings() {
          	
            // get settings from DB
            $default_settings = (array) get_option( $this->default_settings_name );
            $this->default_settings = $default_settings;
          	
          	// Merge with defaults, if some are missing
          	$this->default_settings = array_merge( 
                $this->get_default_settings(), 
                $this->default_settings 
            );

            if ( is_admin() ) {

                global $post;
                
                if ( is_object( $post ) ) {
                    $this->load_post_settings( $post->ID );                
                } elseif ( isset( $_GET['post'] ) ) {
                    $this->load_post_settings( $_GET['post'] );                
                } else {
                    $this->load_post_settings( null );                
                }
            }
        }
  
  
  
        /**
         *   Loads post_meta of current gridster 
         *   
         *   @since    1.0      
         */
        public function load_post_settings( $post_id ) {
        
            // already loaded ?
            if ( isset( $this->post_settings['query_posts_not_in'] ) )
                return;

            // get layout of this gridster
            $this->post_settings['layout'] = get_post_meta( $post_id, '_gridster_layout', true );
            
            // get dimensions of this gridster
            $dimensions = ( $dims = get_post_meta( $post_id, '_gridster_dimensions', true ) ) ? $dims : array(); 
            $this->post_settings['dimensions'] = array_merge( $this->default_settings, $dimensions );            
            
            // get used post->IDs within this gridster
            $not_in = get_post_meta( $post_id, '_gridster_query_posts_not_in', true );
            $this->post_settings['query_posts_not_in'] = ( !empty( $not_in ) ) ? explode( ',', $not_in ) : array( 0 );
        }
        
        
              
        /**
         *  Add Options Page to the settings menu
         *  
         *  @since    1.0
         *  
         */                        
        public function admin_menu () {
             // Add Settings page to default Settings Menu
          	 add_options_page(
                __( 'Gridster Default Options', 'cbach-wp-gridster' ),
                _x( 'Gridster', 'title of options page', 'cbach-wp-gridster' ),
                'manage_options',
                $this->default_settings_slug,
                array( &$this, 'settings_page' )
            );
            
            // do we have gridster posts yet
            if( $this->default_settings['have_gridster_posts'] )
                return;
            
            // remove "All Gridster" Page from "Gridster" post_type menu, if nothing can be shown there            
            remove_submenu_page( 'edit.php?post_type=' . $this->cpt_gridster, 'edit.php?post_type=' . $this->cpt_gridster );
        }
        
        
        
        /**
         *  Render Settings Page Content
         *  
         *  @since    1.0
         *  
         */                                  
        public function settings_page () {
        		$tab = isset( $_GET['tab'] ) ? $_GET['tab'] : $this->default_settings_name;
        		?>
        		<div class="wrap">
        			<?php $this->plugin_options_tabs(); ?>
        			<form method="post" action="options.php">
        				<?php wp_nonce_field( 'update-options' ); ?>
        				<?php settings_fields( $tab ); ?>
        				<?php do_settings_sections( $tab ); ?>
        				<?php submit_button(); ?>
        			</form>
        		</div>
        		<?php            
        }



      	/**
      	 *   Renders our tabs in the plugin options page
      	 *            
      	 *   Walks through the object's tabs array and prints them one by one. 
      	 *   Provides the heading for the plugin_options_page method.
      	 *   
      	 *   @since    1.0                  
      	 *          
      	 */
      	public function plugin_options_tabs() {
      		$current_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : $this->default_settings_name;
      
      		screen_icon();
      		echo '<h2 class="nav-tab-wrapper">';
      		foreach ( $this->plugin_settings_tabs as $tab_key => $tab_caption ) {
      			$active = $current_tab == $tab_key ? 'nav-tab-active' : '';
      			echo '<a class="nav-tab ' . $active . '" href="?page=' . $this->default_settings_slug . '&tab=' . $tab_key . '">' . $tab_caption . '</a>';	
      		}
      		echo '</h2>';
      	}
  
  

        /**
         *  Whitelist all options and option-groups
         * 
         *  Registers the general settings via the Settings API,
         *  appends the setting to the tabs array of the object.
         *                    
         *  @since    1.0
         *                    
         */
      	public function settings_register_general () {
            
            // Title of general Settings Tab
            $this->plugin_settings_tabs[$this->default_settings_name] = __( 'Default Options for all gridsters','cbach-wp-gridster' );
        		
        		// register settings and validate callback
            register_setting( 
                $this->default_settings_name, 
                $this->default_settings_name, 
                array( &$this, 'settings_validate' ) 
            );
        		
            // define section
            add_settings_section( 
                'section_general', 
                __( 'Default Options for all Gridsters', 'cbach-wp-gridster' ), 
                array( &$this, 'settings_default_section_helptext' ), 
                $this->default_settings_name 
            );
        		
            // register "Columns" Setting
            add_settings_field( 
                'min_cols', 
                __( 'Minimum number columns to create', 'cbach-wp-gridster' ), 
                array( &$this, 'settings_text_input' ), 
                $this->default_settings_name, 
                'section_general',
                array(
                    'name' => 'min_cols',
                    'label_for' => $this->default_settings_name.'-min_cols',
                )            
            );

            // register "horizontal margin" Setting
            add_settings_field( 
                'widget_margin_x', 
                __( 'horizontal margin', 'cbach-wp-gridster' ), 
                array( &$this, 'settings_text_input' ), 
                $this->default_settings_name, 
                'section_general',
                array(
                    'name' => 'widget_margin_x',
                    'label_for' => $this->default_settings_name.'-widget_margin_x',
                )            
            );
            
            // register "vertical margin" Setting
            add_settings_field( 
                'widget_margin_y', 
                __( 'vertical margin', 'cbach-wp-gridster' ), 
                array( &$this, 'settings_text_input' ), 
                $this->default_settings_name, 
                'section_general',
                array(
                    'name' => 'widget_margin_y',
                    'label_for' => $this->default_settings_name.'-widget_margin_y',
                )            
            );            

            // register "widgets base width" Setting
            add_settings_field( 
                'widget_base_width', 
                __( 'widgets base width', 'cbach-wp-gridster' ), 
                array( &$this, 'settings_text_input' ), 
                $this->default_settings_name, 
                'section_general',
                array(
                    'name' => 'widget_base_width',
                    'label_for' => $this->default_settings_name.'-widget_base_width',
                )            
            );
            
            // register "widgets base height" Setting
            add_settings_field( 
                'widget_base_height', 
                __( 'widgets base height', 'cbach-wp-gridster' ), 
                array( &$this, 'settings_text_input' ), 
                $this->default_settings_name, 
                'section_general',
                array(
                    'name' => 'widget_base_height',
                    'label_for' => $this->default_settings_name.'-widget_base_height',
                )            
            );            
            
                
      	}
        
        

        /**
         *  Help Text for default settings section
         *  
         *  @since    1.0
         *  
         */
        public function settings_default_section_helptext () {
            _e( 'Enter the default layout-settings, used by every gridster.', 'cbach-wp-gridster' );
            _e( 'You can alter theese settings for every gridster on its edit-page.', 'cbach-wp-gridster' );            
        }        


     
        /**
         *  Sanitize all settings on save
         *  
         *  @since    1.0
         *  
         *  @params   array   new settings to validate and save
         *  
         *  @return   array   updated settings, validated and ready to been saved                                    
         *  
         */                                             
        public function settings_validate ( $input ) {
            
            // get previously stored options
            $output = $this->default_settings;
        
            // sanitize & validate "Columns" count
            if ( absint( $input['min_cols'] ) ) {
                $output['min_cols'] = $input['min_cols'];
            } else {
                add_settings_error( 
                    $this->default_settings_name, 
                    'invalid-min_cols-integer', 
                    '<strong>'.__( 'Minimum number columns to create', 'cbach-wp-gridster' ).'</strong>: '. __( 'You have entered an invalid value. Value must be of type: integer.', 'cbach-wp-gridster' ) 
                );
            }

            // sanitize & validate "Columns" count
            if ( absint( $input['widget_margin_x'] ) ) {
                $output['widget_margin_x'] = $input['widget_margin_x'];
            } else {
                add_settings_error( 
                    $this->default_settings_name, 
                    'invalid-widget_margin_x-integer', 
                    '<strong>'.__( 'horizontal margin', 'cbach-wp-gridster' ).'</strong>: '. __( 'You have entered an invalid value. Value must be of type: integer.', 'cbach-wp-gridster' ) 
                );
            }
            
            // sanitize & validate "Columns" count
            if ( absint( $input['widget_margin_y'] ) ) {
                $output['widget_margin_y'] = $input['widget_margin_y'];
            } else {
                add_settings_error( 
                    $this->default_settings_name, 
                    'invalid-widget_margin_y-integer', 
                    '<strong>'.__( 'vertical margin', 'cbach-wp-gridster' ).'</strong>: '. __( 'You have entered an invalid value. Value must be of type: integer.', 'cbach-wp-gridster' ) 
                );
            }
            
            // sanitize & validate "Columns" count
            if ( absint( $input['widget_base_width'] ) ) {
                $output['widget_base_width'] = $input['widget_base_width'];
            } else {
                add_settings_error( 
                    $this->default_settings_name, 
                    'invalid-widget_base_width-integer', 
                    '<strong>'.__( 'widgets base width', 'cbach-wp-gridster' ).'</strong>: '. __( 'You have entered an invalid value. Value must be of type: integer.', 'cbach-wp-gridster' ) 
                );
            }                        

            // sanitize & validate "Columns" count
            if ( absint( $input['widget_base_height'] ) ) {
                $output['widget_base_height'] = $input['widget_base_height'];
            } else {
                add_settings_error( 
                    $this->default_settings_name, 
                    'invalid-widget_base_height-integer', 
                    '<strong>'.__( 'widgets base height', 'cbach-wp-gridster' ).'</strong>: '. __( 'You have entered an invalid value. Value must be of type: integer.', 'cbach-wp-gridster' ) 
                );
            }                        
            
            return $output;
        }
        
        
        
        /**
         *  Default callback for all kinds of text inputs
         *  
         *  @since    1.0
         *  
         *  @params   array   all arguments of the specific option
         *  
         */                
        public function settings_text_input( $args ) {
            $id =  esc_attr( $this->default_settings_name.'-'.$args['name'] );
            $name = esc_attr( $this->default_settings_name.'['.$args['name'].']' );
            $value = esc_attr( $this->default_settings[$args['name']] );
            echo '<input type="text" id="'.$id.'" name="'.$name.'" value="'.$value.'" />';
        }
        
        
        
        /**
         *  Save & validate all data from our meta_boxes
         *  
         *  @since    1.0
         *  
         *  @param    int     $post->ID
         *  
         */
        public function save_post ( $post_id ) {
        
            // no chance to be on the right side
            if ( !isset( $_POST['post_type'] ) )
                return;
            
            // check if we're on the right post_type 
            if ( $this->cpt_gridster != $_POST['post_type'] ) 
                return;
               
            // check if the current user is authorised to do this action.            
            $pt = get_post_type_object( $_POST['post_type'] );
            if ( ! current_user_can(  $pt->cap->edit_post , $post_id ) )
                return;
            
            // check if the user intended to change this value.
            if ( ! isset( $_POST[$this->nonce] ) || ! wp_verify_nonce( $_POST[$this->nonce], $this->base_name ) )
                return;
            
            //sanitize user input
            $query_posts_not_in = sanitize_text_field( $_POST[$this->prefix.'query_posts_not_in'] );
            $gridster_layout = sanitize_text_field( $_POST[$this->prefix.'layout'] );
            
            $dimensions = array();            
            foreach ( $_POST[$this->prefix.'dimensions'] as $k => $v ) {
                // validate against Integers
                $v = absint($v);
                // compare to default settings 
                if ( $v && ( $v != $this->default_settings[$k] ) )
                    $dimensions[$k] = $v;
            };            
            
            // save our data
            update_post_meta( $post_id, '_gridster_query_posts_not_in', $query_posts_not_in);
            update_post_meta( $post_id, '_gridster_layout', $gridster_layout);
            if ( !empty( $dimensions ) )
                update_post_meta( $post_id, '_gridster_dimensions', $dimensions );             
            
        }                                                                                   



        /**
         *  Delete post_meta if gridster post is deleted
         *  
         *  @since    1.1
         *  
         *  @param    Int   $post->ID of gridster, that should be deleted
         *  
         */                                                              
        public function delete_post ( $post_id ) {
            
            // get post, to ckeck post_type
            $post = get_post( $post_id );
            
            // only go on for gridster posts
            if ( $post->post_type != $this->cpt_gridster )
                return;
                
            // check if the current user is authorised to do this action.            
            $pt = get_post_type_object( $this->cpt_gridster );
            if ( ! current_user_can(  $pt->cap->edit_post , $post_id ) )
                return;
            
            delete_post_meta( $post_id, '_gridster_layout' );
            delete_post_meta( $post_id, '_gridster_query_posts_not_in' );
            delete_post_meta( $post_id, '_gridster_dimensions' );                         
        }
        
        
        
        /**
         *  Add HTML attribute of "autocomplete='off'" to form element
         *  of post.php and edit.php for gridster post_types
         *  to prevent Browsers from prefilling our hidden inputs
         *  
         *  Because some browsers parse our escaped HTML in the 
         *  'layout' input back as unescaped HTML, which follows in JS errors
         *  when the gridster is initiated
         *  
         *  @since    1.0
         *  
         *  @return   string    HTML autocomplete attribute
         *  
         */                                                                                                                    
        public function post_edit_form_tag () {
            global $post;
            // check if we're on the right post_type 
            if ( $this->cpt_gridster != $post->post_type ) 
                return;
            echo ' autocomplete="off"';
        }



/***************************************************************************************************************************************************************************************************
 *
 *  AJAX Calls
 *  
 ***************************************************************************************************************************************************************************************************/



        /**
         *  Get HTML templated $post by ID
         *  
         *  @since    1.0
         *  
         *  @return   string    html of the $post
         *  
         */       
        public function ajax_gridster_get_post (  ) {
            
            // globalize $post-object, to make it visible to our included template
            global $post;
            
            // verifies the AJAX request to prevent external processing requests  
            check_ajax_referer( $this->nonce, 'nonce' );
            
            // get options Array from AJAX Request
            $options = ( is_array( $_REQUEST['options'] ) ) ? $_REQUEST['options'] : false;
        
            // end if something strange is given by AJAX
            if ( $options === false )
                die();
      
            // end if there is no $post->ID
            if ( ! absint( $options['post_id'] ) )
                die();            
            
            // setup $post object
            $post = get_post( $options['post_id'] );
            
            // get widget dimensions to get best fitting images
            $this->thumbnail_filter_dimensions = array(
                absint( $options['widget_width'] ),
                absint( $options['widget_height'] ),                  
            );
            
            // wrapper to separate real html template content
            // from ui helper on widgets-save 
            echo '<div class="admin-html-holder">';
        		
                // Get and include the template we're going to use
            		include( $this->get_template_hierarchy( $post ) ); 
            
            // end wrapper
            echo '</div>';

            // end AJAX request
            die();
                
        }



        /**
         *  Get name of widget template form used theme
         *  or fallback to default plugin template 
         *  
         *  create a folder "/gridster-templates" 
         *  and copy "gridster-default.php" from plugin directory "/views"
         *  
         *  create different templates for each post_type 
         *  using file-names like "gridster-POST_TYPE.php"
         *  
         *  @since    1.0
         *  
         *  @param    object    $post called by widget
         *  
         *  @return   string    name of template file, to be used
         *  
         */                                                                                                                               
        private function get_template_hierarchy ( $post, $noscript = false  ) {

            $locate_templates_from = array (
                'gridster-templates/gridster-'. $post->post_type . '.php',
                'gridster-templates/gridster-default.php'        
            );
            $locate_templates_from = apply_filters( 'gridster_locate_templates_from', $locate_templates_from, $post );
            
            if ( $theme_file = locate_template( $locate_templates_from ) ) {
        		    $file = $theme_file;
        		} else {
        		    $file = 'views/gridster-default.php';
        		}
            
            if ( $noscript !== false )
                return str_replace( '.php', '-noscript.php' );
            		
        		return $file;
        }
        
        
        
        /**
         *  AJAX callback for TinyMCE modal
         *           
         *  called from TinyMCE-Button-Click 
         *  or from the edit-handler-Button situated at a 
         *  visual replaced shortcode inside the editor
         *  
         *  @since    1.1
         *  
         *  @return   string    HTML of the modal window, showing a list of all available gridster-posts
         *  
         */                                                                                 
        public function ajax_gridster_shortcode_update_modal ( ) {

            // verifies the AJAX request to prevent external processing requests  
            check_ajax_referer( $this->nonce, 'nonce' );

            // setup query arguments
            $args = array( 
                'post_type' => $this->cpt_gridster,
                'orderby'=> 'title',
                'order' => 'ASC',
                'post_status' => 'publish',
                'posts_per_page' => -1,                
            );
            
            // get gridster posts as array of objects
            $gridster = new WP_Query( $args );

            // start output
            echo '<html id="' . $this->prefix . 'modal-content">';
            echo '<head>';
            
            // append CSS 
            // for debug only
            $date = new DateTime();
#            echo '<link rel="stylesheet" media="screen" type="text/css" href="' . plugins_url( '/css/gridster_admin.css', __FILE__ ) . '">';
            echo '<link rel="stylesheet" media="screen" type="text/css" href="' . includes_url( '/js/tinymce/themes/advanced/skins/wp_theme/dialog.css?cache=' . $date->getTimestamp(), __FILE__ ) . '">';            
            echo '<link rel="stylesheet" media="screen" type="text/css" href="' . plugins_url( '/css/gridster_admin.css?cache=' . $date->getTimestamp(), __FILE__ ) . '">';            
            echo '<script language="javascript" type="text/javascript" src="' . plugins_url( '/tinymce/tinymce_gridster_shortcode_modal.js?cache=' . $date->getTimestamp(), __FILE__ ) . '" /></script>';            

            echo '</head><body>';

            // posts available ?
            if( $gridster->have_posts() ) : 
                
                echo '<h2>' . __( 'Choose your Gridster to embed here.', 'cbach-wp-gridster' ) . '</h2>';
                while ( $gridster->have_posts()) : $gridster->the_post();

                    echo '<p>';
                        echo '<label for="'.$this->prefix.'choose_shortcode_list_el-' . get_the_ID() . '">';
                            echo '<input type="radio" name="' . $this->prefix . 'choose_shortcode_list" id="' . $this->prefix . 'choose_shortcode_list_el-' . get_the_ID() . '" value="' . esc_attr( '[' . $this->gridster_shortcode . ' id="' . get_the_ID() . '" title="' . get_the_title() . '"]' ) . '">';                
                            echo get_the_title() . ' ';
                            echo '<small class="modified-date howto alignright">'.
                                     '<abbr title="' . sprintf(
                                        __('Last edited by %1$s on %2$s at %3$s'), 
                                        esc_html( get_the_modified_author() ), 
                                        get_the_modified_date(), 
                                        get_the_modified_date('H:i')
                                        ) . 
                                        '">' . get_the_modified_date('d.m.\'y') . 
                                     '</abbr>'.
                                 '</small>';                
                        echo '</label>';                
                    echo '</p>';                

                endwhile;
            
            // no?, then give a hint
            else:
            
                echo '<div class="error">';
                    echo '<h2>' . __( 'No Gridster here, yet.', 'cbach-wp-gridster' ) . '</h2>';
                    echo '<p>' . __( 'You do not have published any gridster.', 'cbach-wp-gridster' ) . '</p>';
                    echo '<p>' . sprintf( 
                        __( 'Go on and <a onclick="javascript:window.top.location.href=\'%1$s\'; return false; " href="%1$s" title="Go to the Gridster Edit Screen (will load a new page)">publish at least one</a>.', 'cbach-wp-gridster' ),
                        admin_url( 'edit.php?post_type=' . $this->cpt_gridster ) 
                        ) . '</p>';            
                echo '</div>';
                
            endif;
            
            // end output
            echo '</body></html>';
            
            // end AJAX properly
            die();
        }



        /**
         *  AJAX callback for listing posts per post_type
         *  inside the widget-blocks
         *  used for default lists and search-results
         *  
         *  @since    1.1
         *  
         *  return    string    HTML of available posts or error message
         *  
         */                                                                                
        public function ajax_get_posts_by_type_widget_block (  ) {

            // verifies the AJAX request to prevent external processing requests  
            check_ajax_referer( $this->nonce, 'nonce' );
          
            // get options Array from AJAX Request
            $options = ( is_array( $_REQUEST['options'] ) ) ? $_REQUEST['options'] : false;
        
            // end if something strange is given by AJAX
            if ( $options === false )
                die();
      
            // what post_type to loop
            $pt = get_post_type_object( $options['post_type'] );
            
            // get current page
            $paged = ( !empty( $options['paged'] ) ) ? $options['paged'] : 1;
            $paged_prev = $paged - 1;            
            $paged_next = $paged + 1;
            
            // define query arguments
            $gridster_args = array(
              'orderby'=> 'modified',
              'order' => 'DESC',
              'post_type' => $pt->name,
              'post_status' => 'publish',
              'posts_per_page' => 10,
              'post__not_in' => $this->post_settings['query_posts_not_in'],
              'paged' => $paged,
            );
            
            // do search
            $s = null;
            if ( !empty( $options['search'] ) ) {
                $s = $options['search'];
                $gridster_args['s'] = $s;
            }
            
            $args = apply_filters( 'gridster_get_posts_by_type_query_args', $gridster_args, $pt );
            $gridster_last = $html = $post_links = null;
            $gridster_last = new WP_Query($gridster_args);
            
            if( $gridster_last->have_posts() ) : 

                while ( $gridster_last->have_posts()) : $gridster_last->the_post();

                    $post_links .= '<li rel="' . get_the_ID() . '">' . 
                                       '<span title="' . esc_attr( get_the_excerpt() ) . '">' . get_the_title() .'</span>'.
                                       '<small class="modified-date howto alignright">'.
                                           '<abbr title="' . sprintf(
                                              __('Last edited by %1$s on %2$s at %3$s'), 
                                              esc_html( get_the_modified_author() ), 
                                              get_the_modified_date(), 
                                              get_the_modified_date('H:i')
                                              ) . 
                                              '">' . get_the_modified_date('d.m.\'y') . 
                                            '</abbr>'.
                                        '</small>' .
                                   '</li>';

                endwhile;
                
                $html .= '<ul>' . $post_links . '</ul>';

            else:
            
                $html .= '<div class="error"><p>' . __( 'Nothing found.', 'cbach-wp-gridster') . '</p></div>';                
            
            endif;

            $html .= '<div class="widget-block-paginav">';
                $html .= '<button type="button" class="alignleft button-secondary get-previous-posts widget-blocks-pagination" data-search="' .  $s . '" data-paged="' .  $paged_prev . '" ' . ( ( $paged_prev < 1 || $paged_prev == $paged ) ? 'disabled="disabled"' : '' ) . ' title="'. sprintf( __( 'Get previous %s', 'cbach-wp-gridster' ), $pt->labels->name ) .'">&laquo;</button>';                
                $html .= '<input type="text" class="'. $this->prefix.'search-posts-by-type" name="'. $this->prefix.'search-posts-by-type" placeholder="'.__( 'Search', 'cbach-wp-gridster' ).'" value="'.$s.'" title="' . __( 'Type searchterm  & wait 2 seconds', 'cbach-wp-gridster' ) .'">';
                $html .= '<button type="button" class="alignright button-secondary get-next-posts widget-blocks-pagination" data-search="' .  $s . '" data-paged="' . $paged_next . '" ' . ( ( $paged_next > $gridster_last->max_num_pages || $paged_next == $paged ) ? 'disabled="disabled"' : '' ) . ' title="'. sprintf( __( 'Get next %s', 'cbach-wp-gridster' ), $pt->labels->name ) .'">&raquo;</button>';
            $html .= '</div>';

            wp_reset_query();  
            
            echo $html;
            
            die();
        }
        
        
        
        public function ajax_get_textile_markup_for_jeditable () {
            if ( !empty( $_POST['value'] ) ) {
                $v = $_POST['value'];
            } elseif( !empty( $_GET['value'] ) ) {
                $v = $_GET['value'];            
            } else {
                die();            
            }

            
            include_once '/libs/Textile.php';
            $t = new Textile();
            /* What is echoed back will be shown in webpage after editing.*/
            echo $t->TextileThis( stripslashes( $v ) );        
        } 
        
        
                
/***************************************************************************************************************************************************************************************************
 *
 *  FILTERS
 *  
 ***************************************************************************************************************************************************************************************************/


        
        /**
         *  Add built-in post_types "Post" & "Page" to list
         *  used by get_widget_blocks() to show contents, usable as gridster widgets
         *  
         *  @since    1.0                  
         *  
         *  @params   array   of post_type names           
         *  
         *  @return   array   new populated list of post_type names 
         *  
         */                                                                     
        public function filter_gridster_post_types_as_widget_blocks ( $post_types ) {
            $new_post_types = array();
            $new_post_types['post'] = 'post';
            $new_post_types['page'] = 'page';
            return array_merge( $new_post_types, $post_types ); 
        }                          



        /**
         *  Add "Settings" link to plugin list
         *  
         *  @since    1.0     
         *             
         *  @param    array   list of all links
         *  
         *  @return   array   list of updated links
         *  
         */                                                              
        public function plugin_action_links ( $links ) {
            $links[] = '<a href="' . admin_url( 'options-general.php?page=' . $this->default_settings_slug ) . '">'. __('Settings') .'</a>';
            return $links;        
        }
        
    
    
        /**
         *   Add additional links to the plugin description
         *   
         *  @since    1.0
         *  
         *  @param    array     predefined list of links, containing 1. Author-URL, 2. Plugin-URL
         *  @param    string    current plugin file
         *  
         *  @return   array     updated list of links
         *  
         */               
        function plugin_row_meta ( $links, $file ) {
         
            // are we really on gridster plugin
            if ( $file == $this->base_name ) {
                return array_merge(
                    $links,
                    array( 
                        '<a href="https://github.com/carstingaxion/cbach-wp-gridster/issues">' . __( 'Report issues', 'cbach-wp-gridster' ) . '</a>', 
                        '<a href="http://wordpress.org/support/plugin/gridster">' . __( 'Support', 'cbach-wp-gridster' ) . '</a>',              
                    )
                );
            }
            return $links;
        }



        /**
         *  Filter image size on AJAX requests
         *  
         *  get correct fitting images, matching the widget size
         *  works for calls on
         *    - the_post_thumbnail()
         *    - get_the_post_thumbnail()
         *    -
         *    
         *  @since    1.0
         *  
         *  @param    string|array    image size defined in function call, maybe i.e. 'thumbnail-size-string' or array( 100,100)
         *  
         *  @return   array           dimensions of the gridster widget, that is calling
         *  
         */                                                                                                                                      
        public function filter_image_size_on_ajax_request ( $size ) {
            if ( !empty( $this->thumbnail_filter_dimensions ) && defined( 'DOING_AJAX' ) && constant( 'DOING_AJAX' )  )
                return $this->thumbnail_filter_dimensions;
            else
                return $size;
        }
        
        

        /**
         *  Load TinyMCE Plugin for 
         *  visual replacement of shortcode inside the editor
         *  
         *  @since    1.1
         *  
         *  @param    array   all loaded TinyMCE plugins
         *  
         *  @return   array   all loaded TinyMCE plugins
         *  
         */                                                                                 
        public function mce_external_plugins ( $plugin_array ) {
#            $plugin_array['gridster_shortcode'] = plugins_url( '/tinymce/tinymce_gridster_shortcode_plugin.js', __FILE__ );      
            // for debug only
            $date = new DateTime();
            $plugin_array['gridster_shortcode'] = plugins_url( '/tinymce/tinymce_gridster_shortcode_plugin.js?cache=' . $date->getTimestamp(), __FILE__ );
            return $plugin_array;
        }
        
        
        
        /**
         *  Load editor style into TinyMCE 
         *  containing CSS for our graphical shortcode replacement
         *  
         *  @since    1.1
         *  
         *  @param    array   tinymce options
         *  
         *  @return   array   updated tinymce options with new editor styles appended
         *  
         */                                                                              
        public function tiny_mce_before_init ( $editor_styles ) {
#            $editor_styles['content_css'] .= ',' . plugins_url( '/css/gridster_shortcode_editor-style.'.$this->minified_css_files.'css' , __FILE__ );
            // for debug only
            $date = new DateTime();
            $editor_styles['content_css'] .= ',' . plugins_url( '/css/gridster_shortcode_editor-style.css?cache=' . $date->getTimestamp() , __FILE__ );
            return $editor_styles;        
        }
        
        
        
        /**
         *  Registers TinyMCE Button
         *  
         *  @since    1.1
         *  
         *  @param    array   tinymce buttons
         *  
         *  @return   array   updated tinymce buttons including "Insert Gridster" Button
         *  
         */                                        
        public function mce_buttons ( $buttons ) {
          	// inserts a separator between existing buttons and our new one
          	array_push( $buttons, '|', 'gridster_shortcode' );
          	return $buttons;
        }
        
        


        /**
         *  TinyMCE Plugin Localization
         *  
         *  @since    1.1                  
         *
         *  @see      http://dnaber.de/blog/2012/wordpress-tinymce-plugin-mit-dialogbox/
         *  @param    array     external languages files per Plugin
         *           
         *  @return   array     updated external languages files per Plugin
         *           
         */
        public function mce_external_languages( $mce_external_languages ) {
          	$mce_external_languages[ 'gridster_shortcode' ] = plugin_dir_path( __FILE__ ) . 'tinymce/i18n/mce_locale.php';
          	return $mce_external_languages;
        }



        /**
         *  Add Body Class if Shortcode is found
         *  
         *  @since    1.2
         *  
         *  @param    array   body classes
         *  
         *  @return   array   updated body classes
         *  
         */                                                                                
        function body_class( $classes ) {
            global $post;
            if (isset( $post->post_content ) && false !== stripos( $post->post_content, '[' . $this->gridster_shortcode ) ) {
                array_push( $classes, 'gridster-not-loaded' );
            }
            return $classes;
        }
        

        
/***************************************************************************************************************************************************************************************************
 *
 *  SHORTCODES
 *  
 ***************************************************************************************************************************************************************************************************/



         public function shortcode_render_gridster ( $atts ) {
            
            // extract working variables and fallback to defaults
            extract( shortcode_atts( array(
          		'id' => '',
              'title' => ''
          	), $atts ) );

          	// abort if ID is not present 
            if ( !$id || !absint( $id ) )
                return;
            
            // you are not allowed to see ...
            if ( ! $this->is_visible( $id ) )
                return;
            
            // load settings of this gridster
            $this->load_post_settings( $id );
            
            // if post_meta exists and has layout value
            if ( ! $layout = $this->post_settings['layout'] )
                return;
            
            // define this, to load scripts & styles accordingly
            $this->shortcode_used = true;   

            $output  = '<div class="gridster-wrap">'.
                       '<ul class="gridster">';
            
            $widgets = json_decode( $layout );
            foreach ( $widgets as $widget ) {
                $output .= '<li data-sizex="'.$widget->size_x.'" data-sizey="'.$widget->size_y.'" data-col="'.$widget->col.'" data-row="'.$widget->row.'" class="' . implode( ' ', get_post_class( '', $widget->id ) ) . '">';
                $output .= html_entity_decode( $widget->html );
                $output .= '</li>';
            }
                                 
            $output .= '</ul></div>';
        
            return $output; 
        }


/***************************************************************************************************************************************************************************************************
 *
 *  HELPER
 *  
 ***************************************************************************************************************************************************************************************************/
  
  
  
        /**
         *  Helper to insert associative array element into existing array to special position
         *  
         *  @source   http://stackoverflow.com/a/3353956
         */         
        private function array_insert($arr, $insert, $position) {
            $i = 0;
            foreach ($arr as $key => $value) {
                    if ($i == $position) {
                            foreach ($insert as $ikey => $ivalue) {
                                    $ret[$ikey] = $ivalue;
                            }
                    }
                    $ret[$key] = $value;
                    $i++;
            }
            return $ret;
        }



        /**
         *  Multi-dimensional array_search()
         *  
         */
        private function multidimensional_array_search ( $array, $key_to_look, $value_to_look  ) {
            foreach( $array as $key => $sub_array ) {
                if( $sub_array[$key_to_look] == $value_to_look) return $key;
            }
            return FALSE;
        }



        /**
         *  set current_screen object
         *  
         *  @since    1.0
         *  
         */                                            
        public function current_screen ( $current_screen ) {
            $this->current_screen = $current_screen;
        }
        
        
        
        /**
         *  Conditional to check content visibility of given post
         *  
         *  @since    1.1
         *  
         *  @param    int     $post->ID
         *  
         *  @return   bool    wether the $post is visible to the user or not
         *  
         */
        private function is_visible ( $post_id ) {
        
            //
            $post = get_post( $post_id );
            
            // if public, everything is fine
            if ( $post->post_status == 'publish' )
                return true;
            
            // check if the current user is authorised to do this action.            
            $pt = get_post_type_object( $post->post_type );
            if ( current_user_can(  $pt->cap->edit_post , $post_id ) )
                return true;
                
            // nothing matched, so hide this post    
            return false;    
        }                                                                                 
        
        
    } 
} // if class exists

if( class_exists( 'cbach_wpGridster' ) ) {
    
    // Initalize the plugin
    $cbach_wpGridster = new cbach_wpGridster();
    
    // On first activation
    register_activation_hook( __FILE__, array( &$cbach_wpGridster, 'activate' ) );
    // On deactivation
    register_activation_hook( __FILE__, array( &$cbach_wpGridster, 'deactivate' ) ); 
    // On delete
    register_activation_hook( __FILE__, array( &$cbach_wpGridster, 'uninstall' ) );        
}