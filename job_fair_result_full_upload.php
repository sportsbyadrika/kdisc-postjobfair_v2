<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();

$uploadColumns = [
    'Job_Fair_No',
    'Job_Fair_Date',
    'DWMS_ID',
    'Candidate_Name',
    'Employer_ID',
    'Employer_Name',
    'Job_Id',
    'Job_Title_Name',
    'Candidate_District',
    'Candidate_Localbody',
    'Candidate_Jobstation',
    'Mobile_Number',
    'EMail',
    'SDPK',
    'SDPK_District',
    'Aggregator',
    'Aggregator_SPOC_Name',
    'Aggregator_SPOC_Mobile',
    'Employer_SPOC_Name',
    'Employer_SPOC_Mobile',
    'CRM_Member',
    'DSM_Member_1',
    'DSM_Member_2',
    'Selection_Status',
    'First_Call_Date',
    'First_Call_Done',
    'Offer_Letter_Generated',
    'Offer_Letter_Generated_Remarks',
    'Offer_Letter_Generated_Date',
    'Link_to_Offer_letter',
    'Link_to_Offer_letter_verified',
    'Confirm_Offer_Letter_Receipt_by_Candidate',
    'Confirm_letter_receipt_remarks',
    'confirmation_date',
    'response_from_employer',
    'Willing_to_Join',
    'willing_to_join_remarks',
    'Offer_Letter_Join_Date',
    'Challenge_Type',
    'Challenge_to_be_addressed',
    'Escalation_to_Aggregator_Due_Date',
    'Escalation_to_Aggregator_Date',
    'Escalation_to_Aggregator_Done',
    'DSM_Follow_Up_Date',
    'DSM_Follow_Up_Status',
    'Specific_Issues_Report_to_MS',
    'MS_EscalationDate',
    'MS_Escalated',
    'Candidate_Joined_Status',
    'Candidate_Joined_Date',
    'Remarks_Candidate_Join',
    'Shortlist_Prepratory_Call_Date',
    'Shortlist_Preparatory_Call_Status',
    'Shortlist_Next_Process',
    'Shortlist_Number_of_Rounds',
    'Shortlist_Process_Deadline_Date',
    'Shortlist_Current_Call_Status',
    'Shortlist_Current_Process_Status',
    'Shortlist_Candidate_Status',
    'Shortlist_remarks',
];

$dateColumns = [
    'Job_Fair_Date' => false,
    'First_Call_Date' => true,
    'Offer_Letter_Generated_Date' => true,
    'confirmation_date' => true,
    'Offer_Letter_Join_Date' => true,
    'Escalation_to_Aggregator_Due_Date' => true,
    'Escalation_to_Aggregator_Date' => true,
    'DSM_Follow_Up_Date' => true,
    'MS_EscalationDate' => true,
    'Candidate_Joined_Date' => true,
    'Shortlist_Prepratory_Call_Date' => true,
    'Shortlist_Process_Deadline_Date' => true,
];

$yesNoColumns = [
    'First_Call_Done',
    'Offer_Letter_Generated',
    'Link_to_Offer_letter_verified',
    'Confirm_Offer_Letter_Receipt_by_Candidate',
    'Willing_to_Join',
    'Escalation_to_Aggregator_Done',
    'DSM_Follow_Up_Status',
    'MS_Escalated',
    'Candidate_Joined_Status',
    'Shortlist_Preparatory_Call_Status',
    'Shortlist_Current_Call_Status',
];

$flashType = null;
$flashMessage = null;

function normalize_csv_header(string $header): string
{
    return strtolower(trim($header));
}

function normalize_date_or_datetime(string $value, bool $withTime): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = $withTime
        ? ['Y-m-d H:i:s', 'd-m-Y H:i:s', 'd/m/Y H:i:s', 'm/d/Y H:i:s', 'Y-m-d H:i', 'd-m-Y H:i', 'd/m/Y H:i', 'm/d/Y H:i']
        : ['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y'];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime && $date->format($format) === $value) {
            return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date($withTime ? 'Y-m-d H:i:s' : 'Y-m-d', $timestamp);
}

function normalize_yes_no_value(string $value): string
{
    $trimmed = trim($value);
    $upper = strtoupper($trimmed);

    if ($upper === 'Y') {
        return 'Yes';
    }

    if ($upper === 'N') {
        return 'No';
    }

    return $trimmed;
}

if (is_post()) {
    $file = $_FILES['csv_file'] ?? null;

    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashType = 'danger';
        $flashMessage = 'Please choose a valid CSV file to upload.';
    } else {
        $tmpName = (string) $file['tmp_name'];
        $handle = fopen($tmpName, 'r');

        if ($handle === false) {
            $flashType = 'danger';
            $flashMessage = 'Unable to read the uploaded CSV file.';
        } else {
            $header = fgetcsv($handle);

            if (!is_array($header) || $header === []) {
                $flashType = 'danger';
                $flashMessage = 'CSV appears to be empty or missing a header row.';
            } else {
                $headerMap = [];
                foreach ($header as $index => $columnName) {
                    $headerMap[normalize_csv_header((string) $columnName)] = $index;
                }

                $missingColumns = [];
                foreach ($uploadColumns as $requiredColumn) {
                    if (!array_key_exists(normalize_csv_header($requiredColumn), $headerMap)) {
                        $missingColumns[] = $requiredColumn;
                    }
                }

                if ($missingColumns !== []) {
                    $flashType = 'danger';
                    $flashMessage = 'Missing required columns: ' . implode(', ', $missingColumns) . '.';
                } else {
                    $columnSql = implode(', ', $uploadColumns) . ', Data_uploaded_date';
                    $placeholderSql = rtrim(str_repeat('?, ', count($uploadColumns)), ', ') . ', NOW()';
                    $insertSql = "INSERT INTO job_fair_result ($columnSql) VALUES ($placeholderSql)";
                    $insertStmt = db()->prepare($insertSql);

                    $processedRows = 0;
                    $insertedRows = 0;
                    $skippedRows = 0;

                    while (($row = fgetcsv($handle)) !== false) {
                        $processedRows++;

                        $values = [];
                        foreach ($uploadColumns as $column) {
                            $columnIndex = $headerMap[normalize_csv_header($column)];
                            $values[$column] = trim((string) ($row[$columnIndex] ?? ''));
                        }

                        if (implode('', $values) === '') {
                            $skippedRows++;
                            continue;
                        }

                        $hasInvalidDate = false;
                        foreach ($dateColumns as $column => $withTime) {
                            $rawValue = $values[$column];
                            $normalizedValue = normalize_date_or_datetime($rawValue, $withTime);
                            if ($rawValue !== '' && $normalizedValue === null) {
                                $hasInvalidDate = true;
                                break;
                            }
                            $values[$column] = $normalizedValue ?? '';
                        }

                        if ($hasInvalidDate) {
                            $skippedRows++;
                            continue;
                        }

                        foreach ($yesNoColumns as $column) {
                            $values[$column] = normalize_yes_no_value($values[$column]);
                        }

                        $insertParams = [];
                        foreach ($uploadColumns as $column) {
                            $insertParams[] = $values[$column] === '' ? null : $values[$column];
                        }

                        $insertStmt->execute($insertParams);
                        $insertedRows++;
                    }

                    $flashType = 'success';
                    $flashMessage = sprintf(
                        'Full upload complete. Processed %d rows, inserted %d rows, skipped %d rows.',
                        $processedRows,
                        $insertedRows,
                        $skippedRows
                    );
                }
            }

            fclose($handle);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Full Upload · Job Fair CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="container py-4">
    <div class="page-header">
        <div class="page-title-group">
            <span class="page-title-icon"><i class="bi bi-cloud-arrow-up"></i></span>
            <div>
                <h1>Full Upload to Job Fair Results</h1>
                <p class="page-subtitle">Standalone upload page (not in menu).</p>
            </div>
        </div>
    </div>

    <?php if ($flashMessage !== null): ?>
        <div class="alert alert-<?= esc($flashType ?? 'info') ?>"><?= esc($flashMessage) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <div class="col-12 col-lg-8">
                    <label for="csv_file" class="form-label">CSV File</label>
                    <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                    <div class="form-text">Uploads the full dataset to <code>job_fair_result</code> and converts <code>Y</code>/<code>N</code> to <code>Yes</code>/<code>No</code>.</div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i>Upload Full CSV</button>
                </div>
            </form>
            <hr class="my-3">
            <p class="form-label mb-1">Required columns</p>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach ($uploadColumns as $col): ?>
                    <span class="status-chip status-neutral"><?= esc($col) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
