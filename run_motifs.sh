<?php
session_start();

// Enable maximum error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_errors.log');

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];

require_once 'config.php';

// Initialize debug information array
$_SESSION['motif_debug'] = [
    'job_id' => $job_id,
    'timing' => [
        'start' => microtime(true)
    ]
];

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $_SESSION['motif_debug']['database'] = 'Connected successfully';

    // Verify user owns this job
    $stmt = $pdo->prepare("SELECT j.*, u.user_id FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch();

    if (!$job) {
        $_SESSION['motif_debug']['error'] = 'Job not found or access denied';
        header("Location: home.php");
        exit();
    }

    $_SESSION['motif_debug']['job_info'] = [
        'search_term' => $job['search_term'],
        'taxon' => $job['taxon'],
        'max_results' => $job['max_results']
    ];

    // Check for existing motif job
    $stmt = $pdo->prepare("SELECT * FROM motif_jobs WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $motif_job = $stmt->fetch();

    if (!$motif_job) {
        $_SESSION['motif_debug']['status'] = 'Creating new motif analysis';
        
        $pdo->beginTransaction();
        
        try {
            $stmt = $pdo->prepare("INSERT INTO motif_jobs (job_id, created_at) VALUES (?, NOW())");
            $stmt->execute([$job_id]);
            $motif_id = $pdo->lastInsertId();
            
            $_SESSION['motif_debug']['motif_job_id'] = $motif_id;

            // Get sequences from database
            $stmt = $pdo->prepare("SELECT sequence_id, ncbi_id, sequence, LENGTH(sequence) as seq_length FROM sequences WHERE job_id = ?");
            $stmt->execute([$job_id]);
            $sequences = $stmt->fetchAll();
            $sequence_count = count($sequences);

            $_SESSION['motif_debug']['sequences'] = [
                'count' => $sequence_count,
                'lengths' => array_column($sequences, 'seq_length'),
                'sample_ids' => array_slice(array_column($sequences, 'ncbi_id'), 0, 5)
            ];

            if (empty($sequences)) {
                $_SESSION['motif_debug']['error'] = 'No sequences found for job';
                $pdo->rollBack();
                header("Location: results.php?job_id=$job_id");
                exit();
            }

            // Create FASTA content in memory
            $fasta_content = '';
            foreach ($sequences as $seq) {
                $fasta_content .= ">{$seq['sequence_id']}_{$seq['ncbi_id']}\n{$seq['sequence']}\n";
            }
            
            $_SESSION['motif_debug']['fasta_info'] = [
                'length' => strlen($fasta_content),
                'sample' => substr($fasta_content, 0, 200) . (strlen($fasta_content) > 200 ? '...' : '')
            ];

            // Prepare patmatmotifs command
            $command = 'patmatmotifs -auto -full Y -stdout';
            $_SESSION['motif_debug']['command'] = $command;
            $_SESSION['motif_debug']['timing']['pre_process'] = microtime(true);

            // Execute patmatmotifs
            $descriptors = [
                0 => ['pipe', 'r'], // stdin
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w']  // stderr
            ];

            $process = proc_open($command, $descriptors, $pipes);
            
            if (!is_resource($process)) {
                throw new Exception("Failed to start patmatmotifs process");
            }

            // Write FASTA content to stdin
            fwrite($pipes[0], $fasta_content);
            fclose($pipes[0]);

            // Read output
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $return_value = proc_close($process);
            $_SESSION['motif_debug']['timing']['post_process'] = microtime(true);
            
            $_SESSION['motif_debug']['process'] = [
                'return_value' => $return_value,
                'output_size' => strlen($output),
                'error_size' => strlen($errors),
                'execution_time' => $_SESSION['motif_debug']['timing']['post_process'] - $_SESSION['motif_debug']['timing']['pre_process']
            ];

            if ($return_value !== 0) {
                throw new Exception("patmatmotifs failed with return value $return_value");
            }

            if (empty($output)) {
                throw new Exception("patmatmotifs returned empty output");
            }

            // Parse results
            $current_seq = '';
            $motif_count = 0;
            $lines = explode("\n", $output);
            
            foreach ($lines as $line) {
                if (preg_match('/^Sequence: (\d+)_/', $line, $matches)) {
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
            
            $_SESSION['motif_debug']['results'] = [
                'motifs_found' => $motif_count,
                'sample_output' => substr($output, 0, 200) . (strlen($output) > 200 ? '...' : '')
            ];

            // Generate report
            $report_text = "Motif Analysis Report\n====================\n\n";
            $report_text .= "Job ID: $job_id\n";
            $report_text .= "Search Term: {$job['search_term']}\n";
            $report_text .= "Taxonomic Group: {$job['taxon']}\n";
            $report_text .= "Date: " . date('Y-m-d H:i:s') . "\n\n";
            $report_text .= "Sequences analyzed: $sequence_count\n";
            $report_text .= "Total motifs found: $motif_count\n";
            
            $stmt = $pdo->prepare("INSERT INTO motif_reports (motif_id, report_text) VALUES (?, ?)");
            $stmt->execute([$motif_id, $report_text]);
            
            $pdo->commit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['motif_debug']['error'] = $e->getMessage();
            $_SESSION['motif_debug']['process']['errors'] = $errors ?? 'None captured';
            error_log("Motif analysis failed: " . $e->getMessage());
        }
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

    // Get report
    $stmt = $pdo->prepare("SELECT * FROM motif_reports WHERE motif_id = (SELECT motif_id FROM motif_jobs WHERE job_id = ?)");
    $stmt->execute([$job_id]);
    $report = $stmt->fetch();

} catch (PDOException $e) {
    $_SESSION['motif_debug']['database_error'] = $e->getMessage();
    error_log("Database error in motifs.php: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motif Analysis Results</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
            background-color: #f9f9f9;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        h1, h2, h3 {
            color: #2c3e50;
        }
        h1 {
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .summary-section {
            background: #eef7ff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 5px solid #3498db;
        }
        .summary-row {
            display: flex;
            margin-bottom: 10px;
        }
        .summary-label {
            font-weight: bold;
            min-width: 200px;
            color: #2c3e50;
        }
        .motif-block {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #3498db;
        }
        .motif-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .motif-title {
            font-size: 1.2em;
            color: #2980b9;
            font-weight: 600;
        }
        .motif-details {
            color: #7f8c8d;
            font-size: 0.95em;
        }
        .download-btn {
            display: inline-block;
            background: #27ae60;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            transition: all 0.3s;
        }
        .download-btn:hover {
            background: #219653;
        }
        .no-results {
            background: #fdecea;
            color: #e74c3c;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .debug-panel {
            background: #f5f5f5;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        .debug-section {
            margin-bottom: 15px;
        }
        .debug-title {
            font-weight: bold;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        pre {
            background: #fff;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
            overflow-x: auto;
            max-height: 300px;
        }
        .nav-links {
            margin-bottom: 20px;
        }
        .nav-links a {
            margin-right: 15px;
            text-decoration: none;
            color: #3498db;
        }
        .sequence-block {
            margin-bottom: 40px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="home.php">New Search</a>
            <a href="past.php">Past Searches</a>
            <a href="results.php?job_id=<?= $job_id ?>">Back to Results</a>
        </div>

        <h1>Motif Analysis: <?= htmlspecialchars($job['search_term'] ?? 'Unknown') ?></h1>
        
        <div class="summary-section">
            <h2>Analysis Summary</h2>
            <div class="summary-row">
                <div class="summary-label">Job ID:</div>
                <div><?= $job_id ?></div>
            </div>
            <div class="summary-row">
                <div class="summary-label">Taxonomic Group:</div>
                <div><?= htmlspecialchars($job['taxon'] ?? 'Unknown') ?></div>
            </div>
            <div class="summary-row">
                <div class="summary-label">Sequences Analyzed:</div>
                <div><?= $_SESSION['motif_debug']['sequences']['count'] ?? '0' ?></div>
            </div>
            <div class="summary-row">
                <div class="summary-label">Total Motifs Found:</div>
                <div><?= count($results ?? []) ?></div>
            </div>
        </div>

        <?php if (!empty($results)): ?>
            <?php
            // Group results by sequence
            $grouped_results = [];
            foreach ($results as $result) {
                $grouped_results[$result['ncbi_id']][] = $result;
            }
            
            foreach ($grouped_results as $ncbi_id => $motifs): 
            ?>
                <div class="sequence-block">
                    <h2>Sequence: <?= htmlspecialchars($ncbi_id) ?></h2>
                    <p>Found <?= count($motifs) ?> motifs:</p>

                    <?php foreach ($motifs as $motif): ?>
                        <div class="motif-block">
                            <div class="motif-header">
                                <span class="motif-title"><?= htmlspecialchars($motif['motif_name']) ?></span>
                                <span class="motif-details">
                                    PROSITE: <?= $motif['motif_id_code'] ?> | 
                                    Positions: <?= $motif['start_pos'] ?>-<?= $motif['end_pos'] ?> | 
                                    Score: <?= number_format($motif['score'], 3) ?> | 
                                    P-value: <?= number_format($motif['p_value'], 4) ?>
                                </span>
                            </div>
                            <div>
                                <p><strong>Description:</strong> <?= htmlspecialchars($motif['description']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <a href="?job_id=<?= $job_id ?>&generate_report=1" class="download-btn">
                Download Full Report (TXT)
            </a>

        <?php else: ?>
            <div class="no-results">
                <h3>No Motifs Found</h3>
                <p>Possible reasons:</p>
                <ul>
                    <li>The sequences don't contain known PROSITE motifs</li>
                    <li>The sequences may be too short or divergent</li>
                    <li>There may have been an error in processing</li>
                </ul>
            </div>

            <div class="debug-panel">
                <h3>Diagnostic Information</h3>
                
                <div class="debug-section">
                    <div class="debug-title">Job Information</div>
                    <pre><?= json_encode($_SESSION['motif_debug']['job_info'] ?? 'Not available', JSON_PRETTY_PRINT) ?></pre>
                </div>

                <div class="debug-section">
                    <div class="debug-title">Sequence Information</div>
                    <pre>Count: <?= $_SESSION['motif_debug']['sequences']['count'] ?? '0' ?>
Sample IDs: <?= implode(', ', $_SESSION['motif_debug']['sequences']['sample_ids'] ?? []) ?>
Lengths: <?= implode(', ', $_SESSION['motif_debug']['sequences']['lengths'] ?? []) ?></pre>
                </div>

                <div class="debug-section">
                    <div class="debug-title">FASTA Input</div>
                    <pre><?= htmlspecialchars($_SESSION['motif_debug']['fasta_info']['sample'] ?? 'Not available') ?></pre>
                </div>

                <div class="debug-section">
                    <div class="debug-title">Process Execution</div>
                    <pre>Command: <?= htmlspecialchars($_SESSION['motif_debug']['command'] ?? 'Not recorded') ?>
Return value: <?= $_SESSION['motif_debug']['process']['return_value'] ?? 'Not recorded' ?>
Output size: <?= $_SESSION['motif_debug']['process']['output_size'] ?? '0' ?> bytes
Error size: <?= $_SESSION['motif_debug']['process']['error_size'] ?? '0' ?> bytes
Execution time: <?= round($_SESSION['motif_debug']['process']['execution_time'] ?? 0, 4) ?> seconds</pre>
                </div>

                <?php if (!empty($_SESSION['motif_debug']['error'])): ?>
                    <div class="debug-section">
                        <div class="debug-title">Error Details</div>
                        <pre><?= htmlspecialchars($_SESSION['motif_debug']['error']) ?></pre>
                    </div>
                <?php endif; ?>

                <?php if (!empty($_SESSION['motif_debug']['results']['sample_output'])): ?>
                    <div class="debug-section">
                        <div class="debug-title">Sample Output</div>
                        <pre><?= htmlspecialchars($_SESSION['motif_debug']['results']['sample_output']) ?></pre>
                    </div>
                <?php endif; ?>
            </div>

            <div class="insights">
                <h4>Next Steps for Troubleshooting:</h4>
                <ol>
                    <li>Verify your sequences contain valid protein data (not nucleotide)</li>
                    <li>Check that EMBOSS patmatmotifs is installed and in PATH</li>
                    <li>Test with a known motif-containing sequence (e.g., trypsin)</li>
                    <li>Check server error logs for complete details</li>
                    <li>Contact support with the diagnostic information above</li>
                </ol>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
// Clear debug info after displaying
unset($_SESSION['motif_debug']);
?>
