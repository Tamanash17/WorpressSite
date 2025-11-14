/**
 * Jetstar API Partner Registration Form
 * Complete JavaScript with All Validation & Logic
 * Version: 7.0
 */

(function($) {
    'use strict';

    let currentStep = 1;
    let totalSteps = 10;
    let formData = {};
    let isExistingPartner = false;

    // ============================================
    // INITIALIZATION
    // ============================================
    
    $(document).ready(function() {
        initializeForm();
        setupEventListeners();
        updateProgressTracker();
    });

    function initializeForm() {
        // Show first step
        $('.form-step').hide();
        $('.step-1').addClass('active').show();
        
        // Initialize conditional fields
        $('#existing-partner-form').hide();
        $('.step-7').hide(); // Hide aggregator step by default
        
        console.log('Jetstar Registration Form Initialized');
    }

    // ============================================
    // EVENT LISTENERS
    // ============================================
    
    function setupEventListeners() {
        // Navigation buttons
        $(document).on('click', '[data-action="next"]', handleNext);
        $(document).on('click', '[data-action="prev"]', handlePrev);
        
        // Form submission
        $('#jetstar-registration-form').on('submit', handleSubmit);
        
        // Partner Status Radio
        $('input[name="already_registered"]').on('change', handlePartnerStatusChange);
        
        // API Type Selection
        $('#api_type').on('change', handleApiTypeChange);
        
        // Business Type Selection
        $('#business_type').on('change', handleBusinessTypeChange);
        
        // Mandatory Ancillary Products
        $('.mandatory-check').on('change', validateMandatoryAncillary);
        
        // Real-time validation
        $('input, select, textarea').on('blur', function() {
            validateField($(this));
        });
        
        // Clear error on focus
        $('input, select, textarea').on('focus', function() {
            $(this).removeClass('error');
            $(this).siblings('.error-msg').removeClass('active');
        });
    }

    // ============================================
    // PARTNER STATUS HANDLING
    // ============================================
    
    function handlePartnerStatusChange() {
        const value = $('input[name="already_registered"]:checked').val();
        
        if (value === 'yes') {
            isExistingPartner = true;
            $('#existing-partner-form').addClass('show').slideDown();
            
            // Hide steps 3-9 for existing partners
            hideStepsForExistingPartner();
        } else {
            isExistingPartner = false;
            $('#existing-partner-form').removeClass('show').slideUp();
            
            // Show all steps for new partners
            showStepsForNewPartner();
        }
        
        updateProgressTracker();
    }

    function hideStepsForExistingPartner() {
        // Hide steps 3-9 in progress tracker
        for (let i = 3; i <= 9; i++) {
            $(`.progress-step[data-step="${i}"]`).addClass('hidden');
        }
        totalSteps = 3; // Account, Status, Review
    }

    function showStepsForNewPartner() {
        // Show all steps in progress tracker
        $('.progress-step').removeClass('hidden');
        totalSteps = 10;
    }

    // ============================================
    // API TYPE HANDLING
    // ============================================
    
    function handleApiTypeChange() {
        const apiType = $('#api_type').val();
        
        if (apiType === 'digital') {
            // Auto-select Internal Partner for Digital API
            $('#business_type').val('internal_partner').prop('disabled', true);
            $('.field-note.auto-selected').addClass('show');
        } else {
            $('#business_type').prop('disabled', false);
            $('.field-note.auto-selected').removeClass('show');
        }
    }

    // ============================================
    // BUSINESS TYPE HANDLING
    // ============================================
    
    function handleBusinessTypeChange() {
        const businessType = $('#business_type').val();
        
        if (businessType === 'travel_agent_indirect') {
            // Show Step 7 (Aggregator Details)
            $(`.progress-step[data-step="7"]`).removeClass('hidden');
        } else {
            // Hide Step 7
            $(`.progress-step[data-step="7"]`).addClass('hidden');
        }
        
        updateProgressTracker();
    }

    // ============================================
    // NAVIGATION
    // ============================================
    
    function handleNext(e) {
        e.preventDefault();
        
        // Validate current step
        if (!validateStep(currentStep)) {
            scrollToError();
            return;
        }
        
        // Save current step data
        saveStepData(currentStep);
        
        // Determine next step
        let nextStep = getNextStep(currentStep);
        
        if (nextStep) {
            goToStep(nextStep);
        }
    }

    function handlePrev(e) {
        e.preventDefault();
        
        // Determine previous step
        let prevStep = getPrevStep(currentStep);
        
        if (prevStep) {
            goToStep(prevStep);
        }
    }

    function getNextStep(current) {
        // Special logic for existing partners
        if (isExistingPartner && current === 2) {
            return 10; // Jump to Review
        }
        
        // Special logic for business type (skip aggregator if not indirect)
        if (current === 6) {
            const businessType = $('#business_type').val();
            if (businessType === 'travel_agent_indirect') {
                return 7; // Go to Aggregator
            } else {
                return 8; // Skip to Contacts
            }
        }
        
        // Normal flow
        if (current < totalSteps) {
            return current + 1;
        }
        
        return null;
    }

    function getPrevStep(current) {
        // Special logic for existing partners
        if (isExistingPartner && current === 10) {
            return 2; // Jump back to Status
        }
        
        // Special logic for contacts (skip aggregator if not indirect)
        if (current === 8) {
            const businessType = $('#business_type').val();
            if (businessType === 'travel_agent_indirect') {
                return 7; // Go back to Aggregator
            } else {
                return 6; // Go back to Server
            }
        }
        
        // Normal flow
        if (current > 1) {
            return current - 1;
        }
        
        return null;
    }

    function goToStep(stepNumber) {
        // Hide current step
        $(`.step-${currentStep}`).removeClass('active').fadeOut(200, function() {
            // Update current step
            currentStep = stepNumber;
            
            // Show new step
            $(`.step-${currentStep}`).addClass('active').fadeIn(300);
            
            // Update progress tracker
            updateProgressTracker();
            
            // Scroll to top
            $('.form-content').animate({ scrollTop: 0 }, 300);
            
            // Populate review if on last step
            if (currentStep === 10) {
                populateReview();
            }
        });
    }

    // ============================================
    // PROGRESS TRACKER
    // ============================================
    
    function updateProgressTracker() {
        $('.progress-step').each(function() {
            const step = $(this).data('step');
            
            // Skip hidden steps
            if ($(this).hasClass('hidden')) {
                return;
            }
            
            if (step < currentStep) {
                $(this).addClass('completed').removeClass('active');
            } else if (step === currentStep) {
                $(this).addClass('active').removeClass('completed');
            } else {
                $(this).removeClass('active completed');
            }
        });
    }

    // ============================================
    // VALIDATION
    // ============================================
    
    function validateStep(step) {
        let isValid = true;
        const $currentStep = $(`.step-${step}`);
        
        // Clear previous errors
        $currentStep.find('.error').removeClass('error');
        $currentStep.find('.error-msg').removeClass('active');
        
        // Get all required fields in current step
        $currentStep.find('input, select, textarea').each(function() {
            const $field = $(this);
            
            // Skip hidden fields
            if (!$field.is(':visible')) {
                return;
            }
            
            // Check if field is required
            const isRequired = $field.siblings('label').find('.req').length > 0 ||
                             $field.hasClass('mandatory-check');
            
            if (isRequired && !validateField($field)) {
                isValid = false;
            }
        });
        
        // Special validation for radio groups
        if (step === 2) {
            if (!$('input[name="already_registered"]:checked').length) {
                showError($('.radio-group').siblings('.error-msg'), 'Please select an option');
                isValid = false;
            }
        }
        
        // Validate mandatory ancillary products
        if (step === 5) {
            if (!validateMandatoryAncillary()) {
                isValid = false;
            }
        }
        
        // Validate terms checkbox
        // Validate passenger contact consent checkbox
if (step === 10) {
    if (!$('#passenger_contact_consent').is(':checked')) {
        showError($('#passenger_contact_consent').closest('.consent-wrapper').find('.error-msg'), 
                 'You must acknowledge and agree to provide passenger contact details to Jetstar');
        $('.consent-wrapper').addClass('error');
        isValid = false;
    } else {
        $('.consent-wrapper').removeClass('error');
    }
}
        
        return isValid;
    }

    function validateField($field) {
        const value = $field.val().trim();
        const fieldName = $field.attr('name');
        const $errorMsg = $field.siblings('.error-msg');
        
        // Skip if field is not visible
        if (!$field.is(':visible')) {
            return true;
        }
        
        // Check if required
        const isRequired = $field.siblings('label').find('.req').length > 0;
        
        if (isRequired && !value) {
            showError($errorMsg, 'This field is required');
            $field.addClass('error');
            return false;
        }
        
        // Field-specific validation
        switch (fieldName) {
            case 'email':
            case 'manager_email':
            case 'tech_email':
            case 'sponsor_email':
            case 'aggregator_email':
                if (value && !isValidEmail(value)) {
                    showError($errorMsg, 'Please enter a valid email address');
                    $field.addClass('error');
                    return false;
                }
                break;
                
            case 'username':
                if (value && !isValidUsername(value)) {
                    showError($errorMsg, 'Username must be 3-20 characters, letters, numbers, _ or - only');
                    $field.addClass('error');
                    return false;
                }
                break;
                
            case 'password':
                if (value && value.length < 8) {
                    showError($errorMsg, 'Password must be at least 8 characters');
                    $field.addClass('error');
                    return false;
                }
                break;
                
           case 'organization_code':
    if (value && !isValidOrgCode(value)) {
        showError($errorMsg, 'Organization code must be 6-9 alphanumeric characters (e.g., ABC123, JETSTAR, AU123456)');
        $field.addClass('error');
        return false;
    }
    break;
                
            case 'test_ip':
case 'prod_ip':
    if (value && !isValidIP(value)) {
        showError($errorMsg, 'Please enter valid IP address(es). Multiple IPs should be comma-separated (e.g., 192.168.1.1, 10.0.0.1)');
        $field.addClass('error');
        return false;
    }
    break;
                
            case 'website':
                if (value && !isValidURL(value)) {
                    showError($errorMsg, 'Please enter a valid URL');
                    $field.addClass('error');
                    return false;
                }
                break;
                
            case 'projected_segments':
                if (value && (isNaN(value) || parseInt(value) <= 0)) {
                    showError($errorMsg, 'Please enter a valid number');
                    $field.addClass('error');
                    return false;
                }
                break;
        }
        
        // Clear error if valid
        $field.removeClass('error');
        $errorMsg.removeClass('active');
        return true;
    }

    function validateMandatoryAncillary() {
        const baggage = $('input[value="baggage"]').is(':checked');
        const seating = $('input[value="seating"]').is(':checked');
        const bundles = $('input[value="bundles"]').is(':checked');
        
        if (!baggage || !seating || !bundles) {
            showError($('#ancillary-error'), 'Baggage, Seating, and Bundles are mandatory');
            return false;
        }
        
        $('#ancillary-error').removeClass('active');
        return true;
    }

    // ============================================
    // VALIDATION HELPERS
    // ============================================
    
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    function isValidUsername(username) {
        const re = /^[a-zA-Z0-9_-]{3,20}$/;
        return re.test(username);
    }

    function isValidOrgCode(code) {
        const re = /^[A-Za-z0-9]{6,9}$/;
        return re.test(code);
    }

function isValidIP(input) {
    // Single IP regex pattern
    const ipPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
    
    // Trim the input and split by comma
    const ips = input.split(',').map(ip => ip.trim()).filter(ip => ip.length > 0);
    
    // If no IPs provided, return false
    if (ips.length === 0) {
        return false;
    }
    
    // Check each IP address
    for (let i = 0; i < ips.length; i++) {
        if (!ipPattern.test(ips[i])) {
            return false;
        }
    }
    
    return true;
}

    function isValidURL(url) {
        try {
            new URL(url);
            return true;
        } catch (e) {
            return false;
        }
    }

    function showError($element, message) {
        $element.text(message).addClass('active');
    }

    function scrollToError() {
        const $firstError = $('.error').first();
        if ($firstError.length) {
            $('.form-content').animate({
                scrollTop: $firstError.offset().top - $('.form-content').offset().top + $('.form-content').scrollTop() - 100
            }, 500);
        }
    }

    // ============================================
    // DATA MANAGEMENT
    // ============================================
    
    function saveStepData(step) {
        const $currentStep = $(`.step-${step}`);
        
        $currentStep.find('input, select, textarea').each(function() {
            const $field = $(this);
            const name = $field.attr('name');
            
            if (!name) return;
            
            if ($field.attr('type') === 'checkbox') {
    if (name === 'ancillary[]') {
        // Handle multiple checkboxes
        if (!formData.ancillary) formData.ancillary = [];
        if ($field.is(':checked')) {
            formData.ancillary.push($field.val());
        }
    } else if (name === 'passenger_contact_consent') {
        // Special handling for consent checkbox - must send string 'true'
        formData[name] = $field.is(':checked') ? 'true' : 'false';
    } else {
        formData[name] = $field.is(':checked');
    }
} else if ($field.attr('type') === 'radio') {
                if ($field.is(':checked')) {
                    formData[name] = $field.val();
                }
            } else {
                formData[name] = $field.val();
            }
        });
    }

    // ============================================
    // REVIEW SECTION
    // ============================================
    
    function populateReview() {
        let html = '';
        
        // Account Information
        html += createReviewSection('Account Information', [
            { label: 'Name', value: `${formData.first_name} ${formData.last_name}` },
            { label: 'Email', value: formData.email },
            { label: 'Company', value: formData.company },
            { label: 'Phone', value: formData.phone }
        ]);
        
        // Partner Status
        html += createReviewSection('Partner Status', [
            { label: 'Status', value: formData.already_registered === 'yes' ? 'Existing Partner' : 'New Partner' },
            { label: 'Org Code', value: formData.organization_code || 'N/A' }
        ]);
        
        if (!isExistingPartner) {
            // API Type
            html += createReviewSection('API Access', [
                { label: 'API Type', value: getSelectText('#api_type') },
                { label: 'Business Type', value: getSelectText('#business_type') }
            ]);
            
            // Organization
            html += createReviewSection('Organization', [
                { label: 'Name', value: formData.organization_name },
                { label: 'Address', value: formData.address },
                { label: 'City', value: formData.city },
                { label: 'State', value: formData.state },
                { label: 'Postal Code', value: formData.postal_code },
                { label: 'Country', value: getSelectText('#country') },
                { label: 'Operating Hours', value: formData.operating_hours },
                { label: 'Time Zone', value: getSelectText('#timezone') },
                { label: 'Website', value: formData.website || 'N/A' }
            ]);
            
            // Ancillary & Volume
            html += createReviewSection('Ancillary & Volume', [
                { label: 'Products', value: (formData.ancillary || []).join(', ') },
                { label: 'Projected Segments', value: formData.projected_segments }
            ]);
            
            // Server Access
            html += createReviewSection('Server Access', [
                { label: 'Test IP', value: formData.test_ip },
                { label: 'Production IP', value: formData.prod_ip }
            ]);
            
            // Aggregator (if applicable)
            if (formData.business_type === 'travel_agent_indirect') {
                html += createReviewSection('Content Aggregator', [
                    { label: 'Company', value: formData.aggregator_name },
                    { label: 'Contact', value: formData.aggregator_contact },
                    { label: 'Email', value: formData.aggregator_email },
                    { label: 'Phone', value: formData.aggregator_phone }
                ]);
            }
            
            // Contacts
            html += createReviewSection('Manager Contact', [
                { label: 'Name', value: formData.manager_name },
                { label: 'Position', value: formData.manager_position },
                { label: 'Email', value: formData.manager_email },
                { label: 'Phone', value: formData.manager_phone }
            ]);
            
            html += createReviewSection('Technical Contact', [
                { label: 'Name', value: formData.tech_name },
                { label: 'Position', value: formData.tech_position },
                { label: 'Email', value: formData.tech_email },
                { label: 'Phone', value: formData.tech_phone }
            ]);
        }
        
        $('#review-content').html(html);
    }

    function createReviewSection(title, items) {
        let html = '<div class="review-section">';
        html += `<h4>${title}</h4>`;
        
        items.forEach(item => {
            if (item.value && item.value !== 'N/A') {
                html += '<div class="review-item">';
                html += `<span class="review-label">${item.label}:</span>`;
                html += `<span class="review-value">${item.value}</span>`;
                html += '</div>';
            }
        });
        
        html += '</div>';
        return html;
    }

    function getSelectText(selector) {
        return $(selector).find('option:selected').text();
    }

    // ============================================
    // FORM SUBMISSION
    // ============================================
    
    function handleSubmit(e) {
        e.preventDefault();
        
        // Final validation
        if (!validateStep(10)) {
            scrollToError();
            return;
        }
        
        // Save final step data
        saveStepData(10);
        
        // Show loading overlay
        $('#loading-overlay').addClass('show');
        
        // Submit form data
        $.ajax({
            url: jetstarAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'jetstar_register',
                nonce: jetstarAjax.nonce,
                formData: formData
            },
            success: function(response) {
                $('#loading-overlay').removeClass('show');
                
                if (response.success) {
                    showSuccessPage(response.data);
                } else {
                    showMessage('error', response.data.message || 'An error occurred. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                $('#loading-overlay').removeClass('show');
                showMessage('error', 'A server error occurred. Please try again later.');
                console.error('Submission error:', error);
            }
        });
    }

    // ============================================
    // SUCCESS PAGE
    // ============================================
    
    function showSuccessPage(data) {
        const appId = data.application_id || 'JQ-APP-' + Date.now();
        const submissionDate = new Date().toLocaleString('en-AU', {
            dateStyle: 'full',
            timeStyle: 'short'
        });
        
        // Hide progress tracker
        $('.progress-tracker').hide();
        
        // Show success message
        $('.form-content').html(`
            <div class="success-container">
                <div class="success-icon">
                    <svg width="60" height="60" viewBox="0 0 60 60" fill="none">
                        <circle cx="30" cy="30" r="28" stroke="#10b981" stroke-width="4" fill="none"/>
                        <path d="M18 30l8 8 16-16" stroke="#10b981" stroke-width="4" stroke-linecap="round" fill="none"/>
                    </svg>
                </div>
                
                <h2>Application Submitted</h2>
                
                <p class="confirmation-text">
                    Thank you for submitting your API Partner registration application.
                </p>
                
                <div class="info-box">
                    <p><strong>Application ID:</strong> <span class="app-id">${appId}</span></p>
                    <p><strong>Submitted:</strong> ${submissionDate}</p>
                </div>
                
                <div class="next-steps">
                    <h3>What Happens Next?</h3>
                    <p>Our team will review your application and contact you within <strong>3 business days</strong> regarding the status of your application and next steps.</p>
                    <p>A confirmation email has been sent to <strong>${formData.email}</strong> with your application details.</p>
                </div>
                
                <div class="contact-info">
                    <p>If you have any questions, please contact:</p>
                    <p><strong>Email:</strong> <a href="mailto:apisupportblog@jetstar.com">apisupportblog@jetstar.com</a></p>
                    <p><strong>Reference:</strong> Application ID ${appId}</p>
                </div>
                
                <div class="action-buttons">
                    <a href="https://apiblog.jetstar.com" class="btn btn-primary">Return to API Blog</a>
                    <button onclick="window.print()" class="btn btn-secondary">Print Confirmation</button>
                </div>
            </div>
        `);
        
        // Scroll to top
        $('.form-content').animate({ scrollTop: 0 }, 300);
    }

    // ============================================
    // MESSAGES
    // ============================================
    
    function showMessage(type, message) {
        const $msg = $('#jetstar-message');
        $msg.removeClass('success error').addClass(type);
        $msg.text(message).fadeIn();
        
        $('.form-content').animate({ scrollTop: 0 }, 300);
        
        setTimeout(function() {
            $msg.fadeOut();
        }, 5000);
    }

})(jQuery);