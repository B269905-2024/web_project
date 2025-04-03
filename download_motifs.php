<?php
// Start session only if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enhanced error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_motifs_error.log');

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];
$sequence_id = isset($_GET['sequence_id']) ? (int)$_GET['sequence_id'] : null;

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Verify user owns this job
    $stmt = $pdo->prepare("SELECT j.*, u.user_id FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch();

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    // Get motif job
    $stmt = $pdo->prepare("SELECT * FROM motif_jobs WHERE job_id = ?");
    $stmt->execute([$job_id]);
    $motif_job = $stmt->fetch();

    if (!$motif_job) {
        $_SESSION['error'] = "No motif analysis found for this job";
        header("Location: motifs.php?job_id=$job_id");
        exit();
    }

    if ($sequence_id) {
        // Download single sequence report
        $stmt = $pdo->prepare("
            SELECT s.ncbi_id, mr.report_text
            FROM motif_reports mr
            JOIN motif_results mres ON mr.motif_id = mres.motif_id
            JOIN sequences s ON mres.sequence_id = s.sequence_id
            WHERE mr.motif_id = ? AND s.sequence_id = ?
            GROUP BY s.ncbi_id, mr.report_text
        ");
        $stmt->execute([$motif_job['motif_id'], $sequence_id]);
        $report = $stmt->fetch();

        if (!$report) {
            $_SESSION['error'] = "No motif report found for this sequence";
            header("Location: motifs.php?job_id=$job_id");
            exit();
        }

        $filename = "motif_report_{$job_id}_{$report['ncbi_id']}.txt";
        $content = $report['report_text'];
    } else {
        // Download all motif results for the job
        $stmt = $pdo->prepare("
            SELECT s.ncbi_id, mres.motif_name, mres.start_pos, mres.end_pos
            FROM motif_results mres
            JOIN sequences s ON mres.sequence_id = s.sequence_id
            WHERE mres.motif_id = ?
            ORDER BY s.ncbi_id, mres.start_pos
        ");
        $stmt->execute([$motif_job['motif_id']]);
        $results = $stmt->fetchAll();

        // Get all reports
        $stmt = $pdo->prepare("
            SELECT mr.report_text
            FROM motif_reports mr
            WHERE mr.motif_id = ?
        ");
        $stmt->execute([$motif_job['motif_id']]);
        $reports = $stmt->fetchAll();

        $filename = "motif_results_{$job_id}.txt";
        $content = "Motif Analysis Report for Job ID: $job_id\n";
        $content .= "Search Term: " . $job['search_term'] . "\n";
        $content .= "Taxonomic Group: " . $job['taxon'] . "\n";
        $content .= "Generated on: " . date('Y-m-d H:i:s') . "\n\n";

        $content .= "=== Summary of All Motifs ===\n";
        foreach ($results as $result) {
            $content .= sprintf("%-15s %-20s %5d - %-5d\n",
                $result['ncbi_id'],
                $result['motif_name'],
                $result['start_pos'],
                $result['end_pos']);
        }

        $content .= "\n\n=== Detailed Reports ===\n";
        foreach ($reports as $report) {
            $content .= "\n\n" . $report['report_text'];
        }
    }

    // Send the file to the browser
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit();

} catch (Exception $e) {
    error_log("Download motifs error: " . $e->getMessage());
    $_SESSION['error'] = "Failed to generate download: " . $e->getMessage();
    header("Location: motifs.php?job_id=$job_id");
    exit();
}
