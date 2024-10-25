<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2024 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

require_once 'config.php';
require_once 'helpers.php';
require_once 'includes/eppClient.php';

use Pinga\Tembo\eppClient;
$registrar = "Epp";

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}

// Define function to update nameservers for expired domain names
function updateExpiredDomainNameservers($pdo) {
    // Get all expired domain names with registrar nameservers
    $sql = "SELECT * FROM namingo_domain WHERE NOW() > exdate";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute();
        $expired_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expired_domains as $domain) {
            // Nameservers to update
            $ns1 = $config['ns1'];
            $ns2 = $config['ns2'];

            // Prepare the SQL to check if ns1 exists
            $sqlCheck = "SELECT id FROM namingo_host WHERE name = :name";
            $stmtCheck = $pdo->prepare($sqlCheck);

            // Get or insert ns1 and ns2
            $host_id_1 = getOrInsertHost($pdo, $ns1);
            $host_id_2 = getOrInsertHost($pdo, $ns2);

            // Remove existing mappings from namingo_domain_host_map
            $sqlDelete = "DELETE FROM namingo_domain_host_map WHERE domain_id = :domain_id";
            $stmtDelete = $pdo->prepare($sqlDelete);
            $stmtDelete->bindParam(':domain_id', $domain['id']);
            $stmtDelete->execute();

            // Insert new mappings for host_id_1 and host_id_2
            $sqlInsertMap = "INSERT INTO namingo_domain_host_map (domain_id, host_id) VALUES (:domain_id, :host_id)";
            $stmtInsertMap = $pdo->prepare($sqlInsertMap);

            // Insert mapping for ns1
            $stmtInsertMap->bindParam(':domain_id', $domain['id']);
            $stmtInsertMap->bindParam(':host_id', $host_id_1);
            $stmtInsertMap->execute();

            // Insert mapping for ns2
            $stmtInsertMap->bindParam(':host_id', $host_id_2);
            $stmtInsertMap->execute();

            // Send EPP update to registry
            $epp = connectEpp("generic", $config);
            $params = array(
                'domainname' => $domain['name'],
                'ns1' => $ns1,
                'ns2' => $ns2
            );
            $domainUpdateNS = $epp->domainUpdateNS($params);
            
            if (array_key_exists('error', $domainUpdateNS))
            {
                echo 'DomainUpdateNS Error: ' . $domainUpdateNS['error'] . PHP_EOL;
            }
            else
            {
                echo 'ERRP cron completed successfully' . PHP_EOL;
            }
            
            $logout = $epp->logout();
        }
    } catch (PDOException $e) {
        // Log the error
        error_log($e->getMessage());
    }
}

// Call the function to update expired domain nameservers
updateExpiredDomainNameservers($pdo);