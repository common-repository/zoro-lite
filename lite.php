<?php
/**
 * Asura Connector (Legacy — Asura v2.x)
 *
 * @wordpress-plugin
 * Plugin Name:         Asura Connector (Legacy — Asura v2.x)
 * Description:         Access to design sets collections managed by the Asura plugin
 * Version:             1.0.2
 * Author:              thelostasura
 * Author URI:          https://thelostasura.com/
 * Requires at least:   5.5
 * Tested up to:        5.5.3
 * Requires PHP:        7.3
 * 
 * @package             Asura Connector (Legacy — Asura v2.x)
 * @author              thelostasura
 * @link                https://thelostasura.com/
 * @since               1.0.0
 * @copyright           2020 thelostasura
 * 
 * Romans 12:12 (ASV)  
 * rejoicing in hope; patient in tribulation; continuing stedfastly in prayer;
 * 
 * Roma 12:12 (TB)  
 * Bersukacitalah dalam pengharapan, sabarlah dalam kesesakan, dan bertekunlah dalam doa! 
 * 
 * https://alkitab.app/v/f27a6d7e714e
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| White Label
|--------------------------------------------------------------------------
*/

define( 'ZL_WHITE_LABEL', [
    'plugin_name' => 'Asura Connector',
    'plugin_url' => 'https://wordpress.org/plugins/zoro-lite',
    'company_name' => 'Asura Web Designer Company',
    'company_url' => 'https://thelostasura.com/designsets'
]);


/*
|--------------------------------------------------------------------------
| Autoload
|--------------------------------------------------------------------------
*/

define( 'ZL_VERSION', '1.0.2' );
define( 'ZL_PLUGIN_FILE', __FILE__ );
define( 'ZL_PLUGIN_DIR', __DIR__ );
define( 'ZL_PLUGIN_URL', plugins_url( '', __FILE__ ) . '/' );

require_once ZL_PLUGIN_DIR . '/vendor/autoload.php';

use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Medoo\Medoo;

/*
|--------------------------------------------------------------------------
| Activation & Deactivation Hook
|--------------------------------------------------------------------------
*/

function activate_zl() {
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $zl_providers = "CREATE TABLE {$wpdb->prefix}zl_providers (
        id BIGINT NOT NULL AUTO_INCREMENT,
        uid VARCHAR(255) NOT NULL,
        site_title VARCHAR(255) NOT NULL,
        provider VARCHAR(255) NOT NULL,
        namespace VARCHAR(255) NOT NULL,
        version VARCHAR(255) NOT NULL,
        api_key VARCHAR(255) NOT NULL,
        api_secret VARCHAR(255) NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};";
    dbDelta( $zl_providers );

    $zl_licenses = "CREATE TABLE {$wpdb->prefix}zl_licenses (
        id BIGINT NOT NULL AUTO_INCREMENT,
        uid VARCHAR(255) NOT NULL,
        provider_id BIGINT NOT NULL,
        license VARCHAR(255) NOT NULL,
        hash VARCHAR(255) NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        expire_at TIMESTAMP NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};";
    dbDelta( $zl_licenses );

}
register_activation_hook( __FILE__, 'activate_zl' );


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

class ZLNotice 
{
    protected $types = [
        'error',
        'success',
        'warning',
        'info',
    ];

    public function __construct() {}

    public function init() {
        foreach ( $this->types as $type ) {
            $messages = get_transient( 'zl_notice_' . $type );

            if ( $messages && is_array( $messages ) ) {
                foreach ( $messages as $message ) {
                    echo sprintf(
                        '<div class="notice notice-%s is-dismissible"><p><b>'. ZL_WHITE_LABEL['plugin_name'] .'</b>: %s</p></div>',
                        $type,
                        $message
                    );
                }

                delete_transient( 'zl_notice_' . $type );
            }
        }
    }

    public static function add( $level, $message, $code = 0, $duration = 60 ) {
        $messages = get_transient( 'zl_notice_' . $level );

        if ( $messages && is_array( $messages ) ) {
            if (!in_array($message, $messages)) {
                $messages[] = $message;
            }
        } else {
            $messages = [ $message ];
        }

        set_transient( 'zl_notice_' . $level, $messages, $duration );
    }

    public static function error( $message ) {
        self::add( 'error', $message );
    }

    public static function success( $message ) {
        self::add( 'success', $message );
    }

    public static function warning( $message ) {
        self::add( 'warning', $message );
    }

    public static function info( $message ) {
        self::add( 'info', $message );
    }
}
add_action('admin_notices', function() {
    $notices = new ZLNotice();
    $notices->init();
});

class ZLCache
{
    private static $instances = [];

    private static $cache;
    
    protected function __construct() 
    {
        // Create a new Container object, needed by the cache manager.
        $container = new Container;

        // The CacheManager creates the cache "repository" based on config values
        // which are loaded from the config class in the container.
        // More about the config class can be found in the config component; for now we will use an array
        $container['config'] = [
            'cache.default' => 'file',
            'cache.stores.file' => [
                'driver' => 'file',
                'path' => __DIR__ . '/storage/framework/cache/data'
            ]
        ];

        // To use the file cache driver we need an instance of Illuminate's Filesystem, also stored in the container
        $container['files'] = new Filesystem;

        // Create the CacheManager
        $cacheManager = new CacheManager($container);

        // Get the default cache driver (file in this case)
        self::$cache = $cacheManager->store();

        // Or, if you have multiple drivers:
        // $cache = $cacheManager->store('file');
    }
    
    protected function __clone() { }

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    public static function getInstance(): ZLCache
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }

    public static function __callStatic($method, $args)
    {
        return self::getInstance()::$cache->{$method}(...$args);
    }
}

class ZLDB
{
    private static $instances = [];

    private static $medoo;
    
    protected function __construct() 
    {
        global $wpdb;
        self::$medoo = new Medoo([
            'database_type' => 'mysql',
            'database_name' => $wpdb->dbname,
            'server' => $wpdb->dbhost,
            'username' => $wpdb->dbuser,
            'password' => $wpdb->dbpassword,
            'prefix' => $wpdb->prefix,
        ]);
    }
    
    protected function __clone() { }

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    public static function getInstance(): ZLDB
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }

    public static function __callStatic($method, $args)
    {
        return self::getInstance()::$medoo->{$method}(...$args);
    }
}

class ZLHttp
{
    private static $instances = [];

    private static $client;
    
    protected function __construct() 
    {
        self::$client = new HttpFactory();
    }
    
    protected function __clone() { }

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    public static function getInstance(): ZLHttp
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }

    public static function __callStatic($method, $args)
    {
        return self::getInstance()::$client->{$method}(...$args);
    }
}

function zl_redirect_js($location)
{
    echo "<script>window.location.href='{$location}';</script>";
}


/*
|--------------------------------------------------------------------------
| Scripts & Styles
|--------------------------------------------------------------------------
*/

wp_register_style('fontawesome_css', 'https://kit-pro.fontawesome.com/releases/v5.15.1/css/pro.css', [], null);

/*
|--------------------------------------------------------------------------
| Providers & License
|--------------------------------------------------------------------------
*/


function zl_source_sites()
{
    $sources_sites = [];
    $providers = ZLDB::select('zl_providers', '*');

    foreach ($providers as $provider) {
        $licenses = ZLDB::select('zl_licenses', '*', [
            'provider_id' => $provider['id'],
        ]);
    
        foreach ($licenses as $license) {
            $tmp_term = ZLCache::remember("terms_{$license['uid']}", Carbon::now()->addHour(), function() use ($provider, $license) {
                $response = ZLHttp::acceptJson()->get("{$provider['provider']}/wp-json/{$provider['namespace']}/{$provider['version']}/licenses/{$license['license']}/terms", [
                    'api_key' => $provider['api_key'],
                    'api_secret' => $provider['api_secret'],
                    'domain' => home_url(),
                ]);
                $rBody = json_decode($response->body());
                
                if ( !$response->successful() ) {
                    ZLNotice::error("<code>{$rBody->code}</code>: {$rBody->message} ");
                } else {
                    return $rBody;
                }
            });


            foreach ($tmp_term as $term) {
                $sources_sites["tla_{$provider['uid']}_{$license['uid']}_{$term->slug}"] = [
                    'label' => ucfirst($term->name). " [{$provider['site_title']}]", 
                    'url' => $provider['provider'], 
                    'accesskey' =>  '', 
                    'system' => true
                ];

            }

        }
    }

    return $sources_sites;
}

if ( defined( 'CT_VERSION' ) ) {
    global $ct_source_sites;
    $zl_site = zl_source_sites();
    $ct_source_sites = array_merge($zl_site, $ct_source_sites);

    // dd($ct_source_sites);
}

/*
|--------------------------------------------------------------------------
| Asura API
|--------------------------------------------------------------------------
*/


function zl_get_items_from_source() 
{
    $name = isset( $_REQUEST['name'] ) ? sanitize_text_field( $_REQUEST['name'] ) : false;

    if ( ! Str::startsWith( $name, 'tla_' ) ) {
        return ct_get_items_from_source();
    }
    $name = Str::replaceFirst( 'tla_', '', $name );

    $zl_pair = Str::of( $name )->explode( '_', 3 );

    list($provider_uid, $license_uid, $slug) = $zl_pair;

    $provider = ZLDB::get('zl_providers', '*', [
        'uid' => $provider_uid,
    ]);

    if (!$provider) {
        exit;
    }

    $license = ZLDB::get('zl_licenses', '*', [
        'provider_id' => $provider['id'],
        'uid' => $license_uid,
    ]);

    if (!$license) {
        exit;
    }

    $data = ZLCache::remember("getItemsFromSource_{$provider['uid']}_{$license['uid']}_{$slug}", Carbon::now()->addMinutes(5), function() use ($provider, $license, $slug) {
        $response = ZLHttp::acceptJson()->get("{$provider['provider']}/wp-json/{$provider['namespace']}/{$provider['version']}/oxygenbuilder/items", [
            'api_key' => $provider['api_key'],
            'api_secret' => $provider['api_secret'],
            'domain' => home_url(),
            'hash' => $license['hash'],
            'term' => $slug,
        ]);
        $rBody = json_decode($response->body());
        if ( $response->successful() ) {
            foreach ($rBody->components as $key => $component) {
                $rBody->components[$key]->source = "tla_{$provider['uid']}_{$license['uid']}_{$slug}";
            }
            foreach ($rBody->pages as $key => $page) {
                $rBody->pages[$key]->source = "tla_{$provider['uid']}_{$license['uid']}_{$slug}";
            }
            return $rBody;
        }
    });

    if ($data) {
        return wp_send_json($data);
    }
    
}


function zl_get_page_from_source() 
{
    $source = isset( $_REQUEST['source'] ) ? sanitize_text_field( base64_decode($_REQUEST['source']) ) : false;

    if ( ! Str::startsWith( $source, 'tla_' ) ) {
        return ct_get_page_from_source();
    }
    $source = Str::replaceFirst( 'tla_', '', $source );

    $zl_pair = Str::of( $source )->explode( '_', 3 );

    list($provider_uid, $license_uid, $slug) = $zl_pair;

    $provider = ZLDB::get('zl_providers', '*', [
        'uid' => $provider_uid,
    ]);

    if (!$provider) {
        exit;
    }

    $license = ZLDB::get('zl_licenses', '*', [
        'provider_id' => $provider['id'],
        'uid' => $license_uid,
    ]);

    if (!$license) {
        exit;
    }

    $data = ZLCache::remember("getPageFromSource_{$provider['uid']}_{$license['uid']}_{$slug}_{$_REQUEST['id']}", Carbon::now()->addMinutes(5), function() use ($provider, $license, $slug) {
        $response = ZLHttp::acceptJson()->get("{$provider['provider']}/wp-json/{$provider['namespace']}/{$provider['version']}/oxygenbuilder/pagesclasses/{$_REQUEST['id']}", [
            'api_key' => $provider['api_key'],
            'api_secret' => $provider['api_secret'],
            'domain' => home_url(),
            'hash' => $license['hash'],
            'term' => $slug,
        ]);

        $components = [];
        $classes = [];
        $colors = [];
        $lookupTable = [];

        $rBody = json_decode($response->body(), true);
        
        if ( $response->successful() ) {
            if(isset($rBody['components'])){
                $components = $rBody['components'];
            }
            if(isset($rBody['classes']))
                $classes = $rBody['classes'];
            if(isset($rBody['colors']))
                $colors = $rBody['colors'];
            if(isset($rBody['lookuptable']))
                $lookupTable = $rBody['lookuptable'];
        }

        foreach ($components as $key => $component) {

            // if it is a reusable do something about it.
            if($component['name'] === 'ct_reusable') {
                unset($components[$key]);
            }

            if(!isset($components[$key])) {
                continue; // it could have bene deleted while dealing with a reusable in the previous step
            }

            $component[$key] = ct_base64_encode_decode_tree([$component], true)[0];

            if(isset($component['children'])) {
                if(is_array($components[$key]['children'])) {
                    $components[$key]['children'] = ct_recursively_manage_reusables($components[$key]['children'], [], 'asura');
                }
            }

        }

        $output = [
            'components' => $components
        ];

        if(sizeof($classes) > 0) {
            $output['classes'] = $classes;
        }

        if(sizeof($colors) > 0) {
            $output['colors'] = $colors;
        }

        if(sizeof($lookupTable) > 0) {
            $output['lookuptable'] = $lookupTable;
        }

        return $output;

    });

    if ($data) {
        return wp_send_json($data);
    }

}

function zl_get_component_from_source() 
{
    $source = isset( $_REQUEST['source'] ) ? sanitize_text_field( base64_decode($_REQUEST['source']) ) : false;

    if ( ! Str::startsWith( $source, 'tla_' ) ) {
        return ct_get_component_from_source();
    }
    $source = Str::replaceFirst( 'tla_', '', $source );

    $zl_pair = Str::of( $source )->explode( '_', 3 );

    list($provider_uid, $license_uid, $slug) = $zl_pair;

    $provider = ZLDB::get('zl_providers', '*', [
        'uid' => $provider_uid,
    ]);

    if (!$provider) {
        exit;
    }

    $license = ZLDB::get('zl_licenses', '*', [
        'provider_id' => $provider['id'],
        'uid' => $license_uid,
    ]);

    if (!$license) {
        exit;
    }

    $data = ZLCache::remember("getComponentFromSource_{$provider['uid']}_{$license['uid']}_{$slug}_{$_REQUEST['id']}_{$_REQUEST['page']}", Carbon::now()->addMinutes(5), function() use ($provider, $license, $slug) {
        $response = ZLHttp::acceptJson()->get("{$provider['provider']}/wp-json/{$provider['namespace']}/{$provider['version']}/oxygenbuilder/componentsclasses/{$_REQUEST['id']}/{$_REQUEST['page']}", [
            'api_key' => $provider['api_key'],
            'api_secret' => $provider['api_secret'],
            'domain' => home_url(),
            'hash' => $license['hash'],
            'term' => $slug,
        ]);

        $component = [];
        $classes = [];
        $colors = [];
        $lookupTable = [];

        $rBody = json_decode($response->body(), true);
        
        if ( $response->successful() ) {
            if(isset($rBody['component']))
                $component = $rBody['component'];
            if(isset($rBody['classes']))
                $classes = $rBody['classes'];
            if(isset($rBody['colors']))
                $colors = $rBody['colors'];
            if(isset($rBody['lookuptable']))
                $lookupTable = $rBody['lookuptable'];
        }

        $component = ct_base64_encode_decode_tree([$component], true)[0];

        $output = [
            'component' => $component
        ];

        if(sizeof($classes) > 0) {
            $output['classes'] = $classes;
        }

        if(sizeof($colors) > 0) {
            $output['colors'] = $colors;
        }

        if(sizeof($lookupTable) > 0) {
            $output['lookuptable'] = $lookupTable;
        }

        return $output;

    });

    if ($data) {
        return wp_send_json($data);
    }

}

/*
|--------------------------------------------------------------------------
| Ajax
|--------------------------------------------------------------------------
*/

function zl_new_style_api_call() {
    $call_type = isset( $_REQUEST['call_type'] ) ? sanitize_text_field( $_REQUEST['call_type'] ) : false;
    
    ct_new_style_api_call_security_check( $call_type );

    switch( $call_type ) {
        case 'setup_default_data':
            ct_setup_default_data();
        break;
        case 'get_component_from_source':
            zl_get_component_from_source();
        break;
        case 'get_page_from_source':
            zl_get_page_from_source();
        break;
        case 'get_items_from_source':
            zl_get_items_from_source();
        break;
        case 'get_stuff_from_source':
            ct_get_stuff_from_source();
        break;
    }

    die();
}

remove_action( 'wp_ajax_ct_new_style_api_call', 'ct_new_style_api_call' );
add_action( 'wp_ajax_ct_new_style_api_call', 'zl_new_style_api_call' );


/*
|--------------------------------------------------------------------------
| AdminMenu
|--------------------------------------------------------------------------
*/

function zl_providers_page()
{
    add_submenu_page(
        'ct_dashboard_page',
        ZL_WHITE_LABEL['plugin_name'],
        ZL_WHITE_LABEL['plugin_name'],
        'manage_options',
        'zl',
        'zl_providers_page_callback'
    );

    add_submenu_page(
        null,
        'License',
        'License',
        'manage_options',
        'zl_licenses',
        'zl_licenses_page_callback'
    );
}
add_action('admin_menu', 'zl_providers_page');

function zl_licenses_page_callback()
{
    wp_enqueue_style('fontawesome_css');

    if ( ! isset( $_REQUEST['provider'] ) || empty( $_REQUEST['provider'] ) ) {
        zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
        exit;
    }

    $provider = ZLDB::get('zl_providers', '*', [
        'id' => $_REQUEST['provider'],
    ]);

    if (!$provider) {
        zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
        exit;
    }

    if ( 
        $_SERVER['REQUEST_METHOD'] === 'GET' 
        && isset($_REQUEST['action']) 
        && $_REQUEST['action'] == 'sync'
        && wp_verify_nonce( $_REQUEST['_wpnonce'], 'zl_licenses_sync' ) 
    ) {

        $lics = ZLDB::select('zl_licenses', '*', [
            'provider_id' => $provider['id'],
        ]);

        if ($lics) {
            foreach ($lics as $lic) {

                $url = "{$provider['provider']}/wp-json/{$provider['namespace']}/{$provider['version']}";
                $response = ZLHttp::acceptJson()->post("{$url}/licenses/{$lic['license']}/activate", [
                    'api_key' => $provider['api_key'],
                    'api_secret' => $provider['api_secret'],
                    'domain' => home_url(),
                ]);
                $rBody = json_decode($response->body());
        
                if ( !$response->successful() ) {
                    ZLNotice::error("<code><b>{$lic['license']}</b></code>: <code>{$rBody->code}</code>: {$rBody->message} ");
                } else {
                   $update = ZLDB::update('zl_licenses', [
                        'hash' => $rBody->hash,
                        'expire_at' => $rBody->expire_at ? Carbon::parse($rBody->expire_at)->toDateTimeString() : null,
                    ], [
                        'provider_id' => $provider['id'],
                        'id' => $lic['id'],
                    ]);
                }

            }
                
            ZLCache::flush();

            ZLNotice::success('Successfully sync Licenses\' data.');
            ZLNotice::warning('Sync the Licenses\' data may temporarily degrade performance for your website and increase load on your server');
            zl_redirect_js( add_query_arg( [
                'page' => 'zl_licenses',
                'provider' => $provider['id']
            ], get_admin_url().'admin.php' ) );
            exit;
        }



    }


    if ( 
        $_SERVER['REQUEST_METHOD'] === 'GET' 
        && isset($_REQUEST['action']) 
        && $_REQUEST['action'] == 'revoke'
        && isset($_REQUEST['license'])
        && wp_verify_nonce( $_REQUEST['_wpnonce'], 'zl_revoke_license' ) 
    ) {
        $exist = ZLDB::get('zl_licenses', '*', [
            'provider_id' => $provider['id'],
            'id' => $_REQUEST['license'],
        ]);

        if ($exist) {
            ZLDB::delete('zl_licenses', [
                'provider_id' => $provider['id'],
                'id' => $_REQUEST['license'],
            ]);
            
            ZLCache::flush();
            ZLNotice::success( 'The license key removed from database' );
        } else {
            ZLNotice::error( 'The license key not exist in database' );
        }

        zl_redirect_js( add_query_arg( [
            'page' => 'zl_licenses',
            'provider' => $provider['id']
        ], get_admin_url().'admin.php' ) );
        exit;
    }

    if ( 
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_REQUEST['zl_license_key'])
        && !empty($_REQUEST['zl_license_key'])
        && wp_verify_nonce( $_REQUEST['_wpnonce'], 'zl_add_license' ) 
    ) {
        
        $license_key = $_REQUEST['zl_license_key'];
        
        $exist = ZLDB::get('zl_licenses', '*', [
            'provider_id' => $provider['id'],
            'license' => $license_key,
        ]);

        if ($exist) {
            ZLNotice::error( 'The license key already exist in database' );
            zl_redirect_js( add_query_arg( [
                'page' => 'zl_licenses',
                'provider' => $provider['id']
            ], get_admin_url().'admin.php' ) );
            exit;
        }

        $url = "{$provider['provider']}/wp-json/{$provider['namespace']}/{$provider['version']}";
        $response = ZLHttp::acceptJson()->post("{$url}/licenses/{$license_key}/activate", [
            'api_key' => $provider['api_key'],
            'api_secret' => $provider['api_secret'],
            'domain' => home_url(),
        ]);
        $rBody = json_decode($response->body());

        if ( !$response->successful() ) {

            ZLNotice::error("<code>{$rBody->code}</code>: {$rBody->message} ");
            zl_redirect_js( add_query_arg( [
                'page' => 'zl_licenses',
                'provider' => $provider['id']
            ], get_admin_url().'admin.php' ) );
            exit;

        } else {
            $insert = ZLDB::insert('zl_licenses', [
                'uid' => Str::random(5),
                'provider_id' => $provider['id'],
                'license' => $rBody->key,
                'hash' => $rBody->hash,
                'expire_at' => $rBody->expire_at ? Carbon::parse($rBody->expire_at)->toDateTimeString() : null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);

            if (!$insert) {
                ZLNotice::error( 'Failed to add the provider to database' );
                zl_redirect_js( add_query_arg( [
                    'page' => 'zl_licenses',
                    'provider' => $provider['id']
                ], get_admin_url().'admin.php' ) );
                exit;
            }

        }

        ZLCache::flush();
        ZLNotice::success('License key added successfully.');
        zl_redirect_js( add_query_arg( [
            'page' => 'zl_licenses',
            'provider' => $provider['id']
        ], get_admin_url().'admin.php' ) );
        exit;

        // return ZLDB::id();
    }

    $licenses = ZLCache::remember( 'licenses', Carbon::now()->addMinutes( 10 ), function() use ($provider) {
        return ZLDB::select( 'zl_licenses', '*', [
            'provider_id' => $provider['id']
        ] );
    } );

    ?>

    
<div class="wrap nosubsub">
    <h1 class="wp-heading-inline"><?php echo ZL_WHITE_LABEL['plugin_name']; ?></h1>
    <h2 class="wp-heading-inline">Licenses — <?php echo "{$provider['site_title']} ({$provider['provider']})"; ?> </h2>

    <a href="<?php echo add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ); ?>"><i class="fas fa-long-arrow-alt-left"></i> Back To Provider Page</a>

    <hr class="wp-header-end">

    <div id="col-container" class="wp-clearfix">

        <div id="col-left" style="width: 25%;">
            <div class="col-wrap">
                <div class="form-wrap">
                    <h2>Add License</h2>
                    <form method="post">
                        <?php wp_nonce_field('zl_add_license');?>

                        <div class="form-field form-required term-name-wrap">
                            <label for="zl_license_key">License Key</label>
                            <input name="zl_license_key" id="zl_license_key" type="text" value="" size="40" aria-required="true">
                            <p>Get it from your email or customer page after you purchase a design set.</p>
                        </div>
                        <?php submit_button('Add New License'); ?>
                    </form>
                </div>
            </div>
        </div>
        <div id="col-right" style="width: 75%;">
            <div class="col-wrap">
                <table class="wp-list-table widefat fixed striped table-view-list tags">
                    <thead>
                        <tr>
                            <th scope="col" id="licensekey" class="manage-column column-licensekey">
                                <span>License Key</span>
                            </th>
                            <th scope="col" id="expire" class="manage-column column-expire">
                                <span>Expire At</span>
                            </th>
                            <th scope="col" id="terms" class="manage-column column-terms">
                                <span>Design Sets</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="the-list">
                    <?php foreach ($licenses as $key => $license): ?>
                        <tr class="level-0">
                        
                            <td class="name column-name has-row-actions column-primary">
                                <strong>
                                    <a class="row-title" title="<?php echo $license['uid']; ?>"> <?php echo $license['license']; ?>  </a>
                                </strong>
                                <br>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="">Edit</a> | 
                                    </span>
                                    <span class="delete">
                                        <a href="<?php
                                            echo add_query_arg([
                                                'page' => 'zl_licenses',
                                                'provider' => $provider['id'],
                                                'license' => $license['id'],
                                                'action' => 'revoke',
                                                '_wpnonce' => wp_create_nonce( 'zl_revoke_license' )
                                            ], get_admin_url().'admin.php');
                                        ?>" class="delete-tag">Revoke</a>
                                    </span>
                                    <!-- <span class="view">
                                        <a href="<?php
                                            echo add_query_arg([
                                                'page' => 'zl_licenses',
                                                'provider' => $provider['id'],
                                            ], get_admin_url().'admin.php');
                                        ?>">License Keys</a>
                                    </span> -->
                                </div>
                            </td>

                            <td>
                                <?php if ($license['expire_at']): ?>
                                    <a style="<?php echo (Carbon::parse($license['expire_at'])->lessThan(Carbon::today())) ? 'color: red;' : ''; ?>"
                                    >
                                        <?php echo Carbon::parse($license['expire_at'])->format('M d, Y'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                    $tmp_term = ZLCache::remember("terms_{$license['uid']}", Carbon::now()->addHour(), function() use ($provider, $license) {
                                        $response = ZLHttp::acceptJson()->get("{$provider['provider']}/wp-json/{$provider['namespace']}/{$provider['version']}/licenses/{$license['license']}/terms", [
                                            'api_key' => $provider['api_key'],
                                            'api_secret' => $provider['api_secret'],
                                            'domain' => home_url(),
                                        ]);
                                        $rBody = json_decode($response->body());
                                        
                                        if ( !$response->successful() ) {
                                            ZLNotice::error("<code>{$rBody->code}</code>: {$rBody->message} ");
                                        } else {
                                            return $rBody;
                                        }
                                    });

                                    // dd($tmp_term);

                                foreach ($tmp_term as $term): ?>
                                            
                                    <b> <?php echo ucfirst($term->name); ?> </b>
                                    — expire: 
                                    <?php if ($term->pivot->expire_at): ?>
                                        <a style="<?php echo (Carbon::parse($term->pivot->expire_at)->lessThan(Carbon::today())) ? 'color: red;' : ''; ?>"
                                        >
                                            <?php echo Carbon::parse($term->pivot->expire_at)->format('M d, Y'); ?>
                                        </a>
                                    <?php else: ?>
                                        non-expiring
                                    <?php endif; ?>
                                    <br/>
                                <?php endforeach; ?>



                            </td>



                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    
                </table>
                
                <div>
                    <div style="float: left;padding-top:10px;">
                        <span style="padding:4px;">
                            <a title="Force <?php echo ZL_WHITE_LABEL['plugin_name']; ?> to sync data from provider's server." href="<?php
                                echo add_query_arg([
                                    'page' => 'zl_licenses',
                                    'provider' => $provider['id'],
                                    'action' => 'sync',
                                    '_wpnonce' => wp_create_nonce( 'zl_licenses_sync' )
                                ], get_admin_url().'admin.php');
                            ?>" style="color:#444444;text-decoration: none !important;">
                                <i class="fas fa-sync fa-lg" style="color: #dc3545"></i>
                                <b>Sync Licenses' data</b>
                            </a>
                        </span>
                    </div>
                    <div style="float: right;padding-top:10px;">
                        <span style="padding:4px;background-color:#fff1a8;transition: opacity 0.4s ease-out 0s; opacity: 1;">
                            <a target="_blank" href="<?php echo ZL_WHITE_LABEL['company_url']; ?>" style="color:#444444;text-decoration: none !important;">
                                <i class="fas fa-ad fa-lg"></i> Powered by
                                <b> <?php echo ZL_WHITE_LABEL['company_name']; ?></b>
                            </a>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
    
    <?php
}

function zl_providers_page_callback()
{
    wp_enqueue_style('fontawesome_css');

    if ( 
        $_SERVER['REQUEST_METHOD'] === 'GET' 
        && isset($_REQUEST['action']) 
        && $_REQUEST['action'] == 'purge'
        && wp_verify_nonce( $_REQUEST['_wpnonce'], 'zl_purge' ) 
    ) {
        ZLCache::flush();

        ZLNotice::success('Successfully purged cache');
        ZLNotice::warning('Purging the cache may temporarily degrade performance for your website and increase load on your server');
        zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
        exit;
    }

    if ( 
        $_SERVER['REQUEST_METHOD'] === 'POST' 
        && wp_verify_nonce( $_REQUEST['_wpnonce'], 'zl_add_provider' ) 
    ) {
        
        if( ! base64_decode( $_REQUEST['zl_provider_string'] ) ) {
            ZLNotice::error( 'Provider String should be base64 encoded string.' );
            zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
            exit;
        }

        if ( ! is_scalar( $_REQUEST['zl_provider_string'] ) && ! method_exists( $_REQUEST['zl_provider_string'], '__toString' ) ) {
            ZLNotice::error( ZL_WHITE_LABEL['plugin_name'].' String should be json string. [1]' );
            zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
            exit;
        }

        $provider = json_decode( base64_decode( $_REQUEST['zl_provider_string'] ) );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            ZLNotice::error( ZL_WHITE_LABEL['plugin_name'].' String should be json string. [2]' );
            zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
            exit;
        }

        if ( ! is_object( $provider )
            || ! isset( $provider->api_key )
            || ! isset( $provider->api_secret )
            || ! isset( $provider->site_title )
            || ! isset( $provider->provider )
            || ! isset( $provider->namespace )
            || ! isset( $provider->version )
        ) {
            ZLNotice::error( ZL_WHITE_LABEL['plugin_name'].' String doesn\'t contain connection config' );
            zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
            exit;
        }

        $exist = ZLDB::get('zl_providers', '*', [
            'provider' => $provider->provider,
        ]);

        if ($exist) {
            ZLNotice::error( 'The provider already exist in database' );
            zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
            exit;
        }

        $insert = ZLDB::insert('zl_providers', [
            'uid' => Str::random(5),
            'api_key' => $provider->api_key,
            'api_secret' => $provider->api_secret,
            'site_title' => $provider->site_title,
            'provider' => $provider->provider,
            'namespace' => $provider->namespace,
            'version' => $provider->version,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);


        if (!$insert) {
            ZLNotice::error( 'Failed to add the provider to database' );
            zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
            exit;
        }

        ZLCache::flush();

        ZLNotice::success( 'Success added the provider to database' );
        zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
        exit;

        // return ZLDB::id();

    }

    if ( 
        $_SERVER['REQUEST_METHOD'] === 'GET' 
        && isset($_REQUEST['action']) 
        && $_REQUEST['action'] == 'revoke'
        && isset($_REQUEST['provider'])
        && wp_verify_nonce( $_REQUEST['_wpnonce'], 'zl_revoke_provider' ) 
    ) {
        $exist = ZLDB::get('zl_providers', '*', [
            'id' => $_REQUEST['provider'],
        ]);

        if ($exist) {
            ZLDB::delete('zl_licenses', [
                'provider_id' => $_REQUEST['provider'],
            ]);

            ZLDB::delete('zl_providers', [
                'id' => $_REQUEST['provider'],
            ]);
            
            ZLCache::flush();
            ZLNotice::success( 'The provider removed from database' );
        } else {
            ZLNotice::error( 'The provider not exist in database' );
        }

        zl_redirect_js( add_query_arg( 'page', 'zl', get_admin_url().'admin.php' ) );
        exit;
    }

    $providers = ZLCache::remember( 'providers', Carbon::now()->addMinutes( 10 ), function() {
        return ZLDB::select( 'zl_providers', '*' );
    } );

    ?>

<div class="wrap nosubsub">
    <h1 class="wp-heading-inline"><?php echo ZL_WHITE_LABEL['plugin_name']; ?></h1>
    <h2 class="wp-heading-inline">Providers</h2>

    <hr class="wp-header-end">

    <div id="col-container" class="wp-clearfix">

        <div id="col-left">
            <div class="col-wrap">
                <div class="form-wrap">
                    <h2>Add Provider</h2>
                    <form method="post">
                        <?php wp_nonce_field('zl_add_provider');?>

                        <div class="form-field form-required term-name-wrap">
                            <label for="zl_provider_string"><?php echo ZL_WHITE_LABEL['plugin_name']; ?> String</label>
                            <input name="zl_provider_string" id="zl_provider_string" type="text" value="" size="40" aria-required="true">
                            <p>Ask your design set seller.</p>
                        </div>
                        <?php submit_button('Add New Provider'); ?>
                    </form>
                </div>
            </div>
        </div>
        <div id="col-right">
            <div class="col-wrap">
                <table class="wp-list-table widefat fixed striped table-view-list tags">
                    <thead>
                        <tr>
                            <th scope="col" id="provider" class="manage-column column-provider">
                                <span>Provider</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="the-list">
                    <?php foreach ($providers as $key => $provider): ?>
                        <tr class="level-0">
                        
                            <td class="name column-name has-row-actions column-primary">
                                <strong>
                                    <a class="row-title" title="<?php echo $provider['uid']; ?>"> <?php echo "{$provider['site_title']} ({$provider['provider']})"; ?>  </a>
                                </strong>
                                <br>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="">Edit</a> | 
                                    </span>
                                    <span class="delete">
                                        <a href="<?php
                                            echo add_query_arg([
                                                'page' => 'zl',
                                                'provider' => $provider['id'],
                                                'action' => 'revoke',
                                                '_wpnonce' => wp_create_nonce( 'zl_revoke_provider' )
                                            ], get_admin_url().'admin.php');
                                        ?>" class="delete-tag">Revoke</a> | 
                                    </span>
                                    <span class="view">
                                        <a href="<?php
                                            echo add_query_arg([
                                                'page' => 'zl_licenses',
                                                'provider' => $provider['id'],
                                            ], get_admin_url().'admin.php');
                                        ?>">License Keys</a>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    
                </table>
                <div>
                    <div style="float: left;padding-top:10px;">
                        <span style="padding:4px;">
                            <a title="Clear cached files to force <?php echo ZL_WHITE_LABEL['plugin_name']; ?> to fetch a fresh version of those datas." href="<?php
                                echo add_query_arg([
                                    'page' => 'zl',
                                    'action' => 'purge',
                                    '_wpnonce' => wp_create_nonce( 'zl_purge' )
                                ], get_admin_url().'admin.php');
                            ?>" style="color:#444444;text-decoration: none !important;">
                                <i class="fas fa-broom fa-lg" style="color: #dc3545"></i>
                                <b>Purge cache</b>
                            </a>
                        </span>
                    </div>
                    <div style="float: right;padding-top:10px;">
                        <span style="padding:4px;background-color:#fff1a8;transition: opacity 0.4s ease-out 0s; opacity: 1;">
                            <a target="_blank" href="<?php echo ZL_WHITE_LABEL['company_url']; ?>" style="color:#444444;text-decoration: none !important;">
                                <i class="fas fa-ad fa-lg"></i> Powered by
                                <b> <?php echo ZL_WHITE_LABEL['company_name']; ?></b>
                            </a>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
    <?php
}
