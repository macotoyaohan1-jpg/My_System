/* =====================================================
   SLIDE BETWEEN LOGIN AND REGISTRATION
   ===================================================== */

function updateAuthView(isRegistering) {
    const title = document.getElementById("visualTitle");
    const text = document.getElementById("visualText");
    const subtitle = document.getElementById("visualSubtitle");
    const description = document.getElementById("visualDescription");
    const switchButton = document.getElementById("visualSwitchButton");

    if (title) {
        title.textContent = isRegistering
            ? "Welcome Back!"
            : "Hello, Welcome!";
    }

    if (text) {
        text.textContent = isRegistering
            ? "Already have an account?"
            : "Don't have an account?";
    }

    if (subtitle) {
        subtitle.textContent = isRegistering
            ? ""
            : "";
    }

    if (description) {
        description.textContent = "";
    }

    if (switchButton) {
        switchButton.textContent = isRegistering
            ? "Login"
            : "Register";
        switchButton.onclick = isRegistering ? showLogin : showRegister;
    }
}

function showRegister() {
    const wrapper = document.getElementById("authWrapper");

    if (wrapper) {
        wrapper.classList.add("show-register");
        updateAuthView(true);
    }
}

function showLogin() {
    const wrapper = document.getElementById("authWrapper");

    if (wrapper) {
        wrapper.classList.remove("show-register");
        updateAuthView(false);
    }
}

document.addEventListener("DOMContentLoaded", function() {
    const wrapper = document.getElementById("authWrapper");

    if (wrapper) {
        updateAuthView(wrapper.classList.contains("show-register"));
    }
});


/* =====================================================
   LOGIN PASSWORD TOGGLE
   ===================================================== */

function togglePassword() {

    const password = document.getElementById("password");
    const eyeIcon = document.getElementById("eyeIcon");

    if (password.type === "password") {

        password.type = "text";

        eyeIcon.classList.remove("fa-eye");
        eyeIcon.classList.add("fa-eye-slash");

    } else {

        password.type = "password";

        eyeIcon.classList.remove("fa-eye-slash");
        eyeIcon.classList.add("fa-eye");
    }
}


/* =====================================================
   LOGIN FORM VALIDATION
   ===================================================== */

const loginForm = document.getElementById("loginForm");

if (loginForm) {

    loginForm.addEventListener("submit", function(event) {

        const username = document.getElementById("username");
        const password = document.getElementById("password");

        const usernameError =
            document.getElementById("usernameError");

        const passwordError =
            document.getElementById("passwordError");

        const loginError =
            document.getElementById("loginError");

        usernameError.textContent = "";
        passwordError.textContent = "";
        loginError.textContent = "";

        let valid = true;


        /* Username */
        if (username.value.trim() === "") {

            usernameError.textContent =
                "Please enter your username.";

            username.classList.add("input-error");

            valid = false;

        } else {

            username.classList.remove("input-error");
        }


        /* Password */
        if (password.value.trim() === "") {

            passwordError.textContent =
                "Please enter your password.";

            password.classList.add("input-error");

            valid = false;

        } else {

            password.classList.remove("input-error");
        }


        /* Prevent Form Submission Only If Validation Fails */
        if (!valid) {
            event.preventDefault();
        }
    });
}


/* =====================================================
   FORGOT PASSWORD MODAL
   ===================================================== */

function openForgotPassword() {

    const overlay = document.getElementById("forgotOverlay");

    if (overlay) {

        overlay.classList.add("show");

        document.getElementById("resetEmail").focus();
    }
}


function closeForgotPassword() {

    const overlay = document.getElementById("forgotOverlay");

    if (overlay) {

        overlay.classList.remove("show");

        document.getElementById("resetEmail").value = "";

        document.getElementById("resetEmailError").textContent = "";

        document.getElementById("resetMessage").textContent = "";
    }
}


/* =====================================================
   FORGOT PASSWORD FORM VALIDATION
   ===================================================== */

const forgotForm = document.getElementById("forgotForm");

if (forgotForm) {

    forgotForm.addEventListener("submit", function(event) {

        const email =
            document.getElementById("resetEmail");

        const emailError =
            document.getElementById("resetEmailError");

        const message =
            document.getElementById("resetMessage");

        emailError.textContent = "";
        message.textContent = "";

        const emailPattern =
            /^[^\s@]+@[^\s@]+\.[^\s@]+$/;


        /* Empty Email */
        if (email.value.trim() === "") {

            event.preventDefault();

            emailError.textContent =
                "Please enter your email address.";

            return;
        }


        /* Invalid Email */
        if (!emailPattern.test(email.value.trim())) {

            event.preventDefault();

            emailError.textContent =
                "Please enter a valid email address.";

            return;
        }
    });
}


/* =====================================================
   CLOSE FORGOT PASSWORD WHEN CLICKING OUTSIDE
   ===================================================== */

const forgotOverlay =
    document.getElementById("forgotOverlay");

if (forgotOverlay) {

    forgotOverlay.addEventListener("click", function(event) {

        if (event.target === this) {
            closeForgotPassword();
        }
    });
}


/* =====================================================
   ESCAPE KEY - CLOSE FORGOT PASSWORD
   ===================================================== */

document.addEventListener("keydown", function(event) {

    if (event.key === "Escape") {

        closeForgotPassword();

        if (document.activeElement) {
            document.activeElement.blur();
        }
    }
});


/* =====================================================
   SHOW LOGIN MESSAGES FROM PHP REDIRECTS
   (?error=... / ?registered=1)
   ===================================================== */

const params =
    new URLSearchParams(window.location.search);

const loginErrorEl =
    document.getElementById("loginError");

const lockoutStatusEl =
    document.getElementById("lockoutStatus");


/* =====================================================
   LOGIN ERROR MESSAGES
   ===================================================== */

const loginErrorMessages = {

    empty:
        "Please enter your username and password.",

    invalid:
        "Incorrect username or password.",

    inactive:
        "This account has been deactivated.",

    session:
        "Please log in to continue.",

    locked:
        "Too many failed attempts. Please try again later."
};


/* =====================================================
   DISPLAY LOGIN ERROR
   ===================================================== */

if (loginErrorEl && params.has("error")) {

    const errorKey =
        params.get("error");

    const attemptsLeft =
        params.get("attempts_left");

    const seconds =
        params.get("seconds");


    /* Invalid Login + Attempts Remaining */
    if (errorKey === "invalid" && attemptsLeft !== null) {

        loginErrorEl.textContent =
            `Incorrect username or password. ${attemptsLeft} attempt(s) remaining.`;
    }


    /* Account Locked */
    else if (errorKey === "locked" && seconds !== null) {

        loginErrorEl.textContent =
            "Too many failed attempts.";

        if (lockoutStatusEl) {

            const remainingSeconds =
                Number(seconds) || 30;

            lockoutStatusEl.style.display =
                "block";

            lockoutStatusEl.textContent =
                `Try again in ${remainingSeconds} seconds.`;


            let countdown =
                remainingSeconds;


            const countdownInterval =
                setInterval(function() {

                    countdown -= 1;


                    if (countdown <= 0) {

                        clearInterval(countdownInterval);

                        lockoutStatusEl.textContent =
                            "You can try again now.";

                        return;
                    }


                    lockoutStatusEl.textContent =
                        `Try again in ${countdown} seconds.`;

                }, 1000);
        }
    }


    /* Other Login Errors */
    else {

        loginErrorEl.textContent =
            loginErrorMessages[errorKey] ||
            "Login failed.";
    }
}


/* =====================================================
   REGISTRATION SUCCESS MESSAGE
   (?registered=1)
   ===================================================== */

if (loginErrorEl && params.get("registered") === "1") {

    loginErrorEl.style.color = "green";

    loginErrorEl.textContent =
        "Account created! Please log in.";
}
