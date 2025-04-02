<?php
session_start();

if (!isset($_COOKIE['protein_search_session'])) {
    header("Location: home.php");
    exit();
}

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE session_id = ?");
    $stmt->execute([$_COOKIE['protein_search_session']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header("Location: home.php");
        exit();
    }
    
    $user_id = $user['user_id'];
    
    $stmt = $pdo->prepare("
        SELECT j.job_id, j.search_term, j.taxon, j.max_results, j.created_at, 
               COUNT(s.sequence_id) as sequence_count
        FROM jobs j
        LEFT JOIN sequences s ON j.job_id = s.job_id
        WHERE j.user_id = ?
        GROUP BY j.job_id
        ORDER BY j.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $past_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Past Protein Searches</title>
</head>
<body>
    <div>
        <a href="home.php">New Search</a>
        <a href="past.php">Past Searches</a>
    </div>
    
    <h1>Your Past Protein Searches</h1>
    
    <div>
        <?php if (empty($past_jobs)): ?>
            <p>You haven't performed any searches yet.</p>
        <?php else: ?>
            <?php foreach ($past_jobs as $job): ?>
                <div>
                    <div>
                        <?php echo htmlspecialchars($job['search_term']); ?> in <?php echo htmlspecialchars($job['taxon']); ?>
                    </div>
                    <div>
                        <span>Searched on: <?php echo date('M j, Y g:i a', strtotime($job['created_at'])); ?></span>
                        <span>Max results: <?php echo $job['max_results']; ?></span>
                        <span>Sequences found: <?php echo $job['sequence_count']; ?></span>
                    </div>
                    <a href="results.php?job_id=<?php echo $job['job_id']; ?>">View Results</a>
                    <form method="post" action="delete_job.php" style="display: inline;">
                        <input type="hidden" name="job_id" value="<?php echo $job['job_id']; ?>">
                        <button type="submit" onclick="return confirm('Are you sure you want to delete this search?')">Delete</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
