<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Credits - Protein Sequence Analysis Tool</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
            max-width: 1000px;
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
            margin-top: 30px;
        }
        .credit-section {
            margin-bottom: 30px;
        }
        .credit-item {
            margin-bottom: 15px;
            padding-left: 20px;
            border-left: 3px solid #3498db;
        }
        .credit-title {
            font-weight: bold;
        }
        .credit-description {
            margin: 5px 0;
        }
        .credit-link {
            color: #27ae60;
            word-break: break-all;
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

    <h1>Project Credits and Attributions</h1>

    <div class="credit-section">
        <h2>Code Sources and Libraries</h2>
        
        <div class="credit-item">
            <div class="credit-title">[Library/Framework Name]</div>
            <div class="credit-description">[Brief description of how it's used in the project]</div>
            <div class="credit-link">[URL to original source]</div>
            <div class="credit-license">[License information, if applicable]</div>
        </div>
        
        <div class="credit-item">
            <div class="credit-title">[Library/Framework Name]</div>
            <div class="credit-description">[Brief description of how it's used in the project]</div>
            <div class="credit-link">[URL to original source]</div>
            <div class="credit-license">[License information, if applicable]</div>
        </div>
        
        <!-- Add more credit items as needed -->
    </div>

    <div class="credit-section">
        <h2>AI Tools Used</h2>
        
        <div class="credit-item">
            <div class="credit-title">[AI Tool Name]</div>
            <div class="credit-description">[Specific purpose in the project, e.g., "Used for generating initial code structure for the conservation analysis module"]</div>
            <div class="credit-link">[URL to tool, if applicable]</div>
        </div>
        
        <div class="credit-item">
            <div class="credit-title">[AI Tool Name]</div>
            <div class="credit-description">[Specific purpose in the project, e.g., "Used for debugging database connection issues"]</div>
            <div class="credit-link">[URL to tool, if applicable]</div>
        </div>
        
        <!-- Add more AI tool items as needed -->
    </div>

    <div class="credit-section">
        <h2>Data Sources</h2>
        
        <div class="credit-item">
            <div class="credit-title">[Database/API Name]</div>
            <div class="credit-description">[Description of how the data is used]</div>
            <div class="credit-link">[URL to data source]</div>
            <div class="credit-license">[Usage terms or license]</div>
        </div>
        
        <!-- Add more data source items as needed -->
    </div>

    <div class="credit-section">
        <h2>Special Thanks</h2>
        <p>[Any acknowledgments for individuals or organizations that contributed to the project]</p>
    </div>
</body>
</html>
