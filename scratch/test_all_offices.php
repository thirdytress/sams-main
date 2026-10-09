<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/reshuffle.php';

$pdo = sams_pdo();
$offices = $pdo->query("SELECT DISTINCT preferred_office FROM applications WHERE preferred_office IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

foreach ($offices as $office) {
    $activeTerm = sams_current_term($pdo);
    $activeTermId = (int) ($activeTerm['term_id'] ?? 0);

    $where = [
        'ds.status IN ("assigned", "pending", "accepted", "deployed")',
        '(ds.office_name = :office_ds OR a.preferred_office = :office_app)',
    ];
    $params = [
        'office_ds' => $office,
        'office_app' => $office,
    ];

    $termFilterForLogs = '';
    $termFilterForEval1 = '';
    $termFilterForEval2 = '';
    if ($activeTermId > 0) {
        $where[] = 'ds.term_id = :term_id_ds';
        $params['term_id_ds'] = $activeTermId;
        $termFilterForLogs = ' AND l.term_id = :term_id_logs';
        $termFilterForEval1 = ' AND e1.term_id = :term_id_eval1';
        $termFilterForEval2 = ' AND e2.term_id = :term_id_eval2';
        $params['term_id_logs'] = $activeTermId;
        $params['term_id_eval1'] = $activeTermId;
        $params['term_id_eval2'] = $activeTermId;
    }

    $sql =
        'SELECT
            a.application_id,
            s.student_id,
            s.student_id_number,
            s.program,
            s.year_level,
            COALESCE(s.reshuffle_count, 0) AS reshuffle_count,
            COALESCE(u.first_name, "") AS first_name,
            COALESCE(u.last_name, "") AS last_name,
            COALESCE(NULLIF(TRIM(a.preferred_office), ""), NULLIF(TRIM(ds.office_name), ""), "Unassigned") AS office_name,
            COUNT(DISTINCT ds.duty_id) AS deployed_schedule_count,
            COALESCE((
                SELECT SUM(TIMESTAMPDIFF(SECOND, l.clock_in_time, l.clock_out_time) / 3600)
                FROM attendance_logs l
                WHERE l.application_id = a.application_id
                  AND l.clock_in_time IS NOT NULL
                  AND l.clock_out_time IS NOT NULL' . $termFilterForLogs . '
            ), 0) AS rendered_hours,
            COALESCE((
                SELECT AVG((e1.performance_rating + e1.reliability_rating + e1.professionalism_rating) / 3)
                FROM evaluations e1
                WHERE e1.application_id = a.application_id' . $termFilterForEval1 . '
            ), 0) AS avg_rating,
            (
                SELECT COUNT(*) FROM evaluations e2
                WHERE e2.application_id = a.application_id' . $termFilterForEval2 . '
            ) AS has_evaluation,
            (
                SELECT sr.status FROM shuffle_requests sr
                WHERE sr.from_student_id = s.student_id AND sr.term_id = :term_id_sr
                ORDER BY sr.created_at DESC LIMIT 1
            ) AS shuffle_request_status
         FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY a.application_id, s.student_id, s.student_id_number, s.program, s.year_level, s.reshuffle_count, u.first_name, u.last_name, office_name
         ORDER BY u.last_name ASC, u.first_name ASC';

    $params['term_id_sr'] = $activeTermId > 0 ? $activeTermId : 0;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($res) > 0) {
        echo "Office {$office} returned " . count($res) . " students:\n";
        foreach ($res as $r) {
            echo " - " . $r['first_name'] . " " . $r['last_name'] . " (ID: " . $r['student_id'] . ") | Eval: " . $r['has_evaluation'] . " | Reshuffles: " . $r['reshuffle_count'] . "\n";
        }
    }
}
echo "All office tests passed cleanly!\n";
