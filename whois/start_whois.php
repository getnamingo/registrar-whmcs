<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

use Swoole\Server;
use Namingo\Rately\Rately;

$c = require_once 'config.php';
require_once 'helpers.php';
$logFilePath = '/var/log/namingo/whois.log';
$log = setupLogger($logFilePath, 'WHOIS');

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4')
);

// Create a Swoole TCP server
$server = new Server('0.0.0.0', 43);
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/whois_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/whois.pid',
    'max_request' => 1000,
    'dispatch_mode' => 2,
    'open_tcp_nodelay' => true,
    'max_conn' => 1024,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'buffer_output_size' => 2 * 1024 * 1024, // 2MB
    'enable_reuse_port' => true,
    'package_max_length' => 8192, // 8KB
    'open_eof_check' => true,
    'package_eof' => "\r\n"
]);

$rateLimiter = new Rately();
$log->info('server started.');

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) use ($log) {
    $log->info('new client connected: ' . $fd);
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) use ($c, $pool, $log, $rateLimiter) {
    // Get a PDO connection from the pool
    $pdo = $pool->get();
    $privacy = $c['privacy'];
    $parsedQuery = parseQuery($data);
    $queryType = $parsedQuery['type'];
    $queryData = $parsedQuery['data'];
    
    $clientInfo = $server->getClientInfo($fd);
    $remoteAddr = $clientInfo['remote_ip'];

    if (($c['rately'] == true) && ($rateLimiter->isRateLimited('whois', $remoteAddr, $c['limit'], $c['period']))) {
        $log->error('rate limit exceeded for ' . $remoteAddr);
        $server->send($fd, "rate limit exceeded. Please try again later");
        $server->close($fd);
        return;
    }

    // Handle the WHOIS query
    try {
        switch ($queryType) {
            case 'domain':
                // Handle domain query
                $domain = $queryData;
                
                if (!$domain) {
                    $server->send($fd, "please enter a domain name");
                    $server->close($fd);
                    return;
                }
                if (strlen($domain) > 68) {
                    $server->send($fd, "domain name is too long");
                    $server->close($fd);
                    return;
                }
                // Convert to Punycode if the domain is not in ASCII
                if (!mb_detect_encoding($domain, 'ASCII', true)) {
                    $convertedDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    if ($convertedDomain === false) {
                        $server->send($fd, "Domain conversion to Punycode failed");
                        $server->close($fd);
                        return;
                    } else {
                        $domain = $convertedDomain;
                    }
                }
                if (!preg_match('/^(?:(xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.){1,3}(xn--[a-zA-Z0-9-]{2,63}|[a-zA-Z]{2,63})$/', $domain)) {
                    $server->send($fd, "domain name invalid format");
                    $server->close($fd);
                    return;
                }
                $domain = strtoupper($domain);
            
                // Extract TLD from the domain and prepend a dot
                $parts = explode('.', $domain);
                $domainName = $parts[0];
                $tld = "." . end($parts);

                // Check if the TLD exists in the tbldomainpricing table
                $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM tbldomainpricing WHERE extension = :tld");
                $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
                $stmtTLD->execute();
                $tldExists = $stmtTLD->fetchColumn();

                if (!$tldExists) {
                    $server->send($fd, "Invalid TLD. Please search only allowed TLDs");
                    $server->close($fd);
                    return;
                }
                
                $query = "SELECT *,
                    DATE_FORMAT(`crdate`, '%Y-%m-%dT%H:%i:%sZ') AS `crdate`,
                    DATE_FORMAT(`lastupdate`, '%Y-%m-%dT%H:%i:%sZ') AS `update`,
                    DATE_FORMAT(`exdate`, '%Y-%m-%dT%H:%i:%sZ') AS `exdate`
                    FROM namingo_domain WHERE name = :domain";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
                $stmt->execute();

                if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $statusQuery = "SELECT status FROM namingo_domain_status WHERE domain_id = :domain_id";
                    $stmtStatus = $pdo->prepare($statusQuery);
                    $stmtStatus->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                    $stmtStatus->execute();
                    $domainStatuses = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);

                    // Check if the domain name is non-ASCII or starts with 'xn--'
                    $isNonAsciiOrPunycode = !mb_check_encoding($domain, 'ASCII') || strpos($domain, 'xn--') === 0;

                    $res = "Domain Name: ".strtoupper($domain)
                        ."\n";

                    // Add the Internationalized Domain Name line if the condition is met
                    if ($isNonAsciiOrPunycode) {
                        // Convert the domain name to UTF-8 and make it uppercase
                        $internationalizedName = idn_to_utf8($domain, 0, INTL_IDNA_VARIANT_UTS46);
                        $res .= "Internationalized Domain Name: " . mb_strtoupper($internationalizedName) . "\n";
                    }

                    $res .= "Registry Domain ID: " . ($f['registry_domain_id'] ?? '')
                        ."\nRegistrar WHOIS Server: ".$c['registrar_whois']
                        ."\nRegistrar URL: ".$c['registrar_url']
                        ."\nUpdated Date: ".$f['update']
                        ."\nCreation Date: ".$f['crdate']
                        ."\nRegistrar Registration Expiration Date: ".$f['exdate']
                        ."\nRegistrar: ".$c['registrar_name']
                        ."\nRegistrar IANA ID: ".$c['registrar_iana']
                        ."\nRegistrar Abuse Contact Email: ".$c['abuse_email']
                        ."\nRegistrar Abuse Contact Phone: ".$c['abuse_phone']
                        ."\nReseller: " . ($f['reseller'] ?? '')
                        ."\nReseller URL: " . ($f['reseller_url'] ?? '');
                        
                    if (!empty($domainStatuses)) {
                        foreach ($domainStatuses as $status) {
                            $res .= "\nDomain Status: " . $status . " https://icann.org/epp#" . $status;
                        }
                    } else {
                        // Default to 'ok' if no statuses are available
                        $res .= "\nDomain Status: ok https://icann.org/epp#ok";
                    }

                    if ($privacy) {
                        $res .= "\nRegistry Registrant ID: REDACTED FOR PRIVACY"
                            ."\nRegistrant Name: REDACTED FOR PRIVACY"
                            ."\nRegistrant Organization: REDACTED FOR PRIVACY"
                            ."\nRegistrant Street: REDACTED FOR PRIVACY"
                            ."\nRegistrant Street: REDACTED FOR PRIVACY"
                            ."\nRegistrant City: REDACTED FOR PRIVACY"
                            ."\nRegistrant State/Province: REDACTED FOR PRIVACY"
                            ."\nRegistrant Postal Code: REDACTED FOR PRIVACY"
                            ."\nRegistrant Country: REDACTED FOR PRIVACY"
                            ."\nRegistrant Phone: REDACTED FOR PRIVACY"
                            ."\nRegistrant Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                    } else {
                        $query5 = "SELECT id, identifier, name, org, street1, street2, street3, city, sp, pc, cc, voice, fax, email FROM namingo_contact WHERE id=:registrant";
                        $stmt5 = $pdo->prepare($query5);
                        $stmt5->bindParam(':registrant', $f['registrant'], PDO::PARAM_INT);
                        $stmt5->execute();

                        $f2 = $stmt5->fetch(PDO::FETCH_ASSOC);

                        $res .= "\nRegistry Registrant ID: " . ($f2['identifier'] ?? '')
                            ."\nRegistrant Name: ".$f2['name']
                            ."\nRegistrant Organization: ".$f2['org']
                            ."\nRegistrant Street: ".$f2['street1']
                            ."\nRegistrant Street: ".$f2['street2']
                            ."\nRegistrant Street: ".$f2['street3']
                            ."\nRegistrant City: ".$f2['city']
                            ."\nRegistrant State/Province: ".$f2['sp']
                            ."\nRegistrant Postal Code: ".$f2['pc']
                            ."\nRegistrant Country: ".strtoupper($f2['cc'])
                            ."\nRegistrant Phone: ".$f2['voice']
                            ."\nRegistrant Fax: ".$f2['fax']
                            ."\nRegistrant Email: ".$f2['email'];
                    }

                    if ($privacy) {
                        $res .= "\nRegistry Admin ID: REDACTED FOR PRIVACY"
                            ."\nAdmin Name: REDACTED FOR PRIVACY"
                            ."\nAdmin Organization: REDACTED FOR PRIVACY"
                            ."\nAdmin Street: REDACTED FOR PRIVACY"
                            ."\nAdmin Street: REDACTED FOR PRIVACY"
                            ."\nAdmin City: REDACTED FOR PRIVACY"
                            ."\nAdmin State/Province: REDACTED FOR PRIVACY"
                            ."\nAdmin Postal Code: REDACTED FOR PRIVACY"
                            ."\nAdmin Country: REDACTED FOR PRIVACY"
                            ."\nAdmin Phone: REDACTED FOR PRIVACY"
                            ."\nAdmin Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                    } else {
                        $query6 = "SELECT id, identifier, name, org, street1, street2, street3, city, sp, pc, cc, voice, fax, email FROM namingo_contact WHERE id=:admin";
                        $stmt6 = $pdo->prepare($query6);
                        $stmt6->bindParam(':admin', $f['admin'], PDO::PARAM_INT);
                        $stmt6->execute();
                        $f2 = $stmt6->fetch(PDO::FETCH_ASSOC);
                        
                        $res .= "\nRegistry Admin ID: " . ($f2['identifier'] ?? '')
                            ."\nAdmin Name: ".$f2['name']
                            ."\nAdmin Organization: ".$f2['org']
                            ."\nAdmin Street: ".$f2['street1']
                            ."\nAdmin Street: ".$f2['street2']
                            ."\nAdmin Street: ".$f2['street3']
                            ."\nAdmin City: ".$f2['city']
                            ."\nAdmin State/Province: ".$f2['sp']
                            ."\nAdmin Postal Code: ".$f2['pc']
                            ."\nAdmin Country: ".strtoupper($f2['cc'])
                            ."\nAdmin Phone: ".$f2['voice']
                            ."\nAdmin Fax: ".$f2['fax']
                            ."\nAdmin Email: ".$f2['email'];
                    }

                    if ($privacy) {
                        $res .= "\nRegistry Billing ID: REDACTED FOR PRIVACY"
                            ."\nBilling Name: REDACTED FOR PRIVACY"
                            ."\nBilling Organization: REDACTED FOR PRIVACY"
                            ."\nBilling Street: REDACTED FOR PRIVACY"
                            ."\nBilling Street: REDACTED FOR PRIVACY"
                            ."\nBilling City: REDACTED FOR PRIVACY"
                            ."\nBilling State/Province: REDACTED FOR PRIVACY"
                            ."\nBilling Postal Code: REDACTED FOR PRIVACY"
                            ."\nBilling Country: REDACTED FOR PRIVACY"
                            ."\nBilling Phone: REDACTED FOR PRIVACY"
                            ."\nBilling Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                    } else {
                        $query7 = "SELECT id, identifier, name, org, street1, street2, street3, city, sp, pc, cc, voice, fax, email FROM namingo_contact WHERE id=:billing";
                        $stmt7 = $pdo->prepare($query7);
                        $stmt7->bindParam(':billing', $f['billing'], PDO::PARAM_INT);
                        $stmt7->execute();
                        $f2 = $stmt7->fetch(PDO::FETCH_ASSOC);
                        
                        $res .= "\nRegistry Billing ID: " . ($f2['identifier'] ?? '')
                            ."\nBilling Name: ".$f2['name']
                            ."\nBilling Organization: ".$f2['org']
                            ."\nBilling Street: ".$f2['street1']
                            ."\nBilling Street: ".$f2['street2']
                            ."\nBilling Street: ".$f2['street3']
                            ."\nBilling City: ".$f2['city']
                            ."\nBilling State/Province: ".$f2['sp']
                            ."\nBilling Postal Code: ".$f2['pc']
                            ."\nBilling Country: ".strtoupper($f2['cc'])
                            ."\nBilling Phone: ".$f2['voice']
                            ."\nBilling Fax: ".$f2['fax']
                            ."\nBilling Email: ".$f2['email'];
                    }

                    if ($privacy) {
                        $res .= "\nRegistry Tech ID: REDACTED FOR PRIVACY"
                            ."\nTech Name: REDACTED FOR PRIVACY"
                            ."\nTech Organization: REDACTED FOR PRIVACY"
                            ."\nTech Street: REDACTED FOR PRIVACY"
                            ."\nTech Street: REDACTED FOR PRIVACY"
                            ."\nTech City: REDACTED FOR PRIVACY"
                            ."\nTech State/Province: REDACTED FOR PRIVACY"
                            ."\nTech Postal Code: REDACTED FOR PRIVACY"
                            ."\nTech Country: REDACTED FOR PRIVACY"
                            ."\nTech Phone: REDACTED FOR PRIVACY"
                            ."\nTech Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                    } else {
                        $query8 = "SELECT id, identifier, name, org, street1, street2, street3, city, sp, pc, cc, voice, fax, email FROM namingo_contact WHERE id=:tech";
                        $stmt8 = $pdo->prepare($query8);
                        $stmt8->bindParam(':tech', $f['tech'], PDO::PARAM_INT);
                        $stmt8->execute();
                        $f2 = $stmt8->fetch(PDO::FETCH_ASSOC);
                        
                        $res .= "\nRegistry Tech ID: " . ($f2['identifier'] ?? '')
                            ."\nTech Name: ".$f2['name']
                            ."\nTech Organization: ".$f2['org']
                            ."\nTech Street: ".$f2['street1']
                            ."\nTech Street: ".$f2['street2']
                            ."\nTech Street: ".$f2['street3']
                            ."\nTech City: ".$f2['city']
                            ."\nTech State/Province: ".$f2['sp']
                            ."\nTech Postal Code: ".$f2['pc']
                            ."\nTech Country: ".strtoupper($f2['cc'])
                            ."\nTech Phone: ".$f2['voice']
                            ."\nTech Fax: ".$f2['fax']
                            ."\nTech Email: ".$f2['email'];
                    }

                    // Loop through each DNS column from ns1 to ns5
                    for ($i = 1; $i <= 5; $i++) {
                        // Check if the ns field exists in $f and is not empty
                        if (!empty($f["ns$i"])) {
                            $res .= "\nName Server: " . $f["ns$i"];
                        }
                    }

                    // Query to check if DNSSEC data exists for the domain
                    $sqlDnssec = "SELECT COUNT(*) FROM namingo_domain_dnssec WHERE domain_id = :domain_id";
                    $stmtDnssec = $pdo->prepare($sqlDnssec);
                    $stmtDnssec->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                    $stmtDnssec->execute();

                    // Fetch the count
                    $dnssecExists = $stmtDnssec->fetchColumn();

                    // Append the DNSSEC status
                    if ($dnssecExists > 0) {
                        $res .= "\nDNSSEC: signedDelegation";
                    } else {
                        $res .= "\nDNSSEC: unsigned";
                    }
                    $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";
                    $currentDateTime = new DateTime();
                    $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                    $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                    $res .= "\n";
                    $res .= "\nFor more information on Whois status codes, please visit https://icann.org/epp";
                    $res .= "\n\n";
                    $res .= "Terms of Use: Access to WHOIS information is provided by the Registrar to help"
                        ."\nindividuals determine details of a domain name registration record"
                        ."\nin the Registrar's WHOIS database. This record's data is for"
                        ."\ninformational purposes only, and the Registrar makes no guarantees"
                        ."\nregarding its accuracy. This service is designed for query-based"
                        ."\naccess only. You commit to using this data exclusively for lawful"
                        ."\nreasons and agree that you will not: (a) facilitate, allow, or"
                        ."\notherwise support mass unsolicited, commercial promotions via email,"
                        ."\ntelephone, or fax directed at anyone other than your current clients;"
                        ."\nor (b) enable automated, high-volume electronic processes that submit"
                        ."\nqueries or data to the Registrar's systems or any related NIC, barring"
                        ."\nactions needed to register or adjust domain names."
                        ."\nAll rights reserved. The Registrar retains the right to adjust these"
                        ."\nterms at any time. By accessing this WHOIS service, you concur with"
                        ."\nthis policy."
                        ."\n";
                    $server->send($fd, $res . "");
                    
                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | FOUND');
                } else {
                    //NOT FOUND or No match for;
                    $server->send($fd, "NOT FOUND");
                    
                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | NOT FOUND');
                }
                break;
            default:
                // Handle unknown query type
                $log->error('Error');
                $server->send($fd, "Error");
        }
    } catch (PDOException $e) {
        // Handle database exceptions
        $log->error('Database error: ' . $e->getMessage());
        $server->send($fd, "Error connecting to the whois database");
        $server->close($fd);
    } catch (Throwable $e) {
        // Catch any other exceptions or errors
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "Error");
        $server->close($fd);
    } finally {
        // Return the connection to the pool
        $pool->put($pdo);
        $server->close($fd);
    }
});

// Register a callback to handle client disconnections
$server->on('close', function ($server, $fd) use ($log) {
    $log->info('client ' . $fd . ' disconnected.');
});

// Start the server
$server->start();