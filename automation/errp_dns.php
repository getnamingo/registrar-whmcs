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

            // Prepare the SQL to update ns1, ns2, and set ns3 to ns5 to NULL
            $sqlUpdate = "
                UPDATE namingo_domain 
                SET ns1 = :ns1, ns2 = :ns2, ns3 = NULL, ns4 = NULL, ns5 = NULL 
                WHERE id = :domain_id
            ";
            $stmtUpdate = $pdo->prepare($sqlUpdate);

            // Bind parameters for ns1, ns2, and domain_id
            $stmtUpdate->bindParam(':ns1', $ns1);
            $stmtUpdate->bindParam(':ns2', $ns2);
            $stmtUpdate->bindParam(':domain_id', $domain['id'], PDO::PARAM_INT);

            // Execute the update
            $stmtUpdate->execute();

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