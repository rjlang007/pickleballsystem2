/**
 * FILE: assets/js/form-validation.js
 * Real-time form field validation with instant feedback
 * Provides visual feedback as users type without server requests
 */

(function() {
    // Validation patterns
    const patterns = {
        phone: /^(09\d{9})$/,  // PH number: 09xxxxxxxxx
        email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
        username: /^[a-zA-Z0-9_]{3,30}$/,
        url: /^https?:\/\/.+/,
        number: /^\d+$/,
        decimal: /^\d+(\.\d{1,2})?$/
    };

    // Real-time validators (client-side only)
    const validators = {
        phone: function(value) {
            if (!value) return null;
            if (!patterns.phone.test(value)) {
                return {
                    valid: false,
                    message: '❌ Invalid PH number format (e.g., 09171234567)'
                };
            }
            return { valid: true, message: '✅ Valid PH number' };
        },

        email: function(value) {
            if (!value) return null;
            if (!patterns.email.test(value)) {
                return {
                    valid: false,
                    message: '❌ Invalid email format'
                };
            }
            return { valid: true, message: '✅ Valid email' };
        },

        username: function(value) {
            if (!value) return null;
            if (!patterns.username.test(value)) {
                return {
                    valid: false,
                    message: '❌ Username: 3-30 chars, alphanumeric + underscore'
                };
            }
            return { valid: true, message: '✅ Valid username' };
        },

        password: function(value) {
            if (!value) return null;
            if (value.length < 8) {
                return {
                    valid: false,
                    message: '❌ Password must be at least 8 characters'
                };
            }
            if (!/[A-Z]/.test(value)) {
                return {
                    valid: false,
                    message: '❌ Must contain at least one uppercase letter'
                };
            }
            if (!/[a-z]/.test(value)) {
                return {
                    valid: false,
                    message: '❌ Must contain at least one lowercase letter'
                };
            }
            if (!/\d/.test(value)) {
                return {
                    valid: false,
                    message: '❌ Must contain at least one number'
                };
            }
            return { valid: true, message: '✅ Strong password' };
        },

        minLength: function(value, min) {
            if (!value) return null;
            if (value.length < min) {
                return {
                    valid: false,
                    message: `❌ Minimum ${min} characters required`
                };
            }
            return { valid: true, message: '✅ Valid length' };
        },

        maxLength: function(value, max) {
            if (!value) return null;
            if (value.length > max) {
                return {
                    valid: false,
                    message: `❌ Maximum ${max} characters allowed`
                };
            }
            return { valid: true, message: '✅ Within limit' };
        },

        number: function(value) {
            if (!value) return null;
            if (!patterns.number.test(value)) {
                return {
                    valid: false,
                    message: '❌ Must be a whole number'
                };
            }
            return { valid: true, message: '✅ Valid number' };
        },

        decimal: function(value) {
            if (!value) return null;
            if (!patterns.decimal.test(value)) {
                return {
                    valid: false,
                    message: '❌ Invalid decimal (e.g., 10.50)'
                };
            }
            return { valid: true, message: '✅ Valid amount' };
        },

        minValue: function(value, min) {
            if (!value) return null;
            const num = parseFloat(value);
            if (isNaN(num) || num < min) {
                return {
                    valid: false,
                    message: `❌ Minimum value: ${min}`
                };
            }
            return { valid: true, message: `✅ Valid (≥${min})` };
        },

        maxValue: function(value, max) {
            if (!value) return null;
            const num = parseFloat(value);
            if (isNaN(num) || num > max) {
                return {
                    valid: false,
                    message: `❌ Maximum value: ${max}`
                };
            }
            return { valid: true, message: `✅ Valid (≤${max})` };
        },

        match: function(value, otherId) {
            if (!value) return null;
            const otherField = document.getElementById(otherId);
            if (!otherField || otherField.value !== value) {
                return {
                    valid: false,
                    message: '❌ Fields do not match'
                };
            }
            return { valid: true, message: '✅ Matches' };
        }
    };

    // Display validation feedback
    function showValidationFeedback(input, result) {
        const group = input.closest('.form-group');
        if (!group) return;

        // Remove existing feedback
        const existingFeedback = group.querySelector('.field-validation-success, .form-error');
        if (existingFeedback) existingFeedback.remove();

        if (!result) {
            input.classList.remove('has-error');
            return;
        }

        if (result.valid) {
            input.classList.remove('has-error');
            const feedback = document.createElement('div');
            feedback.className = 'field-validation-success';
            feedback.textContent = result.message;
            group.appendChild(feedback);
        } else {
            input.classList.add('has-error');
            const feedback = document.createElement('div');
            feedback.className = 'form-error';
            feedback.textContent = result.message;
            group.appendChild(feedback);
        }
    }

    // Validate a field based on its attributes
    function validateField(input) {
        const type = input.dataset.validateType;
        if (!type || !validators[type]) return;

        let result = null;

        if (type === 'minLength') {
            const min = parseInt(input.dataset.validateMin);
            result = validators.minLength(input.value, min);
        } else if (type === 'maxLength') {
            const max = parseInt(input.dataset.validateMax);
            result = validators.maxLength(input.value, max);
        } else if (type === 'minValue') {
            const min = parseFloat(input.dataset.validateMin);
            result = validators.minValue(input.value, min);
        } else if (type === 'maxValue') {
            const max = parseFloat(input.dataset.validateMax);
            result = validators.maxValue(input.value, max);
        } else if (type === 'match') {
            const otherId = input.dataset.validateMatch;
            result = validators.match(input.value, otherId);
        } else {
            result = validators[type](input.value);
        }

        showValidationFeedback(input, result);
    }

    // Initialize form fields with real-time validation
    document.addEventListener('DOMContentLoaded', function() {
        // Find all inputs with validation attributes
        const fields = document.querySelectorAll('[data-validate-type]');

        fields.forEach(field => {
            // Validate on input
            field.addEventListener('input', function() {
                validateField(this);
            });

            // Validate on blur (for debounce)
            field.addEventListener('blur', function() {
                validateField(this);
            });

            // Validate on change (for select/radio/checkbox)
            field.addEventListener('change', function() {
                validateField(this);
            });
        });

        // Form submission - validate all fields
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const fieldsToValidate = form.querySelectorAll('[data-validate-type]');
                let formValid = true;

                fieldsToValidate.forEach(field => {
                    validateField(field);
                    const feedback = field.closest('.form-group').querySelector('.form-error');
                    if (feedback) {
                        formValid = false;
                    }
                });

                if (!formValid) {
                    e.preventDefault();
                    showToast({
                        type: 'error',
                        message: 'Please fix the errors before submitting'
                    });
                }
            });
        });
    });

    // Expose globally for external use
    window.validateField = validateField;
    window.showValidationFeedback = showValidationFeedback;
})();
