<?php
session_start();

if (!isset($_COOKIE['protein_search_session'])) {
    header("Location: home.php");
    exit();
}

$job_id = 90; // Fixed job ID for this example page

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Verify user owns this job
    $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch();

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    // Get basic job info
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $job_info = $stmt->fetch();

    // Get sequences
    $stmt = $pdo->prepare("SELECT * FROM sequences WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $sequences = $stmt->fetchAll();
    $sequence_count = count($sequences);

    // Get conservation analysis
    $stmt = $pdo->prepare("SELECT * FROM conservation_jobs WHERE job_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$job_id]);
    $conservation_job = $stmt->fetch();

    $conservation_results = [];
    $conservation_report = [];
    $conservation_alignments = [];
    
    if ($conservation_job) {
        $stmt = $pdo->prepare("SELECT position, entropy, plotcon_score FROM conservation_results WHERE conservation_id = ? ORDER BY position");
        $stmt->execute([$conservation_job['conservation_id']]);
        $conservation_results = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM conservation_reports WHERE conservation_id = ?");
        $stmt->execute([$conservation_job['conservation_id']]);
        $conservation_report = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT ncbi_id, sequence FROM conservation_alignments WHERE conservation_id = ?");
        $stmt->execute([$conservation_job['conservation_id']]);
        $conservation_alignments = $stmt->fetchAll();
    }

    // Get motif analysis
    $stmt = $pdo->prepare("SELECT * FROM motif_jobs WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $motif_job = $stmt->fetch();

    $motif_results = [];
    $motif_reports = [];
    
    if ($motif_job) {
        $stmt = $pdo->prepare("
            SELECT mr.*, s.ncbi_id as sequence 
            FROM motif_results mr 
            JOIN sequences s ON mr.sequence_id = s.sequence_id 
            WHERE mr.motif_id = ?
            ORDER BY mr.sequence_id, mr.start_pos
        ");
        $stmt->execute([$motif_job['motif_id']]);
        $motif_results = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM motif_reports WHERE motif_id = ?");
        $stmt->execute([$motif_job['motif_id']]);
        $motif_reports = $stmt->fetchAll();
    }

    // Prepare data for visualization
    $entropy_data = [];
    $plotcon_data = [];
    $aa_data = [];
    
    if ($conservation_results) {
        $entropy_data = [
            'data' => [[
                'y' => array_column($conservation_results, 'entropy'),
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
                    'x1' => count($conservation_results),
                    'y0' => $conservation_report['mean_entropy'],
                    'y1' => $conservation_report['mean_entropy'],
                    'line' => ['color' => 'red', 'dash' => 'dash']
                ]]
            ]
        ];

        if (!empty(array_column($conservation_results, 'plotcon_score'))) {
            $plotcon_data = [
                'data' => [[
                    'y' => array_column($conservation_results, 'plotcon_score'),
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
    }

    // Prepare amino acid content data
    $amino_acids = str_split('ACDEFGHIKLMNPQRSTVWY');
    $aa_data = [];
    
    foreach ($sequences as $seq) {
        $sequence = strtoupper($seq['sequence']);
        $total = strlen($sequence);
        $counts = array_fill_keys($amino_acids, 0);

        foreach (str_split($sequence) as $aa) {
            if (isset($counts[$aa])) {
                $counts[$aa]++;
            }
        }

        $percentages = [];
        foreach ($counts as $aa => $count) {
            $percentages[$aa] = $total > 0 ? ($count / $total) * 100 : 0;
        }

        $aa_data[$seq['ncbi_id']] = $percentages;
    }

    $aa_data_json = json_encode($aa_data);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Example Analysis Report - Job #<?= $job_id ?></title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background-color: #f5f5f5; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .section { margin-bottom: 30px; border: 1px solid #ddd; border-radius: 5px; padding: 20px; }
        .section-title { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-top: 0; }
        .plot-container { height: 400px; margin: 20px 0; }
        .sequence-viewer { font-family: monospace; background: #f8f9fa; padding: 15px; overflow-x: auto; white-space: pre; }
        .motif { background-color: #f0f7ff; padding: 10px; margin: 10px 0; border-left: 4px solid #3498db; }
        .highlight { background-color: #ffeb3b; padding: 2px; }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .btn:hover { background: #2980b9; }
        .btn-green { background: #2ecc71; }
        .btn-green:hover { background: #27ae60; }
        .btn-purple { background: #9b59b6; }
        .btn-purple:hover { background: #8e44ad; }
        .nav { margin-bottom: 20px; }
        .summary-card {
            background: white;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .insights { background-color: #e8f4f8; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="home.php" class="btn">New Search</a>
            <a href="past.php" class="btn">Past Searches</a>
            <a href="results.php?job_id=<?= $job_id ?>" class="btn">Back to Results</a>
        </div>

        <div class="header">
            <h1>Comprehensive Analysis Report</h1>
            <p><strong>Job ID:</strong> <?= $job_id ?> | <strong>Search Term:</strong> <?= htmlspecialchars($job_info['search_term']) ?> | 
               <strong>Taxon:</strong> <?= htmlspecialchars($job_info['taxon']) ?></p>
            <p><strong>Sequences:</strong> <?= $sequence_count ?> | <strong>Date:</strong> <?= date('M j, Y g:i a', strtotime($job_info['created_at'])) ?></p>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <h3>Conservation Analysis</h3>
                <?php if ($conservation_job): ?>
                    <p><strong>Status:</strong> <?= ucfirst($conservation_job['status']) ?></p>
                    <p><strong>Window Size:</strong> <?= $conservation_job['window_size'] ?></p>
                    <p><strong>Mean Entropy:</strong> <?= number_format($conservation_report['mean_entropy'] ?? 0, 3) ?> bits</p>
                    <a href="conservation.php?job_id=<?= $job_id ?>" class="btn btn-green">View Details</a>
                <?php else: ?>
                    <p>No conservation analysis performed</p>
                    <a href="conservation.php?job_id=<?= $job_id ?>" class="btn">Run Analysis</a>
                <?php endif; ?>
            </div>

            <div class="summary-card">
                <h3>Motif Analysis</h3>
                <?php if ($motif_job): ?>
                    <p><strong>Motifs Found:</strong> <?= count($motif_results) ?></p>
                    <p><strong>Unique Motifs:</strong> <?= count(array_unique(array_column($motif_results, 'motif_name'))) ?></p>
                    <a href="motifs.php?job_id=<?= $job_id ?>" class="btn btn-green">View Details</a>
                <?php else: ?>
                    <p>No motif analysis performed</p>
                    <a href="motifs.php?job_id=<?= $job_id ?>" class="btn">Run Analysis</a>
                <?php endif; ?>
            </div>

            <div class="summary-card">
                <h3>Amino Acid Content</h3>
                <p><strong>Sequences Analyzed:</strong> <?= $sequence_count ?></p>
                <a href="content.php?job_id=<?= $job_id ?>" class="btn btn-green">View Details</a>
            </div>
        </div>

        <?php if ($conservation_job): ?>
        <div class="section">
            <h2 class="section-title">Conservation Analysis</h2>
            
            <div class="plot-container" id="entropyPlot"></div>
            
            <?php if ($plotcon_data): ?>
                <div class="plot-container" id="plotconPlot"></div>
            <?php endif; ?>

            <div class="insights">
                <h3>Key Insights</h3>
                <?php if ($conservation_report): ?>
                    <pre><?= htmlspecialchars($conservation_report['report_text']) ?></pre>
                <?php else: ?>
                    <p>No detailed report available</p>
                <?php endif; ?>
            </div>

            <h3>Aligned Sequences</h3>
            <div class="sequence-viewer">
                <?php foreach ($conservation_alignments as $aln): ?>
                    ><?= htmlspecialchars($aln['ncbi_id']) ?><br>
                    <?= chunk_split($aln['sequence'], 80, "<br>") ?><br><br>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($motif_job): ?>
        <div class="section">
            <h2 class="section-title">Motif Analysis</h2>
            
            <p><strong>Total Motifs Found:</strong> <?= count($motif_results) ?></p>
            <p><strong>Unique Motif Types:</strong> <?= count(array_unique(array_column($motif_results, 'motif_name'))) ?></p>
            
            <h3>Motifs by Sequence</h3>
            <?php 
            $motifs_by_sequence = [];
            foreach ($motif_results as $motif) {
                $motifs_by_sequence[$motif['sequence']][] = $motif;
            }
            ?>
            
            <?php foreach ($motifs_by_sequence as $seq_id => $seq_motifs): ?>
                <div style="margin-bottom: 20px;">
                    <h4><?= htmlspecialchars($seq_id) ?> (<?= count($seq_motifs) ?> motifs)</h4>
                    
                    <?php 
                    $sequence = '';
                    foreach ($sequences as $seq) {
                        if ($seq['ncbi_id'] == $seq_id) {
                            $sequence = $seq['sequence'];
                            break;
                        }
                    }
                    ?>
                    
                    <?php foreach ($seq_motifs as $motif): ?>
                        <div class="motif">
                            <p><strong><?= htmlspecialchars($motif['motif_name']) ?></strong> 
                            (Positions: <?= $motif['start_pos'] ?>-<?= $motif['end_pos'] ?>)</p>
                            
                            <?php
                            $start = max(0, $motif['start_pos'] - 10);
                            $end = min(strlen($sequence), $motif['end_pos'] + 10);
                            $segment = substr($sequence, $start, $end - $start);
                            $highlight_start = $motif['start_pos'] - $start - 1;
                            $highlight_length = $motif['end_pos'] - $motif['start_pos'] + 1;
                            ?>
                            
                            <div style="font-family: monospace; margin: 5px 0;">
                                <?= substr($segment, 0, $highlight_start) ?>
                                <span class="highlight"><?= substr($segment, $highlight_start, $highlight_length) ?></span>
                                <?= substr($segment, $highlight_start + $highlight_length) ?>
                            </div>
                            <div style="font-family: monospace; color: #666;">
                                <?= str_repeat('&nbsp;', $highlight_start) ?>
                                <?= str_repeat('^', $highlight_length) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="section">
            <h2 class="section-title">Amino Acid Content Analysis</h2>
            
            <select id="sequenceSelector" style="width: 100%; padding: 10px; margin: 20px 0;">
                <?php foreach (array_keys($aa_data) as $id): ?>
                    <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($id) ?></option>
                <?php endforeach; ?>
            </select>
            
            <div class="plot-container" id="aaChart"></div>
        </div>
    </div>

    <script>
        // Conservation plots
        <?php if ($entropy_data): ?>
            var entropyData = <?= json_encode($entropy_data) ?>;
            Plotly.newPlot("entropyPlot", entropyData.data, entropyData.layout);
        <?php endif; ?>

        <?php if ($plotcon_data): ?>
            var plotconData = <?= json_encode($plotcon_data) ?>;
            Plotly.newPlot("plotconPlot", plotconData.data, plotconData.layout);
        <?php endif; ?>

        // Amino acid content chart
        const aaData = <?= $aa_data_json ?>;

        function updateChart(selectedId) {
            const data = [{
                x: Object.keys(aaData[selectedId]),
                y: Object.values(aaData[selectedId]),
                type: 'bar',
                marker: { color: '#007BFF' }
            }];

            const layout = {
                title: `Amino Acid Composition: ${selectedId}`,
                xaxis: { title: 'Amino Acid' },
                yaxis: { title: 'Percentage (%)' },
                hovermode: 'closest'
            };

            Plotly.react('aaChart', data, layout);
        }

        // Initial chart load
        const initialId = Object.keys(aaData)[0];
        updateChart(initialId);

        // Update chart on selection change
        document.getElementById('sequenceSelector').addEventListener('change', function(e) {
            updateChart(e.target.value);
        });
    </script>
</body>
</html>
