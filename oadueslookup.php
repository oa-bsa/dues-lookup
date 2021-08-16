<?php
/*
 * Plugin Name: OA Dues Lookup
 * Plugin URI: https://github.com/oabsa/dues-lookup/
 * Description: Wordpress plugin to use in conjunction with OA LodgeMaster to allow members to look up when they last paid dues
 * Version: 2.1.3
 * Requires at least: 3.0.1
 * Requires PHP: 7.1
 * Author: Dave Miller
 * Author URI: http://twitter.com/justdavemiller
 * Author Email: github@justdave.net
 * GitHub Plugin URI: https://github.com/oabsa/dues-lookup
 * Primary Branch: main
 * Release Asset: true
 * */

/*
 * Copyright (C) 2014-2019 David D. Miller
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

include_once( __DIR__ . '/vendor/autoload.php' );
WP_Dependency_Installer::instance()->run( __DIR__ );
add_action('admin_menu', 'oadueslookup_plugin_menu');
add_action('plugins_loaded', 'oadueslookup_update_db_check');
add_action('wp_loaded', 'oadueslookup_update_shortcodes');
register_activation_hook(__FILE__, 'oadueslookup_install');
register_activation_hook(__FILE__, 'oadueslookup_install_data');
add_action('wp_enqueue_scripts', 'oadueslookup_enqueue_css');

function oadueslookup_enqueue_css()
{
    wp_register_style('oadueslookup-style', plugins_url('style.css', __FILE__));
    wp_enqueue_style('oadueslookup-style');
}

global $oadueslookup_db_version;
$oadueslookup_db_version = 3;

function oadueslookup_create_table($ddl)
{
    global $wpdb;
    $table = "";
    if (preg_match("/create table\s+(\w+)\s/i", $ddl, $match)) {
        $table = $match[1];
    } else {
        return false;
    }
    foreach ($wpdb->get_col("SHOW TABLES", 0) as $tbl) {
        if ($tbl == $table) {
            return true;
        }
    }
    // if we get here it doesn't exist yet, so create it
    $wpdb->query($ddl);
    // check if it worked
    foreach ($wpdb->get_col("SHOW TABLES", 0) as $tbl) {
        if ($tbl == $table) {
            return true;
        }
    }
    return false;
}

function oadueslookup_install()
{
    /* Reference: http://codex.wordpress.org/Creating_Tables_with_Plugins */

    global $wpdb;
    global $oadueslookup_db_version;

    $dbprefix = $wpdb->prefix . "oalm_";

    //
    // CREATE THE TABLES IF THEY DON'T EXIST
    //

    // This code checks if each table exists, and creates it if it doesn't.
    // No checks are made that the DDL for the table actually matches,
    // only if it doesn't exist yet. If the columns or indexes need to
    // change it'll need update code (see below).

    $sql = "CREATE TABLE ${dbprefix}dues_data (
  bsaid                 INT NOT NULL,
  max_dues_year         VARCHAR(4),
  dues_paid_date        DATE,
  level                 VARCHAR(12),
  bsa_reg               TINYINT(1),
  bsa_reg_overridden    TINYINT(1),
  bsa_verify_date       DATE,
  bsa_verify_status     VARCHAR(50),
  PRIMARY KEY (bsaid)
);";
    oadueslookup_create_table($sql);

    //
    // DATABASE UPDATE CODE
    //

    // Check the stored database schema version and compare it to the version
    // required for this version of the plugin.  Run any SQL updates required
    // to bring the DB schema into compliance with the current version.
    // If new tables are created, you don't need to do anything about that
    // here, since the table code above takes care of that.  All that needs
    // to be done here is to make any required changes to existing tables.
    // Don't forget that any changes made here also need to be made to the DDL
    // for the tables above.

    $installed_version = get_option("oadueslookup_db_version");
    if (empty($installed_version)) {
        // if we get here, it's a new install, and the schema will be correct
        // from the initialization of the tables above, so make it the
        // current version so we don't run any update code.
        $installed_version = $oadueslookup_db_version;
        add_option("oadueslookup_db_version", $oadueslookup_db_version);
    }

    if ($installed_version < 2) {
        # Add a column for the Last Audit Date field
        $wpdb->query("ALTER TABLE ${dbprefix}dues_data ADD COLUMN reg_audit_date DATE");
    }

    if ($installed_version < 3) {
        # Drop the old registration audit fields for OALM 4.1.2 or below.
        $wpdb->query("ALTER TABLE ${dbprefix}dues_data DROP COLUMN reg_audit_date");
        $wpdb->query("ALTER TABLE ${dbprefix}dues_data DROP COLUMN reg_audit_result");
        # Add the columns for the BSA registration fields in OALM 4.2.0 and above.
        $wpdb->query("ALTER TABLE ${dbprefix}dues_data ADD COLUMN bsa_reg TINYINT(1)");
        $wpdb->query("ALTER TABLE ${dbprefix}dues_data ADD COLUMN bsa_reg_overridden TINYINT(1)");
        $wpdb->query("ALTER TABLE ${dbprefix}dues_data ADD COLUMN bsa_verify_date DATE");
        $wpdb->query("ALTER TABLE ${dbprefix}dues_data ADD COLUMN bsa_verify_status VARCHAR(50)");
    }

    // insert next database revision update code immediately above this line.
    // don't forget to increment $oadueslookup_db_version at the top of the file.

    if ($installed_version < $oadueslookup_db_version) {
        // updates are done, update the schema version to say we did them
        update_option("oadueslookup_db_version", $oadueslookup_db_version);
    }
}

function oadueslookup_update_db_check()
{
    global $oadueslookup_db_version;
    if (get_site_option("oadueslookup_db_version") != $oadueslookup_db_version) {
        oadueslookup_install();
    }
    # do these here instead of in the starting data insert code because these
    # need to be created if they don't exist when the plugin gets upgraded,
    # too, not just on a new install.  add_option does nothing if the option
    # already exists, sets default value if it does not.
    add_option('oadueslookup_dues_url', 'http://www.example.tld/paydues');
    add_option('oadueslookup_dues_register', '1');
    add_option('oadueslookup_dues_register_msg', 'You must register and login on the MyCouncil site before paying dues.');
    add_option('oadueslookup_update_url', 'http://www.example.tld/paydues');
    add_option('oadueslookup_update_option_text', 'Update Contact Information');
    add_option('oadueslookup_update_option_link_text', 'dues form');
    add_option('oadueslookup_help_email', 'duesadmin@example.tld');
    add_option('oadueslookup_last_import', '1900-01-01');
    add_option('oadueslookup_last_update', '1900-01-01');
    add_option('oadueslookup_max_dues_year', '2016');

}

function oadueslookup_update_shortcodes()
{
    # In version 2.1, we replaced the URL trap with a shortcode.
    # This code converts from the old way to the new way.
    $lookup_slug = get_option('oadueslookup_slug', 'it was not set');
    if (!($lookup_slug === 'it was not set')) {
        $post = wp_insert_post(array(
            'post_type' => 'page',
            'post_name' => $lookup_slug,
            'post_status' => 'publish',
            'post_title' => 'OA Dues Lookup',
            'post_content' => "<!-- wp:shortcode -->\n" .
                              "[oadueslookup]\n" .
                              "<!-- /wp:shortcode -->\n"
        ));
        delete_option('oadueslookup_slug');
        add_option('oadueslookup_oldslug', $lookup_slug);
    }

}

function oadueslookup_insert_sample_data()
{
    global $wpdb;
    $dbprefix = $wpdb->prefix . "oalm_";

    $wpdb->query("INSERT INTO ${dbprefix}dues_data " .
        "(bsaid,    max_dues_year, dues_paid_date, level,        bsa_reg,   bsa_reg_overridden, bsa_verify_date, bsa_verify_status) VALUES " .
        "('123453','2013',         '2012-11-15',   'Brotherhood','1',       '0',                '1900-01-01',   'BSA ID Not Found'), " .
        "('123454','2014',         '2013-12-28',   'Ordeal',     '1',       '0',                '1900-01-01',   'BSA ID Not Found'), " .
        "('123455','2014',         '2013-12-28',   'Brotherhood','1',       '0',                '1900-01-01',   'BSA ID Verified'), " .
        "('123456','2013',         '2013-07-15',   'Ordeal',     '1',       '0',                '1900-01-01',   'BSA ID Verified'), " .
        "('123457','2014',         '2013-12-18',   'Brotherhood','0',       '0',                '1900-01-01',   'BSA ID Found - Data Mismatch'), " .
        "('123458','2013',         '2013-03-15',   'Vigil',      '1',       '0',                '1900-01-01',   'BSA ID Not Found'), " .
        "('123459','2015',         '2014-03-15',   'Ordeal',     '0',       '0',                '1900-01-01',   'Never Run')");
    $wpdb->query($wpdb->prepare("UPDATE ${dbprefix}dues_data SET bsa_verify_date=%s", get_option('oadueslookup_last_update')));
}

function oadueslookup_install_data()
{
    global $wpdb;
    $dbprefix = $wpdb->prefix . "oalm_";

    oadueslookup_insert_sample_data();
}

# Let admin users know about version 2.1 shortcode migration
add_action( 'admin_notices', 'oadueslookup_admin_notices' );
function oadueslookup_admin_notices() {
    $lookup_slug = get_option('oadueslookup_oldslug', 'it was not set');
    if (!($lookup_slug === 'it was not set')) {
        ?><div class="updated">
        <div>
        <p>Your OA Dues Lookup page at <a href="<?php echo esc_html(get_option("home")) . "/" . esc_html($lookup_slug) ?>"><?php echo esc_html(get_option("home")) . "/" . esc_html($lookup_slug) ?></a> was converted from a specially-handled URL to a real WordPress Page, which contains the <code>[oadueslookup]</code> shortcode for the form. You can now use that shortcode on any page to show the dues lookup form.</p>
        </div>
        <div style="float: right;"><a href="?dismiss=oadl_shortcode_update">Dismiss</a></div>
        <div style="clear: both;"></div>
        </div><?php
    }
}
add_action( 'admin_init', 'oadueslookup_dismiss_admin_notices' );
function oadueslookup_dismiss_admin_notices() {
    if ( array_key_exists( 'dismiss', $_GET ) && 'oadl_shortcode_update' === $_GET['dismiss'] ) {
        delete_option('oadueslookup_oldslug');
    }
}

## BEGIN OA TOOLS MENU CODE

# This code is designed to be used in any OA-related plugin. It conditionally
# Adds an "OA Tools" top-level menu in the WP Admin if it doesn't already
# exist. Any OA-related plugins can then add submenus to it.

if (!function_exists('oa_tools_add_menu')) {
    add_action( 'admin_menu', 'oa_tools_add_menu', 9 );
    function oa_tools_add_menu() {
        $oa_tools_icon = <<<EOF
<svg width="4.5in" height="4.5in" viewBox="0 0 450 450" xmlns="http://www.w3.org/2000/svg">
  <path id="Selection" stroke-width="1" d="M 57.00,209.00 C 49.88,204.06 56.38,194.23 49.65,183.00 46.51,177.76 43.26,176.78 41.74,172.96 40.70,170.35 41.18,166.81 40.81,164.00 40.81,164.00 38.34,155.00 38.34,155.00 37.68,150.61 38.05,146.38 36.48,142.00 36.48,142.00 24.74,120.00 24.74,120.00 23.34,116.48 23.56,116.61 23.56,113.01 23.58,108.57 17.46,96.14 15.03,92.00 15.03,92.00 11.30,86.00 11.30,86.00 9.49,81.98 10.91,80.09 9.02,75.00 5.54,65.61 6.88,55.74 5.61,46.00 5.22,43.30 3.84,36.76 5.61,34.63 6.72,33.36 10.39,32.81 12.09,32.35 17.81,30.79 33.50,33.04 39.00,35.52 41.47,36.63 44.62,38.90 47.00,39.40 51.00,40.26 53.70,37.68 62.00,38.04 73.15,38.53 83.25,50.39 93.00,55.57 96.86,57.62 99.91,56.76 104.00,57.09 104.00,57.09 117.00,60.10 117.00,60.10 124.47,62.41 126.86,67.48 134.00,70.29 151.92,77.33 148.34,65.20 169.00,71.93 176.64,74.42 184.03,87.58 194.01,92.04 199.53,94.49 203.12,93.44 208.00,94.78 208.00,94.78 232.00,102.62 232.00,102.62 235.99,103.42 239.88,102.51 244.00,104.03 249.63,106.10 252.85,111.78 256.28,114.42 256.28,114.42 261.72,117.54 261.72,117.54 261.72,117.54 275.00,126.69 275.00,126.69 275.00,126.69 291.66,136.56 291.66,136.56 294.87,139.30 294.58,142.69 298.14,144.26 300.33,145.23 305.92,144.92 309.00,145.46 318.14,147.08 323.64,149.05 329.92,156.00 331.75,158.03 333.75,160.30 334.48,163.00 335.04,165.10 331.86,177.83 330.84,180.00 330.84,180.00 327.15,186.00 327.15,186.00 325.03,190.40 326.49,193.58 325.12,196.91 323.64,200.52 320.40,201.57 319.14,206.00 316.95,213.72 318.06,219.95 309.00,224.43 303.34,227.23 291.62,227.89 285.00,229.08 281.03,229.80 276.08,231.19 274.04,235.08 272.23,238.52 274.99,240.24 278.01,240.97 282.51,242.06 291.59,239.79 293.00,252.00 303.75,242.49 311.44,248.39 309.34,256.00 308.69,258.34 307.36,260.06 306.00,262.00 309.11,264.21 314.53,269.69 318.00,270.08 318.00,270.08 333.00,264.75 333.00,264.75 345.82,261.66 357.21,267.97 364.96,278.01 373.71,289.36 371.94,305.71 366.11,318.00 364.41,321.59 358.78,331.53 353.99,329.83 351.34,328.90 343.44,320.44 341.00,318.00 341.00,318.00 311.00,288.00 311.00,288.00 308.49,285.50 298.03,275.04 295.00,275.04 291.85,275.05 283.50,284.50 281.00,287.00 281.00,287.00 245.00,323.00 245.00,323.00 245.00,323.00 272.01,350.00 272.01,350.00 272.01,350.00 304.00,383.00 304.00,383.00 284.60,401.96 252.83,405.93 239.00,378.00 236.80,373.57 236.95,369.79 237.00,365.00 237.06,360.11 237.58,357.55 239.46,353.00 240.58,350.30 242.87,346.94 242.44,344.00 241.88,340.18 236.63,335.86 234.00,333.00 226.35,341.14 217.28,338.25 218.27,330.00 218.76,325.89 222.27,322.80 225.00,320.00 215.15,319.87 213.01,315.26 214.00,306.00 209.26,304.99 201.30,301.59 198.02,307.15 194.54,313.06 201.56,320.94 200.13,328.00 198.82,334.48 193.45,346.51 187.98,350.30 187.98,350.30 170.00,358.18 170.00,358.18 164.53,361.18 157.28,367.34 151.00,367.74 144.29,368.16 131.80,356.74 128.90,350.99 128.90,350.99 123.96,341.00 123.96,341.00 123.96,341.00 121.83,334.00 121.83,334.00 120.14,330.71 117.03,328.45 114.81,325.00 112.17,320.88 112.02,317.28 110.25,314.00 110.25,314.00 102.65,302.00 102.65,302.00 100.71,298.14 101.85,297.10 100.28,294.28 100.28,294.28 97.03,289.91 97.03,289.91 97.03,289.91 80.99,264.00 80.99,264.00 80.04,261.49 80.04,259.62 80.00,257.00 79.92,250.79 80.28,244.38 76.49,239.00 70.56,230.58 54.98,220.60 57.00,209.00 Z M 27.00,66.00 C 27.50,68.63 27.87,70.62 29.65,72.78 30.86,74.24 32.29,75.17 34.01,75.92 48.78,82.34 48.10,50.54 34.01,60.43 34.01,60.43 27.00,66.00 27.00,66.00 Z M 98.00,80.88 C 96.81,90.85 101.41,91.03 109.08,92.18 112.05,92.62 116.03,93.36 118.55,91.21 125.29,85.51 112.08,81.00 108.00,80.88 108.00,80.88 98.00,80.88 98.00,80.88 Z M 80.00,105.00 C 80.46,110.41 80.11,109.41 81.53,115.00 82.05,117.03 82.47,119.12 83.45,121.00 84.47,122.95 86.50,125.80 88.98,125.80 91.28,125.81 93.61,122.78 95.17,121.28 96.74,119.77 99.26,117.76 100.36,115.96 103.70,110.52 100.16,105.31 95.00,103.10 92.57,102.06 91.66,101.70 89.00,102.14 86.47,102.56 82.40,103.99 80.00,105.00 Z M 198.00,114.00 C 190.92,112.77 186.53,111.20 180.00,108.31 180.00,108.31 171.00,105.04 171.00,105.04 162.16,102.56 155.75,108.67 160.42,114.83 163.92,119.45 170.36,124.76 176.00,126.37 181.00,127.79 193.88,126.02 196.83,121.58 198.16,119.58 197.98,116.33 198.00,114.00 Z M 147.00,127.00 C 147.00,127.00 135.00,120.31 135.00,120.31 131.12,118.40 130.26,118.48 126.00,118.96 115.61,120.14 115.86,130.64 120.41,137.70 123.05,141.79 140.05,142.48 143.49,139.40 146.28,136.91 146.83,130.55 147.00,127.00 Z M 231.04,129.43 C 221.85,133.43 223.40,149.24 233.00,147.59 234.90,147.27 237.17,146.16 239.00,145.46 243.39,143.79 250.73,142.04 250.53,135.97 250.48,134.60 249.83,132.60 248.91,131.57 246.56,128.93 234.57,128.88 231.04,129.43 Z M 61.01,137.58 C 52.53,139.86 56.89,143.39 57.58,149.00 57.83,151.05 57.29,152.64 58.17,154.70 60.30,159.65 71.88,164.49 77.00,163.98 80.54,163.62 86.42,158.52 83.96,154.65 82.47,152.30 77.92,152.03 75.05,150.15 68.68,145.97 70.53,138.26 61.01,137.58 Z M 190.00,147.00 C 177.79,149.34 179.53,155.57 179.00,165.00 182.64,166.84 186.61,170.21 188.28,174.04 189.50,176.82 189.99,181.75 193.45,183.97 198.39,187.14 200.58,180.67 201.19,177.00 202.45,169.40 194.07,160.20 194.00,151.00 194.00,151.00 190.00,147.00 190.00,147.00 Z M 246.02,165.87 C 243.45,167.02 240.10,169.21 241.40,172.63 242.35,175.14 247.20,177.12 248.16,181.17 250.87,192.60 238.80,199.98 237.48,207.00 236.77,210.76 238.86,214.23 243.04,213.31 248.81,212.05 261.25,200.85 262.43,195.00 263.12,192.55 262.59,190.41 262.43,188.00 261.13,179.18 256.40,166.64 246.02,165.87 Z M 292.04,175.43 C 286.44,177.88 287.84,179.97 286.04,185.00 284.17,190.22 280.83,195.20 281.21,201.00 281.51,205.56 284.39,211.85 290.00,210.09 291.70,209.56 293.55,208.05 295.00,207.03 302.50,201.76 301.65,203.88 304.32,195.00 304.87,193.14 305.91,190.89 305.99,189.00 306.32,181.62 299.50,174.54 292.04,175.43 Z M 103.00,178.00 C 102.88,187.52 100.65,185.15 100.11,191.01 99.93,192.92 100.03,195.90 101.17,197.52 103.04,200.15 115.68,203.31 118.98,202.65 123.55,201.75 126.03,197.80 124.48,193.28 123.76,191.21 121.36,188.74 119.95,186.99 119.95,186.99 114.74,180.43 114.74,180.43 111.37,177.50 107.16,178.00 103.00,178.00 Z M 144.00,205.69 C 138.94,208.16 136.02,211.12 136.43,217.00 136.56,218.90 136.74,223.48 137.25,225.00 139.77,232.49 148.46,240.83 156.98,238.43 168.18,235.27 157.80,225.29 153.79,216.00 152.72,213.54 151.91,208.40 150.43,207.09 148.48,205.37 146.35,205.49 144.00,205.69 Z M 95.96,226.77 C 86.88,228.06 84.74,236.26 90.34,242.98 91.88,244.83 94.35,247.32 96.95,247.26 100.01,247.18 101.77,243.63 102.17,241.00 102.99,235.60 101.65,228.51 95.96,226.77 Z M 202.01,226.86 C 199.89,227.40 198.28,227.68 197.40,230.11 196.23,233.34 199.49,237.12 202.98,235.83 207.38,234.21 209.10,228.65 202.01,226.86 Z M 233.00,235.00 C 233.00,235.00 225.09,237.50 225.09,237.50 219.99,239.89 214.33,245.90 212.00,251.00 215.49,253.64 218.21,256.39 222.98,255.62 224.26,255.41 225.45,255.00 226.44,254.15 227.97,252.84 233.37,242.99 233.63,241.00 233.87,239.19 233.32,236.81 233.00,235.00 Z M 256.00,258.00 C 258.76,253.59 265.95,247.26 265.75,243.05 265.44,236.51 248.93,234.66 246.60,236.79 244.81,238.42 245.13,241.00 245.98,243.00 247.33,246.15 253.45,255.62 256.00,258.00 Z M 275.91,254.73 C 270.45,257.38 265.35,263.65 261.00,268.00 261.00,268.00 234.00,295.00 234.00,295.00 232.11,296.91 228.33,300.36 227.74,302.98 227.13,305.74 229.26,307.87 232.02,307.26 235.22,306.54 242.40,298.60 245.00,296.00 245.00,296.00 274.00,267.00 274.00,267.00 276.72,264.26 285.60,255.07 275.91,254.73 Z M 210.00,277.00 C 210.00,277.00 212.61,269.00 212.61,269.00 214.25,260.51 206.18,258.31 203.33,260.06 201.27,261.33 201.39,263.89 201.32,266.00 201.11,273.09 202.63,276.14 210.00,277.00 Z M 231.87,278.00 C 235.76,276.38 243.49,271.08 242.26,266.13 241.61,263.52 237.21,260.95 234.87,262.87 232.96,264.42 232.04,269.63 231.87,272.00 231.40,274.81 231.45,275.27 231.87,278.00 Z M 169.00,268.54 C 157.81,272.59 163.68,288.10 171.89,286.59 175.23,285.98 175.80,282.87 176.07,280.00 176.62,274.16 175.86,268.72 169.00,268.54 Z M 228.00,282.00 C 223.43,279.78 210.59,275.68 209.50,284.02 209.11,286.95 212.77,294.94 214.00,298.00 223.04,296.08 226.41,290.40 228.00,282.00 Z M 296.00,284.00 C 296.00,284.00 315.00,302.00 315.00,302.00 315.00,302.00 347.00,334.00 347.00,334.00 347.00,334.00 379.00,366.00 379.00,366.00 379.00,366.00 397.00,385.00 397.00,385.00 397.00,385.00 357.00,424.00 357.00,424.00 357.00,424.00 291.00,358.00 291.00,358.00 291.00,358.00 257.00,323.00 257.00,323.00 257.00,323.00 296.00,284.00 296.00,284.00 Z M 341.00,285.99 C 329.49,287.01 336.63,299.85 344.00,301.16 348.20,301.91 352.00,298.53 349.76,293.04 348.12,289.02 344.98,287.11 341.00,285.99 Z M 136.05,287.45 C 130.93,289.71 130.13,294.89 131.49,300.00 132.63,304.24 136.10,308.91 137.99,312.87 140.19,317.49 140.42,330.41 153.98,327.37 160.32,325.94 162.11,318.08 159.39,314.19 156.26,309.71 151.55,310.86 147.12,303.27 143.52,297.12 145.91,286.67 136.05,287.45 Z M 264.02,361.95 C 252.67,363.72 260.85,377.03 268.00,377.38 272.70,377.61 274.75,373.01 273.26,369.01 271.73,364.92 268.04,362.81 264.02,361.95 Z M 410.00,383.00 C 410.00,383.00 426.00,383.00 426.00,383.00 426.00,383.00 426.00,388.00 426.00,388.00 426.00,388.00 421.00,388.00 421.00,388.00 421.00,388.00 421.00,403.00 421.00,403.00 421.00,403.00 416.00,403.00 416.00,403.00 416.00,403.00 416.00,388.00 416.00,388.00 416.00,388.00 410.00,388.00 410.00,388.00 410.00,388.00 410.00,383.00 410.00,383.00 Z M 428.00,383.00 C 436.95,383.32 433.39,385.49 438.00,395.00 438.00,395.00 440.00,383.00 440.00,383.00 440.00,383.00 447.00,383.00 447.00,383.00 447.00,383.00 447.00,403.00 447.00,403.00 447.00,403.00 442.00,403.00 442.00,403.00 442.00,403.00 441.00,394.00 441.00,394.00 441.00,394.00 440.00,403.00 440.00,403.00 433.10,402.42 434.80,400.66 432.00,393.00 432.00,393.00 432.00,403.00 432.00,403.00 432.00,403.00 428.00,403.00 428.00,403.00 428.00,403.00 428.00,383.00 428.00,383.00 Z" style="fill: rgb(255, 255, 255); stroke: rgb(255, 255, 255);"/>
</svg>
EOF;
        global $menu;
        $menu_exists = false;
        foreach($menu as $k => $item) {
            if ($item[2] == 'oa_tools') {
                $menu_exists = true;
            }
        }
        if (!$menu_exists) {
            add_menu_page( "OA Tools", "OA Tools", 'none', 'oa_tools', 'oadueslookup_tools_menu', 'data:image/svg+xml;base64,' . base64_encode($oa_tools_icon), 3 );
        }
    }
    function oadueslookup_tools_menu() {
        # this is a no-op, the page can be blank. It's going to go to the first
        # submenu anyway when it's picked.
    }
}

## END OA TOOLS MENU CODE

require_once("includes/user-facing-lookup-page.php");
require_once("includes/management-options-page.php");
require_once("includes/dues-import.php");
