<?php
// Set infinite session cookie at the very top
setcookie('protein_search_session', 'permanent_session', [
    'expires' => time() + (10 * 365 * 24 * 60 * 60), // 10 years (practically infinite)
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Configure session to last essentially forever
ini_set('session.gc_maxlifetime', 60*60*24*365); // 1 year
session_set_cookie_params(60*60*24*365); // 1 year
session_start();

$job_id = 90; // Fixed job ID for this example page

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Bypass normal session verification for this special case
    $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = 'permanent_session'");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch();

    if (!$job) {
        // Create the permanent session if it doesn't exist
        $pdo->beginTransaction();

        // Create permanent user if not exists
        $stmt = $pdo->prepare("INSERT IGNORE INTO users (session_id, created_at) VALUES ('permanent_session', NOW())");
        $stmt->execute();

        // Assign job to permanent user
        $stmt = $pdo->prepare("UPDATE jobs SET user_id = (SELECT user_id FROM users WHERE session_id = 'permanent_session') WHERE job_id = ?");
        $stmt->execute([$job_id]);

        $pdo->commit();

        // Refetch the job
        $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ?");
        $stmt->execute([$job_id]);
        $job = $stmt->fetch();
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
    <title>Permanent Analysis Report - Job #<?= $job_id ?></title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style>
        .scrollable-box {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            font-family: monospace;
            background: #f8f9fa;
            white-space: pre;
        }
        body { font-family: Arial, sans-serif; margin: 20px; }
        .nav { margin-bottom: 20px; }
        .nav a { margin-right: 10px; }
        .section { margin-bottom: 30px; }
        .plot-container { height: 400px; margin: 20px 0; }
        select { padding: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="nav">
        <a href="home.php">New Search</a>
        <a href="past.php">Past Searches</a>
        <a href="results.php?job_id=<?= $job_id ?>">Back to Results</a>
    </div>

    <div>
        <h1>Analysis Report - Job #<?= $job_id ?></h1>
        <p><strong>Search Term:</strong> <?= htmlspecialchars($job_info['search_term']) ?> | 
           <strong>Taxon:</strong> <?= htmlspecialchars($job_info['taxon']) ?></p>
        <p><strong>Sequences:</strong> <?= $sequence_count ?> | 
           <strong>Date:</strong> <?= date('M j, Y g:i a', strtotime($job_info['created_at'])) ?></p>
    </div>

    <div class="section">
        <h2>Conservation Analysis</h2>
        <?php if ($conservation_job): ?>
            <div class="plot-container" id="entropyPlot"></div>
            <?php if ($plotcon_data): ?>
                <div class="plot-container" id="plotconPlot"></div>
            <?php endif; ?>

            <h3>Aligned Sequences</h3>
            <div class="scrollable-box">
                <?php foreach ($conservation_alignments as $aln): ?>
                    ><?= htmlspecialchars($aln['ncbi_id']) ?><br>
                    <?= chunk_split($aln['sequence'], 80, "<br>") ?><br><br>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No conservation analysis performed</p>
        <?php endif; ?>
    </div>

    <?php if ($motif_job): ?>
    <div class="section">
        <h2>Motif Analysis</h2>
        <p><strong>Total Motifs Found:</strong> <?= count($motif_results) ?></p>
        
        <?php
        $motifs_by_sequence = [];
        foreach ($motif_results as $motif) {
            $motifs_by_sequence[$motif['sequence']][] = $motif;
        }
        ?>

        <?php foreach ($motifs_by_sequence as $seq_id => $seq_motifs): ?>
            <div>
                <h3><?= htmlspecialchars($seq_id) ?> (<?= count($seq_motifs) ?> motifs)</h3>
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
                    <div>
                        <p><strong><?= htmlspecialchars($motif['motif_name']) ?></strong>
                        (Positions: <?= $motif['start_pos'] ?>-<?= $motif['end_pos'] ?>)</p>
                        <?php
                        $start = max(0, $motif['start_pos'] - 10);
                        $end = min(strlen($sequence), $motif['end_pos'] + 10);
                        $segment = substr($sequence, $start, $end - $start);
                        $highlight_start = $motif['start_pos'] - $start - 1;
                        $highlight_length = $motif['end_pos'] - $motif['start_pos'] + 1;
                        ?>
                        <div style="font-family: monospace;">
                            <?= substr($segment, 0, $highlight_start) ?>
                            <span style="background-color: #ffeb3b;"><?= substr($segment, $highlight_start, $highlight_length) ?></span>
                            <?= substr($segment, $highlight_start + $highlight_length) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Amino Acid Content Analysis</h2>
        <select id="sequenceSelector">
            <?php foreach (array_keys($aa_data) as $id): ?>
                <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($id) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="plot-container" id="aaChart"></div>
    </div>

    <script>
        <?php if ($entropy_data): ?>
            var entropyData = <?= json_encode($entropy_data) ?>;
            Plotly.newPlot("entropyPlot", entropyData.data, entropyData.layout);
        <?php endif; ?>

        <?php if ($plotcon_data): ?>
            var plotconData = <?= json_encode($plotcon_data) ?>;
            Plotly.newPlot("plotconPlot", plotconData.data, plotconData.layout);
        <?php endif; ?>

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
                yaxis: { title: 'Percentage (%)' }
            };

            Plotly.react('aaChart', data, layout);
        }

        updateChart(document.getElementById('sequenceSelector').value);
        
        document.getElementById('sequenceSelector').addEventListener('change', function(e) {
            updateChart(e.target.value);
        });
    </script>
</body>
</html>
