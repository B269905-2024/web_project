<?php
session_start();
require_once 'login.php';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proteinFamily = $_POST['protein_family'] ?? '';
    $taxonomicGroup = $_POST['taxonomic_group'] ?? '';
    
    // Set cookies that expire in 1 hour
    setcookie('protein_family', $proteinFamily, time() + 3600);
    setcookie('taxonomic_group', $taxonomicGroup, time() + 3600);
    
    try {
        // Connect to database using credentials from login.php
        $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Generate a unique job ID
        $jobId = uniqid();
        
        // Construct query and fetch sequences immediately
        $query = "$proteinFamily AND $taxonomicGroup";
        $sequences = shell_exec("/home/s2713107/edirect/esearch -db protein -query \"$query\" | /home/s2713107/edirect/efetch -format fasta");
        
        // Store the search results in the database
        $status = empty($sequences) ? 'failed' : 'completed';
        
        $stmt = $pdo->prepare("INSERT INTO searches (job_id, protein_family, taxonomic_group, sequences, status, created_at, completed_at) 
                              VALUES (:job_id, :protein_family, :taxonomic_group, :sequences, :status, NOW(), NOW())");
        $stmt->execute([
            ':job_id' => $jobId,
            ':protein_family' => $proteinFamily,
            ':taxonomic_group' => $taxonomicGroup,
            ':sequences' => $sequences,
            ':status' => $status
        ]);
        
        // Set cookie for job_id
        setcookie('job_id', $jobId, time() + 3600);
        
        // Redirect to results page
        header("Location: results.php?job_id=$jobId");
        exit();
        
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Protein Sequence Search</title>
</head>
<body>
    <h1>Protein Sequence Search</h1>
    <form method="post" action="home.php">
        <div>
            <label for="protein_family">Protein Family:</label>
            <input type="text" id="protein_family" name="protein_family" required>
        </div>
        <div>
            <label for="taxonomic_group">Taxonomic Group:</label>
            <input type="text" id="taxonomic_group" name="taxonomic_group" required>
        </div>
        <button type="submit">Search</button>
    </form>
</body>
</html>
