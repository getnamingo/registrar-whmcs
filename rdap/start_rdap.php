<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Namingo\Rately\Rately;

$c = require_once 'config.php';
require_once 'helpers.php';
$logFilePath = '/var/log/namingo/rdap.log';
$log = setupLogger($logFilePath, 'RDAP');

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

// Create a Swoole HTTP server
$http = new Server('127.0.0.1', 7500);
$http->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/rdap_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/rdap.pid',
    'max_request' => 1000,
    'dispatch_mode' => 1,
    'open_tcp_nodelay' => true,
    'max_conn' => 1024,
    'buffer_output_size' => 2 * 1024 * 1024,  // 2MB
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 600,  // 10 minutes
    'package_max_length' => 2 * 1024 * 1024,  // 2MB
    'reload_async' => true,
    'http_compression' => true
]);

$rateLimiter = new Rately();
$log->info('server started.');

// Handle incoming HTTP requests
$http->on('request', function ($request, $response) use ($c, $pool, $log, $rateLimiter) {
    // Get a PDO connection from the pool
    try {
        $pdo = $pool->get();
        if (!$pdo) {
            throw new PDOException("Failed to retrieve a connection from Swoole PDOPool.");
        }
    } catch (PDOException $e) {
        $log->alert("Swoole PDO Pool failed: " . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(500);
        $response->end(json_encode(['error' => 'Database failure. Please try again later.']));
    }

    $remoteAddr = $request->server['remote_addr'];
    if (($c['rately'] == true) && ($rateLimiter->isRateLimited('rdap', $remoteAddr, $c['limit'], $c['period']))) {
        $log->error('rate limit exceeded for ' . $remoteAddr);
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(429);
        $response->end(json_encode(['error' => 'Rate limit exceeded. Please try again later.']));
    }

    try {
        // Extract the request path
        $requestPath = $request->server['request_uri'];

        // Handle domain query
        if (preg_match('#^/domain/([^/?]+)#', $requestPath, $matches)) {
            $domainName = $matches[1];
            handleDomainQuery($request, $response, $pdo, $domainName, $c, $log);
        }
        // Handle help query
        elseif ($requestPath === '/help') {
            handleHelpQuery($request, $response, $pdo, $c);
        }
        else {
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode(['errorCode' => 404,'title' => 'Not Found','error' => 'Endpoint not found']));
        }
    } catch (PDOException $e) {
        // Handle database exceptions
        $log->error('Database error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['Database error:' => $e->getMessage()]));
        return;
    } catch (Throwable $e) {
        // Catch any other exceptions or errors
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    } finally {
        // Return the connection to the pool
        $pool->put($pdo);
    }

});

// Start the server
$http->start();

function handleDomainQuery($request, $response, $pdo, $domainName, $c, $log) {
    // Extract and validate the domain name from the request
    $domain = urldecode($domainName);
    $domain = trim($domain);
    $domain = strtolower($domain);

    // Empty domain check
    if (!$domain) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Please enter a domain name']));
        return;
    }
    
    // Check domain length
    if (strlen($domain) > 68) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name is too long']));
        return;
    }

    // Convert to Punycode if the domain is not in ASCII
    if (!mb_detect_encoding($domain, 'ASCII', true)) {
        $convertedDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($convertedDomain === false) {
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(400); // Bad Request
            $response->end(json_encode(['error' => 'Domain conversion to Punycode failed']));
            return;
        } else {
            $domain = $convertedDomain;
        }
    }

    // Check for prohibited patterns in domain names
    if (!preg_match('/^(?:(xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.){1,3}(xn--[a-zA-Z0-9-]{2,63}|[a-zA-Z]{2,63})$/', $domain)) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name invalid format']));
        return;
    }
    
    // Extract TLD from the domain
    $parts = explode('.', $domain);
    $domainName = $parts[0];

    // Handle multi-segment TLDs (e.g., co.uk, ngo.us, etc.)
    if (count($parts) > 2) {
        $tld = "." . $parts[count($parts) - 2] . "." . $parts[count($parts) - 1];
    } else {
        $tld = "." . end($parts);
    }

    // Check if the TLD exists in the tld table
    $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM tbldomainpricing WHERE extension = :tld");
    $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtTLD->execute();
    $tldExists = $stmtTLD->fetchColumn();

    if (!$tldExists) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Invalid TLD. Please search only allowed TLDs']));
        return;
    }

    // Perform the RDAP lookup
    try {
        // Query 1: Get domain details
        $stmt1 = $pdo->prepare("SELECT *,
                    DATE_FORMAT(`crdate`, '%Y-%m-%dT%H:%i:%sZ') AS `crdate`,
                    DATE_FORMAT(`lastupdate`, '%Y-%m-%dT%H:%i:%sZ') AS `update`,
                    DATE_FORMAT(`exdate`, '%Y-%m-%dT%H:%i:%sZ') AS `exdate`
                    FROM namingo_domain WHERE name = :domain");
        $stmt1->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt1->execute();
        $domainDetails = $stmt1->fetch(PDO::FETCH_ASSOC);

        // Check if the domain exists
        if (!$domainDetails) {
            // Domain not found, respond with a 404 error
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested domain was not found in the RDAP database.'],
                "notices" => [
                    [
                        "description" => [
                            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the domain registrar database.",
                            "The data in this record is provided by the domain registrar for informational purposes only, and the domain registrar does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. The domain registrar reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                    ],
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/help",
                            "value" => $c['rdap_url'] . "/help",
                            "rel" => "self",
                            "type" => "application/rdap+json"
                        ],
                        [
                            "href" => $c['registrar_url'],
                            "value" => $c['registrar_url'],
                            "rel" => "alternate",
                            "type" => "text/html"
                        ],
                    ],
                        "title" => "RDAP Terms of Service"
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }

        // Query 4: Get registrant details
        $stmt4 = $pdo->prepare("SELECT id,identifier,name,org,street1,street2,street3,city,sp,pc,cc,voice,fax,email FROM namingo_contact WHERE id=:registrant");
        $stmt4->bindParam(':registrant', $domainDetails['registrant'], PDO::PARAM_INT);
        $stmt4->execute();
        $registrantDetails = $stmt4->fetch(PDO::FETCH_ASSOC);

        // Query 5: Get admin, billing and tech contacts  
        $stmt5a = $pdo->prepare("SELECT id,identifier,name,org,street1,street2,street3,city,sp,pc,cc,voice,fax,email FROM namingo_contact WHERE id=:admin");
        $stmt5a->bindParam(':admin', $domainDetails['admin'], PDO::PARAM_INT);
        $stmt5a->execute();
        $adminDetails = $stmt5a->fetch(PDO::FETCH_ASSOC);

        $stmt5b = $pdo->prepare("SELECT id,identifier,name,org,street1,street2,street3,city,sp,pc,cc,voice,fax,email FROM namingo_contact WHERE id=:tech");
        $stmt5b->bindParam(':tech', $domainDetails['tech'], PDO::PARAM_INT);
        $stmt5b->execute();
        $techDetails = $stmt5b->fetch(PDO::FETCH_ASSOC);

        $stmt5c = $pdo->prepare("SELECT id,identifier,name,org,street1,street2,street3,city,sp,pc,cc,voice,fax,email FROM namingo_contact WHERE id=:billing");
        $stmt5c->bindParam(':billing', $domainDetails['billing'], PDO::PARAM_INT);
        $stmt5c->execute();
        $billingDetails = $stmt5c->fetch(PDO::FETCH_ASSOC);

        // Query 6: Get nameservers
        $nameservers = [];
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($domainDetails["ns$i"])) {
                $nameservers[] = [
                    'name' => $domainDetails["ns$i"],
                    'host_id' => $i // Use the index as a stand-in for host_id
                ];
            }
        }

        $statusQuery = "SELECT status FROM namingo_domain_status WHERE domain_id = :domain_id";
        $stmtStatus = $pdo->prepare($statusQuery);
        $stmtStatus->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmtStatus->execute();
        $domainStatuses = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);
        if (empty($domainStatuses)) {
            $domainStatuses[] = 'active';
        }
        
        // Query to get DNSSEC data from namingo_domain_dnssec
        $dnssecQuery = "SELECT key_tag, algorithm, digest_type, digest FROM namingo_domain_dnssec WHERE domain_id = :domain_id";
        $stmtDnssec = $pdo->prepare($dnssecQuery);
        $stmtDnssec->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmtDnssec->execute();
        $dnssecRecords = $stmtDnssec->fetchAll(PDO::FETCH_ASSOC);

        // Initialize the dsData array for secureDNS
        $dsDataArray = [];
        foreach ($dnssecRecords as $record) {
            $dsDataArray[] = [
                "keyTag" => $record['key_tag'],
                "algorithm" => $record['algorithm'],
                "digest" => $record['digest'],
                "digestType" => $record['digest_type']
            ];
        }

        // Determine if delegation is signed based on the presence of DNSSEC records
        $delegationSigned = !empty($dsDataArray);

        // Build the secureDNS array
        $secureDNS = ["delegationSigned" => $delegationSigned];

        // Include dsData only if there are records
        if ($delegationSigned) {
            $secureDNS["dsData"] = $dsDataArray;
        }

        // Define the basic events
        $events = [
            ['eventAction' => 'registration', 'eventDate' => $domainDetails['crdate']],
            ['eventAction' => 'expiration', 'eventDate' => $domainDetails['exdate']],
            ['eventAction' => 'last update of RDAP database', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
        ];

        // Check if domain last update is set and not empty
        if (isset($domainDetails['update']) && !empty($domainDetails['update'])) {
            $events[] = ['eventAction' => 'last changed', 'eventDate' => (new DateTime($domainDetails['update']))->format('Y-m-d\TH:i:s.v\Z')];
        }

        // Check if domain transfer date is set and not empty
        if (isset($domainDetails['trdate']) && !empty($domainDetails['trdate'])) {
            $events[] = ['eventAction' => 'transfer', 'eventDate' => (new DateTime($domainDetails['trdate']))->format('Y-m-d\TH:i:s.v\Z')];
        }
        
        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_response_profile_1',
                'icann_rdap_technical_implementation_guide_0',
                'icann_rdap_technical_implementation_guide_1',
            ],
            'objectClassName' => 'domain',
            'entities' => array_merge(
                [
                [
                    'objectClassName' => 'entity',
                    'entities' => [
                    [
                        'objectClassName' => 'entity',
                        'roles' => ["abuse"],
                        "status" => ["active"],
                        "vcardArray" => [
                            "vcard",
                            [
                                ['version', new stdClass(), 'text', '4.0'],
                                ["fn", new stdClass(), "text", $c['registrar_name']],
                                ["tel", ["type" => ["voice"]], "uri", "tel:" . $c['abuse_phone']],
                                ["email", new stdClass(), "text", $c['abuse_email']]
                            ]
                        ],
                    ],
                    ],
                    "handle" => (string)$c['registrar_iana'],
                    "publicIds" => [
                        [
                            "identifier" => (string)$c['registrar_iana'],
                            "type" => "IANA Registrar ID"
                        ]
                    ],
                    "remarks" => [
                        [
                            "description" => ["This record contains only a summary. For detailed information, please submit a query specifically for this object."],
                            "title" => "Incomplete Data",
                            "type" => "object truncated due to authorization"
                        ]
                    ],
                    "roles" => ["registrar"],
                    "vcardArray" => [
                        "vcard",
                        [
                            ['version', new stdClass(), 'text', '4.0'],
                            ["fn", new stdClass(), "text", $c['registrar_name']]
                        ]
                    ],
                    "links" => [
                        [
                            "rel" => "self",
                            "href" => $c['rdap_url'] . "/entity/" . $c['registrar_iana'],
                            "value" => $c['rdap_url'] . "/entity/" . $c['registrar_iana'],
                            "type" => "application/rdap+json",
                            "title" => "Registrar Information"
                        ]
                    ],
                    ],
                ],
                !$c['minimum_data']
                    ? array_merge(
                        [
                            mapContactToVCard($registrantDetails, 'registrant', $c)
                        ],
                        [
                            mapContactToVCard($adminDetails, 'administrative', $c)
                        ],
                        [
                            mapContactToVCard($techDetails, 'billing', $c)
                        ],
                        [
                            mapContactToVCard($billingDetails, 'technical', $c)
                        ],
                    )
                    : []
            ),
            'events' => $events,
            'handle' => $domainDetails['registry_domain_id'] . '',
            'ldhName' => $domain,
            'links' => [
                [
                    'href' => $c['rdap_url'] . '/domain/' . $domain,
                    'value' => $c['rdap_url'] . '/domain/' . $domain,
                    'rel' => 'self',
                    'type' => 'application/rdap+json',
                ],
                [
                    'href' => $c['rdap_url'] . '/domain/' . $domain,
                    'value' => $c['rdap_url'] . '/domain/' . $domain,
                    'rel' => 'related',
                    'type' => 'application/rdap+json',
                ]
            ],
            'nameservers' => array_map(function ($nameserverDetails) use ($c) {
                return [
                    'objectClassName' => 'nameserver',
                    'handle' => 'H' . $nameserverDetails['name'],
                    'ldhName' => $nameserverDetails['name'],
                    'links' => [
                        [
                            'href' => $c['rdap_url'] . '/nameserver/' . $nameserverDetails['name'],
                            'value' => $c['rdap_url'] . '/nameserver/' . $nameserverDetails['name'],
                            'rel' => 'self',
                            'type' => 'application/rdap+json',
                        ],
                    ],
                    'remarks' => [
                        [
                            "description" => [
                                "This record contains only a brief summary. To access the full details, please initiate a specific query targeting this entity."
                            ],
                            "title" => "Incomplete Data",
                            "type" => "object truncated due to authorization"
                        ],
                    ],
                ];
            }, $nameservers),
            'status' => $domainStatuses,
            "secureDNS" => $secureDNS,
            "notices" => [
                [
                    "description" => [
                        "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the domain registrar database.",
                        "The data in this record is provided by the domain registrar for informational purposes only, and the domain registrar does not guarantee its accuracy. ",
                        "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                        "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                        "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                        "All rights reserved. The domain registrar reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                ],
                "links" => [
                    [
                        "href" => $c['rdap_url'] . "/help",
                        "value" => $c['rdap_url'] . "/help",
                        "rel" => "self",
                        "type" => "application/rdap+json"
                    ],
                    [
                        "href" => $c['registrar_url'],
                        "value" => $c['registrar_url'],
                        "rel" => "alternate",
                        "type" => "text/html"
                    ],
                ],
                    "title" => "RDAP Terms of Service"
                ],
                [
                "description" => [
                    "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                ]
                ],
                [
                "description" => [
                    "For more information on domain status codes, please visit https://icann.org/epp"
                ],
                "links" => [
                    [
                        "href" => "https://icann.org/epp",
                        "value" => "https://icann.org/epp",
                        "rel" => "glossary",
                        "type" => "text/html"
                    ]
                ],
                    "title" => "Status Codes"
                ],
                [
                    "description" => [
                        "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                    ],
                    "links" => [
                    [
                        "href" => "https://icann.org/wicf",
                        "value" => "https://icann.org/wicf",
                        "rel" => "help",
                        "type" => "text/html"
                    ]
                    ],
                    "title" => "RDDS Inaccuracy Complaint Form"
                ],
            ]
        ];

        // Send the RDAP response
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    }
}

function handleHelpQuery($request, $response, $pdo, $c) {
    // Set the RDAP conformance levels
    $rdapConformance = [
        'rdap_level_0',
        'icann_rdap_response_profile_0',
        'icann_rdap_response_profile_1',
        'icann_rdap_technical_implementation_guide_0',
        'icann_rdap_technical_implementation_guide_1',
    ];

    // Set the descriptions and links for the help section
    $helpNotices = [
        "description" => [
            "domain/XXXX",
            "help/XXXX"
        ],
        'links' => [
            [
                'value' => $c['rdap_url'] . '/help',
                'rel' => 'self',
                'href' => $c['rdap_url'] . '/help',
                'type' => 'application/rdap+json',
            ],
            [
                'value' => 'https://namingo.org',
                'rel' => 'related',
                'href' => 'https://namingo.org',
                'type' => 'application/rdap+json',
            ]
        ],
        "title" => "RDAP Help"
    ];

    // Set the terms of service
    $termsOfService = [
        "description" => [
            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the domain registrar database.",
            "The data in this record is provided by the domain registrar for informational purposes only, and the domain registrar does not guarantee its accuracy. ",
            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
            "All rights reserved. The domain registrar reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
        ],
        "links" => [
        [
            "href" => $c['rdap_url'] . "/help",
            "value" => $c['rdap_url'] . "/help",
            "rel" => "self",
            "type" => "application/rdap+json"
        ],
        [
            "href" => $c['registrar_url'],
            "value" => $c['registrar_url'],
            "rel" => "alternate",
            "type" => "text/html"
        ],
        ],
        "title" => "RDAP Terms of Service"
    ];

    // Construct the RDAP response for help query
    $rdapResponse = [
        "rdapConformance" => $rdapConformance,
        "notices" => [
            $helpNotices,
            $termsOfService
        ]
    ];

    // Send the RDAP response
    $response->header('Content-Type', 'application/rdap+json');
    $response->status(200);
    $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
}