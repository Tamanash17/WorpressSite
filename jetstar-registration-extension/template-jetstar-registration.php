<?php
/**
 * Template Name: Jetstar API Partner Registration
 * Description: Complete API Partner Registration Form
 */

if (!defined('ABSPATH')) exit;

$ajax_url = admin_url('admin-ajax.php');
$nonce = wp_create_nonce('jetstar_registration_nonce');
$plugin_url = plugin_dir_url(__FILE__);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Partner Registration - Jetstar</title>
    <link rel="stylesheet" href="<?php echo $plugin_url; ?>assets/css/registration.css?v=<?php echo time(); ?>">
</head>
<body class="jetstar-registration-page">

<div class="jetstar-registration-wrapper">
    <div class="jetstar-registration-container">
        
        <!-- Jetstar Header with Logo -->
        <div class="jetstar-header">
            <div class="header-content">
                <!-- Home/Back Button -->
                <a href="https://apiblog.jetstar.com" class="home-link">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="white">
                        <path d="M10 3l-8 7h2v7h5v-5h2v5h5v-7h2l-8-7z"/>
                    </svg>
                    Back to Home
                </a>
                
                <!-- Jetstar Logo & Title -->
                <div class="header-center">
    <?php
    // Get Jetstar logo from WordPress media library
    $jetstar_logo = get_option('jetstar_logo_url', '');
    if (empty($jetstar_logo)) {
        // Fallback: try to find logo in media library by name
        $logo_query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            's' => 'jetstar-logo',
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        if ($logo_query->have_posts()) {
            $logo_query->the_post();
            $jetstar_logo = wp_get_attachment_url(get_the_ID());
            wp_reset_postdata();
        }
    }
    if ($jetstar_logo): ?>
        <img src="<?php echo esc_url($jetstar_logo); ?>" alt="Jetstar" class="jetstar-brand-logo">
    <?php else: ?>
        <div class="jetstar-logo-text">✈️ Jetstar</div>
    <?php endif; ?>
    <h1 class="page-title">Partner Registration</h1>
</div>
                
                <!-- Spacer for alignment -->
                <div class="header-spacer"></div>
            </div>
        </div>

        <!-- Progress Tracker -->
        <div class="progress-tracker">
            <div class="progress-step active" data-step="1">
                <div class="step-circle">1</div>
                <span>Account</span>
            </div>
            <div class="progress-step" data-step="2">
                <div class="step-circle">2</div>
                <span>Status</span>
            </div>
            <div class="progress-step" data-step="3">
                <div class="step-circle">3</div>
                <span>API Type</span>
            </div>
            <div class="progress-step" data-step="4">
                <div class="step-circle">4</div>
                <span>Organization</span>
            </div>
            <div class="progress-step" data-step="5">
                <div class="step-circle">5</div>
                <span>Ancillary</span>
            </div>
            <div class="progress-step" data-step="6">
                <div class="step-circle">6</div>
                <span>Server</span>
            </div>
            <div class="progress-step" data-step="7">
                <div class="step-circle">7</div>
                <span>Aggregator</span>
            </div>
            <div class="progress-step" data-step="8">
                <div class="step-circle">8</div>
                <span>Contacts</span>
            </div>
            <div class="progress-step" data-step="9">
                <div class="step-circle">9</div>
                <span>Additional</span>
            </div>
            <div class="progress-step" data-step="10">
                <div class="step-circle">10</div>
                <span>Review</span>
            </div>
        </div>

        <!-- Form Content -->
        <div class="form-content">
            
            <!-- Messages -->
            <div id="jetstar-message" class="alert-message" style="display:none;"></div>

            <!-- Registration Form -->
            <form id="jetstar-registration-form" autocomplete="off">
                
                <!-- ========================================= -->
                <!-- STEP 1: ACCOUNT INFORMATION -->
                <!-- ========================================= -->
                <div class="form-step step-1 active">
                    <h2 class="step-title">Account Information</h2>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>First Name <span class="req">*</span></label>
                            <input type="text" id="first_name" name="first_name">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Last Name <span class="req">*</span></label>
                            <input type="text" id="last_name" name="last_name">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group full">
                            <label>Email Address <span class="req">*</span></label>
                            <input type="email" id="email" name="email">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Username <span class="req">*</span></label>
                            <input type="text" id="username" name="username">
                            <span class="error-msg"></span>
                            <small class="field-note">3-20 characters, letters, numbers, _ or - only</small>
                        </div>

                        <div class="form-group">
                            <label>Password <span class="req">*</span></label>
                            <input type="password" id="password" name="password">
                            <span class="error-msg"></span>
                            <small class="field-note">At least 8 characters</small>
                        </div>

                        <div class="form-group">
                            <label>Company Name <span class="req">*</span></label>
                            <input type="text" id="company" name="company">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Phone Number <span class="req">*</span></label>
                            <input type="tel" id="phone" name="phone">
                            <span class="error-msg"></span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 2: PARTNER STATUS -->
                <!-- ========================================= -->
                <div class="form-step step-2">
                    <h2 class="step-title">API Partner Status</h2>

                    <div class="form-group">
                        <label class="radio-label">Are you already registered as a Jetstar API Partner?</label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="already_registered" value="yes">
                                <span class="radio-custom"></span>
                                <span class="radio-text">
                                    <strong>Yes</strong> - I am already a registered API partner
                                </span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="already_registered" value="no">
                                <span class="radio-custom"></span>
                                <span class="radio-text">
                                    <strong>No</strong> - I want to register as a new API partner
                                </span>
                            </label>
                        </div>
                        <span class="error-msg"></span>
                    </div>

                    <!-- Existing Partner -->
                    <div id="existing-partner-form" class="conditional-section">
                        <div class="info-box">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="#FF6600" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <div>
                                <strong>Jetstar Organisational Code Required</strong>
                                <p>Please provide your Jetstar organisational code for validation.</p>
                            </div>
                        </div>
                        <div class="form-group full">
                            <label>Jetstar Organisational Code <span class="req">*</span></label>
                            <input type="text" id="organization_code" name="organization_code" placeholder="AU123456">
                            <span class="error-msg"></span>
                            <small class="field-note">Enter your Jetstar organisational code (e.g., AU123456)</small>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 3: API ACCESS TYPE -->
                <!-- ========================================= -->
                <div class="form-step step-3">
                    <h2 class="step-title">API Access Type</h2>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>API Type <span class="req">*</span></label>
                            <select id="api_type" name="api_type">
                                <option value="">-- Select API Type --</option>
                                <option value="ndc">NDC (New Distribution Capability)</option>
                                <option value="soap">SOAP API</option>
                                <option value="digital">Digital API</option>
                                <option value="other">Other</option>
                            </select>
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Business Type <span class="req">*</span></label>
                            <select id="business_type" name="business_type">
                                <option value="">-- Select Business Type --</option>
                                <option value="crs">CRS (Central Reservation System)</option>
                                <option value="content_aggregator">Content Aggregator</option>
                                <option value="meta_search">Meta Search</option>
                                <option value="travel_agent">Travel Agent (Direct)</option>
                                <option value="travel_agent_indirect">Travel Agent (via Aggregator)</option>
                                <option value="obt">Online Booking Tool</option>
                                <option value="internal_partner">Internal Partner</option>
                                <option value="other">Other</option>
                            </select>
                            <span class="error-msg"></span>
                            <small class="field-note auto-selected">✓ Auto-selected for Digital API</small>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 4: ORGANIZATION DETAILS -->
                <!-- ========================================= -->
                <div class="form-step step-4">
                    <h2 class="step-title">Organization Details</h2>

                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Organization Name <span class="req">*</span></label>
                            <input type="text" id="organization_name" name="organization_name">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group full">
                            <label>Business Address <span class="req">*</span></label>
                            <textarea id="address" name="address" rows="2" placeholder="Enter your complete business address"></textarea>
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>City <span class="req">*</span></label>
                            <input type="text" id="city" name="city">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>State/Province <span class="req">*</span></label>
                            <input type="text" id="state" name="state">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Postal Code <span class="req">*</span></label>
                            <input type="text" id="postal_code" name="postal_code">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Country <span class="req">*</span></label>
                            <select id="country" name="country">
                                <option value="">-- Select Country --</option>
                                <option value="AU">Australia</option>
                                <option value="NZ">New Zealand</option>
                                <option value="SG">Singapore</option>
                                <option value="JP">Japan</option>
                                <option value="TH">Thailand</option>
                                <option value="VN">Vietnam</option>
                                <option value="ID">Indonesia</option>
                                <option value="PH">Philippines</option>
                                <option value="US">United States</option>
                                <option value="GB">United Kingdom</option>
                                <option value="OTHER">Other</option>
                            </select>
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Operating Hours <span class="req">*</span></label>
                            <input type="text" id="operating_hours" name="operating_hours" placeholder="Mon-Fri 9AM-5PM AEST">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Time Zone <span class="req">*</span></label>
                            <select id="timezone" name="timezone">
                                <option value="">-- Select Time Zone --</option>
                                <option value="AEST">AEST (Australia Eastern)</option>
                                <option value="ACST">ACST (Australia Central)</option>
                                <option value="AWST">AWST (Australia Western)</option>
                                <option value="NZST">NZST (New Zealand)</option>
                                <option value="SGT">SGT (Singapore)</option>
                                <option value="JST">JST (Japan)</option>
                                <option value="UTC">UTC</option>
                                <option value="OTHER">Other</option>
                            </select>
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group full">
                            <label>Website URL <span class="optional">(Optional)</span></label>
                            <input type="url" id="website" name="website" placeholder="https://www.example.com">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 5: ANCILLARY PRODUCTS & VOLUME -->
                <!-- ========================================= -->
                <div class="form-step step-5">
                    <h2 class="step-title">Ancillary Products & Volume</h2>

                    <div class="form-section">
                        <h3 class="section-label">Ancillary Products</h3>
                        <p class="section-description">Please advise which Jetstar ancillary products you will sell/distribute via API at time of implementation. Note that baggage, seating and bundles are mandatory.</p>
                        
                        <div class="checkbox-grid">
                            <label class="checkbox-item mandatory">
                                <input type="checkbox" name="ancillary[]" value="baggage" class="mandatory-check">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-label">Baggage <span class="mandatory-badge">Mandatory</span></span>
                            </label>

                            <label class="checkbox-item mandatory">
                                <input type="checkbox" name="ancillary[]" value="seating" class="mandatory-check">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-label">Seating <span class="mandatory-badge">Mandatory</span></span>
                            </label>

                            <label class="checkbox-item mandatory">
                                <input type="checkbox" name="ancillary[]" value="bundles" class="mandatory-check">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-label">Bundles <span class="mandatory-badge">Mandatory</span></span>
                            </label>

                            <label class="checkbox-item">
                                <input type="checkbox" name="ancillary[]" value="meals">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-label">Meals</span>
                            </label>

                            <label class="checkbox-item">
                                <input type="checkbox" name="ancillary[]" value="meal_voucher">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-label">Meal Voucher</span>
                            </label>

                            <label class="checkbox-item">
                                <input type="checkbox" name="ancillary[]" value="entertainment">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-label">In-flight Entertainment</span>
                            </label>

                            <label class="checkbox-item">
                                <input type="checkbox" name="ancillary[]" value="comfort_packs">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-label">Comfort Packs</span>
                            </label>
                        </div>
                        <span class="error-msg" id="ancillary-error"></span>
                    </div>

                    <div class="form-section">
                        <h3 class="section-label">Projected Volume</h3>
                        <div class="form-group full">
                            <label>Projected segments sold per annum via API <span class="req">*</span></label>
                            <input type="number" id="projected_segments" name="projected_segments" placeholder="e.g., 50000">
                            <span class="error-msg"></span>
                            <small class="field-note">Estimated number of flight segments per year</small>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 6: SERVER ACCESS -->
                <!-- ========================================= -->
                <div class="form-step step-6">
                    <h2 class="step-title">Server Access</h2>
                    <p class="step-description">For IP whitelisting in firewall</p>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Test Server IP Address <span class="req">*</span></label>
                            <input type="text" id="test_ip" name="test_ip" placeholder="192.168.1.1">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Production Server IP Address <span class="req">*</span></label>
                            <input type="text" id="prod_ip" name="prod_ip" placeholder="192.168.1.2">
                            <span class="error-msg"></span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 7: CONTENT AGGREGATOR (CONDITIONAL) -->
                <!-- ========================================= -->
                <div class="form-step step-7">
                    <h2 class="step-title">Content Aggregator Details</h2>
                    <p class="step-description">Required for Travel Agents via Aggregator</p>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Aggregator Company Name <span class="req">*</span></label>
                            <input type="text" id="aggregator_name" name="aggregator_name" placeholder="e.g., GlobalDistribution Inc">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Contact Person <span class="req">*</span></label>
                            <input type="text" id="aggregator_contact" name="aggregator_contact" placeholder="Full Name">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Contact Email <span class="req">*</span></label>
                            <input type="email" id="aggregator_email" name="aggregator_email" placeholder="contact@aggregator.com">
                            <span class="error-msg"></span>
                        </div>

                        <div class="form-group">
                            <label>Aggregator Phone <span class="req">*</span></label>
                            <input type="tel" id="aggregator_phone" name="aggregator_phone">
                            <span class="error-msg"></span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 8: CONTACTS -->
                <!-- ========================================= -->
                <div class="form-step step-8">
                    <h2 class="step-title">Contact Information</h2>

                    <!-- Sponsor Info -->
                    <div class="form-section">
                        <h3 class="section-label">Sponsor Information <span class="optional-badge">Optional</span></h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Sponsor Name</label>
                                <input type="text" id="sponsor_name" name="sponsor_name" placeholder="Jetstar contact name">
                            </div>

                            <div class="form-group">
                                <label>Sponsor Email</label>
                                <input type="email" id="sponsor_email" name="sponsor_email" placeholder="sponsor@jetstar.com">
                            </div>

                            <div class="form-group">
                                <label>Sponsor Phone</label>
                                <input type="tel" id="sponsor_phone" name="sponsor_phone">
                            </div>
                        </div>
                    </div>

                    <!-- Manager Contact -->
                    <div class="form-section">
                        <h3 class="section-label">Manager Contact</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name <span class="req">*</span></label>
                                <input type="text" id="manager_name" name="manager_name">
                                <span class="error-msg"></span>
                            </div>

                            <div class="form-group">
                                <label>Position <span class="req">*</span></label>
                                <input type="text" id="manager_position" name="manager_position">
                                <span class="error-msg"></span>
                            </div>

                            <div class="form-group">
                                <label>Email <span class="req">*</span></label>
                                <input type="email" id="manager_email" name="manager_email">
                                <span class="error-msg"></span>
                            </div>

                            <div class="form-group">
                                <label>Phone <span class="req">*</span></label>
                                <input type="tel" id="manager_phone" name="manager_phone">
                                <span class="error-msg"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Technical Contact -->
                    <div class="form-section">
                        <h3 class="section-label">Technical Contact</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name <span class="req">*</span></label>
                                <input type="text" id="tech_name" name="tech_name">
                                <span class="error-msg"></span>
                            </div>

                            <div class="form-group">
                                <label>Position <span class="req">*</span></label>
                                <input type="text" id="tech_position" name="tech_position">
                                <span class="error-msg"></span>
                            </div>

                            <div class="form-group">
                                <label>Email <span class="req">*</span></label>
                                <input type="email" id="tech_email" name="tech_email">
                                <span class="error-msg"></span>
                            </div>

                            <div class="form-group">
                                <label>Phone <span class="req">*</span></label>
                                <input type="tel" id="tech_phone" name="tech_phone">
                                <span class="error-msg"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Business Support -->
                    <div class="form-section">
                        <h3 class="section-label">Business Support Technical Contacts</h3>
                        <div class="form-group full">
                            <label>Email addresses that require access to API technical specifications and WordPress information blog invites <span class="req">*</span></label>
                            <textarea id="api_spec_emails" name="api_spec_emails" rows="2" placeholder="email1@company.com, email2@company.com"></textarea>
                            <span class="error-msg"></span>
                            <small class="field-note">Separate multiple email addresses with commas</small>
                        </div>

                        <div class="form-group full">
                            <label>Email addresses to be added to Jetstar's outage notification and important updates distribution list <span class="req">*</span></label>
                            <textarea id="outage_emails" name="outage_emails" rows="2" placeholder="email1@company.com, email2@company.com"></textarea>
                            <span class="error-msg"></span>
                            <small class="field-note">Separate multiple email addresses with commas</small>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 9: ADDITIONAL INFORMATION -->
                <!-- ========================================= -->
                <div class="form-step step-9">
                    <h2 class="step-title">Additional Information</h2>

                    <div class="form-grid">
                        <div class="form-group full">
                            <label>How did you hear about the Jetstar API Partner Program?</label>
                            <select id="referral_source" name="referral_source">
                                <option value="">-- Select --</option>
                                <option value="web_search">Web Search</option>
                                <option value="referral">Referral</option>
                                <option value="jetstar_sales">Jetstar Sales Team</option>
                                <option value="industry_event">Industry Event</option>
                                <option value="existing_partner">Existing Partner</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Additional Comments or Notes</label>
                            <textarea id="comments" name="comments" rows="4" placeholder="Any additional information you'd like to share with us..."></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="button" class="btn btn-primary" data-action="next">Continue →</button>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- STEP 10: REVIEW & SUBMIT -->
                <!-- ========================================= -->
                <div class="form-step step-10">
                    <h2 class="step-title">Review Your Information</h2>

                    <div id="review-content" class="review-box"></div>

                    <div class="consent-wrapper">
    <label class="consent-label">
        <input type="checkbox" id="passenger_contact_consent" name="passenger_contact_consent" value="true">
        <span class="checkmark"></span>
        <span class="consent-text">
            <strong>I acknowledge and agree to provide passenger contact details to Jetstar for operational notifications.</strong>
            <span class="consent-info">
                By checking this box, you consent to receiving operational communications regarding bookings, flight updates, gate changes, and other important notifications.
            </span>
        </span>
    </label>
    <span class="error-msg"></span>
</div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-action="prev">← Back</button>
                        <button type="submit" id="submit-btn" class="btn btn-success">Submit Application</button>
                    </div>
                </div>

            </form>
        </div>

        <!-- Footer -->
        <div class="form-footer">
            <p>Questions? Contact <a href="mailto:apisupportblog@jetstar.com">apisupportblog@jetstar.com</a></p>
        </div>

    </div>
</div>

<!-- Loading Overlay -->
<div id="loading-overlay" class="loading-overlay">
    <div class="spinner"></div>
    <p>Processing your registration...</p>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
var jetstarAjax = {
    ajaxurl: '<?php echo $ajax_url; ?>',
    nonce: '<?php echo $nonce; ?>'
};
</script>
<script src="<?php echo $plugin_url; ?>assets/js/registration.js?v=<?php echo time(); ?>"></script>

</body>
</html>