<?php
session_start();

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM sequences WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $sequences = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['download'])) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="protein_sequences_'.$job_id.'.fasta"');

        foreach ($sequences as $seq) {
            echo ">{$seq['ncbi_id']} {$seq['description']}\n";
            echo chunk_split($seq['sequence'], 80, "\n");
        }
        exit();
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Search Results</title>
    <style>
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
        .action-btn.analysis {
            background: #9c27b0;
        }
        .action-btn.analysis:hover {
            background: #7b1fa2;
        }
    </style>
</head>
<body>
    <div>
        <a href="home.php">New Search</a>
        <a href="past.php">Past Searches</a>
    </div>

    <div>
        <h2>Results for: <?php echo htmlspecialchars($job['search_term']); ?> in <?php echo htmlspecialchars($job['taxon']); ?></h2>
        <p>
            Searched on: <?php echo date('M j, Y g:i a', strtotime($job['created_at'])); ?> |
            Sequences found: <?php echo count($sequences); ?> |
            Max results requested: <?php echo $job['max_results']; ?>
        </p>
    </div>

    <div class="action-buttons">
        <a href="results.php?job_id=<?php echo $job_id; ?>&download=1" class="action-btn">Download FASTA</a>
        <a href="conservation.php?job_id=<?php echo $job_id; ?>" class="action-btn analysis">Run Conservation Analysis</a>
        <a href="past.php" class="action-btn secondary">Back to Past Searches</a>
    </div>

    <?php if (!empty($sequences)): ?>
        <?php foreach ($sequences as $seq): ?>
            <div>
                <div>><?php echo htmlspecialchars($seq['ncbi_id']); ?> <?php echo htmlspecialchars($seq['description']); ?></div>
                <div>Length: <?php echo strlen($seq['sequence']); ?> amino acids</div>
                <div><?php echo chunk_split($seq['sequence'], 80, "\n"); ?></div>
            </div>
        <?php endforeach; ?>

        <div class="action-buttons">
            <a href="results.php?job_id=<?php echo $job_id; ?>&download=1" class="action-btn">Download FASTA</a>
	    <a href="conservation.php?job_id=<?php echo $job_id; ?>" class="action-btn analysis">Run Conservation Analysis</a>
 	    <a href="motifs.php?job_id=<?php echo $job_id; ?>" class="action-btn motif">Run Motif Analysis</a>
            <a href="past.php" class="action-btn secondary">Back to Past Searches</a>
        </div>
    <?php else: ?>
        <p>No sequences were found for this search.</p>
    <?php endif; ?>
</body>
</html>
