<?php
// Start session and buffer output to prevent header errors
ob_start();
session_start();
require_once 'login.php';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proteinFamily = $_POST['protein_family'] ?? '';
    $taxonomicGroup = $_POST['taxonomic_group'] ?? '';

    // Set cookies that expire in 1 hour (3600 seconds)
    setcookie('protein_family', $proteinFamily, time() + 3600, '/');
    setcookie('taxonomic_group', $taxonomicGroup, time() + 3600, '/');

    try {
        // Connect to database using credentials from login.php
        $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Generate a unique job ID
        $jobId = uniqid();

        // Construct query and fetch sequences
        $query = "$proteinFamily AND $taxonomicGroup";
        $sequences = shell_exec("/home/s2713107/edirect/esearch -db protein -query \"$query\" | /home/s2713107/edirect/efetch -format fasta");
        
        // Handle null or empty sequences
        $sequences = $sequences ?? ''; // Convert null to empty string
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

        // Set cookie for job_id that lasts 7 days
        setcookie('job_id', $jobId, time() + 604800, '/');

        // Clear output buffer before redirecting
        ob_end_clean();
        
        if ($sequences === '') {
            $_SESSION['error'] = "No sequences found for '$proteinFamily' in '$taxonomicGroup'. Please try different parameters.";
            header("Location: home.php");
            exit();
        } else {
            // Redirect to results page if sequences found
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

// Retrieve cookie values for form fields
$savedProteinFamily = $_COOKIE['protein_family'] ?? '';
$savedTaxonomicGroup = $_COOKIE['taxonomic_group'] ?? '';

// Clear output buffer before sending content
ob_end_flush();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Protein Sequence Search</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .error {
            color: red;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid red;
            border-radius: 4px;
            background-color: #ffeeee;
        }
        .success {
            color: green;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid green;
            border-radius: 4px;
            background-color: #eeffee;
        }
        .search-form {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button[type="submit"] {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button[type="submit"]:hover {
            background-color: #45a049;
        }
        .button-container {
            margin: 20px 0;
        }
        .past-jobs-btn {
            display: inline-block;
            padding: 10px 15px;
            background-color: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .past-jobs-btn:hover {
            background-color: #0b7dda;
        }
        .tips {
            margin-top: 30px;
            padding: 15px;
            background-color: #f0f8ff;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <h1>Protein Sequence Search</h1>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="success"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    
    <div class="search-form">
        <form method="post" action="home.php">
            <div class="form-group">
                <label for="protein_family">Protein Family:</label>
                <input type="text" id="protein_family" name="protein_family" 
                       value="<?= htmlspecialchars($savedProteinFamily) ?>" required>
            </div>
            <div class="form-group">
                <label for="taxonomic_group">Taxonomic Group:</label>
                <input type="text" id="taxonomic_group" name="taxonomic_group" 
                       value="<?= htmlspecialchars($savedTaxonomicGroup) ?>" required>
            </div>
            <button type="submit">Search</button>
        </form>
    </div>
    
    <div class="button-container">
        <a href="past.php" class="past-jobs-btn">View Past Jobs</a>
    </div>
    
    <div class="tips">
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
