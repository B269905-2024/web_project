<?php
session_start();

// Enhanced error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_motifs_error.log');

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Verify user owns this job
    $stmt = $pdo->prepare("SELECT j.*, u.user_id FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch();

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    // Check for existing motif job
    $stmt = $pdo->prepare("SELECT * FROM motif_jobs WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $motif_job = $stmt->fetch();

    if (!$motif_job) {
        // Create new motif job
        $stmt = $pdo->prepare("INSERT INTO motif_jobs (job_id) VALUES (?)");
        $stmt->execute([$job_id]);
        $motif_id = $pdo->lastInsertId();

        // Get sequences from database
        $stmt = $pdo->prepare("SELECT sequence_id, ncbi_id, sequence FROM sequences WHERE job_id = ?");
        $stmt->execute([$job_id]);
        $sequences = $stmt->fetchAll();
        $sequence_count = count($sequences);

        if (empty($sequences)) {
            $stmt = $pdo->prepare("DELETE FROM motif_jobs WHERE motif_id = ?");
            $stmt->execute([$motif_id]);
            header("Location: results.php?job_id=$job_id");
            exit();
        }

        // Create individual FASTA files for debugging
        $fasta_files = [];
        $fasta_content = '';
        foreach ($sequences as $seq) {
            $individual_file = tempnam(sys_get_temp_dir(), 'motif_seq_');
            file_put_contents($individual_file, ">{$seq['ncbi_id']}\n{$seq['sequence']}\n");
            $fasta_files[] = [
                'file' => $individual_file,
                'ncbi_id' => $seq['ncbi_id'],
                'size' => filesize($individual_file)
            ];
            $fasta_content .= ">{$seq['ncbi_id']}\n{$seq['sequence']}\n";
        }

        // Create combined FASTA file
        $combined_file = tempnam(sys_get_temp_dir(), 'motif_combined_');
        file_put_contents($combined_file, $fasta_content);

        // Execute patmatmotifs on combined file
        $output = [];
        $return_var = 0;
        $command = "/usr/bin/patmatmotifs -sequence $combined_file -full Y -auto 2>&1";
        exec($command, $output, $return_var);
        
        $output_text = implode("\n", $output);

        // Store debug info
        $_SESSION['motif_debug'] = [
            'sequences_count' => $sequence_count,
            'individual_files' => $fasta_files,
            'combined_file' => [
                'path' => $combined_file,
                'size' => filesize($combined_file),
                'content_sample' => substr($fasta_content, 0, 500) . (strlen($fasta_content) > 500 ? '...' : '')
            ],
            'command' => $command,
            'output' => $output_text,
            'return_code' => $return_var
        ];

        // Clean up files
        foreach ($fasta_files as $file) {
            if (file_exists($file['file'])) {
                unlink($file['file']);
            }
        }
        if (file_exists($combined_file)) {
            unlink($combined_file);
        }

        if ($return_var !== 0) {
            throw new Exception("patmatmotifs failed with code $return_var");
        }

        if (empty($output_text)) {
            throw new Exception("patmatmotifs returned empty output");
        }

        // Parse results
        $current_seq = '';
        $motif_count = 0;
        
        foreach ($output as $line) {
            if (preg_match('/^Sequence: (.+?)\s+from:/', $line, $matches)) {
                $current_seq = $matches[1];
            }
            elseif (preg_match('/^Hit: (\S+)\s+(PS\d+);(.*?);(\d+)-(\d+); Score: ([\d.]+); P-value: ([\d.]+)/', $line, $matches)) {
                $stmt = $pdo->prepare("INSERT INTO motif_results 
                    (motif_id, sequence_id, motif_name, motif_id_code, description, start_pos, end_pos, score, p_value)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $motif_id,
                    $current_seq,
                    trim($matches[1]),
                    $matches[2],
                    trim($matches[3]),
                    $matches[4],
                    $matches[5],
                    $matches[6],
                    $matches[7]
                ]);
                $motif_count++;
            }
        }
        
        // Generate report
        $report_text = "Motif Analysis Report\n===========================\n\n";
        $report_text .= "Job ID: $job_id\n";
        $report_text .= "Search Term: " . $job['search_term'] . "\n";
        $report_text .= "Taxonomic Group: " . $job['taxon'] . "\n";
        $report_text .= "Date: " . date('Y-m-d H:i:s') . "\n\n";
        $report_text .= "Sequences analyzed: $sequence_count\n";
        $report_text .= "Total motifs found: $motif_count\n";
        
        $stmt = $pdo->prepare("INSERT INTO motif_reports (motif_id, report_text) VALUES (?, ?)");
        $stmt->execute([$motif_id, $report_text]);
    }

    // Get analysis results
    $stmt = $pdo->prepare("
        SELECT mr.*, s.ncbi_id 
        FROM motif_results mr
        JOIN sequences s ON mr.sequence_id = s.sequence_id
        WHERE mr.motif_id = (SELECT motif_id FROM motif_jobs WHERE job_id = ?)
        ORDER BY mr.sequence_id, mr.start_pos
    ");
    $stmt->execute([$job_id]);
    $results = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Motif analysis error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Motif Analysis</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; margin: 0; padding: 20px; color: #333; background-color: #f9f9f9; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .debug-info { background: #f5f5f5; padding: 20px; border-radius: 8px; margin-top: 20px; }
        pre { white-space: pre-wrap; background: #fff; padding: 10px; border-radius: 4px; border: 1px solid #ddd; overflow-x: auto; max-height: 400px; }
        .file-info { margin-bottom: 15px; }
        .file-info h4 { margin-bottom: 5px; }
        .nav-links { margin-bottom: 20px; }
        .nav-links a { margin-right: 15px; text-decoration: none; color: #3498db; }
        .no-results { background: #fdecea; padding: 20px; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="home.php">New Search</a>
            <a href="past.php">Past Searches</a>
            <a href="results.php?job_id=<?= $job_id ?>">Back to Results</a>
        </div>

        <h1>Motif Analysis: <?= htmlspecialchars($job['search_term']) ?></h1>
        
        <div class="summary-section">
            <h2>Analysis Summary</h2>
            <p><strong>Job ID:</strong> <?= $job_id ?></p>
            <p><strong>Taxonomic Group:</strong> <?= htmlspecialchars($job['taxon']) ?></p>
            <p><strong>Sequences Analyzed:</strong> <?= $_SESSION['motif_debug']['sequences_count'] ?? '0' ?></p>
            <p><strong>Total Motifs Found:</strong> <?= count($results ?? []) ?></p>
        </div>

        <?php if (!empty($results)): ?>
            <!-- Results display -->
        <?php else: ?>
            <div class="no-results">
                <h3>No Motifs Found</h3>
                
                <?php if (isset($_SESSION['motif_debug'])): ?>
                <div class="debug-info">
                    <h4>Detailed Debug Information</h4>
                    
                    <div class="file-info">
                        <h4>Sequence Files Processed (<?= count($_SESSION['motif_debug']['individual_files'] ?? []) ?>)</h4>
                        <ul>
                            <?php foreach ($_SESSION['motif_debug']['individual_files'] as $file): ?>
                                <li><?= htmlspecialchars($file['ncbi_id']) ?> (<?= $file['size'] ?> bytes)</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <div class="file-info">
                        <h4>Combined FASTA File</h4>
                        <p><strong>Size:</strong> <?= $_SESSION['motif_debug']['combined_file']['size'] ?? '0' ?> bytes</p>
                        <p><strong>Sample:</strong></p>
                        <pre><?= htmlspecialchars($_SESSION['motif_debug']['combined_file']['content_sample'] ?? 'No content') ?></pre>
                    </div>
                    
                    <div class="command-info">
                        <h4>Execution Details</h4>
                        <p><strong>Command:</strong> <code><?= htmlspecialchars($_SESSION['motif_debug']['command'] ?? 'Not executed') ?></code></p>
                        <p><strong>Return Code:</strong> <?= $_SESSION['motif_debug']['return_code'] ?? 'N/A' ?></p>
                        <p><strong>Output:</strong></p>
                        <pre><?= htmlspecialchars($_SESSION['motif_debug']['output'] ?? 'No output') ?></pre>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="troubleshooting">
                    <h4>Next Steps:</h4>
                    <ol>
                        <li>Verify each sequence file contains valid protein data</li>
                        <li>Check the combined FASTA format matches expected format</li>
                        <li>Test patmatmotifs manually with the sample sequences</li>
                        <li>Review server error logs for additional details</li>
                    </ol>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
// Clear debug info after displaying
unset($_SESSION['motif_debug']);
?>
