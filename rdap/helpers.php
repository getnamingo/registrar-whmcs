<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    // Create a log channel
    $log = new Logger($channelName);

    // Set up the console handler
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u", // Date format
        true, // Allow inline line breaks
        true  // Ignore empty context and extra
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // Set up the file handler
    $fileHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u" // Date format
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    return $log;
}

function mapContactToVCard($contactDetails, $role, $c) {
    $redacted = 'REDACTED FOR PRIVACY';

    return [
        'objectClassName' => 'entity',
        'handle' => [$contactDetails['identifier']],
        'roles' => [$role],
        'vcardArray' => [
            "vcard",
            [
                ['version', new stdClass(), 'text', '4.0'],
                ["fn", new stdClass(), 'text', $c['privacy'] ? $redacted : $contactDetails['name']],
                ["org", new stdClass(), 'text', $c['privacy'] ? $redacted : $contactDetails['org']],
                ["adr", [
                    "", // Post office box
                    $c['privacy'] ? $redacted : $contactDetails['street1'], // Extended address
                    $c['privacy'] ? $redacted : $contactDetails['street2'], // Street address
                    $c['privacy'] ? $redacted : $contactDetails['city'], // Locality
                    $c['privacy'] ? $redacted : $contactDetails['sp'], // Region
                    $c['privacy'] ? $redacted : $contactDetails['pc'], // Postal code
                    $c['privacy'] ? $redacted : strtoupper($contactDetails['cc']) // Country name
                ]],
                ["tel", new stdClass(), 'text', $c['privacy'] ? $redacted : $contactDetails['voice'], ["type" => "voice"]],
                ["tel", new stdClass(), 'text', $c['privacy'] ? $redacted : $contactDetails['fax'], ["type" => "fax"]],
                ["email", new stdClass(), 'text', $c['privacy'] ? $redacted : $contactDetails['email']],
            ]
        ],
    ];
}