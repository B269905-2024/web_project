<?php
require_once 'login.php';

if (!isset($_GET['job_id'])) {
    die("No job ID provided.");
}

$job_id = $_GET['job_id'];
$is_subset = isset($_GET['subset']) ? 1 : 0;

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get job details and sequences
    $stmt = $pdo->prepare("SELECT protein_family, taxonomic_group, 
                          COALESCE(subset_sequences, sequences) AS sequences
                          FROM searches WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $row = $stmt->fetch();
    
    if (!$row) {
        die("No job found.");
    }

    // Check if content analysis already exists
    $analysis_stmt = $pdo->prepare("SELECT * FROM content_analyses 
                                   WHERE job_id = ? AND is_subset = ?");
    $analysis_stmt->execute([$job_id, $is_subset]);
    $analysis = $analysis_stmt->fetch();
    
    $amino_acids = str_split('ACDEFGHIKLMNPQRSTVWY'); // Standard amino acids
    
    if (!$analysis) {
        // Parse sequences from database content
        $fasta_content = $row['sequences'];
        $sequences = [];
        $current_id = '';
        $current_seq = '';
        
        $lines = explode("\n", $fasta_content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '>')) {
                if ($current_id !== '') {
                    $sequences[$current_id] = $current_seq;
                }
                $current_id = substr($line, 1); // Remove '>'
                $current_seq = '';
            } else {
                $current_seq .= strtoupper($line);
            }
        }
        if ($current_id !== '') {
            $sequences[$current_id] = $current_seq;
        }
        
        // Calculate amino acid percentages
        $aa_data = [];
        $analysis_data = [];
        
        foreach ($sequences as $id => $seq) {
            $total = strlen($seq);
            $counts = array_fill_keys($amino_acids, 0);
            
            foreach (str_split($seq) as $aa) {
                if (isset($counts[$aa])) {
                    $counts[$aa]++;
                }
            }
            
            $percentages = [];
            foreach ($counts as $aa => $count) {
                $percentages[$aa] = $total > 0 ? ($count / $total) * 100 : 0;
            }
            
            $aa_data[$id] = $percentages;
            $analysis_data[] = [
                'sequence_id' => $id,
                'sequence_length' => $total,
                'amino_acid_counts' => json_encode($counts),
                'amino_acid_percentages' => json_encode($percentages)
            ];
        }
        
        // Store analysis in database
        $insert_stmt = $pdo->prepare("INSERT INTO content_analyses 
                                    (job_id, is_subset, sequences_analyzed, analysis_data, created_at)
                                    VALUES (?, ?, ?, ?, NOW())");
        $insert_stmt->execute([
            $job_id, 
            $is_subset,
            count($sequences),
            json_encode($analysis_data)
        ]);
        
        $analysis_id = $pdo->lastInsertId();
        
        // Store individual sequence results
        foreach ($analysis_data as $data) {
            $seq_stmt = $pdo->prepare("INSERT INTO content_results
                                     (analysis_id, job_id, sequence_id, sequence_length, 
                                      amino_acid_counts, amino_acid_percentages)
                                     VALUES (?, ?, ?, ?, ?, ?)");
            $seq_stmt->execute([
                $analysis_id,
                $job_id,
                $data['sequence_id'],
                $data['sequence_length'],
                $data['amino_acid_counts'],
                $data['amino_acid_percentages']
            ]);
        }
        
        // Refresh analysis data
        $analysis_stmt->execute([$job_id, $is_subset]);
        $analysis = $analysis_stmt->fetch();
    }
    
    // Handle report generation
    if (isset($_GET['generate_report'])) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="aa_content_report_' . $job_id . '.txt"');
        
        echo "Amino Acid Content Analysis Report\n";
        echo "=================================\n\n";
        echo "Job ID: $job_id\n";
        echo "Protein Family: " . $row['protein_family'] . "\n";
        echo "Taxonomic Group: " . $row['taxonomic_group'] . "\n";
        echo "Date: " . date('Y-m-d H:i:s') . "\n";
        if ($is_subset) {
            echo "Subset: First " . ($_GET['subset'] ?? '?') . " sequences\n";
        }
        echo "\n";
        
        // Get all sequence results for this analysis
        $results_stmt = $pdo->prepare("SELECT * FROM content_results 
                                      WHERE job_id = ? AND analysis_id = ?
                                      ORDER BY sequence_id");
        $results_stmt->execute([$job_id, $analysis['id']]);
        $results = $results_stmt->fetchAll();
        
        if (!empty($results)) {
            foreach ($results as $result) {
                $counts = json_decode($result['amino_acid_counts'], true);
                $percentages = json_decode($result['amino_acid_percentages'], true);
                
                echo "Sequence: " . $result['sequence_id'] . "\n";
                echo "Length: " . $result['sequence_length'] . " amino acids\n";
                echo "Amino Acid Composition:\n";
                
                foreach ($amino_acids as $aa) {
                    $percentage = $percentages[$aa] ?? 0;
                    $count = $counts[$aa] ?? 0;
                    echo sprintf("  %s: %6.2f%% (%d/%d)\n", $aa, $percentage, $count, $result['sequence_length']);
                }
                
                echo str_repeat("-", 60) . "\n\n";
            }
        } else {
            echo "No amino acid content results were found for this job.\n";
        }
        exit;
    }
    
    // Prepare data for visualization
    $results_stmt = $pdo->prepare("SELECT sequence_id, amino_acid_percentages 
                                  FROM content_results 
                                  WHERE job_id = ? AND analysis_id = ?
                                  ORDER BY sequence_id");
    $results_stmt->execute([$job_id, $analysis['id']]);
    $results = $results_stmt->fetchAll();
    
    $aa_data = [];
    $sequence_ids = [];
    foreach ($results as $result) {
        $aa_data[$result['sequence_id']] = json_decode($result['amino_acid_percentages'], true);
        $sequence_ids[] = $result['sequence_id'];
    }
    
    $aa_data_json = json_encode($aa_data);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Database schema needed:
/*
CREATE TABLE content_analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(255) NOT NULL,
    is_subset BOOLEAN NOT NULL,
    sequences_analyzed INT NOT NULL,
    analysis_data LONGTEXT,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (job_id) REFERENCES searches(job_id)
);

CREATE TABLE content_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_id INT NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    sequence_id VARCHAR(255) NOT NULL,
    sequence_length INT NOT NULL,
    amino_acid_counts JSON,
    amino_acid_percentages JSON,
    FOREIGN KEY (analysis_id) REFERENCES content_analyses(id),
    FOREIGN KEY (job_id) REFERENCES searches(job_id)
);
*/
?>

<!DOCTYPE html>
<html>
<head>
    <title>Amino Acid Content Analysis</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        #sequenceSelector { width: 100%; padding: 10px; margin: 20px 0; font-size: 16px; }
        #aaChart { width: 100%; height: 600px; }
        .subset-info { background: #e8f5e9; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .download-btn {
            display: inline-block;
            background: #27ae60;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            border: none;
            cursor: pointer;
            font-size: 1em;
        }
        .download-btn:hover {
            background: #219653;
        }
        .analysis-info {
            background: #f0f8ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Amino Acid Content Analysis: <?= htmlspecialchars($row['protein_family']) ?></h1>
        
        <div class="analysis-info">
            <p><strong>Job ID:</strong> <?= htmlspecialchars($job_id) ?></p>
            <p><strong>Taxonomic Group:</strong> <?= htmlspecialchars($row['taxonomic_group']) ?></p>
            <p><strong>Analysis Date:</strong> <?= $analysis['created_at'] ?></p>
            <p><strong>Sequences Analyzed:</strong> <?= $analysis['sequences_analyzed'] ?></p>
        </div>

        <?php if ($is_subset): ?>
            <div class="subset-info">
                <strong>Using subset:</strong> First <?= htmlspecialchars($_GET['subset'] ?? '?') ?> sequences
            </div>
        <?php endif; ?>

        <?php if (!empty($sequence_ids)): ?>
            <select id="sequenceSelector">
                <?php foreach ($sequence_ids as $id): ?>
                    <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($id) ?></option>
                <?php endforeach; ?>
            </select>

            <div id="aaChart"></div>

            <a href="?job_id=<?= $job_id ?>&subset=<?= $is_subset ? $_GET['subset'] : '' ?>&generate_report=1" class="download-btn">
                Generate TXT Report
            </a>
        <?php else: ?>
            <div style="background: #fdecea; padding: 20px; border-radius: 5px;">
                <p>No amino acid content results were found for this analysis.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const aaData = <?= $aa_data_json ?>;
        const aminoAcids = <?= json_encode($amino_acids) ?>;

        function updateChart(selectedId) {
            const percentages = aaData[selectedId];
            const data = [{
                x: aminoAcids,
                y: aminoAcids.map(aa => percentages[aa]),
                type: 'bar',
                marker: { color: '#007BFF' }
            }];

            const layout = {
                title: `Amino Acid Composition: ${selectedId}`,
                xaxis: { title: 'Amino Acid' },
                yaxis: { 
                    title: 'Percentage (%)',
                    range: [0, 100] // Fixed scale for comparison
                },
                hovermode: 'closest'
            };

            Plotly.react('aaChart', data, layout);
        }

        // Initial chart load
        if (Object.keys(aaData).length > 0) {
            const initialId = Object.keys(aaData)[0];
            updateChart(initialId);

            // Update chart on selection change
            document.getElementById('sequenceSelector').addEventListener('change', function(e) {
                updateChart(e.target.value);
            });
        }
    </script>
</body>
</html>
