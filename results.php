<?php
session_start();
require_once 'login.php';

$jobId = $_GET['job_id'] ?? $_COOKIE['job_id'] ?? '';

if (empty($jobId)) {
    die("No job ID provided");
}

try {
    // Connect to database using credentials from login.php
    $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get search results
    $stmt = $pdo->prepare("SELECT * FROM searches WHERE job_id = :job_id");
    $stmt->execute([':job_id' => $jobId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        die("Job not found");
    }
    
    // Count number of sequences (count the '>' characters in FASTA format)
    $sequenceCount = 0;
    if (!empty($result['sequences'])) {
        $sequenceCount = substr_count($result['sequences'], '>');
    }
    
    // Construct the NCBI query that was used
   // $ncbiQuery = "({$result['protein_family']}) AND {$result['taxonomic_group']}[Organism]";
    $ncbiQuery = "." . $result['protein_family'] ")"  . " AND " . $result['taxonomic_group'] . "[Organism]";
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Search Results</title>
    <style>
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            background: #f5f5f5;
            padding: 10px;
            border-radius: 5px;
        }
        .success { color: green; }
        .failed { color: red; }
        .info-box {
            background: #e7f3fe;
            border-left: 6px solid #2196F3;
            padding: 10px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <h1>Search Results</h1>
    
    <div class="info-box">
        <p><strong>NCBI Query:</strong> <?php echo htmlspecialchars($ncbiQuery); ?></p>
        <p><strong>Number of Sequences Found:</strong> <?php echo $sequenceCount; ?></p>
    </div>
    
    <p>Job ID: <?php echo htmlspecialchars($jobId); ?></p>
    <p>Protein Family: <?php echo htmlspecialchars($result['protein_family']); ?></p>
    <p>Taxonomic Group: <?php echo htmlspecialchars($result['taxonomic_group']); ?></p>
    <p>Status: <span class="<?php echo $result['status']; ?>"><?php echo htmlspecialchars($result['status']); ?></span></p>
    
    <?php if ($result['status'] === 'completed' && $sequenceCount > 0): ?>
        <h2>Sequences (FASTA format):</h2>
        <pre><?php echo htmlspecialchars($result['sequences']); ?></pre>
    <?php elseif ($result['status'] === 'failed' || $sequenceCount === 0): ?>
        <p class="failed">No sequences found for the specified criteria.</p>
    <?php endif; ?>
    
    <p><a href="home.php">Perform New Search</a></p>
</body>
</html>
