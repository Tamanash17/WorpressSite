<?php
/**
 * Plugin Name: Jetstar Custom Registration System
 * Description: Professional multi-step registration with API partner onboarding
 * Version: 7.4
 * Author: Jetstar
 * Text Domain: jetstar-registration
 */

if (!defined('ABSPATH')) exit;

// Plugin constants
define('JETSTAR_REG_VERSION', '7.4');
define('JETSTAR_REG_PATH', plugin_dir_path(__FILE__));
define('JETSTAR_REG_URL', plugin_dir_url(__FILE__));
define('JETSTAR_FROM_EMAIL', 'apisupportblog@jetstar.com');
define('JETSTAR_FROM_NAME', 'Jetstar API Blog');

// SMTP Configuration (Defaults - can be overridden in settings)
define('JETSTAR_SMTP_HOST', 'smtp.gmail.com');
define('JETSTAR_SMTP_PORT', 587);
define('JETSTAR_SMTP_SECURE', 'tls');
define('JETSTAR_SMTP_AUTH', true);
define('JETSTAR_SMTP_USERNAME', '');
define('JETSTAR_SMTP_PASSWORD', '');
define('JETSTAR_DEBUG_MODE', false);

add_action('wp_enqueue_scripts', 'jetstar_enqueue_registration_assets');

function jetstar_enqueue_registration_assets() {
    if (is_page_template('template-jetstar-registration.php')) {
        wp_enqueue_style('jetstar-registration', JETSTAR_REG_URL . 'assets/css/registration.css', array(), JETSTAR_REG_VERSION);
        wp_enqueue_script('jetstar-registration', JETSTAR_REG_URL . 'assets/js/registration.js', array('jquery'), JETSTAR_REG_VERSION, true);
        wp_localize_script('jetstar-registration', 'jetstarAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jetstar_registration_nonce')
        ));
    }
}

add_action('wp_ajax_nopriv_jetstar_register', 'jetstar_ajax_register');
add_action('wp_ajax_jetstar_register', 'jetstar_ajax_register');

function jetstar_ajax_register() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jetstar_registration_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }
    
    $data = $_POST['formData'];
    
    $validation = jetstar_validate_registration($data);
    if (!$validation['valid']) {
        wp_send_json_error(array('message' => $validation['message']));
    }
    
    if (username_exists($data['username'])) {
        wp_send_json_error(array('message' => 'Username already exists.'));
    }
    
    if (email_exists($data['email'])) {
        $existing_user = get_user_by('email', $data['email']);
        $status = get_user_meta($existing_user->ID, 'account_status', true);
        
        if ($status === 'pending' || $status === 'rejected') {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            wp_delete_user($existing_user->ID);
        } else {
            wp_send_json_error(array('message' => 'Email already registered.'));
        }
    }
    
    $user_id = wp_create_user($data['username'], $data['password'], $data['email']);
    
    if (is_wp_error($user_id)) {
        wp_send_json_error(array('message' => $user_id->get_error_message()));
    }
    
    // Generate Application ID
    $application_id = jetstar_generate_application_id();
    
    wp_update_user(array(
        'ID' => $user_id,
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'display_name' => $data['first_name'] . ' ' . $data['last_name'],
        'role' => 'subscriber'
    ));
    
    update_user_meta($user_id, 'account_status', 'pending');
    update_user_meta($user_id, 'registration_date', current_time('mysql'));
    update_user_meta($user_id, 'application_id', $application_id);
    update_user_meta($user_id, 'company_name', sanitize_text_field($data['company']));
    update_user_meta($user_id, 'phone_number', sanitize_text_field($data['phone']));
    update_user_meta($user_id, 'passenger_contact_consent', true);
    update_user_meta($user_id, 'passenger_contact_consent_date', current_time('mysql'));
    
    jetstar_save_api_partner_data($user_id, $data);
    
    $user = get_userdata($user_id);
    jetstar_send_registration_email_to_user($user, $application_id);
    jetstar_send_registration_email_to_admin($user, $application_id);
    
    wp_send_json_success(array(
        'message' => 'Registration successful!',
        'redirect' => jetstar_get_setting('success_page', home_url('/registration-success'))
    ));
}

function jetstar_generate_application_id() {
    $prefix = get_option('jetstar_app_id_prefix', 'JQ-APP-');
    $format = get_option('jetstar_app_id_format', 'timestamp');
    
    switch ($format) {
        case 'sequential':
            $last_id = get_option('jetstar_last_app_id', 0);
            $new_id = $last_id + 1;
            update_option('jetstar_last_app_id', $new_id);
            return $prefix . str_pad($new_id, 6, '0', STR_PAD_LEFT);
            
        case 'random':
            return $prefix . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            
        case 'timestamp':
        default:
            return $prefix . time() . rand(100, 999);
    }
}function jetstar_save_api_partner_data($user_id, $data) {
    if (!isset($data['already_registered'])) {
        update_user_meta($user_id, 'is_api_partner', false);
        return;
    }
    
    update_user_meta($user_id, 'is_api_partner', true);
    
    if ($data['already_registered'] === 'yes') {
        update_user_meta($user_id, 'api_partner_status', 'existing');
        update_user_meta($user_id, 'organization_code', sanitize_text_field($data['organization_code']));
        update_user_meta($user_id, 'requires_code_validation', true);
    } else {
        update_user_meta($user_id, 'api_partner_status', 'new');
        update_user_meta($user_id, 'api_access_type', sanitize_text_field($data['api_type']));
        update_user_meta($user_id, 'type_of_business', sanitize_text_field($data['business_type']));
        
        if (!empty($data['organization_name'])) update_user_meta($user_id, 'organization_name', sanitize_text_field($data['organization_name']));
        if (!empty($data['address'])) update_user_meta($user_id, 'address', sanitize_textarea_field($data['address']));
        if (!empty($data['city'])) update_user_meta($user_id, 'city', sanitize_text_field($data['city']));
        if (!empty($data['state'])) update_user_meta($user_id, 'state', sanitize_text_field($data['state']));
        if (!empty($data['postal_code'])) update_user_meta($user_id, 'postal_code', sanitize_text_field($data['postal_code']));
        if (!empty($data['country'])) update_user_meta($user_id, 'country', sanitize_text_field($data['country']));
        if (!empty($data['operating_hours'])) update_user_meta($user_id, 'operating_hours', sanitize_text_field($data['operating_hours']));
        if (!empty($data['timezone'])) update_user_meta($user_id, 'timezone', sanitize_text_field($data['timezone']));
        if (!empty($data['website'])) update_user_meta($user_id, 'website', esc_url_raw($data['website']));
        if (!empty($data['ancillary'])) update_user_meta($user_id, 'ancillary_products', json_encode($data['ancillary']));
        if (!empty($data['projected_segments'])) update_user_meta($user_id, 'projected_segments', sanitize_text_field($data['projected_segments']));
        if (!empty($data['test_ip'])) update_user_meta($user_id, 'test_ip', sanitize_text_field($data['test_ip']));
        if (!empty($data['prod_ip'])) update_user_meta($user_id, 'prod_ip', sanitize_text_field($data['prod_ip']));
        
        if ($data['business_type'] === 'travel_agent_indirect') {
            update_user_meta($user_id, 'aggregator_name', sanitize_text_field($data['aggregator_name']));
            update_user_meta($user_id, 'aggregator_contact', sanitize_text_field($data['aggregator_contact']));
            update_user_meta($user_id, 'aggregator_email', sanitize_email($data['aggregator_email']));
            if (!empty($data['aggregator_phone'])) update_user_meta($user_id, 'aggregator_phone', sanitize_text_field($data['aggregator_phone']));
        }
        
        if (!empty($data['sponsor_name'])) update_user_meta($user_id, 'sponsor_name', sanitize_text_field($data['sponsor_name']));
        if (!empty($data['sponsor_email'])) update_user_meta($user_id, 'sponsor_email', sanitize_email($data['sponsor_email']));
        if (!empty($data['sponsor_phone'])) update_user_meta($user_id, 'sponsor_phone', sanitize_text_field($data['sponsor_phone']));
        
        if (!empty($data['manager_name'])) {
            update_user_meta($user_id, 'manager_name', sanitize_text_field($data['manager_name']));
            update_user_meta($user_id, 'manager_position', sanitize_text_field($data['manager_position']));
            update_user_meta($user_id, 'manager_email', sanitize_email($data['manager_email']));
            if (!empty($data['manager_phone'])) update_user_meta($user_id, 'manager_phone', sanitize_text_field($data['manager_phone']));
        }
        
        if (!empty($data['tech_name'])) {
            update_user_meta($user_id, 'tech_name', sanitize_text_field($data['tech_name']));
            update_user_meta($user_id, 'tech_position', sanitize_text_field($data['tech_position']));
            update_user_meta($user_id, 'tech_email', sanitize_email($data['tech_email']));
            if (!empty($data['tech_phone'])) update_user_meta($user_id, 'tech_phone', sanitize_text_field($data['tech_phone']));
        }
        
        if (!empty($data['api_spec_emails'])) update_user_meta($user_id, 'api_spec_emails', sanitize_textarea_field($data['api_spec_emails']));
        if (!empty($data['outage_emails'])) update_user_meta($user_id, 'outage_emails', sanitize_textarea_field($data['outage_emails']));
        if (!empty($data['referral_source'])) update_user_meta($user_id, 'referral_source', sanitize_text_field($data['referral_source']));
        if (!empty($data['comments'])) update_user_meta($user_id, 'comments', sanitize_textarea_field($data['comments']));
    }
}

function jetstar_validate_registration($data) {
    $min_password = get_option('jetstar_min_password_length', 8);
    
    if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
        return array('valid' => false, 'message' => 'Please fill in all required fields.');
    }
    if (!is_email($data['email'])) {
        return array('valid' => false, 'message' => 'Invalid email address.');
    }
    if (strlen($data['password']) < $min_password) {
        return array('valid' => false, 'message' => 'Password must be at least ' . $min_password . ' characters.');
    }
    
    // ✅ NEW: Validate passenger contact consent checkbox
    if (!isset($data['passenger_contact_consent']) || $data['passenger_contact_consent'] !== 'true') {
        return array('valid' => false, 'message' => 'You must acknowledge and agree to provide passenger contact details to Jetstar for operational notifications.');
    }
    
    if (isset($data['already_registered'])) {
        if ($data['already_registered'] === 'yes') {
            if (empty($data['organization_code'])) {
                return array('valid' => false, 'message' => 'Organization code required.');
            }
            
            $org_code = trim($data['organization_code']);
            if (!preg_match('/^[A-Za-z0-9]{6,9}$/', $org_code)) {
                return array('valid' => false, 'message' => 'Organization code must be 6-9 alphanumeric characters (letters and numbers only).');
            }
            
            $valid_codes = get_option('jetstar_valid_org_codes', '');
            if (!empty($valid_codes)) {
                $valid_codes_array = array_map('trim', explode("\n", $valid_codes));
                $valid_codes_array = array_map('strtoupper', $valid_codes_array);
                
                if (!in_array(strtoupper($org_code), $valid_codes_array)) {
                    return array('valid' => false, 'message' => 'Invalid organization code. Please check and try again.');
                }
            }
        }
        
        if ($data['already_registered'] === 'no') {
            if (empty($data['api_type']) || empty($data['business_type'])) {
                return array('valid' => false, 'message' => 'Please select API type and business type.');
            }
            if ($data['business_type'] === 'travel_agent_indirect') {
                if (empty($data['aggregator_name']) || empty($data['aggregator_email'])) {
                    return array('valid' => false, 'message' => 'Aggregator details required for Indirect Partners.');
                }
            }
            if ($data['api_type'] === 'ndc') {
                $required = array('organization_name', 'address', 'city', 'country', 'operating_hours', 'manager_name', 'manager_email', 'tech_name', 'tech_email');
                foreach ($required as $field) {
                    if (empty($data[$field])) {
                        return array('valid' => false, 'message' => ucwords(str_replace('_', ' ', $field)) . ' required for NDC.');
                    }
                }
            }
            if ($data['api_type'] === 'digital') {
                if (empty($data['manager_name']) || empty($data['manager_email']) || empty($data['tech_name']) || empty($data['tech_email'])) {
                    return array('valid' => false, 'message' => 'Contact details required for Digital API.');
                }
            }
        }
    }
    return array('valid' => true);
}

add_action('phpmailer_init', 'jetstar_configure_smtp');

function jetstar_configure_smtp($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host = get_option('jetstar_smtp_host', JETSTAR_SMTP_HOST);
    $phpmailer->Port = get_option('jetstar_smtp_port', JETSTAR_SMTP_PORT);
    $phpmailer->SMTPSecure = get_option('jetstar_smtp_secure', JETSTAR_SMTP_SECURE);
    $phpmailer->SMTPAuth = JETSTAR_SMTP_AUTH;
    $phpmailer->Username = get_option('jetstar_smtp_username', JETSTAR_SMTP_USERNAME);
    $phpmailer->Password = get_option('jetstar_smtp_password', JETSTAR_SMTP_PASSWORD);
    $phpmailer->From = get_option('jetstar_from_email', JETSTAR_FROM_EMAIL);
    $phpmailer->FromName = get_option('jetstar_from_name', JETSTAR_FROM_NAME);
    
    if (get_option('jetstar_debug_mode', JETSTAR_DEBUG_MODE)) {
        $phpmailer->SMTPDebug = 2;
        $phpmailer->Debugoutput = function($str, $level) {
            error_log("SMTP DEBUG: " . trim($str));
        };
    }
}

function jetstar_get_email_header() {
    $header_color = get_option('jetstar_email_header_color', '#FF6600');
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>body{margin:0;padding:0;font-family:Arial,sans-serif;background-color:#f4f4f4}.email-container{max-width:600px;margin:20px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1)}.email-header{background:linear-gradient(135deg,' . $header_color . ' 0%,' . $header_color . 'dd 100%);padding:40px 30px;text-align:center}.email-header h1{color:#fff;margin:0;font-size:28px}.email-body{padding:40px 30px;color:#333;line-height:1.6}.info-box{background:#f8f9fa;border-left:4px solid ' . $header_color . ';padding:20px;margin:20px 0;border-radius:4px}.info-row{display:table;width:100%;margin-bottom:12px}.info-label{display:table-cell;width:40%;font-weight:bold;color:#666}.info-value{display:table-cell;color:#333}.section-title{color:' . $header_color . ';font-size:18px;font-weight:bold;margin:30px 0 15px 0;padding-bottom:10px;border-bottom:2px solid #e2e8f0}.button{display:inline-block;padding:14px 32px;background:' . get_option('jetstar_button_color', '#FF6600') . ';color:#fff!important;text-decoration:none;border-radius:6px;font-weight:bold;margin:20px 0}.email-footer{background:#f8f9fa;padding:30px;text-align:center;color:#666;font-size:14px}.highlight{background:#fef3c7;padding:15px;border-radius:4px;margin:15px 0}.app-id-box{background:#fff7ed;border:2px solid ' . $header_color . ';padding:15px;margin:20px 0;border-radius:6px;text-align:center}</style></head><body><div class="email-container">';
}

function jetstar_get_email_footer() {
    $from_email = get_option('jetstar_from_email', JETSTAR_FROM_EMAIL);
    return '<div class="email-footer"><p><strong>' . get_bloginfo('name') . '</strong></p><p>If you have questions, contact us at<br><a href="mailto:' . $from_email . '" style="color:#FF6600">' . $from_email . '</a></p><p style="color:#999;font-size:12px;margin-top:20px">© ' . date('Y') . ' ' . get_bloginfo('name') . '. All rights reserved.</p></div></div></body></html>';
}

function jetstar_parse_email_template($template, $user, $extra_data = array()) {
    $replacements = array(
        '{{user_name}}' => $user->display_name,
        '{{first_name}}' => $user->first_name,
        '{{last_name}}' => $user->last_name,
        '{{username}}' => $user->user_login,
        '{{email}}' => $user->user_email,
        '{{company}}' => get_user_meta($user->ID, 'company_name', true),
        '{{phone}}' => get_user_meta($user->ID, 'phone_number', true),
        '{{application_id}}' => get_user_meta($user->ID, 'application_id', true),
        '{{site_name}}' => get_bloginfo('name'),
        '{{site_url}}' => home_url(),
        '{{login_url}}' => get_option('jetstar_login_page', home_url('/login')),
        '{{approval_days}}' => get_option('jetstar_approval_days', '3'),
        '{{registration_date}}' => date('F j, Y', strtotime(get_user_meta($user->ID, 'registration_date', true))),
        '{{api_type}}' => strtoupper(get_user_meta($user->ID, 'api_access_type', true)),
        '{{business_type}}' => jetstar_format_business_type(get_user_meta($user->ID, 'type_of_business', true)),
        '{{organization_code}}' => get_user_meta($user->ID, 'organization_code', true)
    );
    
    $replacements = array_merge($replacements, $extra_data);
    
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}function jetstar_send_registration_email_to_user($user, $application_id = '') {
    if (!get_option('jetstar_send_user_confirmation', '1')) return;
    
    if (empty($application_id)) {
        $application_id = get_user_meta($user->ID, 'application_id', true);
    }
    
    $subject = get_option('jetstar_email_user_confirmation_subject', 'Registration Received - {{site_name}}');
    $body = get_option('jetstar_email_user_confirmation_body', jetstar_get_default_template('user_confirmation'));
    
    $subject = jetstar_parse_email_template($subject, $user);
    $body = jetstar_parse_email_template($body, $user);
    
    $message = jetstar_get_email_header() . $body . jetstar_get_email_footer();
    
    add_filter('wp_mail_content_type', function() { return 'text/html'; });
    $result = wp_mail($user->user_email, $subject, $message);
    remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    
    return $result;
}

function jetstar_send_registration_email_to_admin($user, $application_id = '') {
    if (!get_option('jetstar_send_admin_notification', '1')) return;
    
    if (empty($application_id)) {
        $application_id = get_user_meta($user->ID, 'application_id', true);
    }
    
    $admin_email = get_option('jetstar_admin_email', 'apisupportblog@jetstar.com');
    
    $subject = get_option('jetstar_email_admin_notification_subject', '🔔 New Registration - {{user_name}}');
    $body = get_option('jetstar_email_admin_notification_body', jetstar_build_admin_email_body($user));
    
    $subject = jetstar_parse_email_template($subject, $user);
    $body = jetstar_parse_email_template($body, $user);
    
    $message = jetstar_get_email_header() . $body . jetstar_get_email_footer();
    
    $headers = array();
    $cc_emails = get_option('jetstar_cc_emails', '');
    $bcc_emails = get_option('jetstar_bcc_emails', '');
    
    if ($cc_emails) {
        $cc_list = explode("\n", $cc_emails);
        foreach ($cc_list as $cc) {
            $cc = trim($cc);
            if (is_email($cc)) {
                $headers[] = 'Cc: ' . $cc;
            }
        }
    }
    
    if ($bcc_emails) {
        $bcc_list = explode("\n", $bcc_emails);
        foreach ($bcc_list as $bcc) {
            $bcc = trim($bcc);
            if (is_email($bcc)) {
                $headers[] = 'Bcc: ' . $bcc;
            }
        }
    }
    
    add_filter('wp_mail_content_type', function() { return 'text/html'; });
    $result = wp_mail($admin_email, $subject, $message, $headers);
    remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    
    return $result;
}

function jetstar_build_admin_email_body($user) {
    $is_api_partner = get_user_meta($user->ID, 'is_api_partner', true);
    $api_status = get_user_meta($user->ID, 'api_partner_status', true);
    $application_id = get_user_meta($user->ID, 'application_id', true);
    
    $body = '<div class="email-header"><h1>New Registration</h1></div><div class="email-body">';
    
    if ($application_id) {
        $body .= '<div class="app-id-box">';
        $body .= '<div style="font-size:13px;color:#666;margin-bottom:8px">Application ID</div>';
        $body .= '<div style="font-size:24px;font-weight:bold;color:#FF6600;font-family:monospace">{{application_id}}</div>';
        $body .= '<div style="font-size:12px;color:#999;margin-top:8px">Submitted: {{registration_date}}</div>';
        $body .= '</div>';
    }
    
    if ($is_api_partner) {
        $body .= '<div class="highlight"><strong>⚠️ Action Required:</strong> API Partner registration pending review.</div>';
    }
    
    $body .= '<div class="section-title">👤 User Information</div><div class="info-box">';
    $body .= '<div class="info-row"><div class="info-label">Name:</div><div class="info-value"><strong>{{user_name}}</strong></div></div>';
    $body .= '<div class="info-row"><div class="info-label">Username:</div><div class="info-value">{{username}}</div></div>';
    $body .= '<div class="info-row"><div class="info-label">Email:</div><div class="info-value"><a href="mailto:{{email}}">{{email}}</a></div></div>';
    $body .= '<div class="info-row"><div class="info-label">Company:</div><div class="info-value">{{company}}</div></div>';
    $body .= '<div class="info-row"><div class="info-label">Phone:</div><div class="info-value">{{phone}}</div></div>';
    $body .= '</div>';
    
    $body .= '<div style="text-align:center;margin:40px 0"><a href="' . admin_url('users.php?page=pending-registrations') . '" class="button">Review Registration</a></div>';
    $body .= '</div>';
    
    return $body;
}

function jetstar_format_business_type($type) {
    $types = array('crs'=>'CRS','content_aggregator'=>'Content Aggregator','meta_search'=>'Meta Search','travel_agent'=>'Travel Agent','travel_agent_indirect'=>'Travel Agent (Indirect)','obt'=>'OBT','internal_partner'=>'Internal Partner','other'=>'Other');
    return isset($types[$type]) ? $types[$type] : $type;
}

function jetstar_send_approval_email($user) {
    $subject = get_option('jetstar_email_approval_subject', '✅ Account Approved - {{site_name}}');
    $body = get_option('jetstar_email_approval_body', jetstar_get_default_template('approval'));
    
    $subject = jetstar_parse_email_template($subject, $user);
    $body = jetstar_parse_email_template($body, $user);
    
    $message = jetstar_get_email_header() . $body . jetstar_get_email_footer();
    
    add_filter('wp_mail_content_type', function() { return 'text/html'; });
    $result = wp_mail($user->user_email, $subject, $message);
    remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    
    if (get_option('jetstar_email_welcome_enabled', '0')) {
        jetstar_send_welcome_email($user);
    }
    
    return $result;
}

function jetstar_send_rejection_email($user) {
    $subject = get_option('jetstar_email_rejection_subject', 'Registration Status Update - {{site_name}}');
    $body = get_option('jetstar_email_rejection_body', jetstar_get_default_template('rejection'));
    
    $subject = jetstar_parse_email_template($subject, $user);
    $body = jetstar_parse_email_template($body, $user);
    
    $message = jetstar_get_email_header() . $body . jetstar_get_email_footer();
    
    add_filter('wp_mail_content_type', function() { return 'text/html'; });
    $result = wp_mail($user->user_email, $subject, $message);
    remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    
    return $result;
}

function jetstar_send_welcome_email($user) {
    $subject = get_option('jetstar_email_welcome_subject', '🎉 Welcome to {{site_name}}!');
    $body = get_option('jetstar_email_welcome_body', jetstar_get_default_template('welcome'));
    
    $subject = jetstar_parse_email_template($subject, $user);
    $body = jetstar_parse_email_template($body, $user);
    
    $message = jetstar_get_email_header() . $body . jetstar_get_email_footer();
    
    add_filter('wp_mail_content_type', function() { return 'text/html'; });
    $result = wp_mail($user->user_email, $subject, $message);
    remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    
    return $result;
}

add_action('jetstar_send_pending_reminders', 'jetstar_send_reminder_emails');

function jetstar_setup_reminder_cron() {
    if (!wp_next_scheduled('jetstar_send_pending_reminders')) {
        wp_schedule_event(time(), 'daily', 'jetstar_send_pending_reminders');
    }
}
add_action('wp', 'jetstar_setup_reminder_cron');

function jetstar_send_reminder_emails() {
    if (!get_option('jetstar_email_reminder_enabled', '0')) return;
    
    $reminder_days = get_option('jetstar_email_reminder_days', '3');
    $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . $reminder_days . ' days'));
    
    $pending_users = get_users(array(
        'meta_key' => 'account_status',
        'meta_value' => 'pending',
        'meta_query' => array(
            array(
                'key' => 'registration_date',
                'value' => $cutoff_date,
                'compare' => '<=',
                'type' => 'DATETIME'
            ),
            array(
                'key' => 'reminder_sent',
                'compare' => 'NOT EXISTS'
            )
        )
    ));
    
    $admin_email = get_option('jetstar_admin_email', 'apisupportblog@jetstar.com');
    $subject = get_option('jetstar_email_reminder_subject', '⏰ Pending Registration Reminder');
    $body = get_option('jetstar_email_reminder_body', jetstar_get_default_template('reminder'));
    
    foreach ($pending_users as $user) {
        $parsed_subject = jetstar_parse_email_template($subject, $user);
        $parsed_body = jetstar_parse_email_template($body, $user, array(
            '{{pending_days}}' => $reminder_days,
            '{{review_url}}' => admin_url("user-edit.php?user_id={$user->ID}")
        ));
        
        $message = jetstar_get_email_header() . $parsed_body . jetstar_get_email_footer();
        
        add_filter('wp_mail_content_type', function() { return 'text/html'; });
        $sent = wp_mail($admin_email, $parsed_subject, $message);
        remove_filter('wp_mail_content_type', function() { return 'text/html'; });
        
        if ($sent) {
            update_user_meta($user->ID, 'reminder_sent', current_time('mysql'));
        }
    }
}

function jetstar_get_default_template($type) {
    $templates = array(
        'user_confirmation' => '<div class="email-header"><h1>Welcome to {{site_name}}</h1></div><div class="email-body"><p>Hello <strong>{{user_name}}</strong>,</p><p>Thank you for registering. Your registration has been received and is currently under review.</p><div class="app-id-box"><div style="font-size:13px;color:#666;margin-bottom:8px">Application ID</div><div style="font-size:20px;font-weight:bold;color:#FF6600;font-family:monospace">{{application_id}}</div><div style="font-size:12px;color:#999;margin-top:8px">{{registration_date}}</div></div><p>You will receive an email within <strong>{{approval_days}} business days</strong>.</p><p>Best regards,<br><strong>{{site_name}} Team</strong></p></div>',
        
        'admin_notification' => '<div class="email-header"><h1>New Registration</h1></div><div class="email-body"><div class="app-id-box"><div style="font-size:13px;color:#666;margin-bottom:8px">Application ID</div><div style="font-size:24px;font-weight:bold;color:#FF6600;font-family:monospace">{{application_id}}</div></div><div class="highlight"><strong>⚠️ Action Required:</strong> New registration pending review.</div><div class="section-title">👤 User Information</div><div class="info-box"><div class="info-row"><div class="info-label">Name:</div><div class="info-value"><strong>{{user_name}}</strong></div></div><div class="info-row"><div class="info-label">Email:</div><div class="info-value">{{email}}</div></div><div class="info-row"><div class="info-label">Company:</div><div class="info-value">{{company}}</div></div><div class="info-row"><div class="info-label">Phone:</div><div class="info-value">{{phone}}</div></div></div><div style="text-align:center;margin:40px 0"><a href="' . admin_url('users.php?page=pending-registrations') . '" class="button">Review Registration</a></div></div>',
        
        'approval' => '<div class="email-header"><h1>Account Approved!</h1></div><div class="email-body"><p>Hello <strong>{{user_name}}</strong>,</p><p>Great news! Your account has been approved and is now active.</p><div style="text-align:center;margin:30px 0"><a href="{{login_url}}" class="button">Sign In to Your Account</a></div><div class="info-box"><div class="info-row"><div class="info-label">Username:</div><div class="info-value"><strong>{{username}}</strong></div></div><div class="info-row"><div class="info-label">Email:</div><div class="info-value">{{email}}</div></div></div><p style="margin-top:30px">Welcome aboard!<br><strong>{{site_name}} Team</strong></p></div>',
        
        'rejection' => '<div class="email-header"><h1>Registration Update</h1></div><div class="email-body"><p>Hello <strong>{{user_name}}</strong>,</p><p>Thank you for your interest in {{site_name}}.</p><p>Unfortunately, we are unable to approve your registration at this time.</p><p>If you have any questions, please contact us.</p><p style="margin-top:30px">Best regards,<br><strong>{{site_name}} Team</strong></p></div>',
        
        'welcome' => '<div class="email-header"><h1>🎉 Welcome!</h1></div><div class="email-body"><p>Hello <strong>{{user_name}}</strong>,</p><p>Welcome to {{site_name}}! We\'re thrilled to have you on board.</p><div class="highlight"><strong>Here are some things you can do:</strong><ul><li>Explore our resources and documentation</li><li>Connect with our team for support</li><li>Start using our services right away</li></ul></div><div style="text-align:center;margin:30px 0"><a href="{{site_url}}" class="button">Visit Dashboard</a></div><p>If you need any assistance, don\'t hesitate to reach out!</p><p style="margin-top:30px">Best regards,<br><strong>{{site_name}} Team</strong></p></div>',
        
        'reminder' => '<div class="email-header"><h1>⏰ Pending Registration</h1></div><div class="email-body"><p>Hello Admin,</p><p>This is a reminder that <strong>{{user_name}}</strong> ({{email}}) has been waiting for approval for <strong>{{pending_days}} days</strong>.</p><div class="info-box"><div class="info-row"><div class="info-label">Application ID:</div><div class="info-value"><strong>{{application_id}}</strong></div></div><div class="info-row"><div class="info-label">Company:</div><div class="info-value">{{company}}</div></div><div class="info-row"><div class="info-label">Registration Date:</div><div class="info-value">{{registration_date}}</div></div></div><div style="text-align:center;margin:40px 0"><a href="{{review_url}}" class="button">Review Registration</a></div></div>'
    );
    
    return isset($templates[$type]) ? $templates[$type] : '';
}

add_filter('theme_page_templates', 'jetstar_add_registration_template');
function jetstar_add_registration_template($templates) {
    $templates['template-jetstar-registration.php'] = 'Jetstar Registration';
    return $templates;
}

add_filter('template_include', 'jetstar_load_registration_template');
function jetstar_load_registration_template($template) {
    global $post;
    if ($post && get_page_template_slug($post->ID) == 'template-jetstar-registration.php') {
        $plugin_template = JETSTAR_REG_PATH . 'template-jetstar-registration.php';
        if (file_exists($plugin_template)) return $plugin_template;
    }
    return $template;
}

add_filter('authenticate', 'jetstar_check_user_status', 30, 3);
function jetstar_check_user_status($user, $username, $password) {
    if (is_wp_error($user)) return $user;
    $status = get_user_meta($user->ID, 'account_status', true);
    if ($status === 'pending') return new WP_Error('pending_approval', '<strong>Pending:</strong> Awaiting approval.');
    if ($status === 'suspended') return new WP_Error('account_suspended', '<strong>Suspended:</strong> Contact ' . get_option('jetstar_from_email', JETSTAR_FROM_EMAIL));
    if ($status === 'rejected') return new WP_Error('account_rejected', '<strong>Rejected:</strong> Contact ' . get_option('jetstar_from_email', JETSTAR_FROM_EMAIL));
    return $user;
}

function jetstar_get_setting($key, $default = '') {
    $settings_map = array(
        'from_email' => get_option('jetstar_from_email', JETSTAR_FROM_EMAIL),
        'from_name' => get_option('jetstar_from_name', JETSTAR_FROM_NAME),
        'admin_email' => get_option('jetstar_admin_email', 'apisupportblog@jetstar.com'),
        'approval_days' => get_option('jetstar_approval_days', '3'),
        'header_color' => get_option('jetstar_email_header_color', '#FF6600'),
        'button_color' => get_option('jetstar_button_color', '#FF6600'),
        'success_page' => get_option('jetstar_success_page', home_url('/registration-success')),
        'login_page' => get_option('jetstar_login_page', home_url('/login'))
    );
    return isset($settings_map[$key]) ? $settings_map[$key] : $default;
}add_action('show_user_profile', 'jetstar_user_status_field');
add_action('edit_user_profile', 'jetstar_user_status_field');
function jetstar_user_status_field($user) {
    if (!current_user_can('edit_users')) return;
    $status = get_user_meta($user->ID, 'account_status', true) ?: 'active';
    $is_api = get_user_meta($user->ID, 'is_api_partner', true);
    $api_status = get_user_meta($user->ID, 'api_partner_status', true);
    $reg_date = get_user_meta($user->ID, 'registration_date', true);
    $application_id = get_user_meta($user->ID, 'application_id', true);
    $consent = get_user_meta($user->ID, 'passenger_contact_consent', true);
    $consent_date = get_user_meta($user->ID, 'passenger_contact_consent_date', true);
    ?>
    <h3>Account Status</h3>
    <table class="form-table">
        <?php if ($application_id): ?>
        <tr>
            <th>Application ID</th>
            <td>
                <code style="background:#fff7ed;border:2px solid #FF6600;padding:8px 15px;font-size:16px;font-weight:bold;color:#FF6600;display:inline-block;border-radius:4px"><?php echo esc_html($application_id); ?></code>
            </td>
        </tr>
        <?php endif; ?>
        <tr><th><label for="account_status">Status</label></th><td>
            <select name="account_status" id="account_status">
                <option value="pending" <?php selected($status, 'pending'); ?>>Pending</option>
                <option value="active" <?php selected($status, 'active'); ?>>Active</option>
                <option value="suspended" <?php selected($status, 'suspended'); ?>>Suspended</option>
                <option value="rejected" <?php selected($status, 'rejected'); ?>>Rejected</option>
            </select>
            <p class="description">Current: <strong><?php echo ucfirst($status); ?></strong></p>
            <?php if ($reg_date): ?><p class="description">Registered: <?php echo date('F j, Y g:i a', strtotime($reg_date)); ?></p><?php endif; ?>
        </td></tr>
        <?php if ($consent): ?>
        <tr>
            <th>Passenger Contact Consent</th>
            <td>
                <p style="color:#10b981;font-weight:600">✅ Consented</p>
                <?php if ($consent_date): ?>
                <p class="description">Date: <?php echo date('F j, Y g:i a', strtotime($consent_date)); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
        <?php if ($is_api): ?><tr><th>API Partner</th><td>
            <?php if ($api_status === 'existing'): 
                $org_code = get_user_meta($user->ID, 'organization_code', true);
                $validated = get_user_meta($user->ID, 'organization_code_validated', true);
            ?>
                <p><strong>✅ Existing Partner</strong></p><p>Code: <code><?php echo esc_html($org_code); ?></code></p>
                <?php if (!$validated): ?><p style="color:#f59e0b">⚠️ Pending validation</p>
                <label><input type="checkbox" name="validate_org_code" value="1"> Mark validated</label>
                <?php else: ?><p style="color:#10b981">✅ Validated</p><?php endif; ?>
            <?php else: 
                $api_type = get_user_meta($user->ID, 'api_access_type', true);
                $business = get_user_meta($user->ID, 'type_of_business', true);
            ?>
                <p><strong>🆕 New Partner</strong></p><p>API: <strong><?php echo strtoupper($api_type); ?></strong></p>
                <p>Business: <?php echo jetstar_format_business_type($business); ?></p>
            <?php endif; ?>
        </td></tr><?php endif; ?>
    </table>
    <?php if ($is_api && $api_status !== 'existing'): ?>
    <h3>API Partner Details</h3>
    <table class="form-table">
        <?php
        $fields = array('organization_name'=>'Organization','address'=>'Address','city'=>'City','state'=>'State','postal_code'=>'Postal','country'=>'Country','operating_hours'=>'Hours','timezone'=>'Timezone','website'=>'Website','projected_segments'=>'Proj Segments','test_ip'=>'Test IP','prod_ip'=>'Prod IP','aggregator_name'=>'Aggregator','aggregator_contact'=>'Agg Contact','aggregator_email'=>'Agg Email','aggregator_phone'=>'Agg Phone','sponsor_name'=>'Sponsor','sponsor_email'=>'Sponsor Email','sponsor_phone'=>'Sponsor Phone','manager_name'=>'Manager','manager_position'=>'Mgr Position','manager_email'=>'Mgr Email','manager_phone'=>'Mgr Phone','tech_name'=>'Technical','tech_position'=>'Tech Position','tech_email'=>'Tech Email','tech_phone'=>'Tech Phone','api_spec_emails'=>'API Emails','outage_emails'=>'Outage Emails','referral_source'=>'Found Us','comments'=>'Comments');
        foreach ($fields as $key => $label) {
            $value = get_user_meta($user->ID, $key, true);
            if ($value) {
                if ($key === 'ancillary_products') {
                    $products = json_decode($value, true);
                    if ($products) echo '<tr><th>'.esc_html($label).'</th><td>'.implode(', ', array_map('esc_html', $products)).'</td></tr>';
                } else {
                    echo '<tr><th>'.esc_html($label).'</th><td>'.nl2br(esc_html($value)).'</td></tr>';
                }
            }
        }
        $ancillary = json_decode(get_user_meta($user->ID, 'ancillary_products', true), true);
        if ($ancillary) echo '<tr><th>Ancillary</th><td>'.implode(', ', array_map('esc_html', $ancillary)).'</td></tr>';
        ?>
    </table>
    <?php endif;
}

add_action('personal_options_update', 'jetstar_save_user_status');
add_action('edit_user_profile_update', 'jetstar_save_user_status');
function jetstar_save_user_status($user_id) {
    if (!current_user_can('edit_user', $user_id)) return;
    if (isset($_POST['validate_org_code'])) {
        update_user_meta($user_id, 'organization_code_validated', true);
        update_user_meta($user_id, 'requires_code_validation', false);
    }
    $old = get_user_meta($user_id, 'account_status', true);
    $new = isset($_POST['account_status']) ? sanitize_text_field($_POST['account_status']) : '';
    if ($new && $old !== $new) {
        update_user_meta($user_id, 'account_status', $new);
        $user = get_userdata($user_id);
        if ($new === 'active' && $old === 'pending') jetstar_send_approval_email($user);
        elseif ($new === 'rejected') jetstar_send_rejection_email($user);
    }
}

add_action('admin_menu', 'jetstar_add_pending_menu');
function jetstar_add_pending_menu() {
    add_users_page('Pending Registrations','Pending Registrations','edit_users','pending-registrations','jetstar_pending_page');
}

function jetstar_pending_page() {
    $users = get_users(array('meta_key'=>'account_status','meta_value'=>'pending','orderby'=>'registered','order'=>'DESC'));
    ?>
    <div class="wrap">
        <h1>Pending Registrations (<?php echo count($users); ?>)</h1>
        <?php if (empty($users)): ?><p>No pending registrations.</p><?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Name</th><th>Email</th><th>Company</th><th>API Partner</th><th>App ID</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($users as $u): 
                        $company = get_user_meta($u->ID, 'company_name', true);
                        $is_api = get_user_meta($u->ID, 'is_api_partner', true);
                        $date = get_user_meta($u->ID, 'registration_date', true);
                        $app_id = get_user_meta($u->ID, 'application_id', true);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($u->display_name); ?></strong></td>
                            <td><?php echo esc_html($u->user_email); ?></td>
                            <td><?php echo esc_html($company); ?></td>
                            <td><?php echo $is_api ? '✅' : '—'; ?></td>
                            <td><?php echo $app_id ? '<code style="font-size:11px">'.esc_html($app_id).'</code>' : '—'; ?></td>
                            <td><?php echo $date ? date('M j, g:i a', strtotime($date)) : '-'; ?></td>
                            <td><a href="<?php echo admin_url("user-edit.php?user_id={$u->ID}"); ?>" class="button button-primary">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

add_action('admin_menu', 'jetstar_add_pending_badge');
function jetstar_add_pending_badge() {
    global $menu;
    $count = count(get_users(array('meta_key'=>'account_status','meta_value'=>'pending')));
    if ($count > 0) {
        foreach ($menu as $key => $value) {
            if ($menu[$key][2] == 'users.php') {
                $menu[$key][0] .= ' <span class="awaiting-mod count-'.$count.'"><span class="pending-count">'.$count.'</span></span>';
                break;
            }
        }
    }
}

add_filter('manage_users_columns', 'jetstar_add_user_columns');
function jetstar_add_user_columns($columns) {
    $new_columns = array();
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        if ($key === 'email') {
            $new_columns['company'] = 'Company';
            $new_columns['phone'] = 'Phone';
            $new_columns['account_status'] = 'Status';
            $new_columns['api_partner'] = 'API';
            $new_columns['application_id'] = 'App ID';
            $new_columns['registration_date'] = 'Registered';
        }
    }
    return $new_columns;
}

add_filter('manage_users_custom_column', 'jetstar_populate_user_columns', 10, 3);
function jetstar_populate_user_columns($value, $column_name, $user_id) {
    switch ($column_name) {
        case 'company': $company = get_user_meta($user_id, 'company_name', true); return $company ? esc_html($company) : '—';
        case 'phone': $phone = get_user_meta($user_id, 'phone_number', true); return $phone ? esc_html($phone) : '—';
        case 'application_id':
            $app_id = get_user_meta($user_id, 'application_id', true);
            return $app_id ? '<code style="font-size:11px;background:#fff7ed;padding:3px 6px;border:1px solid #FF6600;color:#FF6600">'.esc_html($app_id).'</code>' : '—';
        case 'account_status':
            $status = get_user_meta($user_id, 'account_status', true);
            if (!$status) $status = 'active';
            $colors = array('pending'=>'#f59e0b','active'=>'#10b981','suspended'=>'#ef4444','rejected'=>'#6b7280');
            $icons = array('pending'=>'⏳','active'=>'✅','suspended'=>'🚫','rejected'=>'❌');
            $color = isset($colors[$status]) ? $colors[$status] : '#6b7280';
            $icon = isset($icons[$status]) ? $icons[$status] : '';
            return '<span style="color:'.$color.';font-weight:600">'.$icon.' '.ucfirst($status).'</span>';
        case 'api_partner':
            $is_api = get_user_meta($user_id, 'is_api_partner', true);
            if ($is_api) {
                $api_status = get_user_meta($user_id, 'api_partner_status', true);
                $api_type = get_user_meta($user_id, 'api_access_type', true);
                if ($api_status === 'existing') {
                    $org_code = get_user_meta($user_id, 'organization_code', true);
                    $validated = get_user_meta($user_id, 'organization_code_validated', true);
                    $icon = $validated ? '✅' : '⚠️';
                    return '<span style="color:#10b981;font-weight:600">'.$icon.' Existing</span><br><small>'.esc_html($org_code).'</small>';
                } else {
                    return '<span style="color:#10b981;font-weight:600">✅ '.strtoupper($api_type).'</span>';
                }
            }
            return '<span style="color:#6b7280">—</span>';
        case 'registration_date':
            $date = get_user_meta($user_id, 'registration_date', true);
            if ($date) return date('M j, Y', strtotime($date));
            return '—';
        default: return $value;
    }
}

add_filter('manage_users_sortable_columns', 'jetstar_sortable_user_columns');
function jetstar_sortable_user_columns($columns) {
    $columns['account_status'] = 'account_status';
    $columns['registration_date'] = 'registration_date';
    $columns['company'] = 'company';
    $columns['application_id'] = 'application_id';
    return $columns;
}

add_action('pre_get_users', 'jetstar_sort_user_columns');
function jetstar_sort_user_columns($query) {
    if (!is_admin()) return;
    $orderby = $query->get('orderby');
    if ($orderby === 'account_status') { $query->set('meta_key', 'account_status'); $query->set('orderby', 'meta_value'); }
    if ($orderby === 'registration_date') { $query->set('meta_key', 'registration_date'); $query->set('orderby', 'meta_value'); }
    if ($orderby === 'company') { $query->set('meta_key', 'company_name'); $query->set('orderby', 'meta_value'); }
    if ($orderby === 'application_id') { $query->set('meta_key', 'application_id'); $query->set('orderby', 'meta_value'); }
}

add_action('restrict_manage_users', 'jetstar_add_status_filter');
function jetstar_add_status_filter() {
    $status = isset($_GET['account_status_filter']) ? $_GET['account_status_filter'] : '';
    ?>
    <select name="account_status_filter" style="float:none;margin:0 5px 0 0">
        <option value="">All Statuses</option>
        <option value="pending" <?php selected($status, 'pending'); ?>>⏳ Pending</option>
        <option value="active" <?php selected($status, 'active'); ?>>✅ Active</option>
        <option value="suspended" <?php selected($status, 'suspended'); ?>>🚫 Suspended</option>
        <option value="rejected" <?php selected($status, 'rejected'); ?>>❌ Rejected</option>
    </select>
    <?php
}

add_filter('pre_get_users', 'jetstar_filter_users_by_status');
function jetstar_filter_users_by_status($query) {
    global $pagenow;
    if (is_admin() && $pagenow === 'users.php' && isset($_GET['account_status_filter']) && $_GET['account_status_filter'] != '') {
        $status = $_GET['account_status_filter'];
        $meta_query = $query->get('meta_query') ?: array();
        $meta_query[] = array('key'=>'account_status','value'=>$status,'compare'=>'=');
        $query->set('meta_query', $meta_query);
    }
}

add_action('restrict_manage_users', 'jetstar_add_api_partner_filter');
function jetstar_add_api_partner_filter() {
    $api_filter = isset($_GET['api_partner_filter']) ? $_GET['api_partner_filter'] : '';
    ?>
    <select name="api_partner_filter" style="float:none;margin:0 5px 0 0">
        <option value="">All Users</option>
        <option value="yes" <?php selected($api_filter, 'yes'); ?>>✅ API Partners</option>
        <option value="no" <?php selected($api_filter, 'no'); ?>>Regular Users</option>
        <option value="existing" <?php selected($api_filter, 'existing'); ?>>Existing</option>
        <option value="new" <?php selected($api_filter, 'new'); ?>>New</option>
    </select>
    <?php
}

add_filter('pre_get_users', 'jetstar_filter_users_by_api_partner');
function jetstar_filter_users_by_api_partner($query) {
    global $pagenow;
    if (is_admin() && $pagenow === 'users.php' && isset($_GET['api_partner_filter']) && $_GET['api_partner_filter'] != '') {
        $filter = $_GET['api_partner_filter'];
        $meta_query = $query->get('meta_query') ?: array();
        if ($filter === 'yes') $meta_query[] = array('key'=>'is_api_partner','value'=>true,'compare'=>'=');
        elseif ($filter === 'no') $meta_query[] = array('key'=>'is_api_partner','compare'=>'NOT EXISTS');
        elseif ($filter === 'existing' || $filter === 'new') $meta_query[] = array('key'=>'api_partner_status','value'=>$filter,'compare'=>'=');
        $query->set('meta_query', $meta_query);
    }
}

add_filter('user_row_actions', 'jetstar_add_user_quick_actions', 10, 2);
function jetstar_add_user_quick_actions($actions, $user) {
    $status = get_user_meta($user->ID, 'account_status', true);
    if ($status === 'pending') {
        $approve_url = wp_nonce_url(add_query_arg(array('action'=>'jetstar_quick_approve','user_id'=>$user->ID), admin_url('users.php')), 'jetstar_quick_approve_'.$user->ID);
        $actions['approve'] = '<a href="'.esc_url($approve_url).'" style="color:#10b981;font-weight:600">✅ Approve</a>';
        $reject_url = wp_nonce_url(add_query_arg(array('action'=>'jetstar_quick_reject','user_id'=>$user->ID), admin_url('users.php')), 'jetstar_quick_reject_'.$user->ID);
        $actions['reject'] = '<a href="'.esc_url($reject_url).'" style="color:#ef4444;font-weight:600">❌ Reject</a>';
    }
    return $actions;
}

add_action('admin_init', 'jetstar_handle_quick_approve');
function jetstar_handle_quick_approve() {
    if (isset($_GET['action']) && $_GET['action'] === 'jetstar_quick_approve' && isset($_GET['user_id'])) {
        $user_id = intval($_GET['user_id']);
        if (!current_user_can('edit_users')) wp_die('Permission denied.');
        if (!wp_verify_nonce($_GET['_wpnonce'], 'jetstar_quick_approve_'.$user_id)) wp_die('Security check failed.');
        update_user_meta($user_id, 'account_status', 'active');
        $user = get_userdata($user_id);
        jetstar_send_approval_email($user);
        wp_redirect(add_query_arg('approved', '1', admin_url('users.php')));
        exit;
    }
}

add_action('admin_init', 'jetstar_handle_quick_reject');
function jetstar_handle_quick_reject() {
    if (isset($_GET['action']) && $_GET['action'] === 'jetstar_quick_reject' && isset($_GET['user_id'])) {
        $user_id = intval($_GET['user_id']);
        if (!current_user_can('edit_users')) wp_die('Permission denied.');
        if (!wp_verify_nonce($_GET['_wpnonce'], 'jetstar_quick_reject_'.$user_id)) wp_die('Security check failed.');
        update_user_meta($user_id, 'account_status', 'rejected');
        $user = get_userdata($user_id);
        jetstar_send_rejection_email($user);
        wp_redirect(add_query_arg('rejected', '1', admin_url('users.php')));
        exit;
    }
}

add_action('admin_notices', 'jetstar_show_quick_action_notice');
function jetstar_show_quick_action_notice() {
    if (isset($_GET['approved'])) echo '<div class="notice notice-success is-dismissible"><p><strong>✅ User approved!</strong></p></div>';
    if (isset($_GET['rejected'])) echo '<div class="notice notice-success is-dismissible"><p><strong>❌ User rejected.</strong></p></div>';
}

function jetstar_query_users_by_meta($meta_criteria, $orderby = 'registered', $order = 'DESC') {
    $meta_query = array('relation' => 'AND');
    foreach ($meta_criteria as $key => $value) {
        $meta_query[] = array('key' => $key, 'value' => $value, 'compare' => '=');
    }
    $args = array('meta_query' => $meta_query, 'orderby' => $orderby, 'order' => $order);
    return get_users($args);
}

add_shortcode('jetstar_api_stats', 'jetstar_api_stats_shortcode');
function jetstar_api_stats_shortcode() {
    $total_partners = count(get_users(array('meta_key' => 'is_api_partner', 'meta_value' => true)));
    $ndc_partners = count(jetstar_query_users_by_meta(array('api_access_type' => 'ndc', 'account_status' => 'active')));
    $pending = count(get_users(array('meta_key' => 'account_status', 'meta_value' => 'pending')));
    ob_start();
    ?>
    <div class="jetstar-api-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;padding:20px">
        <div style="background:#f8f9fa;padding:20px;border-radius:8px;text-align:center">
            <h3 style="margin:0;color:#FF6600;font-size:36px"><?php echo $total_partners; ?></h3>
            <p style="margin:10px 0 0 0;color:#666">Total API Partners</p>
        </div>
        <div style="background:#f8f9fa;padding:20px;border-radius:8px;text-align:center">
            <h3 style="margin:0;color:#10b981;font-size:36px"><?php echo $ndc_partners; ?></h3>
            <p style="margin:10px 0 0 0;color:#666">Active NDC Partners</p>
        </div>
        <div style="background:#f8f9fa;padding:20px;border-radius:8px;text-align:center">
            <h3 style="margin:0;color:#f59e0b;font-size:36px"><?php echo $pending; ?></h3>
            <p style="margin:10px 0 0 0;color:#666">Pending Approvals</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}register_activation_hook(__FILE__, 'jetstar_plugin_activate');
function jetstar_plugin_activate() {
    $users = get_users(array('fields' => 'ID'));
    foreach ($users as $user_id) {
        $status = get_user_meta($user_id, 'account_status', true);
        if (!$status) {
            update_user_meta($user_id, 'account_status', 'active');
        }
    }
    jetstar_setup_reminder_cron();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'jetstar_plugin_deactivate');
function jetstar_plugin_deactivate() {
    wp_clear_scheduled_hook('jetstar_send_pending_reminders');
    flush_rewrite_rules();
}

add_action('wp_dashboard_setup', 'jetstar_add_dashboard_widget');
function jetstar_add_dashboard_widget() {
    wp_add_dashboard_widget('jetstar_pending_widget', '⏳ Pending Registrations', 'jetstar_dashboard_widget_display');
}

function jetstar_dashboard_widget_display() {
    $pending_users = get_users(array('meta_key' => 'account_status', 'meta_value' => 'pending', 'number' => 5, 'orderby' => 'registered', 'order' => 'DESC'));
    $total_pending = count(get_users(array('meta_key' => 'account_status', 'meta_value' => 'pending')));
    if (empty($pending_users)) {
        echo '<p>No pending registrations.</p>';
        return;
    }
    echo '<p><strong>' . $total_pending . ' user(s)</strong> awaiting approval</p>';
    echo '<ul>';
    foreach ($pending_users as $user) {
        $is_api = get_user_meta($user->ID, 'is_api_partner', true);
        $app_id = get_user_meta($user->ID, 'application_id', true);
        $badge = $is_api ? ' <span style="background:#10b981;color:white;padding:2px 6px;border-radius:3px;font-size:11px">API</span>' : '';
        echo '<li><strong>' . esc_html($user->display_name) . '</strong>' . $badge;
        if ($app_id) echo ' <code style="font-size:10px;background:#fff7ed;padding:2px 4px;margin-left:5px">' . esc_html($app_id) . '</code>';
        echo ' - <a href="' . admin_url("user-edit.php?user_id={$user->ID}") . '">Review</a></li>';
    }
    echo '</ul>';
    echo '<p><a href="' . admin_url('users.php?page=pending-registrations') . '" class="button button-primary">View All Pending</a></p>';
}

/**
 * ========================================
 * SETTINGS PAGE
 * ========================================
 */

add_action('admin_menu', 'jetstar_add_settings_menu');
function jetstar_add_settings_menu() {
    add_options_page(
        'Jetstar Registration Settings',
        'Jetstar Registration',
        'manage_options',
        'jetstar-registration-settings',
        'jetstar_settings_page'
    );
}

add_action('admin_init', 'jetstar_register_settings');
function jetstar_register_settings() {
    register_setting('jetstar_settings_group', 'jetstar_smtp_host');
    register_setting('jetstar_settings_group', 'jetstar_smtp_port');
    register_setting('jetstar_settings_group', 'jetstar_smtp_secure');
    register_setting('jetstar_settings_group', 'jetstar_smtp_username');
    register_setting('jetstar_settings_group', 'jetstar_smtp_password');
    register_setting('jetstar_settings_group', 'jetstar_from_email');
    register_setting('jetstar_settings_group', 'jetstar_from_name');
    register_setting('jetstar_settings_group', 'jetstar_admin_email');
    register_setting('jetstar_settings_group', 'jetstar_debug_mode');
    register_setting('jetstar_settings_group', 'jetstar_email_header_color');
    register_setting('jetstar_settings_group', 'jetstar_button_color');
    register_setting('jetstar_settings_group', 'jetstar_approval_days');
    register_setting('jetstar_settings_group', 'jetstar_success_page');
    register_setting('jetstar_settings_group', 'jetstar_login_page');
    register_setting('jetstar_settings_group', 'jetstar_send_user_confirmation');
    register_setting('jetstar_settings_group', 'jetstar_send_admin_notification');
    register_setting('jetstar_settings_group', 'jetstar_cc_emails');
    register_setting('jetstar_settings_group', 'jetstar_bcc_emails');
    register_setting('jetstar_settings_group', 'jetstar_app_id_prefix');
    register_setting('jetstar_settings_group', 'jetstar_app_id_format');
    register_setting('jetstar_settings_group', 'jetstar_min_password_length');
    register_setting('jetstar_settings_group', 'jetstar_logo_url');
}

function jetstar_settings_page() {
    $test_result = '';
    if (isset($_POST['send_test_email']) && check_admin_referer('jetstar_test_email')) {
        $test_email = sanitize_email($_POST['test_email']);
        if ($test_email) {
            $subject = 'Test Email - Jetstar Registration System';
            $message = jetstar_get_email_header() . '<div class="email-body"><h2>Test Email</h2><p>If you receive this, your SMTP settings are working correctly!</p></div>' . jetstar_get_email_footer();
            add_filter('wp_mail_content_type', function() { return 'text/html'; });
            $sent = wp_mail($test_email, $subject, $message);
            remove_filter('wp_mail_content_type', function() { return 'text/html'; });
            $test_result = $sent ? 'success' : 'error';
        }
    }
    
    $smtp_host = get_option('jetstar_smtp_host', 'smtp.gmail.com');
    $smtp_port = get_option('jetstar_smtp_port', '587');
    $smtp_secure = get_option('jetstar_smtp_secure', 'tls');
    $smtp_username = get_option('jetstar_smtp_username', '');
    $smtp_password = get_option('jetstar_smtp_password', '');
    $from_email = get_option('jetstar_from_email', 'apisupportblog@jetstar.com');
    $from_name = get_option('jetstar_from_name', 'Jetstar API Blog');
    $admin_email = get_option('jetstar_admin_email', 'apisupportblog@jetstar.com');
    $debug_mode = get_option('jetstar_debug_mode', '0');
    $header_color = get_option('jetstar_email_header_color', '#FF6600');
    $button_color = get_option('jetstar_button_color', '#FF6600');
    $approval_days = get_option('jetstar_approval_days', '3');
    $success_page = get_option('jetstar_success_page', home_url('/registration-success'));
    $login_page = get_option('jetstar_login_page', home_url('/login'));
    $send_user_confirm = get_option('jetstar_send_user_confirmation', '1');
    $send_admin_notif = get_option('jetstar_send_admin_notification', '1');
    $cc_emails = get_option('jetstar_cc_emails', '');
    $bcc_emails = get_option('jetstar_bcc_emails', '');
    $app_id_prefix = get_option('jetstar_app_id_prefix', 'JQ-APP-');
    $app_id_format = get_option('jetstar_app_id_format', 'timestamp');
    $min_password = get_option('jetstar_min_password_length', '8');
    
    ?>
    <div class="wrap">
        <h1>🛠️ Jetstar Registration Settings</h1>
        
        <?php if ($test_result === 'success'): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>✅ Test email sent successfully!</strong> Check your inbox.</p>
            </div>
        <?php elseif ($test_result === 'error'): ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>❌ Test email failed.</strong> Please check your SMTP settings.</p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="options.php">
            <?php settings_fields('jetstar_settings_group'); ?>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2 style="margin-top:0">📧 SMTP Email Settings</h2>
                <p style="color:#666">Configure your email server settings.</p>
                
                <table class="form-table">
                    <tr>
                        <th><label for="jetstar_smtp_host">SMTP Host</label></th>
                        <td>
                            <input type="text" id="jetstar_smtp_host" name="jetstar_smtp_host" value="<?php echo esc_attr($smtp_host); ?>" class="regular-text">
                            <p class="description">Example: smtp.gmail.com, smtp.office365.com</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_smtp_port">SMTP Port</label></th>
                        <td>
                            <input type="number" id="jetstar_smtp_port" name="jetstar_smtp_port" value="<?php echo esc_attr($smtp_port); ?>" class="small-text">
                            <p class="description">Common: 587 (TLS), 465 (SSL)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_smtp_secure">Encryption</label></th>
                        <td>
                            <select id="jetstar_smtp_secure" name="jetstar_smtp_secure">
                                <option value="tls" <?php selected($smtp_secure, 'tls'); ?>>TLS</option>
                                <option value="ssl" <?php selected($smtp_secure, 'ssl'); ?>>SSL</option>
                                <option value="" <?php selected($smtp_secure, ''); ?>>None</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_smtp_username">Username</label></th>
                        <td>
                            <input type="text" id="jetstar_smtp_username" name="jetstar_smtp_username" value="<?php echo esc_attr($smtp_username); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_smtp_password">Password</label></th>
                        <td>
                            <input type="password" id="jetstar_smtp_password" name="jetstar_smtp_password" value="<?php echo esc_attr($smtp_password); ?>" class="regular-text">
                            <p class="description">For Gmail, use <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="jetstar_debug_mode" value="1" <?php checked($debug_mode, '1'); ?>>
                                Enable SMTP debugging
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2 style="margin-top:0">✉️ Email Configuration</h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="jetstar_from_email">From Email</label></th>
                        <td>
                            <input type="email" id="jetstar_from_email" name="jetstar_from_email" value="<?php echo esc_attr($from_email); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_from_name">From Name</label></th>
                        <td>
                            <input type="text" id="jetstar_from_name" name="jetstar_from_name" value="<?php echo esc_attr($from_name); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_admin_email">Admin Email</label></th>
                        <td>
                            <input type="email" id="jetstar_admin_email" name="jetstar_admin_email" value="<?php echo esc_attr($admin_email); ?>" class="regular-text">
                            <p class="description">Receives new registration notifications</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_approval_days">Approval Timeframe</label></th>
                        <td>
                            <input type="number" id="jetstar_approval_days" name="jetstar_approval_days" value="<?php echo esc_attr($approval_days); ?>" class="small-text"> business days
                        </td>
                    </tr>
                    <tr>
                        <th>Send User Confirmation</th>
                        <td>
                            <label>
                                <input type="checkbox" name="jetstar_send_user_confirmation" value="1" <?php checked($send_user_confirm, '1'); ?>>
                                Send confirmation email to users
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Send Admin Notification</th>
                        <td>
                            <label>
                                <input type="checkbox" name="jetstar_send_admin_notification" value="1" <?php checked($send_admin_notif, '1'); ?>>
                                Send notification to admin
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_cc_emails">CC Emails</label></th>
                        <td>
                            <textarea id="jetstar_cc_emails" name="jetstar_cc_emails" rows="3" class="large-text"><?php echo esc_textarea($cc_emails); ?></textarea>
                            <p class="description">One per line</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_bcc_emails">BCC Emails</label></th>
                        <td>
                            <textarea id="jetstar_bcc_emails" name="jetstar_bcc_emails" rows="3" class="large-text"><?php echo esc_textarea($bcc_emails); ?></textarea>
                            <p class="description">One per line</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2 style="margin-top:0">🎨 Email Design</h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="jetstar_email_header_color">Header Color</label></th>
                        <td>
                            <input type="color" id="jetstar_email_header_color" name="jetstar_email_header_color" value="<?php echo esc_attr($header_color); ?>">
                            <input type="text" value="<?php echo esc_attr($header_color); ?>" class="regular-text" readonly>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_button_color">Button Color</label></th>
                        <td>
                            <input type="color" id="jetstar_button_color" name="jetstar_button_color" value="<?php echo esc_attr($button_color); ?>">
                            <input type="text" value="<?php echo esc_attr($button_color); ?>" class="regular-text" readonly>
                        </td>
                    </tr>
                </table>
            </div>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2 style="margin-top:0">🎨 Branding</h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="jetstar_logo_url">Logo Image</label></th>
                        <td>
                            <?php
                            $logo_url = get_option('jetstar_logo_url', '');
                            ?>
                            <div class="logo-preview-wrapper">
                                <?php if ($logo_url): ?>
                                    <img src="<?php echo esc_url($logo_url); ?>" id="logo-preview" style="max-width:300px;height:auto;display:block;margin-bottom:10px;border:1px solid #ddd;padding:10px;background:#fff;">
                                <?php else: ?>
                                    <img src="" id="logo-preview" style="max-width:300px;height:auto;display:none;margin-bottom:10px;border:1px solid #ddd;padding:10px;background:#fff;">
                                <?php endif; ?>
                            </div>
                            <input type="hidden" id="jetstar_logo_url" name="jetstar_logo_url" value="<?php echo esc_attr($logo_url); ?>">
                            <button type="button" class="button" id="upload_logo_button">
                                <?php echo $logo_url ? 'Change Logo' : 'Upload Logo'; ?>
                            </button>
                            <?php if ($logo_url): ?>
                                <button type="button" class="button" id="remove_logo_button" style="margin-left:5px">Remove Logo</button>
                            <?php endif; ?>
                            <p class="description">Recommended: PNG or JPG, minimum 200px wide, transparent background preferred</p>
                        </td>
                    </tr>
                </table>
            </div>

            <script>
            jQuery(document).ready(function($) {
                var mediaUploader;
                
                $('#upload_logo_button').on('click', function(e) {
                    e.preventDefault();
                    
                    if (mediaUploader) {
                        mediaUploader.open();
                        return;
                    }
                    
                    mediaUploader = wp.media({
                        title: 'Choose Jetstar Logo',
                        button: {
                            text: 'Use this image'
                        },
                        multiple: false,
                        library: {
                            type: 'image'
                        }
                    });
                    
                    mediaUploader.on('select', function() {
                        var attachment = mediaUploader.state().get('selection').first().toJSON();
                        $('#jetstar_logo_url').val(attachment.url);
                        $('#logo-preview').attr('src', attachment.url).show();
                        $('#upload_logo_button').text('Change Logo');
                        if ($('#remove_logo_button').length === 0) {
                            $('#upload_logo_button').after('<button type="button" class="button" id="remove_logo_button" style="margin-left:5px">Remove Logo</button>');
                        }
                        $('#remove_logo_button').show();
                    });
                    
                    mediaUploader.open();
                });
                
                $(document).on('click', '#remove_logo_button', function(e) {
                    e.preventDefault();
                    $('#jetstar_logo_url').val('');
                    $('#logo-preview').attr('src', '').hide();
                    $('#upload_logo_button').text('Upload Logo');
                    $(this).remove();
                });
            });
            </script>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2 style="margin-top:0">🔗 Page URLs</h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="jetstar_success_page">Success Page</label></th>
                        <td>
                            <input type="url" id="jetstar_success_page" name="jetstar_success_page" value="<?php echo esc_attr($success_page); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_login_page">Login Page</label></th>
                        <td>
                            <input type="url" id="jetstar_login_page" name="jetstar_login_page" value="<?php echo esc_attr($login_page); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2 style="margin-top:0">🆔 Application ID</h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="jetstar_app_id_prefix">ID Prefix</label></th>
                        <td>
                            <input type="text" id="jetstar_app_id_prefix" name="jetstar_app_id_prefix" value="<?php echo esc_attr($app_id_prefix); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="jetstar_app_id_format">ID Format</label></th>
                        <td>
                            <select id="jetstar_app_id_format" name="jetstar_app_id_format">
                                <option value="timestamp" <?php selected($app_id_format, 'timestamp'); ?>>Timestamp</option>
                                <option value="sequential" <?php selected($app_id_format, 'sequential'); ?>>Sequential</option>
                                <option value="random" <?php selected($app_id_format, 'random'); ?>>Random</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2 style="margin-top:0">🔒 Validation</h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="jetstar_min_password_length">Min Password Length</label></th>
                        <td>
                            <input type="number" id="jetstar_min_password_length" name="jetstar_min_password_length" value="<?php echo esc_attr($min_password); ?>" class="small-text" min="6" max="32"> characters
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php submit_button('💾 Save Settings'); ?>
        </form>
        
        <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
            <h2 style="margin-top:0">📨 Test Email</h2>
            <form method="post" action="">
                <?php wp_nonce_field('jetstar_test_email'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="test_email">Test Email</label></th>
                        <td>
                            <input type="email" id="test_email" name="test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text" required>
                            <input type="submit" name="send_test_email" class="button button-secondary" value="📧 Send Test">
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        
        <p style="text-align:center;margin-top:30px">
            <a href="<?php echo admin_url('options-general.php?page=jetstar-email-templates'); ?>" class="button button-secondary">✏️ Edit Email Templates</a>
        </p>
    </div>
    
    <style>
        .form-table input[type="color"] {
            width: 60px;
            height: 40px;
            border: none;
            cursor: pointer;
            margin-right: 10px;
        }
    </style>
    <?php
}

/**
 * ========================================
 * EMAIL TEMPLATES EDITOR
 * ========================================
 */

add_action('admin_init', 'jetstar_register_email_templates');
function jetstar_register_email_templates() {
    register_setting('jetstar_email_templates', 'jetstar_email_user_confirmation_subject');
    register_setting('jetstar_email_templates', 'jetstar_email_user_confirmation_body');
    register_setting('jetstar_email_templates', 'jetstar_email_admin_notification_subject');
    register_setting('jetstar_email_templates', 'jetstar_email_admin_notification_body');
    register_setting('jetstar_email_templates', 'jetstar_email_approval_subject');
    register_setting('jetstar_email_templates', 'jetstar_email_approval_body');
    register_setting('jetstar_email_templates', 'jetstar_email_rejection_subject');
    register_setting('jetstar_email_templates', 'jetstar_email_rejection_body');
    register_setting('jetstar_email_templates', 'jetstar_email_welcome_enabled');
    register_setting('jetstar_email_templates', 'jetstar_email_welcome_subject');
    register_setting('jetstar_email_templates', 'jetstar_email_welcome_body');
    register_setting('jetstar_email_templates', 'jetstar_email_reminder_enabled');
    register_setting('jetstar_email_templates', 'jetstar_email_reminder_days');
    register_setting('jetstar_email_templates', 'jetstar_email_reminder_subject');
    register_setting('jetstar_email_templates', 'jetstar_email_reminder_body');
}

add_action('admin_menu', 'jetstar_add_email_templates_menu');
function jetstar_add_email_templates_menu() {
    add_submenu_page(
        'options-general.php',
        'Email Templates',
        'Email Templates',
        'manage_options',
        'jetstar-email-templates',
        'jetstar_email_templates_page'
    );
}

function jetstar_email_templates_page() {
    if (isset($_POST['jetstar_save_templates']) && check_admin_referer('jetstar_email_templates')) {
        update_option('jetstar_email_user_confirmation_subject', sanitize_text_field($_POST['user_confirmation_subject']));
        update_option('jetstar_email_user_confirmation_body', wp_kses_post($_POST['user_confirmation_body']));
        update_option('jetstar_email_admin_notification_subject', sanitize_text_field($_POST['admin_notification_subject']));
        update_option('jetstar_email_admin_notification_body', wp_kses_post($_POST['admin_notification_body']));
        update_option('jetstar_email_approval_subject', sanitize_text_field($_POST['approval_subject']));
        update_option('jetstar_email_approval_body', wp_kses_post($_POST['approval_body']));
        update_option('jetstar_email_rejection_subject', sanitize_text_field($_POST['rejection_subject']));
        update_option('jetstar_email_rejection_body', wp_kses_post($_POST['rejection_body']));
        update_option('jetstar_email_welcome_enabled', isset($_POST['welcome_enabled']) ? '1' : '0');
        update_option('jetstar_email_welcome_subject', sanitize_text_field($_POST['welcome_subject']));
        update_option('jetstar_email_welcome_body', wp_kses_post($_POST['welcome_body']));
        update_option('jetstar_email_reminder_enabled', isset($_POST['reminder_enabled']) ? '1' : '0');
        update_option('jetstar_email_reminder_days', intval($_POST['reminder_days']));
        update_option('jetstar_email_reminder_subject', sanitize_text_field($_POST['reminder_subject']));
        update_option('jetstar_email_reminder_body', wp_kses_post($_POST['reminder_body']));
        echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Email templates saved!</strong></p></div>';
    }
    
    $user_confirm_subject = get_option('jetstar_email_user_confirmation_subject', 'Registration Received - {{site_name}}');
    $user_confirm_body = get_option('jetstar_email_user_confirmation_body', jetstar_get_default_template('user_confirmation'));
    $admin_notif_subject = get_option('jetstar_email_admin_notification_subject', '🔔 New Registration - {{user_name}}');
    $admin_notif_body = get_option('jetstar_email_admin_notification_body', jetstar_get_default_template('admin_notification'));
    $approval_subject = get_option('jetstar_email_approval_subject', '✅ Account Approved - {{site_name}}');
    $approval_body = get_option('jetstar_email_approval_body', jetstar_get_default_template('approval'));
    $rejection_subject = get_option('jetstar_email_rejection_subject', 'Registration Status Update');
    $rejection_body = get_option('jetstar_email_rejection_body', jetstar_get_default_template('rejection'));
    $welcome_enabled = get_option('jetstar_email_welcome_enabled', '0');
    $welcome_subject = get_option('jetstar_email_welcome_subject', '🎉 Welcome to {{site_name}}!');
    $welcome_body = get_option('jetstar_email_welcome_body', jetstar_get_default_template('welcome'));
    $reminder_enabled = get_option('jetstar_email_reminder_enabled', '0');
    $reminder_days = get_option('jetstar_email_reminder_days', '3');
    $reminder_subject = get_option('jetstar_email_reminder_subject', '⏰ Pending Registration Reminder');
    $reminder_body = get_option('jetstar_email_reminder_body', jetstar_get_default_template('reminder'));
    
    ?>
    <div class="wrap">
        <h1>📧 Email Templates</h1>
        <p>Customize email templates with merge tags.</p>
        
        <div style="background:#e7f3ff;border-left:4px solid #2196f3;padding:15px;margin:20px 0">
            <h3 style="margin-top:0">📝 Merge Tags</h3>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;font-family:monospace;font-size:12px">
                <div><strong>{{user_name}}</strong></div>
                <div><strong>{{first_name}}</strong></div>
                <div><strong>{{username}}</strong></div>
                <div><strong>{{email}}</strong></div>
                <div><strong>{{company}}</strong></div>
                <div><strong>{{phone}}</strong></div>
                <div><strong>{{application_id}}</strong></div>
                <div><strong>{{site_name}}</strong></div>
                <div><strong>{{login_url}}</strong></div>
                <div><strong>{{approval_days}}</strong></div>
                <div><strong>{{registration_date}}</strong></div>
                <div><strong>{{api_type}}</strong></div>
            </div>
        </div>
        
        <form method="post" action="">
            <?php wp_nonce_field('jetstar_email_templates'); ?>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2>1️⃣ User Confirmation Email</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Subject</label></th>
                        <td><input type="text" name="user_confirmation_subject" value="<?php echo esc_attr($user_confirm_subject); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label>Body</label></th>
                        <td><?php wp_editor($user_confirm_body, 'user_confirmation_body', array('textarea_rows' => 10, 'media_buttons' => false)); ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2>2️⃣ Admin Notification Email</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Subject</label></th>
                        <td><input type="text" name="admin_notification_subject" value="<?php echo esc_attr($admin_notif_subject); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label>Body</label></th>
                        <td><?php wp_editor($admin_notif_body, 'admin_notification_body', array('textarea_rows' => 10, 'media_buttons' => false)); ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2>3️⃣ Approval Email</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Subject</label></th>
                        <td><input type="text" name="approval_subject" value="<?php echo esc_attr($approval_subject); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label>Body</label></th>
                        <td><?php wp_editor($approval_body, 'approval_body', array('textarea_rows' => 10, 'media_buttons' => false)); ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px">
                <h2>4️⃣ Rejection Email</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Subject</label></th>
                        <td><input type="text" name="rejection_subject" value="<?php echo esc_attr($rejection_subject); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label>Body</label></th>
                        <td><?php wp_editor($rejection_body, 'rejection_body', array('textarea_rows' => 10, 'media_buttons' => false)); ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px;border-left:4px solid #10b981">
                <h2>5️⃣ Welcome Email 🆕</h2>
                <p style="color:#666">Sent after approval</p>
                <table class="form-table">
                    <tr>
                        <th>Enable</th>
                        <td><label><input type="checkbox" name="welcome_enabled" value="1" <?php checked($welcome_enabled, '1'); ?>> Send welcome email</label></td>
                    </tr>
                    <tr>
                        <th><label>Subject</label></th>
                        <td><input type="text" name="welcome_subject" value="<?php echo esc_attr($welcome_subject); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label>Body</label></th>
                        <td><?php wp_editor($welcome_body, 'welcome_body', array('textarea_rows' => 10, 'media_buttons' => false)); ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccc;border-radius:5px;border-left:4px solid #f59e0b">
                <h2>6️⃣ Reminder Email 🆕</h2>
                <p style="color:#666">Sent to admin for pending approvals</p>
                <table class="form-table">
                    <tr>
                        <th>Enable</th>
                        <td><label><input type="checkbox" name="reminder_enabled" value="1" <?php checked($reminder_enabled, '1'); ?>> Send reminders</label></td>
                    </tr>
                    <tr>
                        <th><label>Days</label></th>
                        <td><input type="number" name="reminder_days" value="<?php echo esc_attr($reminder_days); ?>" class="small-text" min="1" max="30"> days</td>
                    </tr>
                    <tr>
                        <th><label>Subject</label></th>
                        <td><input type="text" name="reminder_subject" value="<?php echo esc_attr($reminder_subject); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label>Body</label></th>
                        <td><?php wp_editor($reminder_body, 'reminder_body', array('textarea_rows' => 10, 'media_buttons' => false)); ?></td>
                    </tr>
                </table>
            </div>
            
            <input type="hidden" name="jetstar_save_templates" value="1">
            <?php submit_button('💾 Save All Templates'); ?>
        </form>
    </div>
    <?php
}

/**
 * ========================================
 * AJAX EMAIL AVAILABILITY CHECK
 * ========================================
 */
add_action('wp_ajax_nopriv_jetstar_check_email', 'jetstar_check_email_availability');
add_action('wp_ajax_jetstar_check_email', 'jetstar_check_email_availability');

function jetstar_check_email_availability() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jetstar_registration_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }
    
    $email = sanitize_email($_POST['email']);
    
    if (!is_email($email)) {
        wp_send_json_error(array('message' => 'Invalid email format.'));
    }
    
    if (email_exists($email)) {
        $user = get_user_by('email', $email);
        $status = get_user_meta($user->ID, 'account_status', true);
        
        if ($status === 'pending' || $status === 'rejected') {
            wp_send_json_success(array(
                'available' => true,
                'message' => 'This email was previously registered but not approved. You can register again.'
            ));
        } else {
            wp_send_json_error(array(
                'available' => false,
                'message' => 'This email is already registered.'
            ));
        }
    } else {
        wp_send_json_success(array(
            'available' => true,
            'message' => 'Email available'
        ));
    }
}

/**
 * ========================================
 * AJAX USERNAME AVAILABILITY CHECK
 * ========================================
 */
add_action('wp_ajax_nopriv_jetstar_check_username', 'jetstar_check_username_availability');
add_action('wp_ajax_jetstar_check_username', 'jetstar_check_username_availability');

function jetstar_check_username_availability() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jetstar_registration_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }
    
    $username = sanitize_user($_POST['username']);
    
    if (empty($username) || strlen($username) < 3) {
        wp_send_json_error(array('message' => 'Username must be at least 3 characters.'));
    }
    
    if (username_exists($username)) {
        wp_send_json_error(array(
            'available' => false,
            'message' => 'Username already taken.'
        ));
    } else {
        wp_send_json_success(array(
            'available' => true,
            'message' => 'Username available'
        ));
    }
}