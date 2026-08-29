<?php
function resultspack_sort_team_sections(array $sections)
{
    usort($sections, function ($a, $b) {
        $cmp = ((int) ($a['order'] ?? 250)) <=> ((int) ($b['order'] ?? 250));
        if ($cmp !== 0) return $cmp;
        return strcasecmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
    });
    return $sections;
}

function resultspack_fetch_native_teams(array $tournaments, array $selectedEventCodes = array())
{
    if (!$tournaments) {
        return array('sections' => array(), 'warnings' => array());
    }
    $ids = array_keys($tournaments);
    $idList = implode(',', array_map('intval', $ids));
    $eventFilter = '';
    if ($selectedEventCodes) {
        $safeCodes = array_map('StrSafe_DB', array_values(array_unique($selectedEventCodes)));
        $eventFilter = ' AND te.TeEvent IN (' . implode(',', $safeCodes) . ')';
    }
    $teamSql = "SELECT te.TeCoId, te.TeSubTeam, te.TeEvent, te.TeTournament, te.TeScore, te.TeHits, te.TeGold, te.TeXnine, te.TeRank, te.TeIrmType,\n" .
        " COALESCE(c.CoCode,'') CoCode, COALESCE(c.CoName,'') CoName,\n" .
        " COALESCE(ev.EvEventName,'') EvEventName, COALESCE(ev.EvProgr,250) EventOrder\n" .
        "FROM Teams te\n" .
        "LEFT JOIN Countries c ON c.CoId=te.TeCoId AND c.CoTournament=te.TeTournament\n" .
        "LEFT JOIN Events ev ON ev.EvCode=te.TeEvent AND ev.EvTournament=te.TeTournament AND ev.EvTeamEvent=1\n" .
        "WHERE te.TeTournament IN ($idList) AND te.TeFinEvent=1 AND te.TeScore>0{$eventFilter}\n" .
        "ORDER BY te.TeTournament, COALESCE(ev.EvProgr,250), te.TeEvent, te.TeRank, te.TeScore DESC";
    $result = safe_r_sql($teamSql);
    $sourceTeams = array();
    while ($row = safe_fetch($result)) {
        $sourceKey = (int) $row->TeTournament . '|' . (int) $row->TeCoId . '|' . (int) $row->TeSubTeam . '|' . (string) $row->TeEvent;
        $sourceTeams[$sourceKey] = array(
            'tournament_id' => (int) $row->TeTournament,
            'country_id' => (int) $row->TeCoId,
            'subteam' => (int) $row->TeSubTeam,
            'event_code' => (string) $row->TeEvent,
            'event_label' => resultspack_normalise_whitespace($row->EvEventName) ?: (string) $row->TeEvent,
            'event_order' => (int) $row->EventOrder,
            'club_code' => (string) $row->CoCode,
            'club' => (string) $row->CoName,
            'score' => (int) $row->TeScore,
            'hits' => (int) $row->TeHits,
            'golds' => (int) $row->TeGold,
            'xs' => (int) $row->TeXnine,
            'rank' => (int) $row->TeRank,
            'members' => array(),
        );
    }
    if (!$sourceTeams) {
        return array('sections' => array(), 'warnings' => array('No qualification team results were found in the selected competition data.'));
    }

    $componentSql = "SELECT tc.TcCoId, tc.TcSubTeam, tc.TcTournament, tc.TcEvent, tc.TcOrder, e.EnId, e.EnName, e.EnFirstName, e.EnDivision, e.EnClass, e.EnSubClass, e.EnAgeClass, q.QuScore, COALESCE(d.DivDescription,e.EnDivision) DivDescription, COALESCE(cl.ClDescription,e.EnClass) ClDescription\n" .
        "FROM TeamComponent tc\n" .
        "INNER JOIN Entries e ON e.EnId=tc.TcId\n" .
        "LEFT JOIN Qualifications q ON q.QuId=e.EnId\n" .
        "LEFT JOIN Divisions d ON d.DivId=e.EnDivision AND d.DivTournament=e.EnTournament\n" .
        "LEFT JOIN Classes cl ON cl.ClId=e.EnClass AND cl.ClTournament=e.EnTournament\n" .
        "WHERE tc.TcTournament IN ($idList) AND tc.TcFinEvent=1" . ($selectedEventCodes ? " AND tc.TcEvent IN (" . implode(',', array_map('StrSafe_DB', array_values(array_unique($selectedEventCodes)))) . ")" : "") . "\n" .
        "ORDER BY tc.TcTournament, tc.TcEvent, tc.TcCoId, tc.TcSubTeam, tc.TcOrder";
    $components = safe_r_sql($componentSql);
    while ($row = safe_fetch($components)) {
        $sourceKey = (int) $row->TcTournament . '|' . (int) $row->TcCoId . '|' . (int) $row->TcSubTeam . '|' . (string) $row->TcEvent;
        if (!isset($sourceTeams[$sourceKey])) {
            continue;
        }
        $sourceTeams[$sourceKey]['members'][] = array(
            'id' => (int) $row->EnId,
            'name' => resultspack_normalise_whitespace($row->EnName . ' ' . $row->EnFirstName),
            'division' => (string) $row->EnDivision,
            'class' => (string) $row->EnClass,
            'subclass' => (string) $row->EnSubClass,
            'ageclass' => (string) $row->EnAgeClass,
            'score' => (int) $row->QuScore,
            'division_label' => (string) $row->DivDescription,
            'class_label' => (string) $row->ClDescription,
            'tournament_id' => (int) $row->TcTournament,
        );
        if ($sourceTeams[$sourceKey]['event_label'] === $sourceTeams[$sourceKey]['event_code'] && $sourceTeams[$sourceKey]['event_code'] === ((string) $row->EnDivision . (string) $row->EnClass)) {
            $divisionLabel = resultspack_normalise_whitespace($row->DivDescription ?? '');
            $classLabel = resultspack_normalise_whitespace($row->ClDescription ?? '');
            if ($divisionLabel !== '' || $classLabel !== '') {
                $sourceTeams[$sourceKey]['event_label'] = trim($divisionLabel . ' - ' . $classLabel, ' -') . ' Team';
            }
        }
    }

    $combined = array();
    foreach ($sourceTeams as $team) {
        $clubIdentity = resultspack_normalise_key($team['club_code'] !== '' ? $team['club_code'] : $team['club']);
        $key = resultspack_normalise_key($team['event_code']) . '|' . $clubIdentity . '|sub:' . $team['subteam'];
        if (!isset($combined[$key])) {
            $combined[$key] = array(
                'event_code' => $team['event_code'],
                'event_label' => $team['event_label'],
                'event_order' => (int) ($team['event_order'] ?? 250),
                'club_code' => $team['club_code'],
                'club' => $team['club'],
                'subteam' => $team['subteam'],
                'score' => 0,
                'hits' => 0,
                'golds' => 0,
                'xs' => 0,
                'members' => array(),
                'tournament_scores' => array(),
            );
        }
        $combined[$key]['event_order'] = min((int) $combined[$key]['event_order'], (int) ($team['event_order'] ?? 250));
        $combined[$key]['score'] += $team['score'];
        $combined[$key]['hits'] += $team['hits'];
        $combined[$key]['golds'] += $team['golds'];
        $combined[$key]['xs'] += $team['xs'];
        $combined[$key]['tournament_scores'][$team['tournament_id']] = $team['score'];
        foreach ($team['members'] as $member) {
            $memberKey = resultspack_normalise_key($member['name']) . '|' . $member['division'] . '|' . $member['class'];
            if (!isset($combined[$key]['members'][$memberKey])) {
                $combined[$key]['members'][$memberKey] = $member;
                $combined[$key]['members'][$memberKey]['score'] = 0;
                $combined[$key]['members'][$memberKey]['events'] = array();
            }
            $combined[$key]['members'][$memberKey]['score'] += $member['score'];
            $combined[$key]['members'][$memberKey]['events'][$member['tournament_id']] = $member['score'];
        }
    }

    $sections = array();
    foreach ($combined as $team) {
        $team['members'] = array_values($team['members']);
        usort($team['members'], function ($a, $b) { return $b['score'] <=> $a['score']; });
        $sections[$team['event_code']]['code'] = $team['event_code'];
        $sections[$team['event_code']]['label'] = $team['event_label'];
        if (!isset($sections[$team['event_code']]['order'])) {
            $sections[$team['event_code']]['order'] = (int) ($team['event_order'] ?? 250);
        } else {
            $sections[$team['event_code']]['order'] = min((int) $sections[$team['event_code']]['order'], (int) ($team['event_order'] ?? 250));
        }
        $sections[$team['event_code']]['teams'][] = $team;
    }
    foreach ($sections as &$section) {
        usort($section['teams'], function ($a, $b) {
            foreach (array('score', 'golds', 'xs', 'hits') as $field) {
                $cmp = ((int) $b[$field]) <=> ((int) $a[$field]);
                if ($cmp !== 0) return $cmp;
            }
            return strcasecmp($a['club'], $b['club']);
        });
        $position = 0;
        $rank = 0;
        $last = null;
        foreach ($section['teams'] as &$team) {
            $position++;
            $tie = $team['score'] . '|' . $team['golds'] . '|' . $team['xs'] . '|' . $team['hits'];
            if ($tie !== $last) {
                $rank = $position;
                $last = $tie;
            }
            $team['rank'] = $rank;
        }
        unset($team);
    }
    unset($section);
    $sections = resultspack_sort_team_sections(array_values($sections));
    return array('sections' => $sections, 'warnings' => array());
}
