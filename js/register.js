/* =================================================
   SHOW / HIDE PASSWORD (REGISTRATION)
   ================================================= */

function toggleRegisterPassword(passwordId, iconId) {
    const password = document.getElementById(passwordId);
    const icon = document.getElementById(iconId);

    if (password.type === "password") {
        password.type = "text";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    } else {
        password.type = "password";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }
}


/* =================================================
   REGISTRATION FORM VALIDATION
   ================================================= */

const registerForm = document.getElementById("registerForm");

if (registerForm) {
    registerForm.addEventListener("submit", function(event) {

        const fullname = document.getElementById("fullname");
        const username = document.getElementById("regUsername");
        const email = document.getElementById("email");
        const phone = document.getElementById("phone");
        const password = document.getElementById("regPassword");
        const confirmPassword = document.getElementById("confirmPassword");
        const privacy = document.getElementById("privacyAgreement");

        let valid = true;

        /* Clear Previous Errors */
        document.querySelectorAll(".error-message").forEach(function(error) {
            error.textContent = "";
        });

        const registerError = document.getElementById("registerError");

        if (registerError) {
            registerError.textContent = "";
        }

        document.querySelectorAll(".input-wrapper, .password-wrapper").forEach(function(wrapper) {
            wrapper.classList.remove("input-error");
        });

        /* Full Name */
        if (fullname.value.trim() === "") {
            document.getElementById("fullnameError").textContent =
                "Please enter your full name.";
            valid = false;
        }


        /* Username */
        if (username.value.trim() === "") {

            document.getElementById("regUsernameError").textContent =
                "Please enter a username.";

            valid = false;

        } else if (username.value.trim().length < 4) {

            document.getElementById("regUsernameError").textContent =
                "Username must be at least 4 characters.";

            valid = false;
        }


        /* Email */
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if (email.value.trim() === "") {

            document.getElementById("emailError").textContent =
                "Please enter your email address.";

            valid = false;

        } else if (!emailPattern.test(email.value.trim())) {

            document.getElementById("emailError").textContent =
                "Please enter a valid email address.";

            valid = false;
        }


        /* Phone Number */
        const phonePattern = /^09\d{9}$/;

        if (phone.value.trim() === "") {

            document.getElementById("phoneError").textContent =
                "Please enter your phone number.";

            valid = false;

        } else if (!phonePattern.test(phone.value.trim())) {

            document.getElementById("phoneError").textContent =
                "Please enter a valid 11-digit phone number.";

            valid = false;
        }


        /* Password */
        if (password.value === "") {

            document.getElementById("regPasswordError").textContent =
                "Please enter a password.";

            valid = false;
        }


        /* Confirm Password */
        if (confirmPassword.value === "") {

            document.getElementById("confirmPasswordError").textContent =
                "Please confirm your password.";

            valid = false;

        } else if (password.value !== confirmPassword.value) {

            document.getElementById("confirmPasswordError").textContent =
                "Passwords do not match.";

            valid = false;
        }


        /* Privacy Agreement */
        if (!privacy.checked) {

            document.getElementById("privacyError").textContent =
                "Please acknowledge the Privacy and Data Protection Notice.";

            valid = false;
        }


        /* Prevent Form Submission Only If Validation Fails */
        if (!valid) {
            event.preventDefault();
        }
    });
}


/* =================================================
   PHONE NUMBER - NUMBERS ONLY
   ================================================= */

const phoneInput = document.getElementById("phone");

if (phoneInput) {
    phoneInput.addEventListener("input", function() {
        this.value = this.value.replace(/\D/g, "");
    });
}


/* =====================================================
   SHOW REGISTRATION MESSAGES FROM PHP
   (?error=... )
   ===================================================== */

const registerParams = new URLSearchParams(window.location.search);

const registerErrorEl = document.getElementById("registerError");

const registerErrorMessages = {
    validation: "Please check the highlighted fields and try again.",
    duplicate: "That username, email, or phone number is already registered.",
    server: "Something went wrong. Please try again."
};

if (registerErrorEl && registerParams.has("error")) {

    const errorKey = registerParams.get("error");

    registerErrorEl.textContent =
        registerErrorMessages[errorKey] || "Registration failed.";
}
