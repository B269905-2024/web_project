#!/bin/bash

JOB_ID=$1
PROTEIN_FAMILY=$2
TAXONOMIC_GROUP=$3

QUERY="\"$PROTEIN_FAMILY\" AND \"$TAXONOMIC_GROUP\""

DB_CREDENTIALS=$(php -r 'require_once "login.php"; echo "$hostname\n$database\n$username\n$password\n";')
read -r HOSTNAME DATABASE USERNAME PASSWORD <<< "$DB_CREDENTIALS"

# Get sequence data with error handling
#SEQUENCES=$(curl -s "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=protein&id=$(curl -s "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=protein&term=$("$QUERY")&retmax=10" | grep -o '<Id>[0-9]*</Id>' | sed 's/<Id>//g; s/<\/Id>/,/g' | tr -d '\n' | sed 's/,$//')&rettype=fasta&retmode=text")
SEQUENCES=$(curl -s "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=protein&id=$(curl -s "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=protein&term=$(echo "$QUERY" | sed 's/ /+/g')&retmax=2" | grep -o '<Id>[0-9]*</Id>' | sed 's/<Id>//g; s/<\/Id>/,/g' | tr -d '\n' | sed 's/,$//')&rettype=fasta&retmode=text")


if [ -z "$SEQUENCES" ]; then
    STATUS="failed"
else
    STATUS="completed"
    # Escape single quotes for SQL
    SEQUENCES=$(echo "$SEQUENCES" | sed "s/'/''/g")
fi

mysql -h "$HOSTNAME" -u "$USERNAME" -p"$PASSWORD" "$DATABASE" <<EOF
UPDATE searches
SET
    status = '$STATUS',
    sequences = '$SEQUENCES',
    completed_at = NOW()
WHERE job_id = '$JOB_ID';
EOF

echo "Search $STATUS for job $JOB_ID"
