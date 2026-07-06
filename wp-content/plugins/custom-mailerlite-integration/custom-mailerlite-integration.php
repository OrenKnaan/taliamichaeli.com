<?php
/**
 * Plugin Name:       MailerLite Integration for Elementor
 * Description:       Handles Elementor form submissions and sends them to specific MailerLite groups with a full admin UI for management.
 * Version:           6.0
 * Author:            Manus
 * Text Domain:       cmlw-webhook
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('CMLW_LOG_FILE', wp_upload_dir()['basedir'] . '/cmlw-log.txt');

class CMLW_Integration_Plugin_V6 {

    private $apiKey = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiI0IiwianRpIjoiODM0ZTU5ODNiZjVhNGJhN2FlYTliZDU4MWZiMGNkMjkwZGViN2EwYjViYjY4MWU0OGE0YzMyZmM3M2VhNzY2YmNjMGI0N2NlNWRlMWE4NDYiLCJpYXQiOjE3NTM3MDA3MTEuMTc1NTgsIm5iZiI6MTc1MzcwMDcxMS4xNzU1ODMsImV4cCI6NDkwOTM3NDMxMS4xNzA5OTgsInN1YiI6Ijk2NDk4NSIsInNjb3BlcyI6W119.eLtZWzZxFNzHnmWMhomHRrNOX11QHFcNNQDTd7791GgMOwMxshGaS-OFMoFDRP4xK2FU2IF4BR6zlP8Qzy_y4_Mdpt94DPHw7Ee-rGLBNJ-lkhBi9wvYXW5iZwbYBnT5pRQl_VRJdkDjRe3m6aukUbwnci4OOeCSimGzxbrxXTtYUXX48H5mxvxNVbW6uKvd1SFEeBp_YsS61UjbCw6xJYObGL_jz6YgopRPZMM7Wjpxx2jaohT7E1WJS75Y813mriw4Cb9ZPrHmlMHAioi3bPnHwu5i1wKcQaKKbE9DHL1TVf69XajntfLOXudKxstifzJPCxeAwhNkFEtzK0IZGJNh6NRe44RBfV48BzecngYPIeFGNFbCpNHKha_mjfwO8f-2IUW1pXW7Sc2mSRo8_FACH6iS9Llxye6aGWxAxvmkGikRK8MqarRg4ysnTe3vToJs877Vqs0Pd0u-rH1mxDghuuAiFWBeV7QnYHR_lfxoD8X1p8gohjEM95j9yGHjeObs9q0S_JDptNQHVn8RwQnWbox3VbvTudR0Cl4awVSAC0CvOQCpWaxhc0nz0BCB0LmZDyjlbNd6jlImg_knZ1EvUJd-WH6nEL4tNidRgOCNB1ywKUKkQ7TaoV47GBLrx5Q0H6p85Tvv7AKLv0R-SroY981ZzMJnQ3VclFm4-7A';
    private $option_name = 'cmlw_form_groups_v6';
    private
 
$plugin_version = '6.0';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_settings_save']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);
    }

    public function activate() {
        // Pre-populate the settings with known forms on first activation
        if (get_option($this->option_name) === false) {
            $initial_forms = [
                'b033bda' => ['form_name' => 'Newsletter Form - Footer', 'group_id' => '161167418189677685'],
                '393ba31' => ['form_name' => 'Newsletter Form - Podcast', 'group_id' => '161167418189677685'],
                'a47aa6f' => ['form_name' => 'Newsletter Form - Episode', 'group_id' => '161167418189677685'],
                'dbe6e9f' => ['form_name' => 'Contact Form', 'group_id' => '161240032635520438'],
                '06155ba' => ['form_name' => 'Spiritual Crisis Form', 'group_id' => '123498155145692447'],
            ];
            update_option($this->option_name, $initial_forms);
        }
    }

    public function load_textdomain() {
        if (get_user_locale() == 'he_IL') {
            add_filter('gettext', [$this, 'translate_text'], 20, 3);
        }
    }

    public function register_webhook_endpoint() {
        register_rest_route('mailerlite-webhook/v1', '/submit', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook_request'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_webhook_request($request) {
        $data = $request->get_params();
        $formId = $data['form_id'] ?? '';
        $formGroups = get_option($this->option_name, []);
        $log_entry = ["timestamp" => date('Y-m-d H:i:s'), "form_id" => $formId, "form_name" => $data['form_name'] ?? 'N/A', "email" => $data['email'] ?? 'N/A', "status" => "", "response" => ""];

        if (empty($formId) || !isset($formGroups[$formId]) || empty($formGroups[$formId]['group_id'])) {
            $this->handle_unassigned_form($data, $formGroups);
            $log_entry['status'] = 'Unassigned';
            $log_entry['response'] = 'Form ID not configured or has no Group ID.';
            $this->log_to_file(print_r($log_entry, true));
            return new WP_Error('unassigned_form_id', 'This form is not yet configured.', ['status' => 400]);
        }

        $groupId = $formGroups[$formId]['group_id'];
        $email = $data['email'] ?? $data['אימייל'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $log_entry['status'] = 'Failed';
            $log_entry['response'] = 'Invalid email address.';
            $this->log_to_file(print_r($log_entry, true));
            return new WP_Error('invalid_email', 'Invalid email address.', ['status' => 400]);
        }

        $subscriber = ['email' => $email, 'resubscribe' => true, 'fields' => ['name' => $data['name'] ?? $data['שם'] ?? '']];
        if (!empty($data['message'])) $subscriber['fields']['message'] = $data['message'];
        if (!empty($data['phone'])) $subscriber['fields']['phone'] = $data['phone'];

        $response = wp_remote_post("https://connect.mailerlite.com/api/subscribers", [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey],
            'body' => json_encode(array_merge($subscriber, ['groups' => [$groupId]] )), 'timeout' => 15,
        ]);

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $log_entry['status'] = ($response_code >= 200 && $response_code < 300) ? 'Success' : 'Failed';
        $log_entry['response'] = $response_body;
        $this->log_to_file(print_r($log_entry, true));

        if ($log_entry['status'] === 'Success') {
          // Send a simple success response that Elementor understands
          return new WP_REST_Response(['message' => 'Submission successful.'], 200);
        } else {
          // Forward the detailed error from MailerLite if it failed
          return new WP_REST_Response(json_decode($response_body), $response_code);
}

    }

    private function handle_unassigned_form($data, $formGroups) {
        $formId = $data['form_id'] ?? '';
        $form_name = $data['form_name'] ?? 'N/A';
        if (empty($formId) || isset($formGroups[$formId])) return;

        $formGroups[$formId] = ['form_name' => $form_name, 'group_id' => ''];
        update_option($this->option_name, $formGroups);

        $to = "orenknaan@gmail.com";
        $subject = "⚠️ טופס חדש ולא משויך התקבל באתר";
        $headers = ["From: Website Notifier <noreply@yourdomain.com>", "Cc: taliamichaeli@gmail.com", "Content-Type: text/plain; charset=utf-8"];
        $body = "טופס חדש עם ID: $formId ושם: $form_name התקבל.\n";
        $body .= "יש להיכנס ללוח הבקרה של וורדפרס ולהגדיר לו קבוצת MailerLite.\n\n";
        $body .= "פרטי השליחה:\n" . print_r($data, true);
        wp_mail($to, $subject, $body, $headers);
    }

    private function log_to_file($message) {
        file_put_contents(CMLW_LOG_FILE, $message . "\n\n", FILE_APPEND);
    }

    public function add_admin_menu() {
        add_menu_page('MailerLite Webhook', 'MailerLite Webhook', 'manage_options', 'cmlw-dashboard', [$this, 'render_admin_page'], 'dashicons-email-alt', 25);
    }

    public function handle_settings_save() {
        if (isset($_POST['cmlw_save_settings_nonce']) && wp_verify_nonce($_POST['cmlw_save_settings_nonce'], 'cmlw_save_settings')) {
            $formGroups = get_option($this->option_name, []);
            if (isset($_POST['form_groups'])) {
                foreach ($_POST['form_groups'] as $formId => $details) {
                    if (isset($formGroups[$formId])) {
                        $formGroups[$formId]['group_id'] = sanitize_text_field($details['group_id']);
                    }
                }
            }
            update_option($this->option_name, $formGroups);
            add_settings_error('cmlw_settings', 'cmlw_updated', __('Settings saved successfully!', 'cmlw-webhook'), 'updated');
        }
    }

    public function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
        $webhook_url = get_rest_url(null, 'mailerlite-webhook/v1/submit');
        $formGroups = get_option($this->option_name, []);
        ?>
        <style>
            .cmlw-wrap .nav-tab-wrapper { margin-bottom: 20px; }
            .cmlw-wrap .cmlw-card { background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin-bottom: 20px; border-radius: 4px; }
            .cmlw-wrap h1 { font-size: 2em; font-weight: 600; margin-bottom: 20px; }
            .cmlw-wrap h2 { font-size: 1.5em; margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #ddd; }
            .cmlw-wrap .webhook-url-wrapper { display: flex; align-items: center; gap: 10px; }
            .cmlw-wrap .webhook-url-wrapper input { flex-grow: 1; padding: 8px; background: #f0f0f0; border-radius: 4px; }
            .cmlw-wrap .log-textarea { width: 100%; height: 500px; background: #23282d; color: #f0f0f0; border: 1px solid #ccc; padding: 10px; white-space: pre; font-family: monospace; border-radius: 4px; }
            .cmlw-wrap .wp-list-table th { font-weight: 600; }
            .cmlw-wrap .wp-list-table td { vertical-align: middle; }
            .cmlw-wrap .plugin-footer { margin-top: 20px; text-align: center; color: #777; font-size: 0.9em; border-top: 1px solid #ddd; padding-top: 15px; }
        </style>
        <div class="wrap cmlw-wrap">
            <h1><?php _e('MailerLite Integration Dashboard', 'cmlw-webhook'); ?></h1>
            <?php settings_errors('cmlw_settings'); ?>
            <nav class="nav-tab-wrapper">
                <a href="?page=cmlw-dashboard&tab=dashboard" class="nav-tab <?php echo $active_tab == 'dashboard' ? 'nav-tab-active' : ''; ?>"><?php _e('Dashboard & Settings', 'cmlw-webhook'); ?></a>
                <a href="?page=cmlw-dashboard&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>"><?php _e('Submission Log', 'cmlw-webhook'); ?></a>
            </nav>
            <div class="tab-content">
                <?php if ($active_tab == 'dashboard') : ?>
                    <div class="cmlw-card">
                        <h2><?php _e('Webhook Information', 'cmlw-webhook'); ?></h2>
                        <p><?php _e('Use the following URL in your Elementor form\'s "Webhook" action.', 'cmlw-webhook'); ?></p>
                        <div class="webhook-url-wrapper">
                            <input type="text" id="webhook-url" value="<?php echo esc_attr($webhook_url); ?>" readonly>
                            <button onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>').then(() => alert('<?php echo esc_js(__('URL Copied!', 'cmlw-webhook')); ?>'));" class="button button-primary"><?php _e('Copy URL', 'cmlw-webhook'); ?></button>
                        </div>
                    </div>
                    <div class="cmlw-card">
                        <h2><?php _e('Form to Group Mapping', 'cmlw-webhook'); ?></h2>
                        <p><?php _e('Assign a MailerLite Group ID to each form. New forms will appear here automatically after their first submission.', 'cmlw-webhook'); ?></p>
                        <form method="POST" action="">
                            <?php wp_nonce_field('cmlw_save_settings', 'cmlw_save_settings_nonce'); ?>
                            <table class="wp-list-table widefat fixed striped">
                                <thead><tr><th><?php _e('Form ID', 'cmlw-webhook'); ?></th><th><?php _e('Form Name', 'cmlw-webhook'); ?></th><th><?php _e('MailerLite Group ID', 'cmlw-webhook'); ?></th></tr></thead>
                                <tbody>
                                    <?php if (empty($formGroups)) : ?>
                                        <tr><td colspan="3"><?php _e('No forms have been submitted yet. Activate the plugin and submit a test form to begin.', 'cmlw-webhook'); ?></td></tr>
                                    <?php else : ?>
                                        <?php foreach ($formGroups as $formId => $details) : ?>
                                            <tr>
                                                <td><code><?php echo esc_html($formId); ?></code></td>
                                                <td><?php echo esc_html($details['form_name']); ?></td>
                                                <td><input type="text" name="form_groups[<?php echo esc_attr($formId); ?>][group_id]" value="<?php echo esc_attr($details['group_id'] ?? ''); ?>" class="regular-text"></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <?php submit_button(__('Save Changes', 'cmlw-webhook')); ?>
                        </form>
                    </div>
                <?php elseif ($active_tab == 'logs') : ?>
                    <div class="cmlw-card">
                        <h2><?php _e('Submission Log', 'cmlw-webhook'); ?></h2>
                        <p><?php printf(__('This log shows recent webhook submissions. The log file is located at: %s', 'cmlw-webhook'), '<code>' . esc_html(CMLW_LOG_FILE) . '</code>'); ?></p>
                        <textarea readonly class="log-textarea"><?php
                            echo file_exists(CMLW_LOG_FILE) ? esc_textarea(file_get_contents(CMLW_LOG_FILE)) : __('No logs recorded yet.', 'cmlw-webhook');
                        ?></textarea>
                    </div>
                <?php endif; ?>
            </div>
            <div class="plugin-footer">
                <p>MailerLite Integration Plugin v<?php echo $this->plugin_version; ?> by Manus</p>
            </div>
        </div>
        <?php
    }

    public function add_plugin_action_links($links) {
        $settings_link = '<a href="admin.php?page=cmlw-dashboard">' . __('Settings', 'cmlw-webhook') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function translate_text($translated_text, $text, $domain) {
        if ('cmlw-webhook' !== $domain) {
            return $translated_text;
        }
        $translations = [
            'MailerLite Integration Dashboard' => 'לוח בקרה - שילוב מיילרלייט',
            'Dashboard & Settings' => 'לוח בקרה והגדרות',
            'Submission Log' => 'יומן שליחות',
            'Webhook Information' => 'פרטי ה-Webhook',
            'Use the following URL in your Elementor form\'s "Webhook" action.' => 'יש להשתמש בכתובת הבאה בפעולת ה-Webhook בטפסי אלמנטור.',
            'Copy URL' => 'העתק כתובת',
            'URL Copied!' => 'הכתובת הועתקה!',
            'Form to Group Mapping' => 'מיפוי טפסים לקבוצות',
            'Assign a MailerLite Group ID to each form. New forms will appear here automatically after their first submission.' => 'יש לשייך Group ID של מיילרלייט לכל טופס. טפסים חדשים יופיעו כאן אוטומטית לאחר השליחה הראשונה שלהם.',
            'Form ID' => 'מזהה טופס (Form ID)',
            'Form Name' => 'שם הטופס',
            'MailerLite Group ID' => 'מזהה קבוצה (Group ID)',
            'No forms have been submitted yet. Activate the plugin and submit a test form to begin.' => 'טרם הוגדרו טפסים. יש להפעיל את התוסף ולבצע שליחת מבחן כדי להתחיל.',
            'Save Changes' => 'שמירת שינויים',
            'This log shows recent webhook submissions. The log file is located at: %s' => 'יומן זה מציג את השליחות האחרונות. קובץ הלוג המלא נמצא בנתיב: %s',
            'No logs recorded yet.' => 'טרם נרשמו לוגים.',
            'Settings saved successfully!' => 'ההגדרות נשמרו בהצלחה!',
            'Settings' => 'הגדרות',
        ];
        return $translations[$text] ?? $translated_text;
    }
}

new CMLW_Integration_Plugin_V6();