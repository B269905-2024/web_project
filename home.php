<?php
session_start();

if (!isset($_COOKIE['protein_search_session'])) {
    $session_id = bin2hex(random_bytes(32));
    setcookie('protein_search_session', $session_id, time() + 86400 * 30, "/");
    $_COOKIE['protein_search_session'] = $session_id;
}

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE session_id = ?");
    $stmt->execute([$_COOKIE['protein_search_session']]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (session_id) VALUES (?)");
        $stmt->execute([$_COOKIE['protein_search_session']]);
        $user_id = $pdo->lastInsertId();
    } else {
        $user_id = $user['user_id'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $search_term = trim($_POST['search_term']);
        $taxon = trim($_POST['taxon']);
        $max_results = min((int)$_POST['max_results'], 100);

        $stmt = $pdo->prepare("INSERT INTO jobs (user_id, search_term, taxon, max_results, status) VALUES (?, ?, ?, ?, 'completed')");
        $stmt->execute([$user_id, $search_term, $taxon, $max_results]);
        $job_id = $pdo->lastInsertId();

        $search_term_encoded = urlencode($search_term);
        $taxon_encoded = urlencode($taxon);

        $search_url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=protein&term={$search_term_encoded}+AND+{$taxon_encoded}[Organism]&retmax={$max_results}&api_key={$ncbi_api_key}";
        $search_response = file_get_contents($search_url);

        preg_match_all('/<Id>([0-9]+)<\/Id>/', $search_response, $matches);
        $ids = $matches[1] ?? [];

        if (!empty($ids)) {
            $id_list = implode(',', $ids);
            $fetch_url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=protein&id={$id_list}&rettype=fasta&retmode=text&api_key={$ncbi_api_key}";
            $fasta_response = file_get_contents($fetch_url);

            $current_id = $current_desc = $current_seq = '';
            foreach (explode("\n", $fasta_response) as $line) {
                if (strpos($line, '>') === 0) {
                    if ($current_id !== '') {
                        $stmt = $pdo->prepare("INSERT INTO sequences (job_id, ncbi_id, description, sequence) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$job_id, $current_id, $current_desc, $current_seq]);
                    }
                    $header = substr($line, 1);
                    $header_parts = explode(' ', $header, 2);
                    $current_id = $header_parts[0];
                    $current_desc = $header_parts[1] ?? '';
                    $current_seq = '';
                } else {
                    $current_seq .= trim($line);
                }
            }
            if ($current_id !== '') {
                $stmt->execute([$job_id, $current_id, $current_desc, $current_seq]);
            }
        }

        header("Location: results.php?job_id=$job_id");
        exit();
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Protein Sequence Search</title>
    <style>
        .nav-links {
            margin-bottom: 20px;
        }
        .nav-links a {
            margin-right: 15px;
            text-decoration: none;
            color: #0366d6;
        }
        .nav-links a:hover {
            text-decoration: underline;
        }
        form {
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        form div {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }
        button[type="submit"] {
            background-color: #28a745;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button[type="submit"]:hover {
            background-color: #218838;
        }
        .example-searches {
            margin-top: 30px;
            padding: 15px;
            background-color: #f6f8fa;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="nav-links">
        <a href="home.php">New Search</a>
        <a href="past.php">Past Searches</a>
        <a href="example.php">Example Analysis</a>
        <a href="about.php">About</a>
        <a href="help.php">Help</a>
    </div>

    <h1>Protein Sequence Search</h1>

    <form method="post">
        <div>
            <label for="search_term">Protein or Gene Name:</label>
            <input type="text" id="search_term" name="search_term" required placeholder="e.g., glucose-6-phosphatase, ABC transporters">
        </div>

        <div>
            <label for="taxon">Taxonomic Group:</label>
            <input type="text" id="taxon" name="taxon" required placeholder="e.g., Aves, Mammalia, Rodentia">
        </div>

        <div>
            <label for="max_results">Maximum Results (1-100):</label>
            <input type="number" id="max_results" name="max_results" min="1" max="100" value="10" required>
        </div>

        <button type="submit">Search</button>
    </form>

    <div class="example-searches">
        <h3>Example Searches:</h3>
        <ul>
            <li>Glucose-6-phosphatase in Aves (birds)</li>
            <li>ABC transporters in Mammalia (mammals)</li>
            <li>Kinases in Rodentia (rodents)</li>
        </ul>
    </div>
</body>
</html>
