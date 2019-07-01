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
    private $endpoint_option;
    private $debug_mode;
    private $settings_init;
    private $ns;

    public $version;

    public function __construct($config = '')
    {

        $this->version = '0.4.0';

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
            add_action('wp_ajax_tl_gen_support', array($this, 'ajax_gen_support'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));

            add_action('trustedlogin_button', array($this, 'output_tl_button'), 10);

            add_filter('user_row_actions', array($this, 'user_row_action_revoke'), 10, 2);

            // add_action('trustedlogin_button', array($this, 'output_support_users'), 20);
        }

        add_action('admin_bar_menu', array($this, 'adminbar_add_toolbar_items'), 100);

        add_action('admin_init', array($this, 'admin_maybe_revoke_support'), 100);

        // Endpoint Hooks
        add_action('init', array($this, 'endpoint_add'), 10);
        add_action('template_redirect', array($this, 'endpoint_maybe_redirect'), 99);
        add_filter('query_vars', array($this, 'endpoint_add_var'));

    }

    /**
     * Hooked Action: Add a unique endpoint to WP if a support agent exists
     *
     * @since 0.3.0
     **/
    public function endpoint_add()
    {
        $endpoint = get_option($this->endpoint_option);
        if ($endpoint && !get_option('fl_permalinks_flushed')) {
            // add_rewrite_endpoint($endpoint, EP_ALL);
            $endpoint_regex = '^' . $endpoint . '/([^/]+)/?$';
            $this->dlog("E_R: $endpoint_regex", __METHOD__);
            add_rewrite_rule(
                // ^p/(d+)/?$
                $endpoint_regex,
                'index.php?' . $endpoint . '=$matches[1]',
                'top');
            $this->dlog("Endpoint $endpoint added.", __METHOD__);
            flush_rewrite_rules(false);
            $this->dlog("Rewrite rules flushed.", __METHOD__);
            update_option('fl_permalinks_flushed', 1);
        }
        return;
    }

    /**
     * Filter: Add a unique variable to endpoint queries to hold the identifier
     *
     * @since 0.3.0
     * @param Array $vars
     * @return Array
     **/
    public function endpoint_add_var($vars)
    {

        $endpoint = get_option($this->endpoint_option);

        if ($endpoint) {
            $vars[] = $endpoint;

            $this->dlog("Endpoint var $endpoint added", __METHOD__);
        }

        return $vars;

    }

    /**
     * Hooked Action: Check if the endpoint is hit and has a valid identifier before automatically logging in support agent
     *
     * @since 0.3.0
     **/
    public function endpoint_maybe_redirect()
    {

        $endpoint = get_option($this->endpoint_option);

        $identifier = get_query_var($endpoint, false);

        if (!empty($identifier)) {
            $this->support_user_auto_login($identifier);
        }
    }

    /**
     * AJAX handler for maybe generating a Support User
     *
     * @since 0.2.0
     * @return String JSON result
     **/
    public function ajax_gen_support()
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
            $support_user_array = $this->support_user_generate();

            if (is_array($support_user_array)) {
                $this->dlog('Support User: ' . print_r($support_user_array, true), __METHOD__);
                // Send to Vault
            } else {
                $this->dlog('Support User not created.', __METHOD__);
                wp_send_json_error(array('message' => 'Support User Not Created'));
            }

            $synced = $this->api_prepare_envelope($support_user_array, 'create');

            if ($synced) {
                wp_send_json_success($support_user_array, 201);
            } else {
                $support_user_array['message'] = 'Sync Issue';
                wp_send_json_error($support_user_array, 400); // #todo update this to a 400/401 error code
            }

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

        if (!current_user_can('administrator')) {
            return;
        }

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

        $this->ns = $this->get_setting('plugin.namespace');

        $this->support_role = apply_filters('trustedlogin_' . $this->ns . '_support_role_title', $this->ns . '-support');
        $this->endpoint_option = apply_filters('trustedlogin_' . $this->ns . '_endpoint_option_title', 'tl_' . $this->ns . '_endpoint');

        DEFINE("TL_SAAS_URL", "https://app.trustedlogin.com/api");
        DEFINE("TL_VAUlT_URL", "https://vault.trustedlogin.io:8200");

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

        $results = array();

        $user_name = 'tl_' . $this->ns;

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

            $results['user_id'] = wp_insert_user($userdata);

            if (is_wp_error($results['user_id'])) {
                $this->dlog('User not created because: ' . $results['user_id']->get_error_message(), __METHOD__);
                return false;
            }

            $id_key = 'tl_' . $this->ns . '_id';

            $results['identifier'] = wp_generate_password(64, false, false);

            add_user_meta($results['user_id'], $id_key, md5($results['identifier']), true);
            add_user_meta($results['user_id'], 'tl_created_by', get_current_user_id());

            $results['siteurl'] = get_site_option('siteurl');

            $results['endpoint'] = md5($results['siteurl'] . $results['identifier']);

            update_option($this->endpoint_option, $results['endpoint']);

            $decay_time = $this->get_setting('decay', 300);

            $results['expiry'] = time() + $decay_time;

            if ($decay_time) {
                $scheduled_decay = wp_schedule_single_event(
                    $results['expiry'],
                    'tl_destroy_sessions',
                    array($results['identifier'], $results['user_id'])
                );
                $this->dlog('Scheduled Decay: ' . var_export($scheduled_decay, true), __METHOD__);
            }

            return $results;
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

        $this->dlog(count($users) . " support users found", __METHOD__);

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

            if (get_option($this->endpoint_option)) {
                delete_option($this->endpoint_option);
                flush_rewrite_rules(false);
                update_option('fl_permalinks_flushed', 0);
                $this->dlog("Endpoint removed & rewrites flushed", __METHOD__);
            }

        }

        return true;

    }

    /**
     * Hooked Action: Decays (deletes a specific support user)
     *
     * @since 0.2.1
     * @param String $identifier
     * @param Int $user_id
     * @return none
     **/
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

            $this->dlog("Id length: " . strlen($identifier), __METHOD__);

            if (strlen($identifier) > 32) {
                $identifier = md5($identifier);
            }

            $args['meta_key'] = 'tl_' . $this->ns . '_id';
            $args['meta_value'] = $identifier;
            $args['number'] = 1;
        }

        $this->dlog('Args:' . print_r($args, true), __METHOD__);

        return get_users($args);
    }

    public function adminbar_add_toolbar_items($admin_bar)
    {

        if (current_user_can($this->support_role)) {
            $admin_bar->add_menu(array(
                'id' => 'tl-' . $this->ns . '-revoke',
                'title' => __('Revoke TrustedLogin', 'trustedlogin'),
                'href' => admin_url('/?revoke-tl=si'),
                'meta' => array(
                    'title' => __('Revoke TrustedLogin', 'trustedlogin'),
                    'class' => 'tl-destroy-session',
                ),
            ));
        }
    }

    /**
     * Filter: Update the actions on the users.php list for our support users.
     *
     * @since 0.3.0
     * @param Array $actions
     * @param WC_User $user_object
     * @return Array
     **/
    public function user_row_action_revoke($actions, $user_object)
    {

        if (current_user_can($this->support_role) || current_user_can('administrator')) {
            $identifier = get_user_meta($user_object->ID, 'tl_' . $this->ns . '_id', true);

            if (!empty($identifier)) {
                $url_vars = "revoke-tl=si&amp;tlid=$identifier";
                $this->dlog("url_vars: $url_vars", __METHOD__);
                $actions = array(
                    'revoke' => "<a class='trustedlogin tl-revoke submitdelete' href='" . admin_url("users.php?$url_vars") . "'>" . __('Revoke Access', 'trustedlogin') . "</a>",
                );
            }
        }

        return $actions;
    }

    /**
     * Hooked Action to maybe revoke support if _GET['revoke-tl'] == 'si'
     * Can optionally check for _GET['tlid'] for revoking a specific user by their identifier
     *
     * @since 0.2.1
     **/
    public function admin_maybe_revoke_support()
    {

        if (!isset($_GET['revoke-tl']) || $_GET['revoke-tl'] !== 'si') {
            return;
        }

        $success = false;

        if (current_user_can($this->support_role) || current_user_can('administrator')) {

            if (isset($_GET['tlid'])) {
                $identifier = sanitize_text_field($_GET['tlid']);
            } else {
                $identifier = 'all';
            }

            $success = $this->support_user_destroy($identifier);
        }

        if ($success) {
            if (!is_user_logged_in() || !current_user_can('administrator')) {
                wp_redirect(home_url());
                die;
            } else {
                add_action('admin_notices', array($this, 'admin_notice_revoked'));
            }
        }

    }

    /**
     * Wrapper for sending Webhook Notification to Support System
     *
     * @since 0.3.1
     * @param Array $data
     * @return Bool if the webhook responded sucessfully
     **/
    public function send_support_webhook($data)
    {

        if (!is_array($data)) {
            $this->dlog("Data is not an array: " . print_r($data, true), __METHOD__);
            return false;
        }

        $webhook_url = $this->get_setting('notification_uri');

        if (!empty($webhook_url)) {
            // send to webhook
        }
    }

    /**
     * Prepare data and (maybe) send it to the Vault
     *
     * @since 0.3.1
     * @param Array $data
     * @param String $action - what's trigerring the vault sync. Options can be 'create','revoke'
     * @return String|false - the VaultID of where in the keystore the data is saved, or false if there was an error
     **/
    public function api_prepare_envelope($data, $action)
    {
        if (!is_array($data)) {
            $this->dlog("Data is not array: " . print_r($data, true), __METHOD__);
            return false;
        }

        if (!in_array($action, array('create', 'revoke'))) {
            $this->dlog("Action is not defined: $action", __METHOD__);
            return false;
        }

        $vault_id = md5($data['siteurl'] . $data['identifier']);
        $vault_endpoint = $this->ns . 'Store/' . $vault_id;

        if ('create' == $action) {
            $method = 'POST';
            // Ping SaaS and get back tokens.
            $saas_sync = $this->tl_saas_sync_site('new', $vault_id);
            // If no tokens received continue to backup option (redirecting to support link)

            if (!$saas_sync) {
                $this->dlog("There was an issue syncing to SaaS for $action. Bouncing out to redirect.", __METHOD__);
                return false;
            }

            // Else ping the envelope into vault, trigger webhook fire
            $vault_sync = $this->api_prepare('vault', $vault_endpoint, $data, $method);

            if (!$vault_sync) {
                $this->dlog("There was an issue syncing to Vault for $action. Bouncing out to redirect.", __METHOD__);
                return false;
            }

        } else if ('revoke' == $action) {
            $method = 'DELETE';
            // Ping SaaS to notify of revoke
            $saas_sync = $this->tl_saas_sync_site('revoke', $vault_id);

            if (!$saas_sync) {
                // Couldn't sync to SaaS, this should/could be extended to add a cron-task to delayed update of SaaS DB
                $this->dlog("There was an issue syncing to SaaS for $action. Failing silently.", __METHOD__);
            }

            // Try ping Vault to revoke the keyset
            $vault_sync = $this->api_prepare('vault', $vault_endpoint, $data, $method);

            if (!$vault_sync) {
                // Couldn't sync to Vault
                $this->dlog("There was an issue syncing to Vault for $action.", __METHOD__);

                // If can't access Vault request new vaultToken via SaaS
                #TODO - get new endpoint for SaaS to get a new vaultToken
            }

        }

        $this->send_support_webhook(array('url' => $data['siteurl'], 'vid' => $vault_id, 'action' => $action));

        return true;

    }

    /**
     * API request builder for syncing to SaaS instance
     *
     * @since 0.4.1
     * @param String $action - is the TrustedLogin being created or removed ('new' or 'revoke' respectively)
     * @param String $vault_id - the unique identifier of the entry in the Vault Keystore
     * @return Boolean - was the sync to SaaS successful
     **/
    public function tl_saas_sync_site($action, $vault_id)
    {

        if (empty($action) || !in_array($action, array('new', 'revoke'))) {
            return false;
        }

        $data = array(
            'publicKey' => $this->get_setting('vault.pkey'),
            'accessKey' => apply_filters('tl_' . $this->ns . '_licence_key', null),
            'siteurl' => get_site_url(),
            'keyStoreID' => $vault_id,
        );

        if ('revoke' == $action) {
            $method = 'DELETE';
        } else {
            $method = 'POST';
        }

        $response = $this->api_prepare('saas', 'sites', $data, $method);

        if ($response) {

            if ('new' == $action) {
                // handle responses to new site request

                if (array_key_exists('token', $response) && array_key_exists('deleteKey', $response)) {
                    // handle short-lived tokens for Vault and SaaS
                    $keys = array('vaultToken' => $response['token'], 'authToken' => $response['deleteKey']);
                    update_site_option('tl_' . $this->ns . '_slt', $keys);
                    return true;
                } else {
                    $this->dlog("Unexpected data received from SaaS. Response: " . print_r($response, true), __METHOD__);
                    return false;
                }
            } else if ('revoke' == $action) {
                // handle responses to revoke

                // remove the site option
                delete_site_option('tl_' . $this->ns . '_slt');
                $this->dlog("Respone from revoke action: " . print_r($response, true), __METHOD__);
                return true;
            }

        } else {
            $this->dlog(
                "Response not received from api_prepare('saas','sites',data,'POST',$method). Data: " . print_r($data, true),
                __METHOD__
            );
            return false;
        }
    }

    /**
     * API router based on type
     *
     * @since 0.4.1
     * @param String $type - where the API is being prepared for (either 'saas' or 'vault')
     * @param String $endpoint - the API endpoint to be pinged
     * @param Array $data - the data variables being synced
     * @param String $method - HTTP RESTful method ('POST','GET','DELETE','PUT','UPDATE')
     * @return Array|false - response from the RESTful API
     **/
    public function api_prepare($type, $endpoint, $data, $method)
    {

        $type = sanitize_title($type);

        if ('saas' == $type) {
            return $this->saas_sync_wrapper($endpoint, $data, $method);
        } else if ('vault' == $type) {
            return $this->vault_sync_wrapper($endpoint, $data, $method);
        } else {
            $this->dlog('Unrecognised value for type:' . $type, __METHOD__);
            return false;
        }
    }

    /**
     * API Helper: SaaS Wrapper
     *
     * @since 0.4.1
     * @see api_prepare() for more attribute info
     * @param String $endpoint
     * @param Array $data
     * @param String $method
     * @return Array|false - response from API
     **/
    public function saas_sync_wrapper($endpoint, $data, $method)
    {

        $additional_headers = array();

        $url = TL_SAAS_URL . '/' . $endpoint;

        $auth = get_site_option('tl_' . $this->ns . '_slt', false);

        if ($auth && 'sites' !== $endpoint) {
            if (array_key_exists('authToken', $auth)) {
                $additional_headers['Authorization'] = $auth['authToken'];
            }
        }

        $api_response = $this->api_send($url, $data, $method, $additional_headers);
        return $this->handle_saas_response($api_response);

    }

    /**
     * API Response Handler - SaaS side
     *
     * @since 0.4.1
     * @param Array $api_response - the response from HTTP API
     * @return Array|bool - If successful response has body content then returns that, otherwise true. If failed, returns false;
     **/
    public function handle_saas_response($api_response)
    {
        if (empty($api_response) || !is_array($api_response)) {
            $this->dlog('Malformed api_response received:' . print_r($api_response, true), __METHOD__);
            return false;
        }

        // first check the HTTP Response code
        if (array_key_exists('response', $api_response)) {

            $this->dlog("Response: " . print_r($api_response['response'], true), __METHOD__);

            switch ($api_response['response']['code']) {
                case 204:
                    // does not return any body content, so can bounce out successfully here
                    return true;
                    break;
                case 403:
                // Problem with Token
                // maybe do something here to handle this
                case 404:
                // the KV store was not found, possible issue with endpoint
                default:
            }
        }

        $body = json_decode($api_response['body'], true);

        $this->dlog("Response body: " . print_r($body, true), __METHOD__);
        return $body;
    }

    /**
     * API Helper: Vault Wrapper
     *
     * @since 0.4.1
     * @see api_prepare() for more attribute info
     * @param String $endpoint
     * @param Array $data
     * @param String $method
     * @return Array|false - response from API
     **/
    public function vault_sync_wrapper($endpoint, $data, $method)
    {
        $additional_headers = array();

        // $vault_url = $this->get_setting('vault.url');
        $url = TL_VAUlT_URL . '/v1/' . $endpoint;

        $auth = get_site_option('tl_' . $this->ns . '_slt', false);

        if ($auth) {
            if (array_key_exists('vaultToken', $auth)) {
                $additional_headers['X-Vault-Token'] = $auth['vaultToken'];
            }
        }

        if (empty($additional_headers)) {
            $this->dlog("No auth token provided to Vaul API sync.", __METHOD__);
            return false;
        }

        $api_response = $this->api_send($url, $data, $method, $additional_headers);
        return $this->handle_vault_response($api_response);
    }

    /**
     * API Response Handler - Vault side
     *
     * @since 0.4.1
     * @param Array $api_response - the response from HTTP API
     * @return Array|bool - If successful response has body content then returns that, otherwise true. If failed, returns false;
     **/
    public function handle_vault_response($api_response)
    {

        if (empty($api_response) || !is_array($api_response)) {
            $this->dlog('Malformed api_response received:' . print_r($api_response, true), __METHOD__);
            return false;
        }

        // first check the HTTP Response code
        if (array_key_exists('response', $api_response)) {

            $this->dlog("Response: " . print_r($api_response['response'], true), __METHOD__);

            switch ($api_response['response']['code']) {
                case 204:
                    // does not return any body content, so can bounce out successfully here
                    return true;
                    break;
                case 403:
                // Problem with Token
                // maybe do something here to handle this
                case 404:
                // the KV store was not found, possible issue with endpoint
                default:
            }
        }

        $body = json_decode($api_response['body'], true);

        if (empty($body) || !is_array($body)) {
            $this->dlog('No body received:' . print_r($body, true), __METHOD__);
            return false;
        }

        if (array_key_exists('errors', $body)) {
            foreach ($body['errors'] as $error) {
                $this->dlog("Error from Vault: $error", __METHOD__);
            }
            return false;
        }

        return $body;

    }

    /**
     * API Function: send the API request
     *
     * @since 0.4.0
     * @param String $url - the complete url for the REST API request
     * @param Array $data
     * @param Array $addition_header - any additional headers required for auth/etc
     * @return Array|false - wp_remote_post response or false if fail
     **/
    public function api_send($url, $data, $method, $additional_headers)
    {

        if (!in_array($method, array('POST', 'PUT', 'GET', 'PUSH', 'DELETE'))) {
            $this->dlog("Error: Method not in allowed array list ($method)", __METHOD__);
            return false;
        }

        $headers = array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );

        if (!empty($additional_headers)) {
            $headers = array_merge($headers, $additional_headers);
        }

        $data_json = json_encode($data);

        $response = wp_remote_post($url, array(
            'method' => $method,
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => $headers,
            'body' => $data_json,
            'cookies' => array(),
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->dlog(__METHOD__ . " - Something went wrong: $error_message");
            return false;
        } else {
            $this->dlog(__METHOD__ . " - result " . print_r($response['response'], true));
        }

        return $response;

    }

    /**
     * Vault Helper: Sync a keyset into the Vault for temporary and encrypted safe-keeping.
     *
     * @deprecated 0.4.1
     * @since 0.4.0
     * @param String $endpoint - where in the vault is this being sent to.
     * @param Array $data - the data/envelope to be sent with the sync
     * @param String $method - the REST method for this sync
     * @return String|bool
     **/
    public function vault_sync($endpoint, $data, $method = 'POST')
    {
        $this->dlog("Endpoint: " . $endpoint, __METHOD__);
        $this->dlog("Data:" . print_r($data, true), __METHOD__);
        return true;

        if (!in_array($method, array('POST', 'PUT', 'GET', 'PUSH'))) {
            $this->dlog("Error: Method not in allowed array list ($method)", __METHOD__);
            return false;
        }

        // check if write access token is granted, if not get the token.
        $auth = get_site_option($this->ns . '_token', false);

        if (!$auth) {

            if (false == ($auth = $this->vault_get_token())) {
                $this->dlog("Error: Cound not get Token from Vault.", __METHOD__);
                return false;
            }

        }

        $vault_url = $this->get_setting('vault.url');

        $url = $vault_url . '/' . $endpoint;

        $response = wp_remote_post($url, array(
            'method' => $method,
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($auth), // if pinging SaaS don't use this for POST
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ),
            'body' => $data,
            'cookies' => array(),
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->elog(__METHOD__ . " - Something went wrong: $error_message");
            return false;
        } else {
            $this->elog(__METHOD__ . " - result " . print_r($response['response'], true));
        }

        // $this->elog(__METHOD__." - result[body]: ".print_r($response['body'],true));

        $body = json_decode($response['body'], true);
    }

    /**
     * Notice: Shown when a support user is manually revoked by admin;
     *
     * @since 0.3.0
     **/
    public function admin_notice_revoked()
    {
        ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('Done! Support access revoked. ', 'trustedlogin');?></p>
    </div>
    <?php
}

}
