<?php
/**
 * Namingo Registrar WDRP
 *
 * Written in 2023-2024 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

date_default_timezone_set('UTC');
require_once 'config.php';
require_once 'helpers.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}

try {
    $current_date = date('Y-m-d');
    $query = "SELECT registrant, name, exdate FROM namingo_domain WHERE exdate BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 30 DAY)";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['current_date' => $current_date]);
    $domains = $stmt->fetchAll();

    if ($domains) {
        foreach ($domains as $domain) {
            // Prepare the SQL query
            $sql = "SELECT email FROM namingo_contact WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            // Bind the parameter
            $stmt->bindParam(':id', $domain['registrant'], PDO::PARAM_INT);

            // Execute the query
            $stmt->execute();

            // Fetch the result
            $to = $stmt->fetchColumn();

            $subject = $config['email']['subject'];
            $message = sprintf($config['email']['message'], $domain['name'], $domain['exdate']);

            send_email($to, $subject, $message, $config);
        }
    }
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}