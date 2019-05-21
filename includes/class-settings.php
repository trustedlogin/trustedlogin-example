<?php
/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */
class TestSettingsPage
{
    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;
    /**
     * Start up
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        // add_action('admin_init', array($this, 'page_init'));
    }
    /**
     * Add options page
     */
    public function add_plugin_page()
    {
        // This page will be under "Settings"
        /*
        add_options_page(
        'Settings Admin',
        'My Settings',
        'manage_options',
        'trustedlogin-admin',
        array( $this, 'create_admin_page' )
        );
         */

        // add top level menu page
        add_menu_page(
            'TrustedLogin Demo',
            'TrustedLogin Demo',
            'manage_options',
            'trustedlogin-admin',
            array($this, 'create_admin_page')
        );
    }
    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        print('<div class="wrap"><h1>TrustedLogin Demo</h1>');
        do_action('trustedlogin_button');
        print('</div>');
    }

}
if (is_admin()) {
    $my_settings_page = new TestSettingsPage();
}
