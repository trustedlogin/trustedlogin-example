<?php
if (!defined('ABSPATH')) {
    exit;
}
// Exit if accessed directly

class TrustedLogin
{

    use TL_Debug_Logging;

    private $settings;
    private $support_role;
    private $debug_mode;
    private $settings_init;

    public $version;

    public function __construct($config = '')
    {

        $this->version = '0.2.1';

        $this->debug_mode = true;

        $this->settings_init = false;

        if (empty($config)) {
            $this->dlog('No config settings passed to constructor', __METHOD__);
        }

        if (!empty($config)) {

            // Handle JSON encoded config
            if (!is_array($config)) {
                $config = json_decode($config);
            }

            if (!is_null($config)) {
                $this->settings_init = $this->init_settings($config);
            }
        }

        $this->init_hooks();

    }

    /**
     * Initialise the action hooks required
     *
     * @since 0.2.0
     **/
    public function init_hooks()
    {

        add_action('tl_destroy_sessions', array($this, 'support_user_decay'), 10, 2);

        if (is_admin()) {
            add_action('wp_ajax_tl_gen_support', array($this, 'tl_gen_support'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));

            add_action('trustedlogin_button', array($this, 'output_tl_button'), 10);
            add_action('trustedlogin_button', array($this, 'output_support_users'), 20);
        }

        // add_action('init', array($this, 'maybe_add_endpoint'));

    }

    public function maybe_add_endpoint()
    {
        $endpoint = get_option('tl_endpoint');
        if ($endpoint) {

        }
        return;
    }

    /**
     * AJAX handler for maybe generating a Support User
     *
     * @since 0.2.0
     * @return String JSON result
     **/
    public function tl_gen_support()
    {

        $nonce = $_POST['_nonce'];
        if (empty($_POST)) {
            wp_send_json_error(array('message' => 'Auth Issue'));
        }

        $this->dlog(print_r($_POST, true), __METHOD__);

        //!wp_verify_nonce($nonce, 'tl_nonce-' . get_current_user_id()
        if (!check_ajax_referer('tl_nonce-' . get_current_user_id(), '_nonce', false)) {
            wp_send_json_error(array('message' => 'Verification Issue'));
        }

        if (current_user_can('administrator')) {
            $id = $this->support_user_generate();

            if ($id) {
                $this->dlog('Identifier: ' . $id, __METHOD__);
                // Send to Vault
            }

            wp_send_json_success(array('id' => $id), 201);
        } else {
            wp_send_json_error(array('message' => 'Permissions Issue'));
        }

        wp_die();
    }

    /**
     * Register the required scripts and styles for wp-admin
     *
     * @since 0.2.0
     **/
    public function enqueue_admin()
    {

        $jquery_confirm_version = '3.3.2';

        wp_register_style(
            'jquery-confirm',
            'https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/' . $jquery_confirm_version . '/jquery-confirm.min.css',
            array(),
            $jquery_confirm_version,
            'all'
        );

        wp_register_script(
            'jquery-confirm',
            'https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/' . $jquery_confirm_version . '/jquery-confirm.min.js',
            array('jquery'),
            $jquery_confirm_version,
            true
        );

        wp_register_script(
            'trustedlogin',
            plugin_dir_url(dirname(__FILE__)) . '/assets/trustedlogin.js',
            array('jquery', 'jquery-confirm'),
            $this->version,
            true
        );

        wp_register_style(
            'trustedlogin',
            plugin_dir_url(dirname(__FILE__)) . '/assets/trustedlogin.css',
            array(),
            $this->version,
            'all'
        );

    }

    /**
     * Output the TrustedLogin Button and required scripts
     *
     * @since 0.2.0
     * @param Boolean $print - whether to print results or return them
     * @return String the HTML output
     **/
    public function output_tl_button($print = true)
    {

        if (empty($print)) {
            $print = true;
        }

        wp_enqueue_script('jquery-confirm');
        wp_enqueue_style('jquery-confirm');
        wp_enqueue_style('trustedlogin');

        $tl_obj = $this->output_tl_alert();

        $tl_obj['plugin'] = $this->get_setting('plugin');
        $tl_obj['ajaxurl'] = admin_url('admin-ajax.php');
        $tl_obj['_n'] = wp_create_nonce('tl_nonce-' . get_current_user_id());

        wp_localize_script('trustedlogin', 'tl_obj', $tl_obj);

        wp_enqueue_script('trustedlogin');

        $return = 'Need help? <a href="#" id="trustedlogin-grant" class="trustedlogin-btn btn">';
        $return .= sprintf('%1$s <br/><small>Powered by TrustedLogin</small>',
            sprintf(__('Grant %s Support Access', 'trustedlogin'), $this->get_setting('plugin.title')
            ));
        $return .= '</a>';

        if (!$print) {
            return $return;
        }

        echo $return;
    }

    /**
     * Hooked Action to Output the List of Support Users Created
     *
     * @since 0.2.1
     * @param Boolean $return - whether to echo (vs return) the results [default:true]
     * @return Mixed - echoed HTML or returned String of HTML
     **/
    public function output_support_users($print = true)
    {

        if (!is_admin() || !current_user_can('administrator')) {
            return;
        }

        if (empty($print)) {
            $print = true;
        }

        $return = '';

        $users = $this->helper_get_support_users('all');

        if (count($users) > 0) {

            $table_header =
                sprintf('<table class="wp-list-table widefat plugins"><thead>
                <tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr></thead><tbody>',
                __('User', 'trustedlogin'),
                __('Role', 'trustedlogin'),
                __('Created At', 'trustedlogin'),
                __('Created By', 'trustedlogin')
            );

            $return .= $table_header;

            foreach ($users as $_u) {
                $this->dlog('tl_created_by:' . get_user_meta($_u->ID, 'tl_created_by', true), __METHOD__);

                $_gen_u = get_user_by('id', get_user_meta($_u->ID, 'tl_created_by', true));

                $this->dlog('g_u:' . print_r($_gen_u, true));

                $_udata = get_userdata($_u->ID);
                $return .= '<tr><td>' . $_u->first_name . ' ' . $_u->last_name . '(#' . $_u->ID . ')</td>';

                if (count($_u->roles) > 1) {
                    $roles = trim(implode(',', $_u->roles), ',');
                } else {
                    $roles = $_u->roles[0];
                }
                $return .= '<td>' . $roles . '</td>';

                $return .= '<td>' . date('d M Y', strtotime($_udata->user_registered)) . '</td>';
                if ($_gen_u) {
                    $return .= '<td>' . ($_gen_u->exists() ? $_gen_u->display_name : __('unknown', 'trustedlogin')) . '</td>';
                } else {
                    $return .= '<td>' . __('unknown', 'trustedlogin') . '</td>';
                }
                $return .= '</tr>';

            }

            $return .= '</tbody></table>';
        }

        if (!$print) {
            return $return;
        }

        echo $return;
    }

    /**
     * Generate the HTML strings for the Confirmation dialogues
     *
     * @since 0.2.0
     * @return String[] Array containing 'intro', 'description' and 'detail' keys.
     **/
    public function output_tl_alert()
    {

        $result = array();

        $result['intro'] = sprintf(
            __('Grant %1$s Support access to your site.', 'trustedlogin'),
            $this->get_setting('plugin.title')
        );

        $result['description'] = sprintf('<p class="description">%1$s</p>',
            __('By clicking Confirm, the following will happen automatically:', 'trustedlogin')
        );

        $details = '<ul class="tl-details">';

        // Roles
        foreach ($this->get_setting('role') as $role => $reason) {
            $details .= sprintf('<li class="role"> %1$s <br /><small>%2$s</small></li>',
                sprintf(__('A new user will be created with a custom role \'%1$s\' (with the same capabilities as %2$s).', 'trustedlogin'),
                    $this->support_role,
                    $role
                ),
                $reason
            );
        }

        // Extra Caps
        foreach ($this->get_setting('extra_caps') as $cap => $reason) {
            $details .= sprintf('<li class="extra-caps"> %1$s <br /><small>%2$s</small></li>',
                sprintf(__('With the additional \'%1$s\' Capability.', 'trustedlogin'),
                    $cap
                ),
                $reason
            );
        }

        // Decay
        if ($this->get_setting('decay')) {
            $now_date = new DateTime("@0");
            $decay_date = new DateTime("@" . $this->get_setting('decay'));
            $details .= sprintf('<li class="decay">%1$s</li>',
                sprintf(__('The support user, and custom role, will be removed and access revoked in %1$s', 'trustedlogin'),
                    $now_date->diff($decay_date)->format("%a days, %h hours, %i minutes"))
            );
        }

        $details .= '</ul>';

        $result['details'] = $details;

        return $result;

    }

    /**
     * Init all the settings from the provided TL_Config array.
     *
     * @since 0.1.0
     * @param Array as per TL_Config specification
     * @return Bool
     **/
    public function init_settings($config)
    {

        if (!is_array($config) || empty($config)) {
            return false;
        }

        $this->settings = apply_filters('trustedlogin_init_settings', $config);

        $this->support_role = $this->get_setting('plugin.namespace') . '-support';

        return true;
    }

    /**
     * Helper Function: Get a specific setting or return a default value.
     *
     * @since 0.1.0
     * @param String $slug - the setting to fetch, nested results are delimited with periods (eg plugin.name => settings['plugin']['name']
     * @param String $default - if no setting found or settings not init, return this value.
     * @return String
     **/
    public function get_setting($slug, $default = false)
    {

        if (!isset($this->settings) || !is_array($this->settings)) {
            $this->dlog('Settings have not been configured, returning default value', __METHOD__);
            return $default;
        }

        $keys = explode('.', $slug);

        if (count($keys) > 1) {

            $array_ptr = $this->settings;

            $last_key = array_pop($keys);

            while ($arr_key = array_shift($keys)) {
                if (!array_key_exists($arr_key, $array_ptr)) {
                    $this->dlog('Could not find multi-dimension setting. Keys: ' . print_r($keys, true), __METHOD__);
                    return $default;
                }

                $array_ptr = &$array_ptr[$arr_key];
            }

            if (array_key_exists($last_key, $array_ptr)) {
                return $array_ptr[$last_key];
            }

        } else {
            if (array_key_exists($slug, $this->settings)) {
                return $this->settings[$slug];
            } else {
                $this->dlog('Setting for slug ' . $slug . ' not found.', __METHOD__);
                return $default;
            }
        }
    }

    /**
     * Generate the Support User with custom role.
     *
     * @since 0.1.0
     * @return Mixed - Unique Identifier for the user if created, or false if there was an issue.
     **/
    public function support_user_generate()
    {

        $user_name = 'tl_' . $this->get_setting('plugin.namespace');

        if (validate_username($user_name)) {
            $user_id = username_exists($user_name);
        } else {
            $user_id = null;
        }

        foreach ($this->get_setting('role') as $key => $reason) {
            $role_to_clone = $key;
        }

        $role_exists = $this->support_user_create_role(
            $this->support_role,
            $role_to_clone
        );

        $user_email = $this->get_setting('plugin.email');

        if (!$user_id && (email_exists($user_email) == false) && $role_exists) {
            $random_password = wp_generate_password(64, true, true);
            $userdata = array(
                'user_login' => $user_name,
                'user_url' => $this->get_setting('plugin.website'),
                'user_pass' => $random_password,
                'user_email' => $user_email,
                'role' => $this->support_role,
                'first_name' => $this->get_setting('plugin.title'),
                'last_name' => 'Support',
                'user_registered' => date('Y-m-d H:i:s', time()),
            );

            $user_id = wp_insert_user($userdata);

            if (is_wp_error($user_id)) {
                $this->dlog('User not created because: ' . $user_id->get_error_message(), __METHOD__);
                return false;
            }

            $id_key = 'tl_' . $this->get_setting('plugin.namespace') . '_id';

            $identifier = wp_generate_password(64, false, false);

            add_user_meta($user_id, $id_key, md5($identifier), true);
            add_user_meta($user_id, 'tl_created_by', get_current_user_id());

            $siteurl = get_site_option('siteurl');

            $endpoint = md5($siteurl . $identifier);
            update_option('tl_endpoint', $endpoint);

            $decay_time = $this->get_setting('decay');
            $decay_time = 300; // for testing

            if ($decay_time) {
                $scheduled_decay = wp_schedule_single_event(time() + $decay_time, 'tl_destroy_sessions', array($identifier, $user_id));
                $this->dlog('Scheduled Delay: ' . var_export($scheduled_decay, true), __METHOD__);
            }

            return $identifier;
        }

        $this->dlog('Support User NOT created.', __METHOD__);

        return false;

    }

    /**
     * Destroy one or all of the Support Users
     *
     * @since 0.1.0
     * @param String $identifier - Unique Identifier of the user to delete, or 'all' to remove all support users.
     * @return Bool
     **/
    public function support_user_destroy($identifier = 'all')
    {

        $users = $this->helper_get_support_users($identifier);

        $this->dlog(print_r($users, true), __METHOD__);

        $reassign_id = null;

        if ($this->settings['reassign_posts']) {
            $admins = get_users(
                array(
                    'role' => 'administrator',
                    'orderby' => 'registered',
                    'order' => 'DESC',
                    'number' => 1,
                )
            );
            if (!empty($admins)) {
                $reassign_id = $admins[0]->ID;
            }
        }

        $this->dlog("reassign_id: $reassign_id", __METHOD__);

        if (count($users) == 0) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ($users as $_u) {
            $this->dlog("Processing uid " . $_u->ID, __METHOD__);

            if (wp_delete_user($_u->ID, $reassign_id)) {
                $this->dlog("User: " . $_u->ID . " deleted.", __METHOD__);
            } else {
                $this->dlog("User: " . $_u->ID . " NOT deleted.", __METHOD__);
            }

        }

        if (count($users) < 2 || $identifier == 'all') {

            if (get_role($this->support_role)) {
                remove_role($this->support_role);
                $this->dlog("Role " . $this->support_role . " removed.", __METHOD__);
            }

            if (get_option('tl_endpoint')) {
                delete_option('tl_endpoint');
                $this->dlog("Remove tl_endpoint option");
            }

        }

        return true;

    }

    public function support_user_decay($identifier, $user_id)
    {

        $this->dlog('Disabling user with id: ' . $identifier, __METHOD__);
        $this->support_user_destroy($identifier);

    }

    /**
     * Create the custom Support Role if it doesn't already exist
     *
     * @since 0.1.0
     * @param String $new_role_slug - slug for the new role
     * @param String $clone_role_slug - slug for the role to clone, defaults to 'editor'
     * @return Bool
     **/
    public function support_user_create_role($new_role_slug, $clone_role_slug = 'editor')
    {

        $this->dlog('N: ' . $new_role_slug . ', O: ' . $clone_role_slug, __METHOD__);

        if (!is_null(get_role($new_role_slug))) {
            return true;
        }

        $old_role = get_role($clone_role_slug);

        if (!empty($old_role)) {

            $capabilities = $old_role->capabilities;

            $extra_caps = $this->get_setting('extra_caps');

            if (is_array($extra_caps) && !empty($extra_caps)) {
                $capabilities = array_merge($extra_caps, $capabilities);
            }

            $new_role = add_role($new_role_slug, $this->get_setting('plugin.title'), $capabilities);

            return true;
        }

        return false;
    }

    /**
     * Auto-login function, which takes in a unique identifier.
     *
     * @since 0.1.0
     * @param String $identifier - Unique Identifier for the Support User to be logged into
     * @return false if user not logged in, otherwise redirect to wp-admin.
     **/
    public function support_user_auto_login($identifier)
    {

        if (empty($identifier)) {
            return false;
        }

        $users = $this->helper_get_support_users($identifier);

        if (empty($users)) {
            return false;
        }

        $_u = $users[0];

        wp_set_current_user($_u->ID, $_u->user_login);
        wp_set_auth_cookie($_u->ID);
        do_action('wp_login', $_u->user_login, $_u);

        wp_redirect(admin_url());
        exit();

    }

    /**
     * Helper Function: Get the generated support user(s).
     *
     * @since 0.1.0
     * @param String $identifier - Unique Identifier of 'all'
     * @return Array of WP_Users
     **/
    public function helper_get_support_users($identifier = 'all')
    {
        $args = array(
            'role' => $this->support_role,
        );

        if ('all' !== $identifier) {
            // $args['meta_key'] = 'tl_' . $this->get_setting('plugin.namespace') . '_id';
            $args['meta_key'] = 'tl_' . $this->get_setting('plugin.namespace') . '_id';
            $args['meta_value'] = md5($identifier);
            $args['number'] = 1;
        }

        $this->dlog('Args:' . print_r($args, true), __METHOD__);

        return get_users($args);
    }

}
