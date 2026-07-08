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
            const passwordInput = document.getElementById("signup-password");
            const locationInput = document.getElementById("signup-location");

            // Clear previous errors
            clearError(nameInput);
            clearError(emailInput);
            clearError(passwordInput);
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

            // Validate Password
            if (passwordInput.value.trim() === "") {
                showError(passwordInput, "Password is required.");
                isValid = false;
            } else if (passwordInput.value.trim().length < 6) {
                showError(passwordInput, "Password must be at least 6 characters long.");
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