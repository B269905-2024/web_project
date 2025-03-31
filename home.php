<?php
ob_start();
session_start();
require_once 'login.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proteinFamily = $_POST['protein_family'] ?? '';
    $taxonomicGroup = $_POST['taxonomic_group'] ?? '';

    setcookie('protein_family', $proteinFamily, time() + 3600, '/');
    setcookie('taxonomic_group', $taxonomicGroup, time() + 3600, '/');

    try {
        $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $jobId = uniqid();
        $query = "$proteinFamily AND $taxonomicGroup";
        $sequences = shell_exec("/home/s2713107/edirect/esearch -db protein -query \"$query\" | /home/s2713107/edirect/efetch -format fasta");
        
        $sequences = $sequences ?? '';
        $status = ($sequences === '') ? 'failed' : 'completed';

        $stmt = $pdo->prepare("INSERT INTO searches (job_id, protein_family, taxonomic_group, sequences, status, created_at, completed_at)
                              VALUES (:job_id, :protein_family, :taxonomic_group, :sequences, :status, NOW(), NOW())");
        $stmt->execute([
            ':job_id' => $jobId,
            ':protein_family' => $proteinFamily,
            ':taxonomic_group' => $taxonomicGroup,
            ':sequences' => $sequences,
            ':status' => $status
        ]);

        setcookie('job_id', $jobId, time() + 604800, '/');
        ob_end_clean();
        
        if ($sequences === '') {
            $_SESSION['error'] = "No sequences found for '$proteinFamily' in '$taxonomicGroup'. Please try different parameters.";
            header("Location: home.php");
            exit();
        } else {
            header("Location: results.php?job_id=$jobId");
            exit();
        }

    } catch (PDOException $e) {
        ob_end_clean();
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: home.php");
        exit();
    }
}

$savedProteinFamily = $_COOKIE['protein_family'] ?? '';
$savedTaxonomicGroup = $_COOKIE['taxonomic_group'] ?? '';
ob_end_flush();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Protein Sequence Search</title>
</head>
<body>
    <h1>Protein Sequence Search</h1>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    
    <form method="post" action="home.php">
        <div>
            <label for="protein_family">Protein Family:</label>
            <input type="text" id="protein_family" name="protein_family" 
                   value="<?= htmlspecialchars($savedProteinFamily) ?>" required>
        </div>
        <div>
            <label for="taxonomic_group">Taxonomic Group:</label>
            <input type="text" id="taxonomic_group" name="taxonomic_group" 
                   value="<?= htmlspecialchars($savedTaxonomicGroup) ?>" required>
        </div>
        <button type="submit">Search</button>
    </form>
    
    <div>
        <a href="past.php">View Past Jobs</a>
    </div>
    
    <div>
        <h3>Search Tips:</h3>
        <ul>
            <li>For protein families, try terms like "kinase", "hemoglobin", or "G protein-coupled receptor"</li>
            <li>For taxonomic groups, try "Homo sapiens", "Mammalia", or "Bacteria"</li>
            <li>Use square brackets for specific searches: "snake[Organism] AND neurotoxin"</li>
            <li>Broaden your taxonomic group if you get no results</li>
        </ul>
    </div>
</body>
</html>
