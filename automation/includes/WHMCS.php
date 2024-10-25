<?php
/**
 * Namingo Registrar Escrow
 *
 * Written in 2024 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
*/

namespace Namingo\Registrar;

class WHMCS
{
    private $pdo;
    private $full;
    private $hdl;

    public function __construct(\PDO $pdo, $full, $hdl)
    {
        $this->pdo = $pdo;
        $this->full = $full;
        $this->hdl = $hdl;
    }

    public function generateFull(): void
    {
        // Query to get id, registrant, name, crdate, exdate from namingo_domain
        $sqlDomain = "SELECT d.id, d.registrant, d.name, DATE_FORMAT(d.crdate, '%Y-%m-%dT%H:%i:%sZ') AS crdate, DATE_FORMAT(d.exdate, '%Y-%m-%dT%H:%i:%sZ') AS exdate FROM namingo_domain d";
        $stmtDomain = $this->pdo->prepare($sqlDomain);
        $stmtDomain->execute();
        $domains = $stmtDomain->fetchAll(\PDO::FETCH_ASSOC);

        // Open the file for writing and write the CSV header row
        $file = fopen($this->full, 'w');
        fwrite($file, '"domain","status","registration_date","expiry_date","next_due_date","rt-handle","tc-handle","ac-handle","bc-handle","prt-handle","ptc-handle","pac-handle","pbc-handle"' . "\n");
        fclose($file);

        // Loop through each domain and gather additional data
        foreach ($domains as $domain) {
            $domainId = $domain['id'];

            // Get status from namingo_domain_status, default to 'ok' if empty
            $sqlStatus = "SELECT status FROM namingo_domain_status WHERE domain_id = :domain_id";
            $stmtStatus = $this->pdo->prepare($sqlStatus);
            $stmtStatus->bindParam(':domain_id', $domainId, \PDO::PARAM_INT);
            $stmtStatus->execute();
            $status = $stmtStatus->fetchColumn();
            $domain['status'] = $status ? $status : 'ok';

            // Prepare to store contacts by type, including the identifier
            $domain['contacts'] = [
                'admin' => null,
                'tech' => null,
                'billing' => null,
            ];

            // Get contact_id/type pairs from namingo_domain_contact_map
            $sqlContacts = "SELECT contact_id, type FROM namingo_domain_contact_map WHERE domain_id = :domain_id";
            $stmtContacts = $this->pdo->prepare($sqlContacts);
            $stmtContacts->bindParam(':domain_id', $domainId, \PDO::PARAM_INT);
            $stmtContacts->execute();
            $contacts = $stmtContacts->fetchAll(\PDO::FETCH_ASSOC);

            // Assign the contact IDs based on their type and prepare to fetch identifiers
            $contactIds = [$domain['registrant']];
            foreach ($contacts as $contact) {
                $type = strtolower($contact['type']);
                if (in_array($type, ['admin', 'tech', 'billing'])) {
                    $domain['contacts'][$type] = $contact['contact_id'];
                    $contactIds[] = $contact['contact_id'];
                }
            }

            // Ensure we only have unique contact IDs
            $contactIds = array_unique($contactIds);

            // Prepare query to get identifiers from namingo_contact for registrant and other contact_ids
            if (!empty($contactIds)) {
                $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
                $sqlIdentifiers = "SELECT id, identifier FROM namingo_contact WHERE id IN ($placeholders)";
                $stmtIdentifiers = $this->pdo->prepare($sqlIdentifiers);

                // Execute with direct array of contact IDs
                $stmtIdentifiers->execute($contactIds);
                $identifiers = $stmtIdentifiers->fetchAll(\PDO::FETCH_ASSOC);

                // Map identifiers to contact types
                $identifierMap = [];
                foreach ($identifiers as $identifier) {
                    $identifierMap[$identifier['id']] = $identifier['identifier'];
                }

                // Replace contact IDs with identifiers in the contacts array
                foreach ($domain['contacts'] as $type => $contactId) {
                    if ($contactId && isset($identifierMap[$contactId])) {
                        $domain['contacts'][$type] = $identifierMap[$contactId];
                    }
                }

                // Also map the registrant to its identifier
                if (isset($identifierMap[$domain['registrant']])) {
                    $domain['registrant'] = $identifierMap[$domain['registrant']];
                }

                // Add to the domains array for CSV writing
                $this->writeToCsv($domain);
            }
        }
    }

    private function writeToCsv(array $domain): void
    {
        // Open the file for appending
        $file = fopen($this->full, 'a');

        // Extract the necessary data from the domain array
        $domainName = $domain['name'];
        $status = $domain['status'];
        $registrationDate = $domain['crdate'];
        $expiryDate = $domain['exdate'];
        $nextDueDate = ''; // Assuming there is no `nextduedate` from your current data

        // Get handles for the CSV, use empty string if they do not exist
        $rtHandle = $domain['registrant'] ?? '';
        $tcHandle = $domain['contacts']['tech'] ?? '';
        $acHandle = $domain['contacts']['admin'] ?? '';
        $bcHandle = $domain['contacts']['billing'] ?? '';

        // Create privacy-related handles (set them as empty for now)
        $prtHandle = '';
        $ptcHandle = '';
        $pacHandle = '';
        $pbcHandle = '';

        // Build the CSV line
        $line = "\"$domainName\",\"$status\",\"$registrationDate\",\"$expiryDate\",\"$nextDueDate\",\"$rtHandle\",\"$tcHandle\",\"$acHandle\",\"$bcHandle\",\"$prtHandle\",\"$ptcHandle\",\"$pacHandle\",\"$pbcHandle\"";
        fwrite($file, "$line\n");

        // Close the file
        fclose($file);
    }

    public function generateHDL(): void
    {
        // Query the database to get data from both tables
        $sql = "
            SELECT 
                nc.id AS aid, 
                nc.identifier, 
                nc.voice AS phone, 
                nc.fax, 
                nc.email, 
                ncp.name, 
                ncp.street1 AS address, 
                ncp.city, 
                ncp.sp AS state, 
                ncp.pc AS postcode, 
                ncp.cc AS country
            FROM namingo_contact nc
            LEFT JOIN namingo_contact_postalInfo ncp ON nc.id = ncp.contact_id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        // Open the file for writing
        $file = fopen($this->hdl, 'w');

        // Write the CSV header row
        fwrite($file, '"handle","name","address","state","zip","city","country","email","phone","fax"' . "\n");

        // Write the data rows to the file
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Ensure the phone number starts with a single '+'
            if (isset($row['phone'])) {
                $row['phone'] = '+' . ltrim($row['phone'], '+');
            }

            // Ensure the fax number starts with a single '+'
            if (isset($row['fax'])) {
                $row['fax'] = '+' . ltrim($row['fax'], '+');
            } else {
                // Add a default value if no fax number is present
                $row['fax'] = '';
            }

            // Prepare the row by joining values with commas and surrounding with double quotes
            $line = '"' . $row['identifier'] . '","' . $row['name'] . '","' . $row['address'] . '","' . $row['state'] . '","' . $row['postcode'] . '","' . $row['city'] . '","' . $row['country'] . '","' . $row['email'] . '","' . $row['phone'] . '","' . $row['fax'] . '"';
            
            // Write the line to the file
            fwrite($file, "$line\n");
        }

        // Close the file
        fclose($file);
    }
}
