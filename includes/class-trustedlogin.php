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

    public function __construct($config = '')
    {

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

    public function init_hooks()
    {

        add_action('admin_init', array($this, 'tmp_generate_user'), 90);

        add_action('tl_destroy_sessions', array($this, 'support_user_decay'), 10, 2);

        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));
    }

    public function tmp_generate_user()
    {
        if (isset($_GET['gensupportusr'])) {
            $id = $this->support_user_generate();

            if ($id) {
                $this->dlog('Identifier: ' . $id, __METHOD__);
            }
        }
    }

    public function enqueue_admin()
    {
        /**
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/3.3.2/jquery-confirm.min.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/3.3.2/jquery-confirm.min.js"></script>
         **/
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

        $support_role = $this->get_setting('plugin.namespace') . '-support';

        foreach ($this->get_setting('role') as $key => $reason) {
            $role_to_clone = $key;
        }

        $role_exists = $this->support_user_create_role(
            $support_role,
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
                'role' => $support_role,
                'first_name' => $this->get_setting('plugin.title'),
                'last_name' => 'Support',
            );

            $user_id = wp_insert_user($userdata);

            if (is_wp_error($user_id)) {
                $this->dlog('User not created because: ' . $user_id->get_error_message(), __METHOD__);
                return false;
            }

            $id_key = 'tl_' . $this->get_setting('plugin.namespace') . '_id';

            $identifier = wp_generate_password(64, false, false);

            add_user_meta($user_id, $id_key, md5($identifier), true);

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

        if (get_role($this->support_role)) {
            remove_role($this->support_role);
            $this->dlog("Role " . $this->support_role . " removed.", __METHOD__);
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
