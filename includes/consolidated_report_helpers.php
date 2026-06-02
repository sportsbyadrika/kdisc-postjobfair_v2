<?php

const CONSOLIDATED_CANDIDATE_COLUMNS = [
    'Job Fair No' => 'job_fair_no',
    'DWMS_Id' => 'dwms_id',
    'Candidate_Name' => 'candidate_name',
    'Employer_ID' => 'employer_id',
    'Employer_Name' => 'employer_name',
    'Job_Id' => 'job_id',
    'Job_Title_Name' => 'job_title_name',
    'Candidate_District' => 'candidate_district',
    'Mobile_Number' => 'mobile_number',
    'SDPK' => 'sdpk',
    'SDPK_District' => 'sdpk_district',
    'Aggregator' => 'aggregator',
    'CRM_Member' => 'crm_member',
    'Category' => 'category',
    'Selction_Status' => 'selection_status',
    'Shortlist_Candidate_Status' => 'shortlist_candidate_status',
    'offer_letter_Generated' => 'offer_letter_generated',
    'Offer_letter_generated_date' => 'offer_letter_generated_date',
    'Link_to_offer_letter' => 'link_to_offer_letter',
    'response_from_employer' => 'response_from_employer',
    'Willing_to_Join' => 'willing_to_join',
    'Challenge_to_be_addressed' => 'challenge_to_be_addressed',
    'Specific_Isues_Report_to_MS' => 'specific_issues_report_to_ms',
    'Candidate_Joined_Status' => 'candidate_joined_status',
    'Candidate_Join_Remarks_Type' => 'candidate_join_remarks_type',
    'Remarks_Candidate_Join' => 'remarks_candidate_join',
];

const CONSOLIDATED_CALL_HISTORY_COLUMNS = [
    'Call Stage' => 'call_stage',
    'Call Purpose' => 'call_purpose',
    'Call Date Time' => 'call_datetime',
    'Call Status' => 'call_status',
    'Call Remarks' => 'call_remarks',
    'Call Name' => 'call_name',
    'Call Mobile' => 'call_mobile',
];

const CONSOLIDATED_SECTION_LABELS = [
    'selected' => 'First Section: List of Selected Candidate',
    'shortlisted' => 'Second Section: List of Shortlisted/Onhold Candidates',
    'shortlisted_rounds_pending' => 'Third Section: List of Shortlisted/On hold Interview Rounds',
    'shortlisted_rounds_selected' => 'Fourth Section: List of Shortlisted/On hold Interview rounds of Selected Candidates',
    'crm_call_count_pending' => 'Fifth Section: CRM Call count based on Shortlisted/On hold candidates',
    'crm_call_count_joined_status' => 'Sixth Section: CRM Call count based on Shortlisted/On hold candidates (based on Candidate Joined Status)',
];

const CONSOLIDATED_METRIC_LABELS = [
    'selected' => [
        'total_selected_candidate' => 'Total Selected Candidate',
        'offer_generated_yes' => 'Offer Letter Generated: Yes',
        'offer_generated_no' => 'Offer Letter Generated: No',
        'offer_link_with_link' => 'Offer Letter Softcopy: Received',
        'offer_link_blank' => 'Offer Letter Softcopy: Not Received',
        'link_verified_yes' => 'Softcopy Verified: Yes',
        'link_verified_no' => 'Softcopy Verified: No',
        'link_verified_pending' => 'Softcopy Verified: Pending',
        'receipt_confirmed_yes' => 'Offer Letter Receipt: Yes',
        'receipt_confirmed_no' => 'Offer Letter Receipt: No',
        'receipt_confirmed_pending' => 'Offer Letter Receipt: Pending',
        'joined_yes' => 'Candidate Joined: Yes',
        'joined_no' => 'Candidate Joined: No',
        'joined_pending' => 'Candidate Joined: Pending',
        'joined_future_date' => 'Candidate Joined: Future Date',
    ],
    'shortlisted' => [
        'total_shortlisted_onhold_candidate' => 'Total Shortlisted/Onhold Candidate',
        'shortlist_status_selected' => 'Shortlisted Conversion: Selected',
        'shortlist_status_rtd_jobs' => 'Shortlisted Conversion: RTD Jobs',
        'shortlist_status_rejected' => 'Shortlisted Conversion: Rejected',
        'shortlist_status_onhold' => 'Shortlisted Conversion: Pending',
        'shortlist_status_onhold_only' => 'Shortlisted Conversion: Onhold',
        'shortlist_status_yet_to_be_contacted' => 'Shortlisted Conversion: Yet to be contacted',
        'shortlist_status_review_in_progress' => 'Shortlisted Conversion: Review in progress',
        'shortlist_status_selected_for_next_round_net' => 'Shortlisted Conversion: Selected for next round',
        'offer_generated_yes' => 'Offer Letter Generated: Yes',
        'offer_generated_no' => 'Offer Letter Generated: No',
        'offer_link_with_link' => 'Offer Letter Softcopy: Received',
        'offer_link_blank' => 'Offer Letter Softcopy: Not Received',
        'link_verified_yes' => 'Softcopy Verified: Yes',
        'link_verified_no' => 'Softcopy Verified: No',
        'link_verified_pending' => 'Softcopy Verified: Pending',
        'receipt_confirmed_yes' => 'Offer Letter Receipt Confirmed: Yes',
        'receipt_confirmed_no' => 'Offer Letter Receipt Confirmed: No',
        'receipt_confirmed_pending' => 'Offer Letter Receipt Confirmed: Pending',
        'joined_yes' => 'Candidate Joined: Yes',
        'joined_no' => 'Candidate Joined: No',
        'joined_pending' => 'Candidate Joined: Pending',
        'joined_future_date' => 'Candidate Joined: Future Date',
    ],
    'shortlisted_rounds_pending' => [
        'total_shortlisted_onhold_candidate' => 'Total Shortlisted/Onhold Candidate count',
        'shortlist_conversion_pending_count' => 'Shortlisted Conversion pending count',
        'round_status_count' => 'Round status count',
    ],
    'shortlisted_rounds_selected' => [
        'total_shortlisted_onhold_candidate' => 'Total Shortlisted/Onhold Candidate count',
        'shortlist_conversion_selected_count' => 'Shortlisted Conversion Selected count',
        'round_status_count' => 'Round status count',
    ],
    'crm_call_count_pending' => [
        'total_shortlisted_onhold_candidate' => 'Total Shortlisted/Onhold Candidate count',
        'shortlist_conversion_pending_count' => 'Shortlisted Conversion pending count',
        'call_stage_count' => 'Call stage count',
    ],
    'crm_call_count_joined_status' => [
        'selected_candidate_count' => 'Selected count',
        'shortlist_converted_selected_count' => 'Shortlisted/Onhold converted Selected count',
        'candidate_joined_status_other_count' => 'Candidate Joined Status count (other than Yes/No)',
        'call_stage_count_joined_other' => 'Call stage count',
    ],
];

function consolidated_detail_columns(string $section): array
{
    if ($section === 'crm_call_count_pending' || $section === 'crm_call_count_joined_status') {
        return [...CONSOLIDATED_CANDIDATE_COLUMNS, ...CONSOLIDATED_CALL_HISTORY_COLUMNS];
    }

    return CONSOLIDATED_CANDIDATE_COLUMNS;
}

function fetch_consolidated_distinct_values(string $column): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM($column), ''), 'Unknown') AS value
        FROM job_fair_result
        ORDER BY value ASC";

    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function build_consolidated_filters(): array
{
    return [
        'aggregator' => trim((string) ($_GET['aggregator'] ?? '')),
        'candidate_district' => trim((string) ($_GET['candidate_district'] ?? '')),
        'job_fair' => trim((string) ($_GET['job_fair'] ?? '')),
        'category' => trim((string) ($_GET['category'] ?? '')),
        'selection_status' => trim((string) ($_GET['selection_status'] ?? '')),
        'round_number' => trim((string) ($_GET['round_number'] ?? '')),
        'round_selection_status' => trim((string) ($_GET['round_selection_status'] ?? '')),
        'call_stage' => trim((string) ($_GET['call_stage'] ?? '')),
    ];
}

function normalized_column(string $column): string
{
    return "LOWER(REPLACE(TRIM(COALESCE($column, '')), ' ', ''))";
}

function job_fair_result_has_id_column(): bool
{
    static $hasIdColumn = null;
    if ($hasIdColumn !== null) {
        return $hasIdColumn;
    }

    try {
        db()->query('SELECT id FROM job_fair_result LIMIT 1');
        $hasIdColumn = true;
    } catch (Throwable $exception) {
        $hasIdColumn = false;
    }

    return $hasIdColumn;
}

function build_common_conditions(array $filters, array &$params): array
{
    $conditions = [];

    if (($filters['aggregator'] ?? '') !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') = ?";
        $params[] = $filters['aggregator'];
    }

    if (($filters['candidate_district'] ?? '') !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Candidate_District), ''), 'Unknown') = ?";
        $params[] = $filters['candidate_district'];
    }

    if ($filters['job_fair'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') = ?";
        $params[] = $filters['job_fair'];
    }

    if ($filters['category'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Category), ''), 'Unknown') = ?";
        $params[] = $filters['category'];
    }

    return $conditions;
}

function fetch_selected_candidates_report(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);
    $conditions[] = normalized_column('Selection_Status') . " = 'selected'";

    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COUNT(*) AS total_selected_candidate,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'yes' THEN 1 ELSE 0 END) AS offer_generated_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) IN ('no', 'pending') OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '' THEN 1 ELSE 0 END) AS offer_generated_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'pending' OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '' THEN 1 ELSE 0 END) AS offer_generated_pending,
            SUM(CASE WHEN TRIM(COALESCE(Link_to_Offer_letter, '')) <> '' THEN 1 ELSE 0 END) AS offer_link_with_link,
            SUM(CASE WHEN TRIM(COALESCE(Link_to_Offer_letter, '')) = '' THEN 1 ELSE 0 END) AS offer_link_blank,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'yes' THEN 1 ELSE 0 END) AS link_verified_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'no' THEN 1 ELSE 0 END) AS link_verified_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'pending' OR TRIM(COALESCE(Link_to_Offer_letter_verified, '')) = '' THEN 1 ELSE 0 END) AS link_verified_pending,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'yes' THEN 1 ELSE 0 END) AS receipt_confirmed_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'no' THEN 1 ELSE 0 END) AS receipt_confirmed_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'pending' OR TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, '')) = '' THEN 1 ELSE 0 END) AS receipt_confirmed_pending,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS joined_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending' OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '' THEN 1 ELSE 0 END) AS joined_pending,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'future date' THEN 1 ELSE 0 END) AS joined_future_date
        FROM job_fair_result
        $whereClause
        GROUP BY job_fair_no
        ORDER BY job_fair_no ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_shortlisted_onhold_report(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);

    $selectionStatusExpression = normalized_column('Selection_Status');
    $conditions[] = "$selectionStatusExpression IN ('shortlisted', 'onhold')";

    if ($filters['selection_status'] !== '') {
        $conditions[] = "$selectionStatusExpression = ?";
        $params[] = strtolower(str_replace(' ', '', $filters['selection_status']));
    }

    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $shortlistStatusExpression = normalized_column('Shortlist_Candidate_Status');
    $categoryExpression = normalized_column('Category');

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COUNT(*) AS total_shortlisted_onhold_candidate,
            SUM(CASE WHEN $shortlistStatusExpression = 'shortlisted' THEN 1 ELSE 0 END) AS shortlist_status_shortlisted,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' THEN 1 ELSE 0 END) AS shortlist_status_selected,
            SUM(CASE WHEN $selectionStatusExpression IN ('shortlisted', 'onhold') AND $categoryExpression IN ('k-disc-rtd', 'rtd') THEN 1 ELSE 0 END) AS shortlist_status_rtd_jobs,
            SUM(CASE WHEN $shortlistStatusExpression IN ('rejected', 'candidatenotinterested') AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '') THEN 1 ELSE 0 END) AS shortlist_status_rejected,
            SUM(CASE WHEN $shortlistStatusExpression = 'onhold' AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '') THEN 1 ELSE 0 END) AS shortlist_status_onhold_only,
            SUM(CASE WHEN $shortlistStatusExpression = 'yettobecontacted' AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '') THEN 1 ELSE 0 END) AS shortlist_status_yet_to_be_contacted,
            SUM(CASE WHEN $shortlistStatusExpression = 'reviewinprogress' AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '') THEN 1 ELSE 0 END) AS shortlist_status_review_in_progress,
            SUM(CASE WHEN $shortlistStatusExpression IN ('selectedfornextround', 'shortlisted') AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '') THEN 1 ELSE 0 END) AS shortlist_status_selected_for_next_round_net,
            (
                SUM(CASE WHEN $shortlistStatusExpression IN ('onhold', '', 'shortlisted') THEN 1 ELSE 0 END)
                - SUM(CASE WHEN $selectionStatusExpression IN ('shortlisted', 'onhold') AND $categoryExpression IN ('k-disc-rtd', 'rtd') THEN 1 ELSE 0 END)
            ) AS shortlist_status_onhold,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'yes' THEN 1 ELSE 0 END) AS offer_generated_yes,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) IN ('no', 'pending') OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '') THEN 1 ELSE 0 END) AS offer_generated_no,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'pending' OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '') THEN 1 ELSE 0 END) AS offer_generated_pending,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND TRIM(COALESCE(Link_to_Offer_letter, '')) <> '' THEN 1 ELSE 0 END) AS offer_link_with_link,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND TRIM(COALESCE(Link_to_Offer_letter, '')) = '' THEN 1 ELSE 0 END) AS offer_link_blank,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'yes' THEN 1 ELSE 0 END) AS link_verified_yes,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'no' THEN 1 ELSE 0 END) AS link_verified_no,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'pending' OR TRIM(COALESCE(Link_to_Offer_letter_verified, '')) = '') THEN 1 ELSE 0 END) AS link_verified_pending,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'yes' THEN 1 ELSE 0 END) AS receipt_confirmed_yes,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'no' THEN 1 ELSE 0 END) AS receipt_confirmed_no,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'pending' OR TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, '')) = '') THEN 1 ELSE 0 END) AS receipt_confirmed_pending,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_yes,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS joined_no,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending' OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '') THEN 1 ELSE 0 END) AS joined_pending,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'future date' THEN 1 ELSE 0 END) AS joined_future_date
        FROM job_fair_result
        $whereClause
        GROUP BY job_fair_no
        ORDER BY job_fair_no ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_shortlisted_onhold_round_pivot_report(array $filters, string $conversionType = 'pending'): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);
    $selectionStatusExpression = normalized_column('jfr.Selection_Status');
    $shortlistStatusExpression = normalized_column('jfr.Shortlist_Candidate_Status');
    $categoryExpression = normalized_column('jfr.Category');
    $conditions[] = "$selectionStatusExpression IN ('shortlisted', 'onhold')";

    if ($filters['selection_status'] !== '') {
        $conditions[] = "$selectionStatusExpression = ?";
        $params[] = strtolower(str_replace(' ', '', $filters['selection_status']));
    }

    $pendingCondition = "$shortlistStatusExpression IN ('rejected', 'onhold', 'yettobecontacted', 'reviewinprogress', 'selectedfornextround', 'shortlisted') AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
    $selectedCondition = "$shortlistStatusExpression = 'selected'";
    $conversionCondition = $conversionType === 'selected' ? $selectedCondition : $pendingCondition;
    $pendingCountExpression = "SUM(
            CASE
                WHEN $shortlistStatusExpression IN ('rejected', 'onhold', 'yettobecontacted', 'reviewinprogress', 'selectedfornextround', 'shortlisted')
                    AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')
                THEN 1
                ELSE 0
            END
        )";
    $conversionCountExpression = $conversionType === 'selected'
        ? "SUM(CASE WHEN $selectedCondition THEN 1 ELSE 0 END)"
        : $pendingCountExpression;
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $baseParams = $params;
    $dateSql = "SELECT DISTINCT csr.round_number
        FROM job_fair_result jfr
        INNER JOIN candidate_shortlist_rounds csr ON csr.candidate_id = jfr.id
        $whereClause
        AND $conversionCondition
        ORDER BY csr.round_number ASC";
    $dateStmt = db()->prepare($dateSql);
    $dateStmt->execute($baseParams);
    $roundNumbers = array_map(static fn(array $row): string => (string) $row['round_number'], $dateStmt->fetchAll());

    $latestRoundSql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COUNT(*) AS total_shortlisted_onhold_candidate,
            $conversionCountExpression AS shortlist_conversion_count
        FROM job_fair_result jfr
        $whereClause
        GROUP BY job_fair_no
        ORDER BY job_fair_no ASC";
    $latestRoundStmt = db()->prepare($latestRoundSql);
    $latestRoundStmt->execute($baseParams);
    $rows = $latestRoundStmt->fetchAll();

    $statusLabels = ['Selected', 'Rejected', 'Candidate not Attended', 'Candidate Not Willing'];
    foreach ($rows as &$row) {
        foreach ($roundNumbers as $roundNumber) {
            foreach ($statusLabels as $statusLabel) {
                $row['pivot'][$roundNumber][$statusLabel] = 0;
            }
        }
    }
    unset($row);

    if ($rows === [] || $roundNumbers === []) {
        return ['round_numbers' => $roundNumbers, 'rows' => $rows, 'status_labels' => $statusLabels];
    }

    $pivotParams = $params;
    $pivotSql = "SELECT
            grouped.job_fair_no,
            grouped.round_number,
            grouped.round_selection_status,
            COUNT(*) AS total_count
        FROM (
            SELECT
                COALESCE(NULLIF(TRIM(jfr.Job_Fair_No), ''), 'Unknown') AS job_fair_no,
                csr.round_number,
                csr.round_selection_status,
                ROW_NUMBER() OVER (
                    PARTITION BY csr.candidate_id, csr.round_number
                    ORDER BY csr.round_scheduled_date DESC, csr.id DESC
                ) AS row_num
            FROM job_fair_result jfr
            INNER JOIN candidate_shortlist_rounds csr ON csr.candidate_id = jfr.id
            $whereClause
            AND $conversionCondition
        ) AS grouped
        WHERE grouped.row_num = 1
        GROUP BY grouped.job_fair_no, grouped.round_number, grouped.round_selection_status";
    $pivotStmt = db()->prepare($pivotSql);
    $pivotStmt->execute($pivotParams);
    $pivotRows = $pivotStmt->fetchAll();

    $rowIndex = [];
    foreach ($rows as $index => $row) {
        $rowIndex[(string) $row['job_fair_no']] = $index;
    }

    foreach ($pivotRows as $pivotRow) {
        $jobFairNo = (string) ($pivotRow['job_fair_no'] ?? '');
        $roundNumber = (string) ($pivotRow['round_number'] ?? '');
        $statusLabel = (string) ($pivotRow['round_selection_status'] ?? '');
        $totalCount = (int) ($pivotRow['total_count'] ?? 0);

        if (!isset($rowIndex[$jobFairNo]) || !isset($rows[$rowIndex[$jobFairNo]]['pivot'][$roundNumber][$statusLabel])) {
            continue;
        }

        $rows[$rowIndex[$jobFairNo]]['pivot'][$roundNumber][$statusLabel] = $totalCount;
    }

    return ['round_numbers' => $roundNumbers, 'rows' => $rows, 'status_labels' => $statusLabels];
}

function fetch_shortlisted_onhold_call_stage_pivot_report(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);
    $selectionStatusExpression = normalized_column('jfr.Selection_Status');
    $shortlistStatusExpression = normalized_column('jfr.Shortlist_Candidate_Status');
    $categoryExpression = normalized_column('jfr.Category');
    $conditions[] = "$selectionStatusExpression IN ('shortlisted', 'onhold')";

    if ($filters['selection_status'] !== '') {
        $conditions[] = "$selectionStatusExpression = ?";
        $params[] = strtolower(str_replace(' ', '', $filters['selection_status']));
    }

    $pendingCondition = "$shortlistStatusExpression IN ('rejected', 'onhold', 'yettobecontacted', 'reviewinprogress', 'selectedfornextround', 'shortlisted') AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
    $pendingCountExpression = "SUM(
            CASE
                WHEN $shortlistStatusExpression IN ('rejected', 'onhold', 'yettobecontacted', 'reviewinprogress', 'selectedfornextround', 'shortlisted')
                    AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')
                THEN 1
                ELSE 0
            END
        )";
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $stageSql = "SELECT DISTINCT COALESCE(NULLIF(TRIM(ch.stage), ''), 'Unknown') AS stage_label
        FROM job_fair_result jfr
        INNER JOIN candidate_call_history ch ON ch.candidate_id = jfr.id
        $whereClause
        AND $pendingCondition
        ORDER BY stage_label ASC";
    $stageStmt = db()->prepare($stageSql);
    $stageStmt->execute($params);
    $stages = array_map(static fn(array $row): string => (string) $row['stage_label'], $stageStmt->fetchAll());

    $baseSql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COUNT(*) AS total_shortlisted_onhold_candidate,
            $pendingCountExpression AS shortlist_conversion_count
        FROM job_fair_result jfr
        $whereClause
        GROUP BY job_fair_no
        ORDER BY job_fair_no ASC";
    $baseStmt = db()->prepare($baseSql);
    $baseStmt->execute($params);
    $rows = $baseStmt->fetchAll();

    foreach ($rows as &$row) {
        foreach ($stages as $stage) {
            $row['pivot'][$stage] = 0;
        }
    }
    unset($row);

    if ($rows === [] || $stages === []) {
        return ['stages' => $stages, 'rows' => $rows];
    }

    $pivotSql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COALESCE(NULLIF(TRIM(ch.stage), ''), 'Unknown') AS stage_label,
            COUNT(*) AS total_count
        FROM job_fair_result jfr
        INNER JOIN candidate_call_history ch ON ch.candidate_id = jfr.id
        $whereClause
        AND $pendingCondition
        GROUP BY job_fair_no, stage_label";
    $pivotStmt = db()->prepare($pivotSql);
    $pivotStmt->execute($params);
    $pivotRows = $pivotStmt->fetchAll();

    $rowIndex = [];
    foreach ($rows as $index => $row) {
        $rowIndex[(string) $row['job_fair_no']] = $index;
    }

    foreach ($pivotRows as $pivotRow) {
        $jobFairNo = (string) ($pivotRow['job_fair_no'] ?? '');
        $stageLabel = (string) ($pivotRow['stage_label'] ?? '');
        $count = (int) ($pivotRow['total_count'] ?? 0);
        if (!isset($rowIndex[$jobFairNo]) || !isset($rows[$rowIndex[$jobFairNo]]['pivot'][$stageLabel])) {
            continue;
        }

        $rows[$rowIndex[$jobFairNo]]['pivot'][$stageLabel] = $count;
    }

    return ['stages' => $stages, 'rows' => $rows];
}

function fetch_shortlisted_onhold_joined_status_call_stage_pivot_report(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);
    $selectionStatusExpression = normalized_column('jfr.Selection_Status');
    $shortlistStatusExpression = normalized_column('jfr.Shortlist_Candidate_Status');
    $joinedStatusExpression = normalized_column('jfr.Candidate_Joined_Status');
    $selectedCondition = "$selectionStatusExpression = 'selected'";
    $convertedSelectedCondition = "$selectionStatusExpression IN ('shortlisted', 'onhold') AND $shortlistStatusExpression = 'selected'";
    $eligibleCondition = "($selectedCondition OR $convertedSelectedCondition)";
    $conditions[] = $eligibleCondition;

    if ($filters['selection_status'] !== '') {
        $conditions[] = "$selectionStatusExpression = ?";
        $params[] = strtolower(str_replace(' ', '', $filters['selection_status']));
    }

    $joinedOtherCondition = "($joinedStatusExpression NOT IN ('yes', 'no') OR $joinedStatusExpression = '')";
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $stageSql = "SELECT DISTINCT COALESCE(NULLIF(TRIM(ch.stage), ''), 'Unknown') AS stage_label
        FROM job_fair_result jfr
        INNER JOIN candidate_call_history ch ON ch.candidate_id = jfr.id
        INNER JOIN users u ON u.id = ch.created_by
        $whereClause
        AND u.role = 'district_user'
        AND $joinedOtherCondition
        ORDER BY stage_label ASC";
    $stageStmt = db()->prepare($stageSql);
    $stageStmt->execute($params);
    $stages = array_map(static fn(array $row): string => (string) $row['stage_label'], $stageStmt->fetchAll());

    $baseSql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            SUM(CASE WHEN $selectedCondition THEN 1 ELSE 0 END) AS selected_candidate_count,
            SUM(CASE WHEN $convertedSelectedCondition THEN 1 ELSE 0 END) AS shortlist_converted_selected_count,
            SUM(CASE WHEN $eligibleCondition AND $joinedOtherCondition THEN 1 ELSE 0 END) AS candidate_joined_status_other_count
        FROM job_fair_result jfr
        $whereClause
        GROUP BY job_fair_no
        ORDER BY job_fair_no ASC";
    $baseStmt = db()->prepare($baseSql);
    $baseStmt->execute($params);
    $rows = $baseStmt->fetchAll();

    foreach ($rows as &$row) {
        foreach ($stages as $stage) {
            $row['pivot'][$stage] = 0;
        }
    }
    unset($row);

    if ($rows === [] || $stages === []) {
        return ['stages' => $stages, 'rows' => $rows];
    }

    $pivotSql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COALESCE(NULLIF(TRIM(ch.stage), ''), 'Unknown') AS stage_label,
            COUNT(*) AS total_count
        FROM job_fair_result jfr
        INNER JOIN candidate_call_history ch ON ch.candidate_id = jfr.id
        INNER JOIN users u ON u.id = ch.created_by
        $whereClause
        AND u.role = 'district_user'
        AND $joinedOtherCondition
        GROUP BY job_fair_no, stage_label";
    $pivotStmt = db()->prepare($pivotSql);
    $pivotStmt->execute($params);
    $pivotRows = $pivotStmt->fetchAll();

    $rowIndex = [];
    foreach ($rows as $index => $row) {
        $rowIndex[(string) $row['job_fair_no']] = $index;
    }

    foreach ($pivotRows as $pivotRow) {
        $jobFairNo = (string) ($pivotRow['job_fair_no'] ?? '');
        $stageLabel = (string) ($pivotRow['stage_label'] ?? '');
        $count = (int) ($pivotRow['total_count'] ?? 0);
        if (!isset($rowIndex[$jobFairNo]) || !isset($rows[$rowIndex[$jobFairNo]]['pivot'][$stageLabel])) {
            continue;
        }

        $rows[$rowIndex[$jobFairNo]]['pivot'][$stageLabel] = $count;
    }

    return ['stages' => $stages, 'rows' => $rows];
}

function fetch_selected_candidates_report_by_job_station(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);
    $conditions[] = normalized_column('Selection_Status') . " = 'selected'";

    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Candidate_Jobstation), ''), 'Unknown') AS job_station,
            COUNT(*) AS total_selected_candidate,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'yes' THEN 1 ELSE 0 END) AS offer_generated_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) IN ('no', 'pending') OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '' THEN 1 ELSE 0 END) AS offer_generated_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'pending' OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '' THEN 1 ELSE 0 END) AS offer_generated_pending,
            SUM(CASE WHEN TRIM(COALESCE(Link_to_Offer_letter, '')) <> '' THEN 1 ELSE 0 END) AS offer_link_with_link,
            SUM(CASE WHEN TRIM(COALESCE(Link_to_Offer_letter, '')) = '' THEN 1 ELSE 0 END) AS offer_link_blank,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'yes' THEN 1 ELSE 0 END) AS link_verified_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'no' THEN 1 ELSE 0 END) AS link_verified_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'pending' OR TRIM(COALESCE(Link_to_Offer_letter_verified, '')) = '' THEN 1 ELSE 0 END) AS link_verified_pending,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'yes' THEN 1 ELSE 0 END) AS receipt_confirmed_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'no' THEN 1 ELSE 0 END) AS receipt_confirmed_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'pending' OR TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, '')) = '' THEN 1 ELSE 0 END) AS receipt_confirmed_pending,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS joined_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending' OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '' THEN 1 ELSE 0 END) AS joined_pending
        FROM job_fair_result
        $whereClause
        GROUP BY job_station
        ORDER BY job_station ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_shortlisted_onhold_report_by_job_station(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);

    $selectionStatusExpression = normalized_column('Selection_Status');
    $conditions[] = "$selectionStatusExpression IN ('shortlisted', 'onhold')";

    if ($filters['selection_status'] !== '') {
        $conditions[] = "$selectionStatusExpression = ?";
        $params[] = strtolower(str_replace(' ', '', $filters['selection_status']));
    }

    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $shortlistStatusExpression = normalized_column('Shortlist_Candidate_Status');
    $categoryExpression = normalized_column('Category');

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Candidate_Jobstation), ''), 'Unknown') AS job_station,
            COUNT(*) AS total_shortlisted_onhold_candidate,
            SUM(CASE WHEN $shortlistStatusExpression = 'shortlisted' THEN 1 ELSE 0 END) AS shortlist_status_shortlisted,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' THEN 1 ELSE 0 END) AS shortlist_status_selected,
            SUM(CASE WHEN ($selectionStatusExpression = 'selected' AND $categoryExpression IN ('k-disc-rtd', 'rtd')) OR ($shortlistStatusExpression = 'selected' AND $categoryExpression IN ('k-disc-rtd', 'rtd')) THEN 1 ELSE 0 END) AS shortlist_status_rtd_jobs,
            SUM(CASE WHEN $shortlistStatusExpression IN ('rejected', 'candidatenotinterested') AND $categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '') THEN 1 ELSE 0 END) AS shortlist_status_rejected,
            SUM(CASE WHEN $shortlistStatusExpression IN ('onhold', '', 'shortlisted') THEN 1 ELSE 0 END) AS shortlist_status_onhold,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'yes' THEN 1 ELSE 0 END) AS offer_generated_yes,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) IN ('no', 'pending') OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '') THEN 1 ELSE 0 END) AS offer_generated_no,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'pending' OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '') THEN 1 ELSE 0 END) AS offer_generated_pending,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND TRIM(COALESCE(Link_to_Offer_letter, '')) <> '' THEN 1 ELSE 0 END) AS offer_link_with_link,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND TRIM(COALESCE(Link_to_Offer_letter, '')) = '' THEN 1 ELSE 0 END) AS offer_link_blank,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'yes' THEN 1 ELSE 0 END) AS link_verified_yes,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'no' THEN 1 ELSE 0 END) AS link_verified_no,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'pending' OR TRIM(COALESCE(Link_to_Offer_letter_verified, '')) = '') THEN 1 ELSE 0 END) AS link_verified_pending,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'yes' THEN 1 ELSE 0 END) AS receipt_confirmed_yes,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'no' THEN 1 ELSE 0 END) AS receipt_confirmed_no,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'pending' OR TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, '')) = '') THEN 1 ELSE 0 END) AS receipt_confirmed_pending,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_yes,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS joined_no,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND (LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending' OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '') THEN 1 ELSE 0 END) AS joined_pending,
            SUM(CASE WHEN $shortlistStatusExpression = 'selected' AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'future date' THEN 1 ELSE 0 END) AS joined_future_date
        FROM job_fair_result
        $whereClause
        GROUP BY job_station
        ORDER BY job_station ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function calculate_consolidated_totals(array $rows, array $keys): array
{
    $totals = array_fill_keys($keys, 0);

    foreach ($rows as $row) {
        foreach ($keys as $key) {
            $totals[$key] += (int) ($row[$key] ?? 0);
        }
    }

    return $totals;
}

function build_consolidated_detail_conditions(string $section, string $metric, array $filters, array &$params, ?string $jobFairRow, string $groupField = 'job_fair_no'): array
{
    $conditions = build_common_conditions($filters, $params);
    $selectionStatusExpression = normalized_column('Selection_Status');
    $shortlistStatusExpression = normalized_column('Shortlist_Candidate_Status');

    if ($jobFairRow !== null) {
        $groupColumn = $groupField === 'candidate_job_station' ? 'Candidate_Jobstation' : 'Job_Fair_No';
        $conditions[] = "COALESCE(NULLIF(TRIM($groupColumn), ''), 'Unknown') = ?";
        $params[] = $jobFairRow;
    }

    if ($section === 'selected') {
        $conditions[] = "$selectionStatusExpression = 'selected'";

        switch ($metric) {
            case 'offer_generated_yes':
                $conditions[] = "LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'yes'";
                break;
            case 'offer_generated_no':
                $conditions[] = "(LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) IN ('no', 'pending') OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '')";
                break;
            case 'offer_link_with_link':
                $conditions[] = "TRIM(COALESCE(Link_to_Offer_letter, '')) <> ''";
                break;
            case 'offer_link_blank':
                $conditions[] = "TRIM(COALESCE(Link_to_Offer_letter, '')) = ''";
                break;
            case 'link_verified_yes':
                $conditions[] = "LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'yes'";
                break;
            case 'link_verified_no':
                $conditions[] = "LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'no'";
                break;
            case 'link_verified_pending':
                $conditions[] = "(LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'pending' OR TRIM(COALESCE(Link_to_Offer_letter_verified, '')) = '')";
                break;
            case 'receipt_confirmed_yes':
                $conditions[] = "LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'yes'";
                break;
            case 'receipt_confirmed_no':
                $conditions[] = "LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'no'";
                break;
            case 'receipt_confirmed_pending':
                $conditions[] = "(LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'pending' OR TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, '')) = '')";
                break;
            case 'joined_yes':
                $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes'";
                break;
            case 'joined_no':
                $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no'";
                break;
            case 'joined_pending':
                $conditions[] = "(LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending' OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '')";
                break;
            case 'joined_future_date':
                $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'future date'";
                break;
            case 'total_selected_candidate':
            default:
                break;
        }

        return $conditions;
    }

    if ($section === 'shortlisted_rounds_pending' || $section === 'shortlisted_rounds_selected' || $section === 'crm_call_count_pending' || $section === 'crm_call_count_joined_status') {
        $categoryExpression = normalized_column('Category');
        $pendingCondition = "$shortlistStatusExpression IN ('onhold', '', 'shortlisted')";
        $shortlistedRoundsPendingCondition = "$shortlistStatusExpression IN ('rejected', 'onhold', 'yettobecontacted', 'reviewinprogress', 'selectedfornextround', 'shortlisted')";
        $selectedCondition = "$shortlistStatusExpression = 'selected'";

        if ($section === 'crm_call_count_joined_status') {
            $selectedStatusCondition = "$selectionStatusExpression = 'selected'";
            $convertedSelectedCondition = "$selectionStatusExpression IN ('shortlisted', 'onhold') AND $selectedCondition";
            $conditions[] = "($selectedStatusCondition OR $convertedSelectedCondition)";
        } else {
            $conditions[] = "$selectionStatusExpression IN ('shortlisted', 'onhold')";
        }

        if ($filters['selection_status'] !== '') {
            $conditions[] = "$selectionStatusExpression = ?";
            $params[] = strtolower(str_replace(' ', '', $filters['selection_status']));
        }

        $isPendingSection = $section !== 'shortlisted_rounds_selected';

        if ($metric === 'call_stage_count') {
            $conditions[] = $shortlistedRoundsPendingCondition;
            $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
            $callStage = trim((string) ($filters['call_stage'] ?? ''));
            if ($callStage !== '' && job_fair_result_has_id_column()) {
                $conditions[] = "EXISTS (
                    SELECT 1
                    FROM candidate_call_history cch
                    WHERE cch.candidate_id = job_fair_result.id
                    AND COALESCE(NULLIF(TRIM(cch.stage), ''), 'Unknown') = ?
                )";
                $params[] = $callStage;
            } else {
                $conditions[] = '1 = 0';
            }
        }

        if ($metric === 'call_stage_count_joined_other') {
            $joinedStatusExpression = normalized_column('Candidate_Joined_Status');
            $conditions[] = "($joinedStatusExpression NOT IN ('yes', 'no') OR $joinedStatusExpression = '')";
            $callStage = trim((string) ($filters['call_stage'] ?? ''));
            if ($callStage !== '' && job_fair_result_has_id_column()) {
                $conditions[] = "EXISTS (
                    SELECT 1
                    FROM candidate_call_history cch
                    INNER JOIN users u ON u.id = cch.created_by
                    WHERE cch.candidate_id = job_fair_result.id
                    AND u.role = 'district_user'
                    AND COALESCE(NULLIF(TRIM(cch.stage), ''), 'Unknown') = ?
                )";
                $params[] = $callStage;
            } else {
                $conditions[] = '1 = 0';
            }
        }

        if ($metric === 'round_status_count') {
            if ($isPendingSection) {
                if ($section === 'shortlisted_rounds_pending') {
                    $conditions[] = $shortlistedRoundsPendingCondition;
                    $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
                } else {
                    $conditions[] = $pendingCondition;
                    $conditions[] = "$categoryExpression NOT IN ('k-disc-rtd', 'rtd')";
                }
            } else {
                $conditions[] = $selectedCondition;
            }
            $roundNumber = trim((string) ($filters['round_number'] ?? ''));
            $roundSelectionStatus = trim((string) ($filters['round_selection_status'] ?? ''));
            if (
                $roundNumber !== ''
                && ctype_digit($roundNumber)
                && $roundSelectionStatus !== ''
                && job_fair_result_has_id_column()
            ) {
                $conditions[] = "EXISTS (
                    SELECT 1
                    FROM (
                        SELECT csr2.round_selection_status
                        FROM candidate_shortlist_rounds csr2
                        WHERE csr2.candidate_id = job_fair_result.id
                        AND csr2.round_number = ?
                        ORDER BY csr2.round_scheduled_date DESC, csr2.id DESC
                        LIMIT 1
                    ) latest_round
                    WHERE latest_round.round_selection_status = ?
                )";
                $params[] = (int) $roundNumber;
                $params[] = $roundSelectionStatus;
            } else {
                $conditions[] = '1 = 0';
            }
        }

        if ($metric === 'shortlist_conversion_pending_count') {
            if ($section === 'shortlisted_rounds_pending') {
                $conditions[] = $shortlistedRoundsPendingCondition;
                $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
            } else {
                $conditions[] = $shortlistedRoundsPendingCondition;
                $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
            }
        }

        if ($metric === 'shortlist_conversion_selected_count') {
            $conditions[] = $selectedCondition;
        }

        if ($metric === 'candidate_joined_status_other_count') {
            $joinedStatusExpression = normalized_column('Candidate_Joined_Status');
            $conditions[] = "($joinedStatusExpression NOT IN ('yes', 'no') OR $joinedStatusExpression = '')";
        }

        if ($section === 'crm_call_count_joined_status' && $metric === 'shortlist_converted_selected_count') {
            $conditions[] = "$selectionStatusExpression IN ('shortlisted', 'onhold')";
            $conditions[] = $selectedCondition;
        }

        if ($section === 'crm_call_count_joined_status' && $metric === 'selected_candidate_count') {
            $conditions[] = "$selectionStatusExpression = 'selected'";
        }

        return $conditions;
    }

    $conditions[] = "$selectionStatusExpression IN ('shortlisted', 'onhold')";

    if ($filters['selection_status'] !== '') {
        $conditions[] = "$selectionStatusExpression = ?";
        $params[] = strtolower(str_replace(' ', '', $filters['selection_status']));
    }

    switch ($metric) {
        case 'shortlist_status_selected':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            break;
        case 'shortlist_status_rejected':
            $conditions[] = "$shortlistStatusExpression IN ('rejected', 'candidatenotinterested')";
            $categoryExpression = normalized_column('Category');
            $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
            break;
        case 'shortlist_status_rtd_jobs':
            $categoryExpression = normalized_column('Category');
            $conditions[] = "$selectionStatusExpression IN ('shortlisted', 'onhold')";
            $conditions[] = "$categoryExpression IN ('k-disc-rtd', 'rtd')";
            break;
        case 'shortlist_status_onhold':
            $conditions[] = "$shortlistStatusExpression IN ('onhold', '', 'shortlisted')";
            $categoryExpression = normalized_column('Category');
            $conditions[] = "$categoryExpression NOT IN ('k-disc-rtd', 'rtd')";
            break;
        case 'shortlist_status_onhold_only':
            $conditions[] = "$shortlistStatusExpression = 'onhold'";
            $categoryExpression = normalized_column('Category');
            $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
            break;
        case 'shortlist_status_yet_to_be_contacted':
            $conditions[] = "$shortlistStatusExpression = 'yettobecontacted'";
            $categoryExpression = normalized_column('Category');
            $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
            break;
        case 'shortlist_status_review_in_progress':
            $conditions[] = "$shortlistStatusExpression = 'reviewinprogress'";
            $categoryExpression = normalized_column('Category');
            $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
            break;
        case 'shortlist_status_selected_for_next_round_net':
            $conditions[] = "$shortlistStatusExpression IN ('selectedfornextround', 'shortlisted')";
            $categoryExpression = normalized_column('Category');
            $conditions[] = "$categoryExpression IN ('non-rtd', 'k-disc-non-rtd', '')";
            break;
        case 'offer_generated_yes':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'yes'";
            break;
        case 'offer_generated_no':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "(LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) IN ('no', 'pending') OR TRIM(COALESCE(Offer_Letter_Generated, '')) = '')";
            break;
        case 'offer_link_with_link':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "TRIM(COALESCE(Link_to_Offer_letter, '')) <> ''";
            break;
        case 'offer_link_blank':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "TRIM(COALESCE(Link_to_Offer_letter, '')) = ''";
            break;
        case 'link_verified_yes':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'yes'";
            break;
        case 'link_verified_no':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'no'";
            break;
        case 'link_verified_pending':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "(LOWER(TRIM(COALESCE(Link_to_Offer_letter_verified, ''))) = 'pending' OR TRIM(COALESCE(Link_to_Offer_letter_verified, '')) = '')";
            break;
        case 'receipt_confirmed_yes':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'yes'";
            break;
        case 'receipt_confirmed_no':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'no'";
            break;
        case 'receipt_confirmed_pending':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "(LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'pending' OR TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, '')) = '')";
            break;
        case 'joined_yes':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes'";
            break;
        case 'joined_no':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no'";
            break;
        case 'joined_pending':
            $conditions[] = "$shortlistStatusExpression = 'selected'";
            $conditions[] = "(LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending' OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '')";
            break;
        case 'total_shortlisted_onhold_candidate':
        default:
            break;
    }

    return $conditions;
}

function fetch_consolidated_detail_rows(string $section, string $metric, array $filters, ?string $jobFairRow, string $groupField = 'job_fair_no'): array
{
    if (
        ($section === 'crm_call_count_pending' && $metric === 'call_stage_count')
        || ($section === 'crm_call_count_joined_status' && $metric === 'call_stage_count_joined_other')
    ) {
        return fetch_consolidated_call_history_detail_rows($section, $metric, $filters, $jobFairRow, $groupField);
    }

    $params = [];
    $conditions = build_consolidated_detail_conditions($section, $metric, $filters, $params, $jobFairRow, $groupField);
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COALESCE(TRIM(DWMS_ID), '') AS dwms_id,
            COALESCE(TRIM(Candidate_Name), '') AS candidate_name,
            COALESCE(TRIM(Employer_ID), '') AS employer_id,
            COALESCE(TRIM(Employer_Name), '') AS employer_name,
            COALESCE(TRIM(Job_Id), '') AS job_id,
            COALESCE(TRIM(Job_Title_Name), '') AS job_title_name,
            COALESCE(TRIM(Candidate_District), '') AS candidate_district,
            COALESCE(TRIM(Mobile_Number), '') AS mobile_number,
            COALESCE(TRIM(SDPK), '') AS sdpk,
            COALESCE(TRIM(SDPK_District), '') AS sdpk_district,
            COALESCE(TRIM(Aggregator), '') AS aggregator,
            COALESCE(TRIM(CRM_Member), '') AS crm_member,
            COALESCE(TRIM(Category), '') AS category,
            COALESCE(TRIM(Selection_Status), '') AS selection_status,
            COALESCE(TRIM(Shortlist_Candidate_Status), '') AS shortlist_candidate_status,
            COALESCE(TRIM(Offer_Letter_Generated), '') AS offer_letter_generated,
            COALESCE(DATE_FORMAT(Offer_Letter_Generated_Date, '%Y-%m-%d %H:%i:%s'), '') AS offer_letter_generated_date,
            COALESCE(TRIM(Link_to_Offer_letter), '') AS link_to_offer_letter,
            COALESCE(TRIM(response_from_employer), '') AS response_from_employer,
            COALESCE(TRIM(Willing_to_Join), '') AS willing_to_join,
            COALESCE(TRIM(Challenge_to_be_addressed), '') AS challenge_to_be_addressed,
            COALESCE(TRIM(Specific_Issues_Report_to_MS), '') AS specific_issues_report_to_ms,
            COALESCE(TRIM(Candidate_Joined_Status), '') AS candidate_joined_status,
            COALESCE(TRIM(Candidate_Join_Remarks_Type), '') AS candidate_join_remarks_type,
            COALESCE(TRIM(Remarks_Candidate_Join), '') AS remarks_candidate_join
        FROM job_fair_result
        $whereClause
        ORDER BY job_fair_no ASC, Employer_Name ASC, Candidate_Name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_consolidated_call_history_detail_rows(string $section, string $metric, array $filters, ?string $jobFairRow, string $groupField = 'job_fair_no'): array
{
    $params = [];
    $conditions = build_consolidated_detail_conditions($section, $metric, $filters, $params, $jobFairRow, $groupField);
    $conditions = array_map(
        static fn(string $condition): string => str_replace('job_fair_result.', 'jfr.', $condition),
        $conditions
    );
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);
    $callStage = trim((string) ($filters['call_stage'] ?? ''));

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COALESCE(TRIM(jfr.DWMS_ID), '') AS dwms_id,
            COALESCE(TRIM(jfr.Candidate_Name), '') AS candidate_name,
            COALESCE(TRIM(jfr.Employer_ID), '') AS employer_id,
            COALESCE(TRIM(jfr.Employer_Name), '') AS employer_name,
            COALESCE(TRIM(jfr.Job_Id), '') AS job_id,
            COALESCE(TRIM(jfr.Job_Title_Name), '') AS job_title_name,
            COALESCE(TRIM(jfr.Candidate_District), '') AS candidate_district,
            COALESCE(TRIM(jfr.Mobile_Number), '') AS mobile_number,
            COALESCE(TRIM(jfr.SDPK), '') AS sdpk,
            COALESCE(TRIM(jfr.SDPK_District), '') AS sdpk_district,
            COALESCE(TRIM(jfr.Aggregator), '') AS aggregator,
            COALESCE(TRIM(jfr.CRM_Member), '') AS crm_member,
            COALESCE(TRIM(jfr.Category), '') AS category,
            COALESCE(TRIM(jfr.Selection_Status), '') AS selection_status,
            COALESCE(TRIM(jfr.Shortlist_Candidate_Status), '') AS shortlist_candidate_status,
            COALESCE(TRIM(jfr.Offer_Letter_Generated), '') AS offer_letter_generated,
            COALESCE(DATE_FORMAT(jfr.Offer_Letter_Generated_Date, '%Y-%m-%d %H:%i:%s'), '') AS offer_letter_generated_date,
            COALESCE(TRIM(jfr.Link_to_Offer_letter), '') AS link_to_offer_letter,
            COALESCE(TRIM(jfr.response_from_employer), '') AS response_from_employer,
            COALESCE(TRIM(jfr.Willing_to_Join), '') AS willing_to_join,
            COALESCE(TRIM(jfr.Challenge_to_be_addressed), '') AS challenge_to_be_addressed,
            COALESCE(TRIM(jfr.Specific_Issues_Report_to_MS), '') AS specific_issues_report_to_ms,
            COALESCE(TRIM(jfr.Candidate_Joined_Status), '') AS candidate_joined_status,
            COALESCE(TRIM(jfr.Candidate_Join_Remarks_Type), '') AS candidate_join_remarks_type,
            COALESCE(TRIM(jfr.Remarks_Candidate_Join), '') AS remarks_candidate_join,
            COALESCE(NULLIF(TRIM(ch.stage), ''), 'Unknown') AS call_stage,
            COALESCE(TRIM(cp.purpose_name), '') AS call_purpose,
            COALESCE(DATE_FORMAT(ch.call_datetime, '%Y-%m-%d %H:%i:%s'), '') AS call_datetime,
            COALESCE(TRIM(ch.call_status), '') AS call_status,
            COALESCE(TRIM(ch.call_remarks), '') AS call_remarks,
            COALESCE(TRIM(ch.call_name), '') AS call_name,
            COALESCE(TRIM(ch.call_mobile), '') AS call_mobile
        FROM job_fair_result jfr
        INNER JOIN candidate_call_history ch ON ch.candidate_id = jfr.id
        LEFT JOIN candidate_call_purpose cp ON cp.id = ch.purpose_id
        $whereClause
        " . (($section === 'crm_call_count_joined_status' && $metric === 'call_stage_count_joined_other') ? " AND EXISTS (SELECT 1 FROM users u WHERE u.id = ch.created_by AND u.role = 'district_user')" : '') . "
        " . ($callStage !== '' ? " AND COALESCE(NULLIF(TRIM(ch.stage), ''), 'Unknown') = ?" : '') . "
        ORDER BY job_fair_no ASC, jfr.Employer_Name ASC, jfr.Candidate_Name ASC, ch.call_datetime DESC, ch.id DESC";

    if ($callStage !== '') {
        $params[] = $callStage;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Aggregates activity-log entries authored by district_user accounts so admin
 * can do a district-wise review of district user activity on the post job
 * fair workflow. Reuses build_common_conditions() to honour the aggregator,
 * job_fair and category filters already on the consolidated report page.
 *
 * Grouped by Candidate_District (the column district users actually scope to
 * in /district_candidate_data.php).
 */
function fetch_district_user_activity_report(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);

    $jfrConditionsSql = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Candidate_District), ''), 'Unknown') AS district,
            COUNT(cmal.id) AS total_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Confirm Offer Letter Receipt by Candidate%' THEN 1 ELSE 0 END) AS receipt_confirm_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Candidate Joined Status%' THEN 1 ELSE 0 END) AS joined_status_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Willing to Join%' THEN 1 ELSE 0 END) AS willing_to_join_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Challenge Type%' OR cmal.activity_details LIKE '%Challenge to be addressed%' THEN 1 ELSE 0 END) AS challenge_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Remarks Candidate Join%' OR cmal.activity_details LIKE '%Candidate Join Remarks Type%' THEN 1 ELSE 0 END) AS join_remarks_updates,
            COUNT(DISTINCT cmal.candidate_id) AS distinct_candidates,
            COUNT(DISTINCT cmal.created_by) AS distinct_users,
            MAX(cmal.created_at) AS last_activity
        FROM candidate_manage_activity_log cmal
        INNER JOIN users u ON u.id = cmal.created_by
        INNER JOIN job_fair_result jfr ON jfr.id = cmal.candidate_id
        WHERE u.role = 'district_user'
            $jfrConditionsSql
        GROUP BY COALESCE(NULLIF(TRIM(jfr.Candidate_District), ''), 'Unknown')
        ORDER BY district ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Drill-down for the District User Activity Review table: lists the
 * district_user accounts active in a given Candidate_District with their
 * per-field activity counts.
 */
function fetch_district_user_activity_users(string $district, array $filters): array
{
    $params = [$district];
    $conditions = build_common_conditions($filters, $params);
    $jfrConditionsSql = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

    $sql = "SELECT
            u.id AS user_id,
            u.name AS user_name,
            u.mobile_number,
            u.email,
            COUNT(cmal.id) AS total_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Confirm Offer Letter Receipt by Candidate%' THEN 1 ELSE 0 END) AS receipt_confirm_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Candidate Joined Status%' THEN 1 ELSE 0 END) AS joined_status_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Willing to Join%' THEN 1 ELSE 0 END) AS willing_to_join_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Challenge Type%' OR cmal.activity_details LIKE '%Challenge to be addressed%' THEN 1 ELSE 0 END) AS challenge_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Remarks Candidate Join%' OR cmal.activity_details LIKE '%Candidate Join Remarks Type%' THEN 1 ELSE 0 END) AS join_remarks_updates,
            COUNT(DISTINCT cmal.candidate_id) AS distinct_candidates,
            MAX(cmal.created_at) AS last_activity
        FROM candidate_manage_activity_log cmal
        INNER JOIN users u ON u.id = cmal.created_by
        INNER JOIN job_fair_result jfr ON jfr.id = cmal.candidate_id
        WHERE u.role = 'district_user'
            AND COALESCE(NULLIF(TRIM(jfr.Candidate_District), ''), 'Unknown') = ?
            $jfrConditionsSql
        GROUP BY u.id, u.name, u.mobile_number, u.email
        ORDER BY total_updates DESC, u.name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Drill-down: distribution of Challenge_Type values among candidates in a
 * district touched by district users.
 */
function fetch_district_user_activity_challenges(string $district, array $filters): array
{
    $params = [$district];
    $conditions = build_common_conditions($filters, $params);
    $jfrConditionsSql = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Challenge_Type), ''), '(No Challenge Recorded)') AS challenge_type,
            COUNT(DISTINCT jfr.id) AS candidate_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(jfr.Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(jfr.Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS joined_no,
            MAX(cmal.created_at) AS last_update
        FROM candidate_manage_activity_log cmal
        INNER JOIN users u ON u.id = cmal.created_by
        INNER JOIN job_fair_result jfr ON jfr.id = cmal.candidate_id
        WHERE u.role = 'district_user'
            AND COALESCE(NULLIF(TRIM(jfr.Candidate_District), ''), 'Unknown') = ?
            $jfrConditionsSql
        GROUP BY COALESCE(NULLIF(TRIM(jfr.Challenge_Type), ''), '(No Challenge Recorded)')
        ORDER BY candidate_count DESC, challenge_type ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Drill-down: distribution of Candidate_Join_Remarks_Type among candidates
 * in a district touched by district users.
 */
function fetch_district_user_activity_join_remarks(string $district, array $filters): array
{
    $params = [$district];
    $conditions = build_common_conditions($filters, $params);
    $jfrConditionsSql = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(jfr.Candidate_Join_Remarks_Type), ''), '(No Remark Recorded)') AS remark_type,
            COUNT(DISTINCT jfr.id) AS candidate_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(jfr.Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(jfr.Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS joined_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(jfr.Candidate_Joined_Status, ''))) IN ('pending', 'future date') OR TRIM(COALESCE(jfr.Candidate_Joined_Status, '')) = '' THEN 1 ELSE 0 END) AS joined_pending,
            MAX(cmal.created_at) AS last_update
        FROM candidate_manage_activity_log cmal
        INNER JOIN users u ON u.id = cmal.created_by
        INNER JOIN job_fair_result jfr ON jfr.id = cmal.candidate_id
        WHERE u.role = 'district_user'
            AND COALESCE(NULLIF(TRIM(jfr.Candidate_District), ''), 'Unknown') = ?
            $jfrConditionsSql
        GROUP BY COALESCE(NULLIF(TRIM(jfr.Candidate_Join_Remarks_Type), ''), '(No Remark Recorded)')
        ORDER BY candidate_count DESC, remark_type ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Aggregates activity by CRM team users so admin can review who is doing
 * "first part" updates (up to offer letter receipt confirmed) versus
 * "field level" updates (joining, challenges, join remarks). Calls logged
 * by the CRM user are pulled from candidate_call_history and merged.
 *
 * Returns one row per CRM user with both activity-log and call counts.
 */
function fetch_crm_user_activity_report(array $filters): array
{
    // Activity-log aggregation.
    $params = [];
    $conditions = build_common_conditions($filters, $params);
    $jfrConditionsSql = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

    $sql = "SELECT
            u.id AS user_id,
            u.name AS user_name,
            u.mobile_number,
            COUNT(cmal.id) AS total_updates,
            SUM(CASE WHEN cmal.activity_section = 'shortlist_onhold' THEN 1 ELSE 0 END) AS shortlist_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%First Call Done%'
                  OR cmal.activity_details LIKE '%Offer Letter Generated%'
                  OR cmal.activity_details LIKE '%Link to Offer letter%'
                  OR cmal.activity_details LIKE '%Confirm Offer Letter Receipt by Candidate%'
                  OR cmal.activity_details LIKE '%confirmation date%'
                THEN 1 ELSE 0 END) AS first_part_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Offer Letter Generated%' THEN 1 ELSE 0 END) AS offer_generated_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Confirm Offer Letter Receipt by Candidate%' THEN 1 ELSE 0 END) AS receipt_confirm_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Willing to Join%'
                  OR cmal.activity_details LIKE '%Challenge Type%'
                  OR cmal.activity_details LIKE '%Challenge to be addressed%'
                  OR cmal.activity_details LIKE '%Candidate Joined Status%'
                  OR cmal.activity_details LIKE '%Candidate Joined Date%'
                  OR cmal.activity_details LIKE '%Candidate Joining Future Date%'
                  OR cmal.activity_details LIKE '%Offer Letter Join Date%'
                  OR cmal.activity_details LIKE '%Remarks Candidate Join%'
                  OR cmal.activity_details LIKE '%Candidate Join Remarks Type%'
                  OR cmal.activity_details LIKE '%response from employer%'
                THEN 1 ELSE 0 END) AS field_level_updates,
            SUM(CASE WHEN cmal.activity_details LIKE '%Candidate Joined Status%' THEN 1 ELSE 0 END) AS joined_status_updates,
            COUNT(DISTINCT cmal.candidate_id) AS distinct_candidates,
            MAX(cmal.created_at) AS last_activity
        FROM candidate_manage_activity_log cmal
        INNER JOIN users u ON u.id = cmal.created_by
        INNER JOIN job_fair_result jfr ON jfr.id = cmal.candidate_id
        WHERE u.role = 'crm_member'
            $jfrConditionsSql
        GROUP BY u.id, u.name, u.mobile_number";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $byUser = [];
    foreach ($stmt->fetchAll() as $r) {
        $uid = (int) $r['user_id'];
        $r['calls_count'] = 0;
        $r['distinct_call_candidates'] = 0;
        $byUser[$uid] = $r;
    }

    // Calls aggregation.
    $callParams = [];
    $callConditions = build_common_conditions($filters, $callParams);
    $callJfrConditionsSql = $callConditions === [] ? '' : (' AND ' . implode(' AND ', $callConditions));

    $callSql = "SELECT
            u.id AS user_id,
            u.name AS user_name,
            u.mobile_number,
            COUNT(cch.id) AS calls_count,
            COUNT(DISTINCT cch.candidate_id) AS distinct_call_candidates,
            MAX(cch.created_at) AS last_call
        FROM candidate_call_history cch
        INNER JOIN users u ON u.id = cch.created_by
        INNER JOIN job_fair_result jfr ON jfr.id = cch.candidate_id
        WHERE u.role = 'crm_member'
            $callJfrConditionsSql
        GROUP BY u.id, u.name, u.mobile_number";

    $stmtCalls = db()->prepare($callSql);
    $stmtCalls->execute($callParams);

    foreach ($stmtCalls->fetchAll() as $r) {
        $uid = (int) $r['user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'user_id' => $uid,
                'user_name' => $r['user_name'],
                'mobile_number' => $r['mobile_number'],
                'total_updates' => 0,
                'shortlist_updates' => 0,
                'first_part_updates' => 0,
                'offer_generated_updates' => 0,
                'receipt_confirm_updates' => 0,
                'field_level_updates' => 0,
                'joined_status_updates' => 0,
                'distinct_candidates' => 0,
                'last_activity' => null,
                'calls_count' => (int) $r['calls_count'],
                'distinct_call_candidates' => (int) $r['distinct_call_candidates'],
            ];
        } else {
            $byUser[$uid]['calls_count'] = (int) $r['calls_count'];
            $byUser[$uid]['distinct_call_candidates'] = (int) $r['distinct_call_candidates'];
        }
        $lastActivity = $byUser[$uid]['last_activity'] ?? null;
        $lastCall = $r['last_call'] ?? null;
        if ($lastCall && (!$lastActivity || strtotime((string) $lastCall) > strtotime((string) $lastActivity))) {
            $byUser[$uid]['last_activity'] = $lastCall;
        }
    }

    $rows = array_values($byUser);
    usort($rows, static function (array $a, array $b): int {
        $aScore = (int) ($a['total_updates'] ?? 0) + (int) ($a['calls_count'] ?? 0);
        $bScore = (int) ($b['total_updates'] ?? 0) + (int) ($b['calls_count'] ?? 0);
        if ($aScore === $bScore) {
            return strcasecmp((string) ($a['user_name'] ?? ''), (string) ($b['user_name'] ?? ''));
        }
        return $bScore <=> $aScore;
    });

    return $rows;
}

/**
 * For the Shortlisted/On hold tab: district-wise breakdown of joining
 * outcomes split into two cohorts matching the First and Second section
 * universes:
 *  - Selected directly (Selection_Status = 'Selected')
 *  - Shortlist/Onhold whose Final status = 'Selected'
 * Also reports the distinct job-station count per district.
 */
function fetch_shortlisted_district_jobstation_joined_report(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);
    $jfrConditionsSql = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Candidate_District), ''), 'Unknown') AS district,
            COUNT(DISTINCT NULLIF(TRIM(Candidate_Jobstation), '')) AS job_station_count,
            -- Direct Selected cohort
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'
                     AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS selected_joined_yes,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'
                     AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS selected_joined_no,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'
                     AND (LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending'
                          OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '') THEN 1 ELSE 0 END) AS selected_joined_pending,
            -- Shortlist/Onhold with Final Selected cohort
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
                     AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
                     AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS shortlist_joined_yes,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
                     AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
                     AND LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS shortlist_joined_no,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
                     AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
                     AND (LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending'
                          OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '') THEN 1 ELSE 0 END) AS shortlist_joined_pending
        FROM job_fair_result
        WHERE (
                LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'
                OR (
                    LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
                    AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
                )
          )
          $jfrConditionsSql
        GROUP BY COALESCE(NULLIF(TRIM(Candidate_District), ''), 'Unknown')
        ORDER BY district ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * For the Shortlisted/On hold tab: pivot of Candidate_Join_Remarks_Type
 * against Candidate_Joined_Status (Yes / No / Pending / Future Date).
 * Limited to Shortlisted / On hold candidates and the common filters.
 *
 * Returns ['rows' => [...], 'statuses' => [...]] so the caller can render
 * the matrix in any order.
 */
function fetch_shortlisted_join_remarks_pivot(array $filters): array
{
    $params = [];
    $conditions = build_common_conditions($filters, $params);
    $conditions[] = "(
        LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'
        OR (
            LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
            AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
        )
    )";
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Candidate_Join_Remarks_Type), ''), '(No Remark Type)') AS remark_type,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_yes,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no' THEN 1 ELSE 0 END) AS joined_no,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending'
                     OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '' THEN 1 ELSE 0 END) AS joined_pending,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'future date' THEN 1 ELSE 0 END) AS joined_future_date,
            COUNT(*) AS total
        FROM job_fair_result
        $whereClause
        GROUP BY COALESCE(NULLIF(TRIM(Candidate_Join_Remarks_Type), ''), '(No Remark Type)')
        ORDER BY total DESC, remark_type ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
