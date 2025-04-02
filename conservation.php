<?php
session_start();

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];
$window_size = isset($_GET['window_size']) ? max(1, min(20, (int)$_GET['window_size'])) : 4;

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verify user owns this job
    $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    // Check for existing conservation analysis
    $stmt = $pdo->prepare("SELECT * FROM conservation_jobs WHERE job_id = ? AND window_size = ?");
    $stmt->execute([$job_id, $window_size]);
    $conservation_job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conservation_job) {
        // Create new conservation job
        $stmt = $pdo->prepare("INSERT INTO conservation_jobs (job_id, window_size, status) VALUES (?, ?, 'running')");
        $stmt->execute([$job_id, $window_size]);
        $conservation_id = $pdo->lastInsertId();

        // Get sequences from database
        $stmt = $pdo->prepare("SELECT ncbi_id, sequence FROM sequences WHERE job_id = ?");
        $stmt->execute([$job_id]);
        $sequences = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sequences)) {
            $stmt = $pdo->prepare("UPDATE conservation_jobs SET status = 'failed' WHERE conservation_id = ?");
            $stmt->execute([$conservation_id]);
            
            $report_text = "Conservation Analysis Report\n===========================\n\n";
            $report_text .= "Job ID: $job_id\n";
            $report_text .= "Conservation ID: $conservation_id\n";
            $report_text .= "Window size: $window_size\n\n";
            $report_text .= "ERROR: No sequences found for this job\n";
            
            $stmt = $pdo->prepare("INSERT INTO conservation_reports (conservation_id, report_text) VALUES (?, ?)");
            $stmt->execute([$conservation_id, $report_text]);
            
            header("Location: results.php?job_id=$job_id");
            exit();
        }

        // Perform alignment and analysis
        perform_conservation_analysis($pdo, $conservation_id, $job_id, $window_size, $sequences);

        // Refresh the conservation job data
        $stmt = $pdo->prepare("SELECT * FROM conservation_jobs WHERE conservation_id = ?");
        $stmt->execute([$conservation_id]);
        $conservation_job = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get all analysis data
    $stmt = $pdo->prepare("SELECT position, entropy, plotcon_score FROM conservation_results WHERE conservation_id = ? ORDER BY position");
    $stmt->execute([$conservation_job['conservation_id']]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM conservation_reports WHERE conservation_id = ?");
    $stmt->execute([$conservation_job['conservation_id']]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT ncbi_id, sequence FROM conservation_alignments WHERE conservation_id = ?");
    $stmt->execute([$conservation_job['conservation_id']]);
    $alignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

/**
 * Performs the conservation analysis and stores results in the database
 */
function perform_conservation_analysis($pdo, $conservation_id, $job_id, $window_size, $sequences) {
    $report_text = "Conservation Analysis Report\n===========================\n\n";
    $report_text .= "Job ID: $job_id\n";
    $report_text .= "Conservation ID: $conservation_id\n";
    $report_text .= "Window size: $window_size\n\n";
    
    $num_sequences = count($sequences);
    $report_text .= "Number of sequences: $num_sequences\n";
    
    try {
        // Step 1: Perform alignment (simplified example - in reality you'd use a proper alignment library)
        $alignment = perform_alignment($sequences);
        $alignment_length = strlen($alignment[0]['aligned_sequence']);
        
        // Store aligned sequences
        foreach ($alignment as $aligned_seq) {
            $stmt = $pdo->prepare("INSERT INTO conservation_alignments (conservation_id, ncbi_id, sequence) VALUES (?, ?, ?)");
            $stmt->execute([$conservation_id, $aligned_seq['ncbi_id'], $aligned_seq['aligned_sequence']]);
        }
        
        $report_text .= "\nAlignment completed successfully. Alignment length: $alignment_length residues\n";
        
        // Step 2: Calculate Shannon entropy
        $entropy_results = calculate_entropy($alignment);
        
        // Store entropy results
        foreach ($entropy_results as $position => $entropy) {
            $stmt = $pdo->prepare("INSERT INTO conservation_results (conservation_id, position, entropy) VALUES (?, ?, ?)");
            $stmt->execute([$conservation_id, $position + 1, $entropy]);
        }
        
        // Step 3: Calculate Plotcon scores (simplified)
        $plotcon_scores = calculate_plotcon_scores($entropy_results, $window_size);
        
        // Store plotcon results
        foreach ($plotcon_scores as $position => $score) {
            $stmt = $pdo->prepare("UPDATE conservation_results SET plotcon_score = ? WHERE conservation_id = ? AND position = ?");
            $stmt->execute([$score, $conservation_id, $position + 1]);
        }
        
        // Calculate statistics
        $mean_entropy = array_sum($entropy_results) / count($entropy_results);
        $max_entropy = max($entropy_results);
        $min_entropy = min($entropy_results);
        $max_pos = array_search($max_entropy, $entropy_results) + 1;
        $min_pos = array_search($min_entropy, $entropy_results) + 1;
        
        // Generate report
        $report_text .= "\n=== Shannon Entropy Results ===\n";
        $report_text .= "Alignment length: $alignment_length residues\n";
        $report_text .= sprintf("Mean entropy: %.3f bits\n", $mean_entropy);
        $report_text .= sprintf("Max entropy: %.3f bits (position %d)\n", $max_entropy, $max_pos);
        $report_text .= sprintf("Min entropy: %.3f bits (position %d)\n", $min_entropy, $min_pos);
        
        // Top conserved/variable positions
        asort($entropy_results);
        $sorted_positions = array_slice($entropy_results, 0, 5, true);
        
        $report_text .= "\nTop 5 most conserved positions:\n";
        foreach ($sorted_positions as $pos => $ent) {
            $report_text .= sprintf("Position %d: %.3f bits\n", $pos + 1, $ent);
        }
        
        arsort($entropy_results);
        $sorted_positions = array_slice($entropy_results, 0, 5, true);
        
        $report_text .= "\nTop 5 most variable positions:\n";
        foreach ($sorted_positions as $pos => $ent) {
            $report_text .= sprintf("Position %d: %.3f bits\n", $pos + 1, $ent);
        }
        
        $report_text .= "\nAnalysis completed successfully.\n";
        
        // Store report
        $stmt = $pdo->prepare("INSERT INTO conservation_reports 
            (conservation_id, report_text, mean_entropy, max_entropy, min_entropy, max_position, min_position)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $conservation_id, 
            $report_text, 
            $mean_entropy, 
            $max_entropy, 
            $min_entropy, 
            $max_pos, 
            $min_pos
        ]);
        
        // Update job status
        $stmt = $pdo->prepare("UPDATE conservation_jobs SET status = 'completed' WHERE conservation_id = ?");
        $stmt->execute([$conservation_id]);
        
    } catch (Exception $e) {
        // On error, update status and store error message
        $stmt = $pdo->prepare("UPDATE conservation_jobs SET status = 'failed' WHERE conservation_id = ?");
        $stmt->execute([$conservation_id]);
        
        $report_text .= "\nERROR: " . $e->getMessage() . "\n";
        
        $stmt = $pdo->prepare("INSERT INTO conservation_reports (conservation_id, report_text) VALUES (?, ?)");
        $stmt->execute([$conservation_id, $report_text]);
    }
}

/**
 * Simplified alignment function - in a real implementation you would use a proper alignment library
 */
function perform_alignment($sequences) {
    // This is a simplified example - in reality you would use a proper alignment algorithm
    $aligned_sequences = [];
    $max_length = 0;
    
    foreach ($sequences as $seq) {
        $max_length = max($max_length, strlen($seq['sequence']));
    }
    
    foreach ($sequences as $seq) {
        $aligned_sequences[] = [
            'ncbi_id' => $seq['ncbi_id'],
            'aligned_sequence' => str_pad($seq['sequence'], $max_length, '-')
        ];
    }
    
    return $aligned_sequences;
}

/**
 * Calculate Shannon entropy for each position in the alignment
 */
function calculate_entropy($alignment) {
    $entropy_results = [];
    $alignment_length = strlen($alignment[0]['aligned_sequence']);
    $num_sequences = count($alignment);
    
    for ($i = 0; $i < $alignment_length; $i++) {
        $column = '';
        foreach ($alignment as $seq) {
            $column .= $seq['aligned_sequence'][$i];
        }
        
        // Count amino acids (ignore gaps)
        $column = str_replace('-', '', $column);
        $counts = count_chars($column, 1);
        $total = strlen($column);
        
        $entropy = 0;
        if ($total > 0) {
            foreach ($counts as $count) {
                $p = $count / $total;
                $entropy -= $p * log($p, 2);
            }
        }
        
        $entropy_results[$i] = $entropy;
    }
    
    return $entropy_results;
}

/**
 * Simplified Plotcon score calculation
 */
function calculate_plotcon_scores($entropy_results, $window_size) {
    $plotcon_scores = [];
    $count = count($entropy_results);
    
    for ($i = 0; $i < $count; $i++) {
        $start = max(0, $i - floor($window_size / 2));
        $end = min($count - 1, $i + floor($window_size / 2));
        
        $sum = 0;
        $window_count = 0;
        for ($j = $start; $j <= $end; $j++) {
            $sum += $entropy_results[$j];
            $window_count++;
        }
        
        // Convert entropy to conservation score (higher = more conserved)
        $plotcon_scores[$i] = $window_count > 0 ? (1 - ($sum / $window_count)) : 0;
    }
    
    return $plotcon_scores;
}

// Prepare entropy data for JSON
$entropy_data = [
    'data' => [[
        'y' => array_column($results, 'entropy'),
        'type' => 'line',
        'name' => 'Entropy',
        'line' => ['color' => 'blue']
    ]],
    'layout' => [
        'title' => 'Shannon Entropy Analysis',
        'xaxis' => ['title' => 'Position'],
        'yaxis' => ['title' => 'Entropy (bits)'],
        'shapes' => [[
            'type' => 'line',
            'x0' => 0,
            'x1' => count($results),
            'y0' => $report['mean_entropy'],
            'y1' => $report['mean_entropy'],
            'line' => ['color' => 'red', 'dash' => 'dash']
        ]]
    ]
];

// Prepare plotcon data if available
$plotcon_data = null;
if (!empty(array_column($results, 'plotcon_score'))) {
    $plotcon_data = [
        'data' => [[
            'y' => array_column($results, 'plotcon_score'),
            'type' => 'line',
            'name' => 'Conservation Score',
            'line' => ['color' => 'green']
        ]],
        'layout' => [
            'title' => 'Plotcon Conservation Analysis',
            'xaxis' => ['title' => 'Position'],
            'yaxis' => ['title' => 'Conservation Score']
        ]
    ];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Conservation Analysis</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style>
        .analysis-container { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 30px; }
        .visualization { flex: 1; min-width: 400px; border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
        .sequence-viewer { height: 500px; overflow-y: auto; font-family: monospace; background: #f5f5f5; padding: 10px; white-space: pre; }
        .insights { background-color: #f8f9fa; padding: 15px; border-left: 4px solid #6c757d; margin: 20px 0; }
        .plot-container { height: 400px; }
        .download-buttons { margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap; }
        .download-btn {
            padding: 8px 12px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
        }
        .download-btn:hover { background: #45a049; }
        .download-btn.secondary {
            background: #2196F3;
        }
        .download-btn.secondary:hover {
            background: #0b7dda;
        }
    </style>
</head>
<body>
    <div>
        <a href="home.php">New Search</a>
        <a href="past.php">Past Searches</a>
    </div>

    <h1>Conservation Analysis for <?php echo htmlspecialchars($job['search_term']); ?></h1>
    <p><strong>Taxonomic Group:</strong> <?php echo htmlspecialchars($job['taxon']); ?></p>
    <p><strong>Window Size:</strong> <?php echo $window_size; ?></p>

    <div class="analysis-container">
        <div class="visualization">
            <h2>Shannon Entropy Analysis</h2>
            <div class="plot-container" id="entropyPlot"></div>
            <div class="download-buttons">
                <a href="download_conservation.php?type=entropy_json&conservation_id=<?php echo $conservation_job['conservation_id']; ?>" class="download-btn">
                    Download JSON
                </a>
                <a href="download_conservation.php?type=entropy_csv&conservation_id=<?php echo $conservation_job['conservation_id']; ?>" class="download-btn secondary">
                    Download CSV
                </a>
            </div>
            <script>
                var entropyData = <?php echo json_encode($entropy_data); ?>;
                Plotly.newPlot("entropyPlot", entropyData.data, entropyData.layout);
            </script>
        </div>

        <div class="visualization">
            <h2>Aligned Sequences</h2>
            <div class="sequence-viewer">
                <?php foreach ($alignments as $aln): ?>
                    ><?php echo htmlspecialchars($aln['ncbi_id']); ?><br>
                    <?php echo chunk_split($aln['sequence'], 80, "<br>"); ?><br><br>
                <?php endforeach; ?>
            </div>
            <div class="download-buttons">
                <a href="download_conservation.php?type=alignment&conservation_id=<?php echo $conservation_job['conservation_id']; ?>" class="download-btn">
                    Download Alignment
                </a>
            </div>
        </div>
    </div>

    <?php if ($plotcon_data): ?>
        <div class="visualization">
            <h2>EMBOSS Plotcon Analysis</h2>
            <div class="plot-container" id="plotconPlot"></div>
            <script>
                var plotconData = <?php echo json_encode($plotcon_data); ?>;
                Plotly.newPlot("plotconPlot", plotconData.data, plotconData.layout);
            </script>
            <div class="download-buttons">
                <a href="download_conservation.php?type=plotcon_csv&conservation_id=<?php echo $conservation_job['conservation_id']; ?>" class="download-btn">
                    Download Plotcon Data (CSV)
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="insights">
        <h3>Analysis Report</h3>
        <pre><?php echo htmlspecialchars($report['report_text']); ?></pre>

        <h3>Interpretation Guide</h3>
        <p><strong>Shannon Entropy:</strong></p>
        <ul>
            <li>0 = Perfectly conserved position</li>
            <li>Higher values = More diversity at that position</li>
            <li>Look for conserved regions (low values) that may indicate functional importance</li>
        </ul>
        <p><strong>Plotcon:</strong></p>
        <ul>
            <li>Shows smoothed conservation over <?php echo $window_size; ?>-residue windows</li>
            <li>High scores = Conserved regions</li>
            <li>Low scores = Variable regions</li>
        </ul>
    </div>
</body>
</html>
