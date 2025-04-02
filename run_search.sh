<?php
// This script is called by home.php to run the search in the background

if (empty($argv[1]) || !is_numeric($argv[1])) {
    die("Job ID is required");
}

$job_id = (int)$argv[1];

// Include database connection
require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get job details
    $stmt = $pdo->prepare("SELECT j.*, u.session_id FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ?");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$job) {
        die("Job not found");
    }
    
    // Update job status to pending
    $stmt = $pdo->prepare("UPDATE jobs SET status = 'pending' WHERE job_id = ?");
    $stmt->execute([$job_id]);
    
    // Prepare search terms
    $search_term = urlencode($job['search_term']);
    $taxon = urlencode($job['taxon']);
    $max_results = (int)$job['max_results'];
    
    // Step 1: Search NCBI for protein IDs
    $search_url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=protein&term={$search_term}+AND+{$taxon}[Organism]&retmax={$max_results}&api_key=9dc98a734d9a91f8aeded236c02838155308";
    
    $search_response = file_get_contents($search_url);
    if ($search_response === false) {
        throw new Exception("Failed to connect to NCBI search API");
    }
    
    // Parse IDs from response
    preg_match_all('/<Id>([0-9]+)<\/Id>/', $search_response, $matches);
    $ids = $matches[1] ?? [];
    
    if (empty($ids)) {
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'failed', error_message = 'No sequences found' WHERE job_id = ?");
        $stmt->execute([$job_id]);
        exit();
    }
    
    $id_list = implode(',', $ids);
    
    // Step 2: Fetch sequences in FASTA format
    $fetch_url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=protein&id={$id_list}&rettype=fasta&retmode=text&api_key=9dc98a734d9a91f8aeded236c02838155308";
    $fasta_response = file_get_contents($fetch_url);
    
    if ($fasta_response === false) {
        throw new Exception("Failed to fetch sequences from NCBI");
    }
    
    // Parse FASTA sequences
    $sequences = [];
    $current_id = '';
    $current_desc = '';
    $current_seq = '';
    
    foreach (explode("\n", $fasta_response) as $line) {
        if (strpos($line, '>') === 0) {
            // Save previous sequence if exists
            if ($current_id !== '') {
                $sequences[] = [
                    'ncbi_id' => $current_id,
                    'description' => $current_desc,
                    'sequence' => $current_seq
                ];
            }
            
            // Parse new header
            $header = substr($line, 1);
            $header_parts = explode(' ', $header, 2);
            $current_id = $header_parts[0];
            $current_desc = $header_parts[1] ?? '';
            $current_seq = '';
        } else {
            $current_seq .= trim($line);
        }
    }
    
    // Add the last sequence
    if ($current_id !== '') {
        $sequences[] = [
            'ncbi_id' => $current_id,
            'description' => $current_desc,
            'sequence' => $current_seq
        ];
    }
    
    // Store sequences in database
    $stmt = $pdo->prepare("INSERT INTO sequences (job_id, ncbi_id, description, sequence) VALUES (?, ?, ?, ?)");
    
    foreach ($sequences as $seq) {
        $stmt->execute([$job_id, $seq['ncbi_id'], $seq['description'], $seq['sequence']]);
    }
    
    // Update job status to completed
    $stmt = $pdo->prepare("UPDATE jobs SET status = 'completed' WHERE job_id = ?");
    $stmt->execute([$job_id]);
    
} catch (Exception $e) {
    // Update job status to failed
    $stmt = $pdo->prepare("UPDATE jobs SET status = 'failed', error_message = ? WHERE job_id = ?");
    $stmt->execute([$e->getMessage(), $job_id]);
}
?>
