<?php
function resultspack_selected_tournament_ids($source)
{
    $values = isset($source['ToIds']) ? $source['ToIds'] : array();
    if (!is_array($values)) {
        $values = array($values);
    }
    $ids = array();
    foreach ($values as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function resultspack_fetch_tournament_list()
{
    $result = safe_r_sql(
        "SELECT ToId, ToType, ToCode, ToName, ToNameShort, ToWhere, ToVenue, ToWhenFrom, ToWhenTo, ToNumDist, ToGolds, ToXNine, ToTypeName, ToComDescr\n" .
        "FROM Tournament\n" .
        "ORDER BY ToWhenFrom DESC, ToName, ToId DESC"
    );
    $rows = array();
    while ($row = safe_fetch($result)) {
        $rows[] = array(
            'id' => (int) $row->ToId,
            'type' => (int) $row->ToType,
            'code' => (string) $row->ToCode,
            'name' => (string) $row->ToName,
            'short_name' => (string) $row->ToNameShort,
            'where' => (string) $row->ToWhere,
            'venue' => (string) $row->ToVenue,
            'date_from' => (string) $row->ToWhenFrom,
            'date_to' => (string) $row->ToWhenTo,
            'num_dist' => max(0, min(8, (int) $row->ToNumDist)),
            'golds_label' => (string) $row->ToGolds,
            'xnine_label' => (string) $row->ToXNine,
            'type_name' => (string) $row->ToTypeName,
            'organiser' => (string) $row->ToComDescr,
        );
    }
    return $rows;
}

function resultspack_fetch_selected_tournaments(array $ids)
{
    if (!$ids) {
        return array();
    }
    $idList = implode(',', array_map('intval', $ids));
    $result = safe_r_sql(
        "SELECT ToId, ToType, ToCode, ToName, ToNameShort, ToWhere, ToVenue, ToWhenFrom, ToWhenTo, ToNumDist, ToGolds, ToXNine, ToTypeName, ToComDescr\n" .
        "FROM Tournament\n" .
        "WHERE ToId IN ($idList)\n" .
        "ORDER BY ToWhenFrom, ToName, ToId"
    );
    $rows = array();
    while ($row = safe_fetch($result)) {
        $id = (int) $row->ToId;
        $rows[$id] = array(
            'id' => $id,
            'type' => (int) $row->ToType,
            'code' => (string) $row->ToCode,
            'name' => (string) $row->ToName,
            'short_name' => (string) $row->ToNameShort,
            'where' => (string) $row->ToWhere,
            'venue' => (string) $row->ToVenue,
            'date_from' => (string) $row->ToWhenFrom,
            'date_to' => (string) $row->ToWhenTo,
            'num_dist' => max(0, min(8, (int) $row->ToNumDist)),
            'golds_label' => (string) $row->ToGolds,
            'xnine_label' => (string) $row->ToXNine,
            'type_name' => (string) $row->ToTypeName,
            'organiser' => (string) $row->ToComDescr,
            'distance_summaries' => array(),
        );
    }
    if ($rows) {
        $summaries = resultspack_fetch_distance_summaries(array_keys($rows));
        foreach ($rows as $id => &$row) {
            $row['distance_summaries'] = $summaries[$id] ?? array();
        }
        unset($row);
    }
    return $rows;
}

function resultspack_fetch_distance_summaries(array $ids)
{
    if (!$ids) {
        return array();
    }
    $idList = implode(',', array_map('intval', $ids));
    $result = safe_r_sql(
        "SELECT TdTournament, Td1, Td2, Td3, Td4, Td5, Td6, Td7, Td8\n" .
        "FROM TournamentDistances WHERE TdTournament IN ($idList)"
    );
    $values = array();
    while ($row = safe_fetch($result)) {
        $tournamentId = (int) $row->TdTournament;
        for ($distance = 1; $distance <= 8; $distance++) {
            $property = 'Td' . $distance;
            $label = resultspack_normalise_whitespace($row->$property ?? '');
            if ($label !== '') {
                $values[$tournamentId][$distance][$label] = $label;
            }
        }
    }
    $summaries = array();
    foreach ($values as $tournamentId => $distances) {
        foreach ($distances as $distance => $labels) {
            $labels = array_values($labels);
            natcasesort($labels);
            $summaries[$tournamentId][$distance] = implode('/', $labels);
        }
    }
    return $summaries;
}

function resultspack_fetch_native_individual_events(array $tournaments)
{
    if (!$tournaments) {
        return array();
    }
    $idList = implode(',', array_map('intval', array_keys($tournaments)));
    $sql = "SELECT ev.EvTournament, ev.EvCode, COALESCE(NULLIF(ev.EvEventName,''), ev.EvCode) AS EvEventName, ev.EvProgr, " .
        "COUNT(DISTINCT ind.IndId) AS EntryCount " .
        "FROM Events ev " .
        "LEFT JOIN Individuals ind ON ind.IndTournament=ev.EvTournament AND ind.IndEvent=ev.EvCode " .
        "WHERE ev.EvTournament IN ($idList) AND ev.EvTeamEvent=0 " .
        "GROUP BY ev.EvTournament, ev.EvCode, ev.EvEventName, ev.EvProgr " .
        "ORDER BY ev.EvProgr, ev.EvCode";
    $result = safe_r_sql($sql);
    $events = array();
    while ($row = safe_fetch($result)) {
        $code = resultspack_normalise_whitespace($row->EvCode);
        if ($code === '') {
            continue;
        }
        if (!isset($events[$code])) {
            $events[$code] = array(
                'code' => $code,
                'labels' => array(),
                'tournaments' => array(),
                'entry_count' => 0,
                'order' => (int) $row->EvProgr,
            );
        }
        $label = resultspack_normalise_whitespace($row->EvEventName) ?: $code;
        $events[$code]['labels'][$label] = $label;
        $events[$code]['tournaments'][(int) $row->EvTournament] = (int) $row->EvTournament;
        $events[$code]['entry_count'] += (int) $row->EntryCount;
        $events[$code]['order'] = min($events[$code]['order'], (int) $row->EvProgr);
    }
    foreach ($events as &$event) {
        $labels = array_values($event['labels']);
        natcasesort($labels);
        $event['label'] = implode(' / ', $labels);
        $event['tournament_count'] = count($event['tournaments']);
        $event['labels'] = array_values($event['labels']);
        $event['tournaments'] = array_values($event['tournaments']);
    }
    unset($event);
    uasort($events, function ($a, $b) {
        $cmp = ((int) $a['order']) <=> ((int) $b['order']);
        if ($cmp !== 0) return $cmp;
        return strcasecmp($a['code'], $b['code']);
    });
    return array_values($events);
}

function resultspack_fetch_combined_rows(array $tournaments, array $selectedEventCodes = array())
{
    if (!$tournaments) {
        return array();
    }
    $idList = implode(',', array_map('intval', array_keys($tournaments)));
    $eventFilter = '';
    if ($selectedEventCodes) {
        $safeCodes = array_map('StrSafe_DB', array_values(array_unique($selectedEventCodes)));
        $eventFilter = ' AND ev.EvCode IN (' . implode(',', $safeCodes) . ')';
    }
    $distanceFields = array();
    for ($distance = 1; $distance <= 8; $distance++) {
        $distanceFields[] = "q.QuD{$distance}Score";
        $distanceFields[] = "q.QuD{$distance}Hits";
        $distanceFields[] = "q.QuD{$distance}Gold";
        $distanceFields[] = "q.QuD{$distance}Xnine";
    }
    $sql = "SELECT\n" .
        " e.EnId, e.EnTournament, e.EnCode, e.EnName, e.EnFirstName, e.EnDivision, e.EnClass, e.EnSubClass, e.EnAgeClass, e.EnStatus,\n" .
        " ind.IndEvent, ind.IndIrmType, ind.IndRank, ev.EvEventName, ev.EvProgr, ev.EvLockResults, ev.EvQualBestOfDistances,\n" .
        " COALESCE(c.CoCode, '') AS CoCode, COALESCE(c.CoName, '') AS CoName,\n" .
        " COALESCE(d.DivDescription, e.EnDivision) AS DivDescription, COALESCE(d.DivViewOrder, 250) AS DivViewOrder,\n" .
        " COALESCE(cl.ClDescription, e.EnClass) AS ClDescription, COALESCE(cl.ClViewOrder, 250) AS ClViewOrder,\n" .
        " COALESCE(sc.ScDescription, e.EnSubClass) AS ScDescription, COALESCE(sc.ScViewOrder, 250) AS ScViewOrder,\n" .
        " td.Td1 AS DistanceLabel1, td.Td2 AS DistanceLabel2, td.Td3 AS DistanceLabel3, td.Td4 AS DistanceLabel4, " .
        "td.Td5 AS DistanceLabel5, td.Td6 AS DistanceLabel6, td.Td7 AS DistanceLabel7, td.Td8 AS DistanceLabel8,\n" .
        " q.QuTargetNo,\n" .
        " IF((ev.EvLockResults OR ev.EvQualBestOfDistances), ind.IndScore, q.QuScore) AS ResultScore,\n" .
        " IF((ev.EvLockResults OR ev.EvQualBestOfDistances), ind.IndHits, q.QuHits) AS ResultHits,\n" .
        " IF((ev.EvLockResults OR ev.EvQualBestOfDistances), ind.IndGold, q.QuGold) AS ResultGold,\n" .
        " IF((ev.EvLockResults OR ev.EvQualBestOfDistances), ind.IndXnine, q.QuXnine) AS ResultXnine,\n" .
        " COALESCE(i.IrmType, '') AS IrmType, COALESCE(i.IrmShowRank, 1) AS IrmShowRank,\n" . implode(",\n", $distanceFields) . "\n" .
        "FROM Entries e\n" .
        "INNER JOIN Tournament t ON t.ToId=e.EnTournament\n" .
        "INNER JOIN Qualifications q ON q.QuId=e.EnId\n" .
        "INNER JOIN Individuals ind ON ind.IndId=e.EnId AND ind.IndTournament=e.EnTournament\n" .
        "INNER JOIN Events ev ON ev.EvTournament=e.EnTournament AND ev.EvCode=ind.IndEvent AND ev.EvTeamEvent=0\n" .
        "LEFT JOIN IrmTypes i ON i.IrmId=ind.IndIrmType\n" .
        "LEFT JOIN Countries c ON c.CoId=(CASE ev.EvTeamCreationMode WHEN 1 THEN e.EnCountry2 WHEN 2 THEN e.EnCountry3 ELSE e.EnCountry END) AND c.CoTournament=e.EnTournament\n" .
        "LEFT JOIN Divisions d ON d.DivId=e.EnDivision AND d.DivTournament=e.EnTournament\n" .
        "LEFT JOIN Classes cl ON cl.ClId=e.EnClass AND cl.ClTournament=e.EnTournament\n" .
        "LEFT JOIN SubClass sc ON sc.ScId=e.EnSubClass AND sc.ScTournament=e.EnTournament\n" .
        "LEFT JOIN TournamentDistances td ON td.TdTournament=e.EnTournament AND td.TdType=t.ToType " .
        "AND CONCAT(TRIM(e.EnDivision),TRIM(e.EnClass)) LIKE td.TdClasses\n" .
        "WHERE e.EnTournament IN ($idList) AND e.EnAthlete=1 AND e.EnStatus<=1{$eventFilter}\n" .
        "ORDER BY e.EnTournament, ev.EvProgr, ev.EvCode, e.EnId";
    $result = safe_r_sql($sql);
    $rows = array();
    while ($row = safe_fetch($result)) {
        $record = array(
            'entry_id' => (int) $row->EnId,
            'tournament_id' => (int) $row->EnTournament,
            'membership' => resultspack_normalise_whitespace($row->EnCode),
            'given_name' => resultspack_normalise_whitespace($row->EnName),
            'family_name' => resultspack_normalise_whitespace($row->EnFirstName),
            'event_code' => resultspack_normalise_whitespace($row->IndEvent),
            'event_label' => resultspack_normalise_whitespace($row->EvEventName) ?: resultspack_normalise_whitespace($row->IndEvent),
            'event_order' => (int) $row->EvProgr,
            'division_code' => resultspack_normalise_whitespace($row->EnDivision),
            'class_code' => resultspack_normalise_whitespace($row->EnClass),
            'subclass_code' => resultspack_normalise_whitespace($row->EnSubClass),
            'subclass' => resultspack_normalise_whitespace($row->ScDescription),
            'subclass_order' => (int) $row->ScViewOrder,
            'age_class_code' => resultspack_normalise_whitespace($row->EnAgeClass),
            'club_code' => resultspack_normalise_whitespace($row->CoCode),
            'club_name' => resultspack_normalise_whitespace($row->CoName),
            'division' => resultspack_normalise_whitespace($row->DivDescription),
            'division_order' => (int) $row->DivViewOrder,
            'class' => resultspack_normalise_class_description($row->ClDescription),
            'class_order' => (int) $row->ClViewOrder,
            'target' => resultspack_normalise_whitespace($row->QuTargetNo),
            'irm_id' => (int) $row->IndIrmType,
            'irm_type' => resultspack_normalise_whitespace($row->IrmType),
            'irm_show_rank' => (int) $row->IrmShowRank,
            'native_rank' => (int) $row->IndRank,
            'score' => (int) $row->ResultScore,
            'hits' => (int) $row->ResultHits,
            'golds' => (int) $row->ResultGold,
            'xs' => (int) $row->ResultXnine,
            'distances' => array(),
            'distance_labels' => array(),
        );
        for ($distance = 1; $distance <= 8; $distance++) {
            $scoreProperty = 'QuD' . $distance . 'Score';
            $hitsProperty = 'QuD' . $distance . 'Hits';
            $goldProperty = 'QuD' . $distance . 'Gold';
            $xProperty = 'QuD' . $distance . 'Xnine';
            $record['distances'][$distance] = array(
                'score' => (int) $row->$scoreProperty,
                'hits' => (int) $row->$hitsProperty,
                'golds' => (int) $row->$goldProperty,
                'xs' => (int) $row->$xProperty,
            );
            $labelProperty = 'DistanceLabel' . $distance;
            $record['distance_labels'][$distance] = resultspack_normalise_whitespace($row->$labelProperty ?? '');
        }
        $rows[] = $record;
    }
    return $rows;
}

function resultspack_fetch_native_team_events(array $tournaments)
{
    if (!$tournaments) {
        return array();
    }
    $idList = implode(',', array_map('intval', array_keys($tournaments)));
    $sql = "SELECT ev.EvTournament, ev.EvCode, COALESCE(NULLIF(ev.EvEventName,''), ev.EvCode) AS EvEventName, ev.EvProgr, " .
        "COUNT(DISTINCT CONCAT(te.TeCoId,'|',te.TeSubTeam)) AS TeamCount " .
        "FROM Events ev " .
        "LEFT JOIN Teams te ON te.TeTournament=ev.EvTournament AND te.TeEvent=ev.EvCode AND te.TeFinEvent=1 AND te.TeScore>0 " .
        "WHERE ev.EvTournament IN ($idList) AND ev.EvTeamEvent=1 " .
        "GROUP BY ev.EvTournament, ev.EvCode, ev.EvEventName, ev.EvProgr " .
        "ORDER BY ev.EvProgr, ev.EvCode";
    $result = safe_r_sql($sql);
    $events = array();
    while ($row = safe_fetch($result)) {
        $code = resultspack_normalise_whitespace($row->EvCode);
        if ($code === '') {
            continue;
        }
        if (!isset($events[$code])) {
            $events[$code] = array(
                'code' => $code,
                'labels' => array(),
                'tournaments' => array(),
                'team_count' => 0,
                'order' => (int) $row->EvProgr,
            );
        }
        $label = resultspack_normalise_whitespace($row->EvEventName) ?: $code;
        $events[$code]['labels'][$label] = $label;
        $events[$code]['tournaments'][(int) $row->EvTournament] = (int) $row->EvTournament;
        $events[$code]['team_count'] += (int) $row->TeamCount;
        $events[$code]['order'] = min($events[$code]['order'], (int) $row->EvProgr);
    }
    foreach ($events as &$event) {
        $labels = array_values($event['labels']);
        natcasesort($labels);
        $event['label'] = implode(' / ', $labels);
        $event['tournament_count'] = count($event['tournaments']);
        $event['labels'] = array_values($event['labels']);
        $event['tournaments'] = array_values($event['tournaments']);
    }
    unset($event);
    uasort($events, function ($a, $b) {
        $cmp = ((int) $a['order']) <=> ((int) $b['order']);
        if ($cmp !== 0) return $cmp;
        return strcasecmp($a['code'], $b['code']);
    });
    return array_values($events);
}

function resultspack_selected_event_codes($source, $field)
{
    $values = isset($source[$field]) ? $source[$field] : array();
    if (!is_array($values)) {
        $values = array($values);
    }
    $codes = array();
    foreach ($values as $value) {
        $code = resultspack_normalise_whitespace($value);
        if ($code !== '') {
            $codes[$code] = $code;
        }
    }
    return array_values($codes);
}

function resultspack_selected_individual_event_codes($source)
{
    return resultspack_selected_event_codes($source, 'individual_event_codes');
}

function resultspack_selected_team_event_codes($source)
{
    return resultspack_selected_event_codes($source, 'team_event_codes');
}


function resultspack_is_indoor_tournament(array $tournament)
{
    $parts = array(
        $tournament['name'] ?? '',
        $tournament['short_name'] ?? '',
        $tournament['type_name'] ?? '',
    );
    foreach (($tournament['distance_summaries'] ?? array()) as $summary) {
        $parts[] = $summary;
    }
    $haystack = function_exists('mb_strtolower')
        ? mb_strtolower(implode(' ', $parts), 'UTF-8')
        : strtolower(implode(' ', $parts));
    $normalised = preg_replace('/[^a-z0-9]+/', ' ', $haystack);
    $normalised = trim(preg_replace('/\s+/', ' ', $normalised));

    $indoorNeedles = array(
        'bray 1',
        'bray 2',
        'stafford',
        'portsmouth',
        'worcester',
        'vegas 300',
        'las vegas',
        'wa18m',
        'wa 18m',
        'wa25m',
        'wa 25m',
        'wa combined',
        'wa vi indoor',
    );
    foreach ($indoorNeedles as $needle) {
        $needleNormalised = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower($needle))));
        if ($needleNormalised !== '' && strpos($normalised, $needleNormalised) !== false) {
            return true;
        }
    }
    return false;
}

function resultspack_is_indoor_selection(array $tournaments)
{
    if (!$tournaments) {
        return false;
    }
    foreach ($tournaments as $tournament) {
        if (!resultspack_is_indoor_tournament($tournament)) {
            return false;
        }
    }
    return true;
}

function resultspack_fetch_final_events(array $tournaments)
{
    if (!$tournaments) {
        return array('individual' => array(), 'team' => array(), 'counts' => array('individual' => 0, 'team' => 0));
    }

    $idList = implode(',', array_map('intval', array_keys($tournaments)));
    $sql = "SELECT ev.EvTournament, ev.EvCode, COALESCE(NULLIF(ev.EvEventName,''), ev.EvCode) AS EvEventName, " .
        "ev.EvTeamEvent, ev.EvProgr, ev.EvFinalFirstPhase, ev.EvNumQualified, " .
        "CASE WHEN ev.EvTeamEvent=0 THEN (SELECT COUNT(*) FROM Finals f WHERE f.FinTournament=ev.EvTournament AND f.FinEvent=ev.EvCode) " .
        "ELSE (SELECT COUNT(*) FROM TeamFinals tf WHERE tf.TfTournament=ev.EvTournament AND tf.TfEvent=ev.EvCode) END AS FinalRows " .
        "FROM Events ev " .
        "WHERE ev.EvTournament IN ($idList) AND ev.EvFinalFirstPhase>0 " .
        "ORDER BY ev.EvTournament, ev.EvTeamEvent, ev.EvProgr, ev.EvCode";
    $result = safe_r_sql($sql);
    $out = array('individual' => array(), 'team' => array(), 'counts' => array('individual' => 0, 'team' => 0));
    while ($row = safe_fetch($result)) {
        $tourId = (int) $row->EvTournament;
        if (!isset($tournaments[$tourId])) {
            continue;
        }
        $kind = ((int) $row->EvTeamEvent === 1) ? 'team' : 'individual';
        $event = array(
            'tournament_id' => $tourId,
            'tournament_code' => $tournaments[$tourId]['code'] ?? '',
            'tournament_name' => $tournaments[$tourId]['name'] ?? ('Competition ' . $tourId),
            'code' => resultspack_normalise_whitespace($row->EvCode),
            'label' => resultspack_normalise_whitespace($row->EvEventName) ?: resultspack_normalise_whitespace($row->EvCode),
            'order' => (int) $row->EvProgr,
            'first_phase' => (int) $row->EvFinalFirstPhase,
            'qualified' => (int) $row->EvNumQualified,
            'result_rows' => (int) $row->FinalRows,
            'has_result_rows' => ((int) $row->FinalRows > 0),
        );
        if ($event['code'] === '') {
            continue;
        }
        $out[$kind][] = $event;
        $out['counts'][$kind]++;
    }
    return $out;
}

function resultspack_selected_final_event_keys($source, $field)
{
    $values = isset($source[$field]) ? $source[$field] : array();
    if (!is_array($values)) {
        $values = array($values);
    }
    $keys = array();
    foreach ($values as $value) {
        $value = resultspack_normalise_whitespace($value);
        if ($value === '' || strpos($value, '|') === false) {
            continue;
        }
        list($tourId, $eventCode) = array_pad(explode('|', $value, 2), 2, '');
        $tourId = (int) $tourId;
        $eventCode = resultspack_normalise_whitespace($eventCode);
        if ($tourId > 0 && $eventCode !== '') {
            $keys[$tourId . '|' . $eventCode] = array('tournament_id' => $tourId, 'code' => $eventCode);
        }
    }
    return array_values($keys);
}

function resultspack_group_selected_final_events(array $selected)
{
    $out = array();
    foreach ($selected as $item) {
        $tourId = (int) ($item['tournament_id'] ?? 0);
        $code = resultspack_normalise_whitespace($item['code'] ?? '');
        if ($tourId <= 0 || $code === '') {
            continue;
        }
        if (!isset($out[$tourId])) {
            $out[$tourId] = array();
        }
        $out[$tourId][$code] = $code;
    }
    foreach ($out as $tourId => $codes) {
        $out[$tourId] = array_values($codes);
    }
    return $out;
}

function resultspack_settings_key(array $ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
    sort($ids, SORT_NUMERIC);
    return 'tournaments:' . implode(',', $ids);
}

function resultspack_ensure_settings_table()
{
    static $done = false;
    if ($done) return;
    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS CustomResultsPackSettings (" .
        "CrpsKey varchar(190) NOT NULL," .
        "CrpsJson longtext NOT NULL," .
        "CrpsUpdated datetime NOT NULL," .
        "PRIMARY KEY (CrpsKey)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

function resultspack_load_settings($key)
{
    resultspack_ensure_settings_table();
    $key = resultspack_normalise_whitespace($key);
    if ($key === '') return array();
    $result = safe_r_sql('SELECT CrpsJson FROM CustomResultsPackSettings WHERE CrpsKey=' . StrSafe_DB($key) . ' LIMIT 1');
    $row = safe_fetch($result);
    if (!$row) return array();
    $decoded = json_decode((string) $row->CrpsJson, true);
    return is_array($decoded) ? $decoded : array();
}

function resultspack_save_settings($key, array $settings)
{
    resultspack_ensure_settings_table();
    $key = resultspack_normalise_whitespace($key);
    if (!preg_match('/^tournaments:\\d+(?:,\\d+)*$/', $key)) return false;
    $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 262144) return false;
    safe_w_sql(
        'INSERT INTO CustomResultsPackSettings (CrpsKey, CrpsJson, CrpsUpdated) VALUES (' .
        StrSafe_DB($key) . ',' . StrSafe_DB($json) . ',NOW()) ' .
        'ON DUPLICATE KEY UPDATE CrpsJson=VALUES(CrpsJson), CrpsUpdated=VALUES(CrpsUpdated)'
    );
    return true;
}


function resultspack_award_field_key($key)
{
    return substr(sha1((string) $key), 0, 12);
}

function resultspack_ensure_awards_table()
{
    static $done = false;
    if ($done) return;
    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS CustomResultsPackAwards (" .
        "CrpaKey varchar(80) NOT NULL," .
        "CrpaKind varchar(16) NOT NULL DEFAULT 'award'," .
        "CrpaSystem tinyint(1) NOT NULL DEFAULT 0," .
        "CrpaName varchar(255) NOT NULL DEFAULT ''," .
        "CrpaDescription text NOT NULL," .
        "CrpaCategory varchar(255) NOT NULL DEFAULT ''," .
        "CrpaSort int NOT NULL DEFAULT 0," .
        "CrpaUpdated datetime NOT NULL," .
        "PRIMARY KEY (CrpaKey), KEY CrpaSort (CrpaSort)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

function resultspack_fetch_award_library()
{
    resultspack_ensure_awards_table();
    $out = array();
    $res = safe_r_sql('SELECT CrpaKey,CrpaKind,CrpaSystem,CrpaName,CrpaDescription,CrpaCategory,CrpaSort FROM CustomResultsPackAwards ORDER BY CrpaSort, CrpaUpdated, CrpaKey');
    while ($row = safe_fetch($res)) {
        $kind = strtolower((string) $row->CrpaKind) === 'divider' ? 'divider' : 'award';
        $out[] = array(
            'key' => (string) $row->CrpaKey,
            'kind' => $kind,
            'system' => !empty($row->CrpaSystem),
            'name' => (string) $row->CrpaName,
            'description' => (string) $row->CrpaDescription,
            'category' => (string) $row->CrpaCategory,
            'sort' => (int) $row->CrpaSort,
            'field_key' => resultspack_award_field_key((string) $row->CrpaKey),
        );
    }
    return $out;
}

function resultspack_add_award_library_item($kind = 'award')
{
    resultspack_ensure_awards_table();
    $kind = strtolower((string) $kind) === 'divider' ? 'divider' : 'award';
    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Exception $e) {
        $suffix = str_replace('.', '', uniqid('', true));
    }
    $key = 'custom:' . $suffix;
    $res = safe_r_sql('SELECT COALESCE(MAX(CrpaSort),0) AS MaxSort FROM CustomResultsPackAwards');
    $row = safe_fetch($res);
    $sort = ($row ? (int) $row->MaxSort : 0) + 10;
    $name = $kind === 'divider' ? 'Award group' : 'New award';
    safe_w_sql(
        'INSERT INTO CustomResultsPackAwards (CrpaKey,CrpaKind,CrpaSystem,CrpaName,CrpaDescription,CrpaCategory,CrpaSort,CrpaUpdated) VALUES (' .
        StrSafe_DB($key) . ',' . StrSafe_DB($kind) . ',0,' . StrSafe_DB($name) . ",'' ,'' ," . (int) $sort . ',NOW())'
    );
    return $key;
}

function resultspack_update_award_library_item($key, array $values)
{
    resultspack_ensure_awards_table();
    $key = resultspack_normalise_whitespace($key);
    if (strpos($key, 'custom:') !== 0) return false;
    $name = resultspack_normalise_whitespace($values['name'] ?? '');
    $description = resultspack_normalise_whitespace($values['description'] ?? '');
    $category = resultspack_normalise_whitespace($values['category'] ?? '');
    if ($name === '') $name = 'Untitled award';
    safe_w_sql(
        'UPDATE CustomResultsPackAwards SET CrpaName=' . StrSafe_DB($name) .
        ',CrpaDescription=' . StrSafe_DB($description) .
        ',CrpaCategory=' . StrSafe_DB($category) .
        ',CrpaUpdated=NOW() WHERE CrpaKey=' . StrSafe_DB($key) . ' AND CrpaSystem=0'
    );
    return true;
}

function resultspack_delete_award_library_item($key)
{
    resultspack_ensure_awards_table();
    $key = resultspack_normalise_whitespace($key);
    if (strpos($key, 'custom:') !== 0) return false;
    safe_w_sql('DELETE FROM CustomResultsPackAwards WHERE CrpaKey=' . StrSafe_DB($key) . ' AND CrpaSystem=0');
    return true;
}

function resultspack_move_award_library_item($key, $direction)
{
    resultspack_ensure_awards_table();
    $items = resultspack_fetch_award_library();
    $index = null;
    foreach ($items as $i => $item) {
        if ($item['key'] === $key) { $index = $i; break; }
    }
    if ($index === null) return false;
    $target = $direction === 'up' ? $index - 1 : $index + 1;
    if ($target < 0 || $target >= count($items)) return true;
    $a = $items[$index];
    $b = $items[$target];
    safe_w_sql('UPDATE CustomResultsPackAwards SET CrpaSort=' . (int) $b['sort'] . ',CrpaUpdated=NOW() WHERE CrpaKey=' . StrSafe_DB($a['key']));
    safe_w_sql('UPDATE CustomResultsPackAwards SET CrpaSort=' . (int) $a['sort'] . ',CrpaUpdated=NOW() WHERE CrpaKey=' . StrSafe_DB($b['key']));
    return true;
}

function resultspack_selected_custom_awards(array $post, array $library)
{
    $out = array();
    $currentDivider = '';
    $currentGroupIncluded = true;
    foreach ($library as $item) {
        if ($item['kind'] === 'divider') {
            $dividerField = $item['field_key'];
            $currentDivider = resultspack_normalise_whitespace($post['award_definition_name_' . $dividerField] ?? $item['name']);
            $currentGroupIncluded = !empty($post['custom_award_group_include_' . $dividerField]);
            continue;
        }
        $field = $item['field_key'];
        $include = !empty($post['custom_award_include_' . $field]);
        $winner = resultspack_normalise_whitespace($post['custom_award_winner_' . $field] ?? '');

        if (!$currentGroupIncluded || !$include || $winner === '') continue;
        $name = resultspack_normalise_whitespace($post['award_definition_name_' . $field] ?? $item['name']);
        $description = resultspack_normalise_whitespace($post['award_definition_description_' . $field] ?? $item['description']);
        $category = resultspack_normalise_whitespace($post['award_definition_category_' . $field] ?? $item['category']);
        $out[] = array(
            'group' => $currentDivider,
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'winner' => $winner,
        );
    }
    return $out;
}
