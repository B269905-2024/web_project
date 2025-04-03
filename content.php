<?php
session_start();

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];
$subset = isset($_GET['subset']) ? (int)$_GET['subset'] : null;

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

    // Check if we need to generate a report
    if (isset($_GET['generate_report'])) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="aa_content_report_' . $job_id . '.txt"');

        // Get sequences for this job
        $sql = "SELECT ncbi_id, description, sequence FROM sequences WHERE job_id = ?";
        if ($subset) {
            $sql .= " LIMIT ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$job_id, $subset]);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$job_id]);
        }
        $sequences = $stmt->fetchAll();

        // Basic report header
        echo "Amino Acid Content Analysis Report\n";
        echo "=================================\n\n";
        echo "Job ID: $job_id\n";
        echo "Search Term: " . $job['search_term'] . "\n";
        echo "Taxonomic Group: " . $job['taxon'] . "\n";
        echo "Date: " . date('Y-m-d H:i:s') . "\n";
        if ($subset) {
            echo "Subset: First $subset sequences\n";
        }
        echo "\n";

        $amino_acids = str_split('ACDEFGHIKLMNPQRSTVWY');

        // Generate report content
        foreach ($sequences as $seq) {
            $id = $seq['ncbi_id'];
            $sequence = strtoupper($seq['sequence']);
            $total = strlen($sequence);
            $counts = array_fill_keys($amino_acids, 0);

            foreach (str_split($sequence) as $aa) {
                if (isset($counts[$aa])) {
                    $counts[$aa]++;
                }
            }

            echo "Sequence: $id\n";
            echo "Description: " . $seq['description'] . "\n";
            echo "Length: $total amino acids\n";
            echo "Amino Acid Composition:\n";

            foreach ($counts as $aa => $count) {
                $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                echo sprintf("  %s: %6.2f%% (%d/%d)\n", $aa, $percentage, $count, $total);
            }

            echo str_repeat("-", 60) . "\n\n";
        }
        exit;
    }

    // Get sequences for analysis
    $sql = "SELECT ncbi_id, sequence FROM sequences WHERE job_id = ?";
    if ($subset) {
        $sql .= " LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$job_id, $subset]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$job_id]);
    }
    $sequences = $stmt->fetchAll();

    // Calculate amino acid percentages
    $aa_data = [];
    $amino_acids = str_split('ACDEFGHIKLMNPQRSTVWY');

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
    <title>Amino Acid Content Analysis</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        #sequenceSelector { width: 100%; padding: 10px; margin: 20px 0; }
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
        .action-buttons {
            margin: 20px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .action-btn {
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
        .action-btn:hover { background: #45a049; }
        .action-btn.secondary {
            background: #2196F3;
        }
        .action-btn.secondary:hover {
            background: #0b7dda;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="action-buttons">
            <a href="results.php?job_id=<?= $job_id ?>" class="action-btn secondary">Back to Results</a>
            <a href="content.php?job_id=<?= $job_id ?>&subset=<?= $subset ?>&generate_report=1" class="action-btn">Download TXT Report</a>
        </div>

        <h1>Amino Acid Content Analysis: <?= htmlspecialchars($job['search_term']) ?></h1>
        <p><strong>Job ID:</strong> <?= htmlspecialchars($job_id) ?></p>
        <p><strong>Taxonomic Group:</strong> <?= htmlspecialchars($job['taxon']) ?></p>

        <?php if ($subset): ?>
            <div class="subset-info">
                <strong>Using subset:</strong> First <?= $subset ?> sequences
            </div>
        <?php endif; ?>

        <select id="sequenceSelector">
            <?php foreach (array_keys($aa_data) as $id): ?>
                <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($id) ?></option>
            <?php endforeach; ?>
        </select>

        <div id="aaChart"></div>
    </div>

    <script>
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
