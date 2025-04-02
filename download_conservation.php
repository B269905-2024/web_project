<?php
session_start();

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['conservation_id']) || !is_numeric($_GET['conservation_id']) || !isset($_GET['type'])) {
    header("Location: home.php");
    exit();
}

$conservation_id = (int)$_GET['conservation_id'];
$download_type = $_GET['type'];

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verify user owns this conservation job
    $stmt = $pdo->prepare("SELECT c.* FROM conservation_jobs c 
                          JOIN jobs j ON c.job_id = j.job_id 
                          JOIN users u ON j.user_id = u.user_id 
                          WHERE c.conservation_id = ? AND u.session_id = ?");
    $stmt->execute([$conservation_id, $_COOKIE['protein_search_session']]);
    $conservation_job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conservation_job) {
        header("Location: home.php");
        exit();
    }

    // Get the job details for filename
    $stmt = $pdo->prepare("SELECT search_term, taxon FROM jobs WHERE job_id = ?");
    $stmt->execute([$conservation_job['job_id']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    $filename = "conservation_" . str_replace(' ', '_', $job['search_term']) . 
               "_" . str_replace(' ', '_', $job['taxon']) . 
               "_window{$conservation_job['window_size']}";

    switch ($download_type) {
        case 'entropy_json':
            $stmt = $pdo->prepare("SELECT position, entropy FROM conservation_results 
                                 WHERE conservation_id = ? ORDER BY position");
            $stmt->execute([$conservation_id]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '_entropy.json"');
            echo json_encode($results, JSON_PRETTY_PRINT);
            break;

        case 'entropy_csv':
            $stmt = $pdo->prepare("SELECT position, entropy FROM conservation_results 
                                 WHERE conservation_id = ? ORDER BY position");
            $stmt->execute([$conservation_id]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '_entropy.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Position', 'Entropy (bits)']);
            foreach ($results as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
            break;

        case 'plotcon_csv':
            $stmt = $pdo->prepare("SELECT position, plotcon_score FROM conservation_results 
                                 WHERE conservation_id = ? AND plotcon_score IS NOT NULL 
                                 ORDER BY position");
            $stmt->execute([$conservation_id]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($results)) {
                header("Location: conservation.php?job_id={$conservation_job['job_id']}");
                exit();
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '_plotcon.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Position', 'Conservation Score']);
            foreach ($results as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
            break;

        case 'alignment':
            $stmt = $pdo->prepare("SELECT ncbi_id, sequence FROM conservation_alignments 
                                 WHERE conservation_id = ?");
            $stmt->execute([$conservation_id]);
            $alignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="' . $filename . '_alignment.fasta"');
            
            foreach ($alignments as $aln) {
                echo ">{$aln['ncbi_id']}\n";
                echo chunk_split($aln['sequence'], 80, "\n");
            }
            break;

        case 'report':
            $stmt = $pdo->prepare("SELECT report_text FROM conservation_reports 
                                 WHERE conservation_id = ?");
            $stmt->execute([$conservation_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="' . $filename . '_report.txt"');
            echo $report['report_text'];
            break;

        default:
            header("Location: conservation.php?job_id={$conservation_job['job_id']}");
            break;
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
