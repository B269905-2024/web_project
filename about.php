<?php session_start(); ?>
<!DOCTYPE html>
<html>
<head>
    <title>About - Protein Sequence Analysis Tool</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
        }
        .nav-links {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .nav-links a {
            margin-right: 15px;
            text-decoration: none;
            color: #0366d6;
        }
        .nav-links a:hover {
            text-decoration: underline;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #2980b9;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }
        .diagram {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            font-family: monospace;
            white-space: pre;
            overflow-x: auto;
        }
        .script-selector {
            margin: 20px 0;
        }
        select {
            padding: 8px;
            font-size: 16px;
            width: 300px;
        }
        .script-info {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .script-info h3 {
            margin-top: 0;
            color: #2980b9;
        }
        .script-info ul {
            margin-bottom: 0;
        }
        .database-table {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }
        .database-table h3 {
            background-color: #3498db;
            color: white;
            margin: 0;
            padding: 10px;
            font-size: 16px;
        }
        .database-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .database-table th, .database-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .database-table th {
            background-color: #f2f2f2;
        }
        .database-table tr:nth-child(even) {
            background-color: #f9f9f9;
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
        <a href="credits.php">Credits</a>
    </div>

    <h1>About the Protein Sequence Analysis Tool</h1>

    <h2>System Architecture Diagram</h2>
    <div class="diagram">
├── config.php (Shared configuration for all scripts)
│
├── Entry Points
│   ├── home.php (Main search form)
│   │   ├── run_search.sh (Background search processing)
│   │   └── results.php (Displays search results)
│   │       ├── conservation.php
│   │       │   ├── run_conservation.sh
│   │       │   └── download_conservation.php
│   │       ├── motifs.php
│   │       │   ├── run_motifs.sh
│   │       │   └── download_motifs.php
│   │       └── content.php
│   ├── past.php (Past searches list)
│   │   └── delete_job.php
│   └── example.php (Permanent example analysis)
│
├── Utility Scripts
│   ├── check_motifs.php (AJAX endpoint)
│   └── download.php (Legacy download handler)
│
└── Documentation/Info
    ├── about.php
    ├── help.php
    ├── credits.php
    └── example.php
    </div>

    <h2>Database Structure</h2>
    <p>The database consists of 11 tables that store user sessions, search jobs, sequences, and analysis results:</p>
    
    <div class="diagram">
Database Schema:
┌───────────────────────────────┐
│            users              │
├───────────────┬───────────────┤
│ user_id (PK)  │ session_id    │
└───────┬───────┴───────────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│            jobs               │
├───────────────┬───────────────┤
│ job_id (PK)   │ user_id (FK)  │
└───────┬───────┴───────────────┘
        │
        │ 1:n
        ├───────────────────────┐
        ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│   sequences     │    │   temp_fasta    │
├───────┬─────────┤    ├───────┬─────────┤
│ seq_id│ job_id  │    │ id    │ job_id  │
└───────┴─────────┘    └───────┴─────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│        motif_results          │
├───────────────┬───────────────┤
│ result_id (PK)│ sequence_id   │
└───────┬───────┴───────────────┘
        │
        │ n:1
        ▼
┌───────────────────────────────┐
│        motif_jobs             │
├───────────────┬───────────────┤
│ motif_id (PK) │ job_id (FK)   │
└───────┬───────┴───────────────┘
        │
        │ 1:1
        ▼
┌───────────────────────────────┐
│       motif_reports           │
├───────────────┬───────────────┤
│ report_id (PK)│ motif_id (FK) │
└───────────────┴───────────────┘

┌───────────────────────────────┐
│    conservation_jobs          │
├───────────────┬───────────────┤
│ cons_id (PK)  │ job_id (FK)   │
└───────┬───────┴───────────────┘
        │
        │ 1:1
        ├───────────────────────┐
        ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│ cons_alignments │    │ cons_reports    │
├───────┬─────────┤    ├───────┬─────────┤
│ aln_id│ cons_id │    │ rep_id│ cons_id │
└───────┴─────────┘    └───────┴─────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│    conservation_results       │
├───────────────┬───────────────┤
│ result_id (PK)│ cons_id (FK)  │
└───────────────┴───────────────┘
    </div>

    <h3>Detailed Table Information</h3>
    
    <div class="database-table">
        <h3>users</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>user_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>session_id</td><td>varchar(255)</td><td>Unique</td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>jobs</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>job_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>user_id</td><td>int</td><td>Foreign Key (users)</td></tr>
            <tr><td>search_term</td><td>varchar(255)</td><td></td></tr>
            <tr><td>taxon</td><td>varchar(255)</td><td></td></tr>
            <tr><td>max_results</td><td>int</td><td></td></tr>
            <tr><td>status</td><td>enum</td><td></td></tr>
            <tr><td>error_message</td><td>text</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
            <tr><td>fasta_data</td><td>longtext</td><td></td></tr>
            <tr><td>motif_results</td><td>longtext</td><td></td></tr>
            <tr><td>motif_report</td><td>longtext</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>sequences</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>sequence_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>job_id</td><td>int</td><td>Foreign Key (jobs)</td></tr>
            <tr><td>ncbi_id</td><td>varchar(50)</td><td></td></tr>
            <tr><td>description</td><td>text</td><td></td></tr>
            <tr><td>sequence</td><td>text</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>motif_jobs</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>motif_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>job_id</td><td>int</td><td>Foreign Key (jobs)</td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>motif_results</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>result_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>motif_id</td><td>int</td><td>Foreign Key (motif_jobs)</td></tr>
            <tr><td>sequence_id</td><td>int</td><td>Foreign Key (sequences)</td></tr>
            <tr><td>motif_name</td><td>varchar(255)</td><td></td></tr>
            <tr><td>motif_id_code</td><td>varchar(20)</td><td></td></tr>
            <tr><td>description</td><td>text</td><td></td></tr>
            <tr><td>start_pos</td><td>int</td><td></td></tr>
            <tr><td>end_pos</td><td>int</td><td></td></tr>
            <tr><td>score</td><td>decimal(10,3)</td><td></td></tr>
            <tr><td>p_value</td><td>decimal(10,5)</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>motif_reports</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>report_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>motif_id</td><td>int</td><td>Foreign Key (motif_jobs)</td></tr>
            <tr><td>report_text</td><td>text</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>conservation_jobs</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>conservation_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>job_id</td><td>int</td><td>Foreign Key (jobs)</td></tr>
            <tr><td>window_size</td><td>int</td><td></td></tr>
            <tr><td>status</td><td>enum</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
            <tr><td>updated_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>conservation_alignments</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>alignment_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>conservation_id</td><td>int</td><td>Foreign Key (conservation_jobs)</td></tr>
            <tr><td>ncbi_id</td><td>varchar(50)</td><td></td></tr>
            <tr><td>sequence</td><td>text</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>conservation_results</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>result_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>conservation_id</td><td>int</td><td>Foreign Key (conservation_jobs)</td></tr>
            <tr><td>position</td><td>int</td><td></td></tr>
            <tr><td>entropy</td><td>float</td><td></td></tr>
            <tr><td>plotcon_score</td><td>float</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>conservation_reports</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>report_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>conservation_id</td><td>int</td><td>Foreign Key (conservation_jobs)</td></tr>
            <tr><td>report_text</td><td>text</td><td></td></tr>
            <tr><td>mean_entropy</td><td>float</td><td></td></tr>
            <tr><td>max_entropy</td><td>float</td><td></td></tr>
            <tr><td>min_entropy</td><td>float</td><td></td></tr>
            <tr><td>max_position</td><td>int</td><td></td></tr>
            <tr><td>min_position</td><td>int</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>temp_fasta</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>job_id</td><td>int</td><td>Foreign Key (jobs)</td></tr>
            <tr><td>fasta_content</td><td>longtext</td><td></td></tr>
            <tr><td>created_at</td><td>datetime</td><td></td></tr>
        </table>
    </div>

    <h2>Script Descriptions</h2>
    <div class="script-selector">
        <select id="scriptSelector" onchange="showScriptInfo()">
            <option value="">-- Select a script to learn more --</option>
            <option value="home.php">home.php</option>
            <option value="run_search.sh">run_search.sh</option>
            <option value="results.php">results.php</option>
            <option value="conservation.php">conservation.php</option>
            <option value="run_conservation.sh">run_conservation.sh</option>
            <option value="motifs.php">motifs.php</option>
            <option value="run_motifs.sh">run_motifs.sh</option>
            <option value="content.php">content.php</option>
            <option value="past.php">past.php</option>
            <option value="delete_job.php">delete_job.php</option>
            <option value="example.php">example.php</option>
            <option value="check_motifs.php">check_motifs.php</option>
            <option value="download.php">download.php</option>
        </select>
    </div>

    <div id="scriptInfo" class="script-info" style="display:none;">
        <h3 id="scriptTitle"></h3>
        <ul id="scriptDetails"></ul>
    </div>

    <script>
        const scriptData = {
            "home.php": [
                "Main entry point for the application",
                "Provides search form for protein sequences",
                "Creates user session if none exists",
                "Initiates search jobs via run_search.sh"
            ],
            "run_search.sh": [
                "Background script for NCBI protein searches",
                "Queries NCBI protein database using E-utilities",
                "Processes FASTA format results",
                "Stores sequences in database"
            ],
            "results.php": [
                "Displays search results from NCBI",
                "Shows protein sequences in FASTA format",
                "Provides links to analysis tools",
                "Offers FASTA download option"
            ],
            "conservation.php": [
                "Conservation analysis interface",
                "Calculates Shannon entropy and conservation scores",
                "Visualizes results with Plotly.js",
                "Links to run_conservation.sh for processing"
            ],
            "run_conservation.sh": [
                "Performs conservation analysis",
                "Uses Clustal Omega for alignment",
                "Calculates entropy and plotcon scores",
                "Stores results in database"
            ],
            "motifs.php": [
                "Motif discovery interface",
                "Uses EMBOSS patmatmotifs",
                "Displays PROSITE motif matches",
                "Provides detailed sequence views"
            ],
            "run_motifs.sh": [
                "Executes motif analysis",
                "Processes sequences with patmatmotifs",
                "Parses PROSITE motif results",
                "Stores motif positions and details"
            ],
            "content.php": [
                "Amino acid composition analysis",
                "Calculates percentage of each amino acid",
                "Interactive visualization with Plotly.js",
                "Generates downloadable reports"
            ],
            "past.php": [
                "Displays user's search history",
                "Shows job status and sequence counts",
                "Links to previous results",
                "Provides delete functionality"
            ],
            "delete_job.php": [
                "Handles job deletion",
                "Removes job and associated sequences",
                "Cleans up related analysis data",
                "Returns to past.php after completion"
            ],
            "example.php": [
                "Permanent example analysis",
                "Shows all analysis types pre-computed",
                "Uses fixed job ID (90)",
                "Helpful for demonstration purposes"
            ],
            "check_motifs.php": [
                "AJAX endpoint for motif status",
                "Checks if motif analysis is complete",
                "Returns JSON response",
                "Used by motifs.php for progress checking"
            ],
            "download.php": [
                "Legacy download handler",
                "Provides various download formats",
                "Handles conservation analysis results",
                "Being replaced by specific download scripts"
            ]
        };

        function showScriptInfo() {
            const selector = document.getElementById('scriptSelector');
            const scriptName = selector.value;
            const infoDiv = document.getElementById('scriptInfo');

            if (!scriptName) {
                infoDiv.style.display = 'none';
                return;
            }

            document.getElementById('scriptTitle').textContent = scriptName;
            const detailsList = document.getElementById('scriptDetails');
            detailsList.innerHTML = '';

            scriptData[scriptName].forEach(item => {
                const li = document.createElement('li');
                li.textContent = item;
                detailsList.appendChild(li);
            });

            infoDiv.style.display = 'block';
        }
    </script>
</body>
</html><?php session_start(); ?>
<!DOCTYPE html>
<html>
<head>
    <title>About - Protein Sequence Analysis Tool</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
        }
        .nav-links {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .nav-links a {
            margin-right: 15px;
            text-decoration: none;
            color: #0366d6;
        }
        .nav-links a:hover {
            text-decoration: underline;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #2980b9;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }
        .diagram {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            font-family: monospace;
            white-space: pre;
            overflow-x: auto;
        }
        .script-selector {
            margin: 20px 0;
        }
        select {
            padding: 8px;
            font-size: 16px;
            width: 300px;
        }
        .script-info {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .script-info h3 {
            margin-top: 0;
            color: #2980b9;
        }
        .script-info ul {
            margin-bottom: 0;
        }
        .database-table {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }
        .database-table h3 {
            background-color: #3498db;
            color: white;
            margin: 0;
            padding: 10px;
            font-size: 16px;
        }
        .database-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .database-table th, .database-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .database-table th {
            background-color: #f2f2f2;
        }
        .database-table tr:nth-child(even) {
            background-color: #f9f9f9;
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
        <a href="credits.php">Credits</a>
    </div>

    <h1>About the Protein Sequence Analysis Tool</h1>

    <h2>System Architecture Diagram</h2>
    <div class="diagram">
├── config.php (Shared configuration for all scripts)
│
├── Entry Points
│   ├── home.php (Main search form)
│   │   ├── run_search.sh (Background search processing)
│   │   └── results.php (Displays search results)
│   │       ├── conservation.php
│   │       │   ├── run_conservation.sh
│   │       │   └── download_conservation.php
│   │       ├── motifs.php
│   │       │   ├── run_motifs.sh
│   │       │   └── download_motifs.php
│   │       └── content.php
│   ├── past.php (Past searches list)
│   │   └── delete_job.php
│   └── example.php (Permanent example analysis)
│
├── Utility Scripts
│   ├── check_motifs.php (AJAX endpoint)
│   └── download.php (Legacy download handler)
│
└── Documentation/Info
    ├── about.php
    ├── help.php
    ├── credits.php
    └── example.php
    </div>

    <h2>Database Structure</h2>
    <p>The database consists of 11 tables that store user sessions, search jobs, sequences, and analysis results:</p>
    
    <div class="diagram">
Database Schema:
┌───────────────────────────────┐
│            users              │
├───────────────┬───────────────┤
│ user_id (PK)  │ session_id    │
└───────┬───────┴───────────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│            jobs               │
├───────────────┬───────────────┤
│ job_id (PK)   │ user_id (FK)  │
└───────┬───────┴───────────────┘
        │
        │ 1:n
        ├───────────────────────┐
        ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│   sequences     │    │   temp_fasta    │
├───────┬─────────┤    ├───────┬─────────┤
│ seq_id│ job_id  │    │ id    │ job_id  │
└───────┴─────────┘    └───────┴─────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│        motif_results          │
├───────────────┬───────────────┤
│ result_id (PK)│ sequence_id   │
└───────┬───────┴───────────────┘
        │
        │ n:1
        ▼
┌───────────────────────────────┐
│        motif_jobs             │
├───────────────┬───────────────┤
│ motif_id (PK) │ job_id (FK)   │
└───────┬───────┴───────────────┘
        │
        │ 1:1
        ▼
┌───────────────────────────────┐
│       motif_reports           │
├───────────────┬───────────────┤
│ report_id (PK)│ motif_id (FK) │
└───────────────┴───────────────┘

┌───────────────────────────────┐
│    conservation_jobs          │
├───────────────┬───────────────┤
│ cons_id (PK)  │ job_id (FK)   │
└───────┬───────┴───────────────┘
        │
        │ 1:1
        ├───────────────────────┐
        ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│ cons_alignments │    │ cons_reports    │
├───────┬─────────┤    ├───────┬─────────┤
│ aln_id│ cons_id │    │ rep_id│ cons_id │
└───────┴─────────┘    └───────┴─────────┘
        │
        │ 1:n
        ▼
┌───────────────────────────────┐
│    conservation_results       │
├───────────────┬───────────────┤
│ result_id (PK)│ cons_id (FK)  │
└───────────────┴───────────────┘
    </div>

    <h3>Detailed Table Information</h3>
    
    <div class="database-table">
        <h3>users</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>user_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>session_id</td><td>varchar(255)</td><td>Unique</td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>jobs</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>job_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>user_id</td><td>int</td><td>Foreign Key (users)</td></tr>
            <tr><td>search_term</td><td>varchar(255)</td><td></td></tr>
            <tr><td>taxon</td><td>varchar(255)</td><td></td></tr>
            <tr><td>max_results</td><td>int</td><td></td></tr>
            <tr><td>status</td><td>enum</td><td></td></tr>
            <tr><td>error_message</td><td>text</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
            <tr><td>fasta_data</td><td>longtext</td><td></td></tr>
            <tr><td>motif_results</td><td>longtext</td><td></td></tr>
            <tr><td>motif_report</td><td>longtext</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>sequences</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>sequence_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>job_id</td><td>int</td><td>Foreign Key (jobs)</td></tr>
            <tr><td>ncbi_id</td><td>varchar(50)</td><td></td></tr>
            <tr><td>description</td><td>text</td><td></td></tr>
            <tr><td>sequence</td><td>text</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>motif_jobs</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>motif_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>job_id</td><td>int</td><td>Foreign Key (jobs)</td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>motif_results</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>result_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>motif_id</td><td>int</td><td>Foreign Key (motif_jobs)</td></tr>
            <tr><td>sequence_id</td><td>int</td><td>Foreign Key (sequences)</td></tr>
            <tr><td>motif_name</td><td>varchar(255)</td><td></td></tr>
            <tr><td>motif_id_code</td><td>varchar(20)</td><td></td></tr>
            <tr><td>description</td><td>text</td><td></td></tr>
            <tr><td>start_pos</td><td>int</td><td></td></tr>
            <tr><td>end_pos</td><td>int</td><td></td></tr>
            <tr><td>score</td><td>decimal(10,3)</td><td></td></tr>
            <tr><td>p_value</td><td>decimal(10,5)</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>motif_reports</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>report_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>motif_id</td><td>int</td><td>Foreign Key (motif_jobs)</td></tr>
            <tr><td>report_text</td><td>text</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>conservation_jobs</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>conservation_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>job_id</td><td>int</td><td>Foreign Key (jobs)</td></tr>
            <tr><td>window_size</td><td>int</td><td></td></tr>
            <tr><td>status</td><td>enum</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
            <tr><td>updated_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>conservation_alignments</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>alignment_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>conservation_id</td><td>int</td><td>Foreign Key (conservation_jobs)</td></tr>
            <tr><td>ncbi_id</td><td>varchar(50)</td><td></td></tr>
            <tr><td>sequence</td><td>text</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>conservation_results</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>result_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>conservation_id</td><td>int</td><td>Foreign Key (conservation_jobs)</td></tr>
            <tr><td>position</td><td>int</td><td></td></tr>
            <tr><td>entropy</td><td>float</td><td></td></tr>
            <tr><td>plotcon_score</td><td>float</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>conservation_reports</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>report_id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>conservation_id</td><td>int</td><td>Foreign Key (conservation_jobs)</td></tr>
            <tr><td>report_text</td><td>text</td><td></td></tr>
            <tr><td>mean_entropy</td><td>float</td><td></td></tr>
            <tr><td>max_entropy</td><td>float</td><td></td></tr>
            <tr><td>min_entropy</td><td>float</td><td></td></tr>
            <tr><td>max_position</td><td>int</td><td></td></tr>
            <tr><td>min_position</td><td>int</td><td></td></tr>
            <tr><td>created_at</td><td>timestamp</td><td></td></tr>
        </table>
    </div>
    
    <div class="database-table">
        <h3>temp_fasta</h3>
        <table>
            <tr><th>Field</th><th>Type</th><th>Key</th></tr>
            <tr><td>id</td><td>int (PK)</td><td>Primary Key</td></tr>
            <tr><td>job_id</td><td>int</td><td>Foreign Key (jobs)</td></tr>
            <tr><td>fasta_content</td><td>longtext</td><td></td></tr>
            <tr><td>created_at</td><td>datetime</td><td></td></tr>
        </table>
    </div>

    <h2>Script Descriptions</h2>
    <div class="script-selector">
        <select id="scriptSelector" onchange="showScriptInfo()">
            <option value="">-- Select a script to learn more --</option>
            <option value="home.php">home.php</option>
            <option value="run_search.sh">run_search.sh</option>
            <option value="results.php">results.php</option>
            <option value="conservation.php">conservation.php</option>
            <option value="run_conservation.sh">run_conservation.sh</option>
            <option value="motifs.php">motifs.php</option>
            <option value="run_motifs.sh">run_motifs.sh</option>
            <option value="content.php">content.php</option>
            <option value="past.php">past.php</option>
            <option value="delete_job.php">delete_job.php</option>
            <option value="example.php">example.php</option>
            <option value="check_motifs.php">check_motifs.php</option>
            <option value="download.php">download.php</option>
        </select>
    </div>

    <div id="scriptInfo" class="script-info" style="display:none;">
        <h3 id="scriptTitle"></h3>
        <ul id="scriptDetails"></ul>
    </div>

    <script>
        const scriptData = {
            "home.php": [
                "Main entry point for the application",
                "Provides search form for protein sequences",
                "Creates user session if none exists",
                "Initiates search jobs via run_search.sh"
            ],
            "run_search.sh": [
                "Background script for NCBI protein searches",
                "Queries NCBI protein database using E-utilities",
                "Processes FASTA format results",
                "Stores sequences in database"
            ],
            "results.php": [
                "Displays search results from NCBI",
                "Shows protein sequences in FASTA format",
                "Provides links to analysis tools",
                "Offers FASTA download option"
            ],
            "conservation.php": [
                "Conservation analysis interface",
                "Calculates Shannon entropy and conservation scores",
                "Visualizes results with Plotly.js",
                "Links to run_conservation.sh for processing"
            ],
            "run_conservation.sh": [
                "Performs conservation analysis",
                "Uses Clustal Omega for alignment",
                "Calculates entropy and plotcon scores",
                "Stores results in database"
            ],
            "motifs.php": [
                "Motif discovery interface",
                "Uses EMBOSS patmatmotifs",
                "Displays PROSITE motif matches",
                "Provides detailed sequence views"
            ],
            "run_motifs.sh": [
                "Executes motif analysis",
                "Processes sequences with patmatmotifs",
                "Parses PROSITE motif results",
                "Stores motif positions and details"
            ],
            "content.php": [
                "Amino acid composition analysis",
                "Calculates percentage of each amino acid",
                "Interactive visualization with Plotly.js",
                "Generates downloadable reports"
            ],
            "past.php": [
                "Displays user's search history",
                "Shows job status and sequence counts",
                "Links to previous results",
                "Provides delete functionality"
            ],
            "delete_job.php": [
                "Handles job deletion",
                "Removes job and associated sequences",
                "Cleans up related analysis data",
                "Returns to past.php after completion"
            ],
            "example.php": [
                "Permanent example analysis",
                "Shows all analysis types pre-computed",
                "Uses fixed job ID (90)",
                "Helpful for demonstration purposes"
            ],
            "check_motifs.php": [
                "AJAX endpoint for motif status",
                "Checks if motif analysis is complete",
                "Returns JSON response",
                "Used by motifs.php for progress checking"
            ],
            "download.php": [
                "Legacy download handler",
                "Provides various download formats",
                "Handles conservation analysis results",
                "Being replaced by specific download scripts"
            ]
        };

        function showScriptInfo() {
            const selector = document.getElementById('scriptSelector');
            const scriptName = selector.value;
            const infoDiv = document.getElementById('scriptInfo');

            if (!scriptName) {
                infoDiv.style.display = 'none';
                return;
            }

            document.getElementById('scriptTitle').textContent = scriptName;
            const detailsList = document.getElementById('scriptDetails');
            detailsList.innerHTML = '';

            scriptData[scriptName].forEach(item => {
                const li = document.createElement('li');
                li.textContent = item;
                detailsList.appendChild(li);
            });

            infoDiv.style.display = 'block';
        }
    </script>
</body>
</html>
