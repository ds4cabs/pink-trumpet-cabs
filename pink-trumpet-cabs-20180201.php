<?php
/*
Plugin Name: Pink Trumpet Cabs
Description: This plugin allows dynamic wordpress pages with unique URLs
Author: Michael Lin
Author URI: http://www.pinktrumpetassociates.com
Version: 1.0
Text Domain: 	pink_trumpet
Domain Path: 	languages/
*/

error_reporting(E_ALL);
ini_set('display_errors', 1);

// set DEFAULT TIME ZONE TO PST
date_default_timezone_set('America/Los_Angeles');


//include( plugin_dir_path( __FILE__ ) . 'post-types/virtual-page.php');

include( plugin_dir_path( __FILE__ ) . "/fastcache/phpfastcache.php");
include( plugin_dir_path( __FILE__ ) . 'wa_client.php');

class DataManager
{
    //const  DEFAULT_CACHE_TIMEOUT = 3600;  // 60 min
    const  DEFAULT_CACHE_TIMEOUT = 7200;  // 2 hrs
    const  CACHE_ENABLED = true;

    static $initialized = false;
    static $wa_client   = null;
    static $cache       = null;

    // initialize WA API client
    private static function init()
    {
        if ( self::$initialized ) return true;

        // if WA client fails to initialize return
        self::$wa_client = new wa_client();

        // initialize cache
        self::$cache = phpFastCache();

        self::$initialized = true;

        return true;
        
    }

    public static function reset_cache()
    {
        if ( self::$cache == null )
        {
            self::$cache = phpFastCache();
        }

        self::$cache->clean();
        echo "Data Refresh Completed";
    }

    // fetch object from cache
    public static function get_cache( $key )
    {
        if ( self::$cache == null )
        {
            self::$cache = phpFastCache();
        }

        if ( !DataManager::CACHE_ENABLED ) {

            self::$cache->clean();
            return null;
        }


        $object = self::$cache->get( $key );

if (false && $object != null)
{
    print_r("<pre>");
    print_r("get_cache() >> FOUND CACHE ENTRY \n ");
    print_r("Hash KEY:  $key  \n");
    print_r($object);
    print_r("</pre>");
}

        return $object;
    }

    // store object in cache (set to 600 seconds timeout)
    public static function set_cache( $key, $object, $timeout = DataManager::DEFAULT_CACHE_TIMEOUT )
    {
        if ( self::$cache == null )
        {
            self::$cache = phpFastCache();
        }

        //return self::$cache->set( $key, $object, $timeout);
        return self::$cache->set( $key, $object, 0);
    }


    public static function get_account()
    {
        self::init();
        return self::$wa_client->account;
    }

    public static function get_contact($contactid)
    {
        self::init();
        return self::$wa_client->get_contact( $contactid );
    }

    public static function get_event_list( $showdetail,  $showupcoming, $showrecent,  $tags, $eventid )
    {
        // check if cached object exists
        $hashkey = "Event|$showdetail|$showupcoming|$showrecent|$tags|$eventid";
        $events = self::get_cache( $hashkey );
        if ( $events != null ) {
            return $events;
        }

        // initialize
        self::init();

        // fetch events
        $events = self::$wa_client->get_event_list( $showdetail,  $showupcoming, $showrecent,  $tags, $eventid );

        // exclude events without tags or sponsor-only events (unless sponsor is the tag)
        // TODO: remove magic constant "sponsor"
        if ( empty($eventid) )
        {
            $events = array_filter($events, function($v) use ($tags)
            {
              $hasTags       = sizeof($v['Tags']) > 0;
              $hasSponsorTag = ( in_array("sponsor", $v['Tags']) ? 1 : 0);
              $public_access = ( $v['AccessLevel'] == "Public" );
              $result        = $public_access && (( $tags == "sponsor" && $hasSponsorTag ) || ( $tags != "sponsor" && $hasTags && (!$hasSponsorTag) ));
              return $result;
            });

            // sort by date
            usort($events, function($a, $b) { // anonymous function
                  // compare dates only
                  return strtotime($a['StartDate']) - strtotime($b['StartDate']);
            });
        }

        // cache events
        self::set_cache( $hashkey, $events);

        return $events;
    }
}

class Pink_Trumpet
{
    //const CONST_BASE_URL_WA            = "https://cabs.wildapricot.org";
    const CONST_BASE_URL_WA            = "http://cabsweb.org";
    const CONST_EVENT_DETAIL_PAGE_NAME   = "Event Detail Template";
    const CONST_SPONSOR_DETAIL_PAGE_NAME = "Sponsor Detail Template";
    const CONST_REGISTRATION_PAGE_NAME   = "Registration Template";

    private $vars;          // place holder for query variables

    // Wild Apricot client
//    private $wa_client;

    // track if class has been initialized already
    private $pnk_initialized = false;


    //  constructor
    public function __construct()
    {
        // Installation and uninstallation hooks
        register_activation_hook    ( __FILE__,                 array($this, 'plugin_activate'));
        register_deactivation_hook  ( __FILE__,                 array($this, 'plugin_deactivate'));

        add_action                  ('init',                    array($this, 'plugin_init'));
        add_action                  ('admin_init',              array($this, 'admin_init'));
        add_action                  ('admin_menu',              array($this, 'admin_menu'));
        add_action                  ('admin_head',              array($this, 'admin_head'));

        add_filter                  ('query_vars',              array($this, 'plugin_load_query_vars'));
        add_filter                  ('the_title',               array($this, 'plugin_the_title'));
        add_filter                  ('document_title_parts',    array($this, 'plugin_document_title_parts'));
        add_filter                  ('wpseo_title',             array($this, 'plugin_wpseo_title'));

        // short codes
        add_shortcode               ('wa_events',               array($this, 'shortcode_wa_event_list'));
        add_shortcode               ('wa_account',              array($this, 'shortcode_wa_account'));
        add_shortcode               ('wa_ifadmin',              array($this, 'shortcode_wa_ifadmin'));
        add_shortcode               ('wa_cache_reset',          array($this, 'shortcode_wa_cache_reset'));
        

        add_shortcode               ('img',                     array($this,  'shortcode_img'));
        add_shortcode               ('iframe',                  array($this,  'shortcode_iframe'));
        add_shortcode               ('parameter',               array($this,  'shortcode_parameter'));

    }
    
    public function admin_init()
    {
        // Set up the settings for this plugin
        $this->init_settings();
    }

    public function init_settings()
    {
        // register the settings for this plugin
        register_setting('pink_trumpet-group', 'setting_a', '123');
        register_setting('pink_trumpet-group', 'setting_b', 'abc');
        
    }

    public function admin_head()
    {
        if (!current_user_can('update_core'))
        {
            remove_action( 'admin_notices', 'update_nag', 3 );
        }
    }

    public function admin_menu()
    {
        add_options_page('Pink Trumpet Settings', 'Pink Trumpet', 'manage_options', 'pink_trumpet', array(&$this, 'plugin_settings_page'));

        // remove GF caps (all users)
        $currentUser = wp_get_current_user();
        $currentUser->remove_cap('gform_full_access');
        $currentUser->remove_cap( 'gravityforms_edit_forms' );
        $currentUser->remove_cap( 'gravityforms_delete_forms' );
        $currentUser->remove_cap( 'gravityforms_create_form' );
        $currentUser->remove_cap( 'gravityforms_view_settings' );
        $currentUser->remove_cap( 'gravityforms_view_help' );

        if ( self::is_wa_admin() )
        {
/*            
print_r("<pre>current user-------------------------------->");
print_r($currentUser);
print_r("<pre>");
*/            
            $currentUser->add_cap( 'gravityforms_view_entries' );
            $currentUser->add_cap( 'gravityforms_edit_entries' );
            $currentUser->add_cap( 'gravityforms_delete_entries' );
            $currentUser->add_cap( 'gravityforms_export_entries' );
            $currentUser->add_cap( 'gravityforms_view_entry_notes' );
            $currentUser->add_cap( 'gravityforms_edit_entry_notes' );

/*
            $currentUser->remove_cap( 'gravityforms_create_form');
            $currentUser->remove_cap( 'gravityforms_delete_entries');
            $currentUser->remove_cap( 'gravityforms_delete_forms');
            $currentUser->remove_cap( 'gravityforms_edit_entries');
            $currentUser->remove_cap( 'gravityforms_edit_entry_notes');
            $currentUser->remove_cap( 'gravityforms_edit_forms');
            $currentUser->remove_cap( 'gravityforms_edit_settings');
            $currentUser->remove_cap( 'gravityforms_export_entries');
            $currentUser->remove_cap( 'gravityforms_uninstall');
            $currentUser->remove_cap( 'gravityforms_view_entries');
            $currentUser->remove_cap( 'gravityforms_view_entry_notes');
            $currentUser->remove_cap( 'gravityforms_view_settings');
*/            
        }

    }

    public function plugin_settings_page()
    {
        if(!current_user_can('manage_options'))
        {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Render the settings template
        include(sprintf("%s/templates/settings.php", dirname(__FILE__)));
    } // END public function plugin_settings_page()



    function plugin_the_title( $title, $post_id = null )
    {
        // Admin area, bail out
        if( is_admin() )
            return $title;

        if ( $title == self::CONST_EVENT_DETAIL_PAGE_NAME ||
             $title == self::CONST_SPONSOR_DETAIL_PAGE_NAME ||
             $title == self::CONST_REGISTRATION_PAGE_NAME )
        {
            if ( $event_name = urldecode(get_query_var("p_eventname")) ) {
                return "$event_name";
            }
        }

        return $title;
    }


    function plugin_document_title_parts( $title, $post_id = null )
    {
        if ( strpos($title['title'], self::CONST_EVENT_DETAIL_PAGE_NAME) !== false ||
             strpos($title['title'], self::CONST_SPONSOR_DETAIL_PAGE_NAME) !== false ||
             strpos($title['title'], self::CONST_REGISTRATION_PAGE_NAME) !== false
        )
        {
            if ( $event_name = urldecode(get_query_var("p_eventname")) ) {
                $title['title'] = $event_name;
                $title['site'] = "";
            }
        }
        return $title;
    }
    
    
    function plugin_wpseo_title( $title )
    {
        if ( strpos($title, self::CONST_EVENT_DETAIL_PAGE_NAME) !== false ||
             strpos($title, self::CONST_SPONSOR_DETAIL_PAGE_NAME) !== false ||
             strpos($title, self::CONST_REGISTRATION_PAGE_NAME) !== false
        )
        {
            if ( $event_name = urldecode(get_query_var("p_eventname")) ) {
                return $event_name;
            }
        }
        return $title;
    }


    // define [img] shortcode
    public function shortcode_img( $atts, $content = ""  )
    {
        $this->PNK_INIT();

        // extract the attributes into variables
        extract(shortcode_atts(array(
            'src'   => 'src',
            'alt'   => '',
            'class' => '',
            'style' => ''
        ), $atts));

        $target_src = $this->ee_product_data[  $src  ]->value;
        return "<img src=\"$target_src\" alt=\"$alt\" class=\"$class\" style=\"$style\"/>";

    }

    // define [iframe] shortcode
    public function shortcode_iframe( $atts, $content = ""  )
    {
        // extract the attributes into variables
        extract(shortcode_atts(array(
            'width'  => '',
            'height' => '',
            'target' => '',
            'class'  => '',
            'style' => ''
        ), $atts));

        // hard coded to handle eventid only
        $src = strtr($content, array(
            '{$eventid}' => get_query_var("p_eventid")
        ));


        return "<iframe onload='tryToEnableWACookies(\"" . self::CONST_BASE_URL_WA .  "\");'  xsandbox=\"allow-same-origin allow-scripts allow-popups allow-forms\" width=\"$width\" height=\"$height\" class=\"$class\" style=\"$style\" src=\"$src\"></iframe><script  type=\"text/javascript\" language=\"javascript\" src=\"" . self::CONST_BASE_URL_WA  . "/Common/EnableCookies.js\" ></script>";

    }

    // define [parameter name=""] shortcode
    public function shortcode_parameter( $atts, $content = ""  )
    {
        // extract the attributes into variables
        extract(shortcode_atts(array(
            'name'  => '',
        ), $atts));

        return urldecode(get_query_var("p_$name"));
    }


    private function fix_relative_url($content, $baseurl = self::CONST_BASE_URL_WA)
    {
        // fix images & documents
        $output = str_replace(' src="/resources/', " src=\"$baseurl/resources/", $content);
        //$output = str_replace('<img src="/resources/', "<img src=\"$baseurl/resources/", $content);
        $output = str_replace('<a href="/resources/', "<a href=\"$baseurl/resources/", $output);
        return $output;
    }


    // define [wa_events] shortcode
    public function shortcode_wa_event_list( $atts, $content = ""  )
    {
        extract(shortcode_atts(array(
            'showdetail'      => 0,
            'showupcoming'    => 1,
            'showrecent'      => 0,
            'tags'            => '',
            'eventid'         => get_query_var("p_eventid"),
            'debug'           => false
        ), $atts));

        // main & regtype content separated by "||"
        $out = explode( "||", $content);
        $template_main    = trim($out[0]);
        $template_regtype = @$out[1];  // maybe optional

        //$output = "shortcode_wa_event_list >>> \n";
        $output = "";

        $events = DataManager::get_event_list( $showdetail,  $showupcoming, $showrecent,  $tags, $eventid );

        // sort by date
        usort($events, function($a, $b) { // anonymous function
              // compare dates only
              return strtotime($b['StartDate']) - strtotime($a['StartDate']);
        });


        foreach ($events as $event)
        {
            $eventdate = strtotime($event['StartDate']);
            $eventenddate = strtotime($event['EndDate']);
            $eventdescription = @$event['Details']['DescriptionHtml'];

            // genereate registration type content
            $regtypes    = @$event['Details']['RegistrationTypes'];
            $reg_output = "";
            if ($regtypes) {
                foreach ($regtypes as $rtype)
                {
                    $spaces_left = @($rtype['MaximumRegistrantsCount'] - $rtype['CurrentRegistrantsCount']);
                    $reg_output .= strtr($template_regtype, array(
                        '{$regtype-name}'         => $rtype['Name'],
                        '{$regtype-price}'        => $rtype['BasePrice'],
                        '{$regtype-description}'  => str_replace("\n* ", "<li>", $rtype['Description']),
                        '{$regtype-remaining}'    => ( $spaces_left > 0 ? " - $spaces_left left": "")
                    ));
                }
            }
            
            // check if start/end date is the same
            $sDate = date('Y-m-d', $eventdate);
            $eDate = date('Y-m-d', $eventenddate);
            $endTime = ( date('g:i a', $eventenddate) == "12:00 am" ? "" : date('@ g:i a', $eventenddate));
            $endDate = ( $sDate == $eDate  ? "" : date('F j, Y  ', $eventenddate) ) . $endTime;
            
            //wp_trim_words
            $output .= strtr($template_main, array(
                '{$eventid}'        => $event['Id'],
                '{$event}'          => $event['Name'],
                '{$event-encoded}'  => urlencode($event['Name']),
                '{$startdate}'      => date('F j, Y   @ g:i a', $eventdate),
                '{$enddate}'        => $endDate,
                '{$location}'       => $event['Location'],
                '{$description}'    => $this->fix_relative_url($eventdescription),
                '{$register}'       => ($event['RegistrationEnabled'] ? "visible" : "hidden"),
                '{$regtypes}'       => $reg_output
            ));
        }
        
        if ($debug) {
            $output .= "<pre>" . print_r($events,true) . "</pre>";
        }
        return str_replace("<p></p>","",$output);

    }

    // define [wa_event] shortcode
    public function shortcode_wa_account( $atts, $content = ""  )
    {
        // extract wa user id from login
        $currentUser = wp_get_current_user();
        $contactid = str_replace('wa_contact_','',$currentUser->data->user_login);

        $contact = DataManager::get_contact($contactid);

        $output = "<pre>shortcode_wa_account >>> \n";
        //$output .= print_r(DataManager::get_account(), true);
        $output .= print_r($contact, true);
        $output .= "</pre>";

        return $output;
    }

    // define [wa_ifadmin] shortcode
    public static function shortcode_wa_cache_reset( $atts, $content = "")
    {
        DataManager::reset_cache();
        $referrer = $_SERVER['HTTP_REFERER'];
        print_r("<script>document.location.href='$referrer';</script>" );
        //print_r("<a href='' onclick=\"document.location.href='" . $_SERVER['HTTP_REFERER'] .  "'; return false;\">Click to Return</a>" );
    }

    private function is_wa_admin()
    {
        $is_admin = false;
        
        // extract wa user id from login
        $currentUser = wp_get_current_user();
        $contactid = @str_replace('wa_contact_', '', $currentUser->data->user_login);
        $contact = DataManager::get_contact($contactid);

        // extract custom fields
        if ( isset( $contact['FieldValues'] ) )
        {
          $customfields = array();
          foreach ($contact['FieldValues'] as $v) {
              $customfields[$v['FieldName']] = $v['Value'];
          }
          $is_admin = (count(@$customfields['Administrator role']) > 0);
        }
        else $is_admin = false;
        
        return $is_admin;
    }

    // define [wa_ifadmin] shortcode
    public function shortcode_wa_ifadmin( $atts, $content = "")
    {
        $is_admin = self::is_wa_admin();
/*    
        // extract wa user id from login
        $currentUser = wp_get_current_user();
        $contactid = @str_replace('wa_contact_', '', $currentUser->data->user_login);
        $contact = DataManager::get_contact($contactid);

        // extract custom fields
        if ( isset( $contact['FieldValues'] ) )
        {
          $customfields = array();
          foreach ($contact['FieldValues'] as $v) {
              $customfields[$v['FieldName']] = $v['Value'];
          }
          $is_admin = (count(@$customfields['Administrator role']) > 0);
        }
        else $is_admin = false;
*/        

/*
print_r("<pre> IFADMIN \n");
print_r($contact);
print_r(@isset($customfields['Administrator role']));
print_r('len-->' . count($customfields['Administrator role']));
print_r(@($customfields['Administrator role']));
print_r("</pre>");
*/

        // display style if WA admin
//        if ( @$contact['IsAccountAdministrator'] == 1)
        if ( $is_admin )
        {
            $css = @$atts['admincss'];
            return "<style>\n/* ifadmin admincss */ \n" . $css . "\n</style> $content";
            //return "<style>\n/* ifadmin admincss */ \n" . $atts['admincss'] . "\n</style>";
        }

        // display style non admin
        if ( @$contact['MembershipEnabled'] == 1  && @$contact['Status'] == 'Active')
        {
            $css = @$atts['membercss'];
            return "<style>\n/* ifadmin membercss */ \n" . $css . "\n</style> $content";
        }

    }

    // MCL: not used
    public function plugin_template_include( $original_template )
    {
         // do something here
         return $original_template;
    }

    // permalink definition / rewrites
    public function plugin_init()
    {
        // todo: replace event detail template slug
        add_rewrite_rule('events/([^/]+)/([^/]+)/?$'  ,         'index.php?pagename=event-detail-template&p_eventid=$matches[1]&p_eventname=$matches[2]', 'top');
        // todo: replace registration template slug
        add_rewrite_rule('registration/([^/]+)/([^/]+)/?$'  ,   'index.php?pagename=registration-template&p_eventid=$matches[1]&p_eventname=$matches[2]', 'top');
        // todo: replace event detail template slug
        add_rewrite_rule('sponsors/([^/]+)/([^/]+)/?$'  ,       'index.php?pagename=sponsor-detail-template&p_eventid=$matches[1]&p_eventname=$matches[2]', 'top');
    }

    // define query variables to be used with each data object
    public function plugin_load_query_vars($vars)
    {
        array_push($vars, 'p_eventid');
        array_push($vars, 'p_eventname');
        return $vars;
    }

    // activate plugin
    public function plugin_activate()
    {
        $this->plugin_init();
    //    flush_rewrite_rules(true);
        //Ensure the $wp_rewrite global is loaded
        global $wp_rewrite;
        $wp_rewrite->flush_rules( false );

    }

    /**
     * Deactivate the plugin
     */
    public function plugin_deactivate()
    {
         global $wp_rewrite;
         unset($wp_rewrite->extra_rules_top['events/([^/]+)/([^/]+)/?$']);
         unset($wp_rewrite->extra_rules_top['registration/([^/]+)/([^/]+)/?$']);
         unset($wp_rewrite->extra_rules_top['sponsors/([^/]+)/([^/]+)/?$']);
         flush_rewrite_rules();

    } // END public static function deactivate


    public static function PNK_DEBUG( $x )
    {
        echo "<pre>";
        echo "\n BEGIN ====================================  \n";
        print_r($x);
        echo "\n END    ==================================== \n";
        echo "</pre>";
    }


    // execute this before fetching data from form
    // TODO: optimize the init on only fetch data that we need - don't need to prefetch all the data
    public function PNK_INIT( )
    {
        // exit if already initialized
        if ( $this->pnk_initialized ) return;

        $this->pnk_initialized = true;

        // initialize query variables
        $this->p_eventid= get_query_var('p_eventid');

    }


}  // END class definition

// Instantiate plugin

$pink_trumpet = new Pink_Trumpet();

// Add a link to the settings page onto the plugin page
if(isset($pink_trumpet))
{
    // Add the settings link to the plugins page
    function plugin_settings_link($links)
    {
        $settings_link = '<a href="options-general.php?page=pink_trumpet">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    $plugin = plugin_basename(__FILE__);
    add_filter("plugin_action_links_$plugin", 'plugin_settings_link');
}

