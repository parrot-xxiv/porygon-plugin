<?php

/**
 * Plugin Name:     Porygon Plugin 
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     Adding records (CPT) upon the upload of files, with CSV and XLSX formats as acceptable options.
 * Author:          Eren 
 * Author URI:      YOUR SITE HERE
 * Text Domain:     entry
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Porygon
 */

// Your code starts here.
namespace Eren\Porygon;

// If this file is called directly, abort.
if (! defined('WPINC')) die;

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = __NAMESPACE__ . '\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $file = plugin_dir_path(__FILE__) . 'inc/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});


// Activation hook
register_activation_hook(__FILE__, function () {
    // Perform actions on plugin activation
    // ... your code here ...
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Perform actions on plugin deactivation
    // ... your code here ...
});

// Instantiate Admin Page
$porygonAdminPage = new PorygonImportCsvPage();
$porygonMetaPage = new PorygonMetaPage();
$porygonBulkEditPage = new PorygonBulkEditPage();
