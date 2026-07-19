// js/validation.js

document.addEventListener("DOMContentLoaded", function () {
    const loginForm = document.getElementById("login-form");
    const signupForm = document.getElementById("signup-form");

    // Utility function to show an error message
    function showError(inputElement, message) {
        if (!inputElement) return;
        const formGroup = inputElement.closest(".form-group");
        let errorElement = formGroup.querySelector(".error-text");
        
        // If error element doesn't exist, create it
        if (!errorElement) {
            errorElement = document.createElement("div");
            errorElement.className = "error-text";
            formGroup.appendChild(errorElement);
        }
        
        errorElement.innerText = message;
        inputElement.classList.add("input-error");
    }

    // Utility function to clear an error message
    function clearError(inputElement) {
        if (!inputElement) return;
        const formGroup = inputElement.closest(".form-group");
        const errorElement = formGroup.querySelector(".error-text");
        
        if (errorElement) {
            errorElement.remove();
        }
        inputElement.classList.remove("input-error");
    }

    // NEW: Automatically clear the error as soon as the user starts typing
    document.querySelectorAll('.form-control').forEach(input => {
        input.addEventListener('input', function() {
            clearError(this);
        });
    });

    // Keep the "passwords match" error in sync as the user edits either password field
    const pwField = document.getElementById("signup-password");
    const confirmPwField = document.getElementById("signup-confirm-password");
    if (pwField && confirmPwField) {
        [pwField, confirmPwField].forEach(field => {
            field.addEventListener('input', function () {
                if (confirmPwField.value !== "" && pwField.value !== confirmPwField.value) {
                    showError(confirmPwField, "Passwords do not match.");
                } else {
                    clearError(confirmPwField);
                }
            });
        });
    }

    // Utility function to validate email format
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // --- LOGIN FORM VALIDATION ---
    if (loginForm) {
        loginForm.addEventListener("submit", function (e) {
            let isValid = true;
            const emailInput = document.getElementById("login-email");
            const passwordInput = document.getElementById("login-password");

            // Clear previous errors
            clearError(emailInput);
            clearError(passwordInput);

            // Validate Email
            if (emailInput.value.trim() === "") {
                showError(emailInput, "Email address is required.");
                isValid = false;
            } else if (!isValidEmail(emailInput.value.trim())) {
                showError(emailInput, "Please enter a valid email address.");
                isValid = false;
            }

            // Validate Password
            if (passwordInput.value.trim() === "") {
                showError(passwordInput, "Password is required.");
                isValid = false;
            }

            // Prevent submission if invalid
            if (!isValid) {
                e.preventDefault();
            }
        });
    }

    // --- SIGNUP FORM VALIDATION ---
    if (signupForm) {
        signupForm.addEventListener("submit", function (e) {
            let isValid = true;
            const nameInput = document.getElementById("signup-name");
            const emailInput = document.getElementById("signup-email");
            const mobileInput = document.getElementById("signup-mobile");
            const passwordInput = document.getElementById("signup-password");
            const confirmPasswordInput = document.getElementById("signup-confirm-password");
            const locationInput = document.getElementById("signup-location");

            // Clear previous errors
            clearError(nameInput);
            clearError(emailInput);
            clearError(mobileInput);
            clearError(passwordInput);
            clearError(confirmPasswordInput);
            clearError(locationInput);

            // Validate Name
            if (nameInput.value.trim() === "") {
                showError(nameInput, "Full Name is required.");
                isValid = false;
            } else if (nameInput.value.trim().length < 3) {
                showError(nameInput, "Name must be at least 3 characters long.");
                isValid = false;
            }

            // Validate Email
            if (emailInput.value.trim() === "") {
                showError(emailInput, "Email address is required.");
                isValid = false;
            } else if (!isValidEmail(emailInput.value.trim())) {
                showError(emailInput, "Please enter a valid email address.");
                isValid = false;
            }

            // Validate Mobile Number
            const mobileRegex = /^[0-9+\-\s]{7,15}$/;
            if (mobileInput.value.trim() === "") {
                showError(mobileInput, "Mobile number is required.");
                isValid = false;
            } else if (!mobileRegex.test(mobileInput.value.trim())) {
                showError(mobileInput, "Please enter a valid mobile number.");
                isValid = false;
            }

            // Validate Password
            if (passwordInput.value.trim() === "") {
                showError(passwordInput, "Password is required.");
                isValid = false;
            } else if (passwordInput.value.trim().length < 6) {
                showError(passwordInput, "Password must be at least 6 characters long.");
                isValid = false;
            }

            // Validate Confirm Password
            if (confirmPasswordInput.value.trim() === "") {
                showError(confirmPasswordInput, "Please confirm your password.");
                isValid = false;
            } else if (passwordInput.value !== confirmPasswordInput.value) {
                showError(confirmPasswordInput, "Passwords do not match.");
                isValid = false;
            }

            // Validate Location
            if (locationInput.value.trim() === "") {
                showError(locationInput, "City / Location is required.");
                isValid = false;
            }

            // Prevent submission if invalid
            if (!isValid) {
                e.preventDefault();
            }
        });
    }
});