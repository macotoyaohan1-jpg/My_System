<?php
/* =================================================
   DATABASE CONNECTION
   Include this file in any PHP script that needs
   access to the database, e.g.:

       require_once 'db.php';

   Then use the $pdo variable to run queries.
   ================================================= */

$host    = "localhost";
$dbname  = "pharmacy_locator";
$dbuser  = "root";        // change to your MySQL username
$dbpass  = "";             // change to your MySQL password

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Don't leak connection details to the browser in production
    die("Database connection failed: " . $e->getMessage());
}