<?php
function resultspack_membership_is_usable($membership)
{
    $membership = preg_replace('/\s+/', '', (string) $membership);
    return $membership !== '' && !preg_match('/^0+$/', $membership);
}

function resultspack_name_key(array $row)
{
    return resultspack_normalise_key(($row['given_name'] ?? '') . ' ' . ($row['family_name'] ?? ''));
}

function resultspack_fallback_identity(array $row)
{
    return 'name:' . resultspack_name_key($row) . '|club:' . resultspack_normalise_key($row['club_name'] ?? $row['club_code'] ?? '');
}

function resultspack_build_combined_results(array $tournaments, array $rows, $rankBySubclass = false)
{
    $warnings = array();
    $codeNames = array();
    foreach ($rows as $row) {
        $membership = preg_replace('/\s+/', '', strtoupper((string) ($row['membership'] ?? '')));
        if (resultspack_membership_is_usable($membership)) {
            $codeNames[$membership][(int) $row['tournament_id']][resultspack_name_key($row)] = true;
        }
    }
    $conflictingCodes = array();
    foreach ($codeNames as $membership => $tournamentNames) {
        foreach ($tournamentNames as $names) {
            if (count($names) > 1) {
                $conflictingCodes[$membership] = true;
                $warnings[] = 'Membership number ' . $membership . ' identifies more than one name in the same competition; those records were matched by name and club.';
                break;
            }
        }
    }
    $nameClubCodes = array();
    foreach ($rows as $row) {
        $membership = preg_replace('/\s+/', '', strtoupper((string) ($row['membership'] ?? '')));
        if (resultspack_membership_is_usable($membership) && !isset($conflictingCodes[$membership])) {
            $nameClubCodes[resultspack_fallback_identity($row)][$membership] = $membership;
        }
    }

    $entries = array();
    foreach ($rows as $row) {
        $tournamentId = (int) $row['tournament_id'];
        if (!isset($tournaments[$tournamentId])) {
            continue;
        }
        $eventCode = resultspack_normalise_whitespace($row['event_code'] ?? '');
        if ($eventCode === '') {
            continue;
        }
        $membership = preg_replace('/\s+/', '', strtoupper((string) $row['membership']));
        $fallback = !resultspack_membership_is_usable($membership) || isset($conflictingCodes[$membership]);
        if ($fallback) {
            $possibilities = array_values($nameClubCodes[resultspack_fallback_identity($row)] ?? array());
            if (count($possibilities) === 1) {
                $membership = $possibilities[0];
                $identity = 'agb:' . $membership;
                $fallback = false;
            } else {
                $identity = resultspack_fallback_identity($row);
            }
        } else {
            $identity = 'agb:' . $membership;
        }
        $entryKey = $identity . '|event:' . resultspack_normalise_key($eventCode);
        if ($rankBySubclass) {
            $entryKey .= '|subclass:' . resultspack_normalise_key($row['subclass_code'] ?? '');
        }
        if (!isset($entries[$entryKey])) {
            $eventLabel = resultspack_normalise_whitespace($row['event_label'] ?? '') ?: $eventCode;
            $entries[$entryKey] = array(
                'identity' => $identity,
                'membership' => preg_replace('/^agb:/', '', $identity),
                'name' => resultspack_normalise_whitespace($row['given_name'] . ' ' . $row['family_name']),
                'event_code' => $eventCode,
                'event_label' => $eventLabel,
                'event_labels' => array($eventLabel => $eventLabel),
                'event_order' => (int) ($row['event_order'] ?? 250),
                'division_code' => $row['division_code'],
                'class_code' => $row['class_code'],
                'division' => $row['division'] !== '' ? $row['division'] : $row['division_code'],
                'class' => $row['class'] !== '' ? $row['class'] : $row['class_code'],
                'division_order' => $row['division_order'],
                'class_order' => $row['class_order'],
                'subclass_code' => $rankBySubclass ? ($row['subclass_code'] ?? '') : '',
                'subclass' => $rankBySubclass ? (($row['subclass'] ?? '') !== '' ? $row['subclass'] : ($row['subclass_code'] ?? '')) : '',
                'subclass_order' => $rankBySubclass ? (int) ($row['subclass_order'] ?? 250) : 250,
                'rank_by_subclass' => (bool) $rankBySubclass,
                'clubs' => array(),
                'club_codes' => array(),
                'subclass_codes' => array(),
                'age_class_codes' => array(),
                'events' => array(),
                'score' => 0,
                'hits' => 0,
                'golds' => 0,
                'xs' => 0,
                'rank' => 0,
                'fallback_identity' => $fallback,
                'disqualified' => false,
            );
        }
        $entry = &$entries[$entryKey];
        $rowEventLabel = resultspack_normalise_whitespace($row['event_label'] ?? '') ?: $eventCode;
        $entry['event_labels'][$rowEventLabel] = $rowEventLabel;
        $entry['event_order'] = min((int) $entry['event_order'], (int) ($row['event_order'] ?? 250));
        if ($row['club_name'] !== '') {
            $entry['clubs'][$row['club_name']] = $row['club_name'];
        } elseif ($row['club_code'] !== '') {
            $entry['clubs'][$row['club_code']] = $row['club_code'];
        }
        if ($row['club_code'] !== '') {
            $entry['club_codes'][$row['club_code']] = $row['club_code'];
        }
        if ($row['subclass_code'] !== '') {
            $entry['subclass_codes'][$row['subclass_code']] = $row['subclass_code'];
        }
        if ($row['age_class_code'] !== '') {
            $entry['age_class_codes'][$row['age_class_code']] = $row['age_class_code'];
        }
        $status = strtoupper(resultspack_normalise_whitespace($row['irm_type']));
        $showRank = (int) $row['irm_show_rank'];
        $isDsq = in_array($status, array('DSQ', 'DQB'), true) || in_array((int) $row['irm_id'], array(15, 20), true);
        if ($isDsq) {
            $entry['disqualified'] = true;
        }
        $event = array(
            'entry_id' => (int) $row['entry_id'],
            'target' => $row['target'],
            'status' => $status,
            'show_rank' => $showRank,
            'score' => $showRank ? (int) $row['score'] : 0,
            'hits' => $showRank ? (int) $row['hits'] : 0,
            'golds' => $showRank ? (int) $row['golds'] : 0,
            'xs' => $showRank ? (int) $row['xs'] : 0,
            'distances' => $row['distances'],
            'distance_labels' => $row['distance_labels'] ?? array(),
        );
        if (isset($entry['events'][$tournamentId])) {
            $existing = $entry['events'][$tournamentId];
            if (resultspack_compare_score_tuple($event, $existing) > 0) {
                $entry['events'][$tournamentId] = $event;
            }
            $warnings[] = $entry['name'] . ' has more than one entry in ' . $entry['event_label'] . ' at ' . $tournaments[$tournamentId]['name'] . '; the higher ranked score was retained.';
        } else {
            $entry['events'][$tournamentId] = $event;
        }
        unset($entry);
    }

    foreach ($entries as &$entry) {
        foreach ($entry['events'] as $event) {
            $entry['score'] += (int) $event['score'];
            $entry['hits'] += (int) $event['hits'];
            $entry['golds'] += (int) $event['golds'];
            $entry['xs'] += (int) $event['xs'];
        }
        $eventLabels = array_values($entry['event_labels']);
        natcasesort($eventLabels);
        $entry['event_label'] = implode(' / ', $eventLabels);
        unset($entry['event_labels']);
        $entry['clubs'] = array_values($entry['clubs']);
        natcasesort($entry['clubs']);
        $entry['club'] = implode(' / ', $entry['clubs']);
        $entry['club_codes'] = array_values($entry['club_codes']);
        $entry['subclass_codes'] = array_values($entry['subclass_codes']);
        $entry['age_class_codes'] = array_values($entry['age_class_codes']);
        $entry['tournaments_shot_count'] = count(array_filter($entry['events'], function ($event) { return (int) $event['score'] > 0; }));
        $entry['ranked'] = $entry['score'] > 0 && !$entry['disqualified'];
        $entry['status'] = '';
        if (!$entry['ranked']) {
            foreach ($entry['events'] as $event) {
                if ($event['status'] !== '') {
                    $entry['status'] = $event['status'];
                    break;
                }
            }
            if ($entry['status'] === '') {
                $entry['status'] = $entry['disqualified'] ? 'DSQ' : 'DNS';
            }
        }
    }
    unset($entry);

    $entries = array_values($entries);
    usort($entries, 'resultspack_compare_combined_entries');
    $lastGroup = null;
    $position = 0;
    $rank = 0;
    $lastTie = null;
    foreach ($entries as &$entry) {
        $group = $entry['event_code'] . ($entry['rank_by_subclass'] ? '|' . $entry['subclass_code'] : '');
        if ($group !== $lastGroup) {
            $position = 0;
            $rank = 0;
            $lastTie = null;
            $lastGroup = $group;
        }
        if (!$entry['ranked']) {
            $entry['rank'] = 0;
            continue;
        }
        $position++;
        $tie = $entry['score'] . '|' . $entry['golds'] . '|' . $entry['xs'] . '|' . $entry['hits'];
        if ($tie !== $lastTie) {
            $rank = $position;
            $lastTie = $tie;
        }
        $entry['rank'] = $rank;
    }
    unset($entry);

    return array(
        'tournaments' => $tournaments,
        'entries' => $entries,
        'warnings' => array_values(array_unique($warnings)),
    );
}

function resultspack_compare_combined_entries($a, $b)
{
    $leftGroup = array((int) ($a['event_order'] ?? 250), resultspack_normalise_key($a['event_code'] ?? ''), (int) ($a['subclass_order'] ?? 250), resultspack_normalise_key($a['subclass_code'] ?? $a['subclass'] ?? ''));
    $rightGroup = array((int) ($b['event_order'] ?? 250), resultspack_normalise_key($b['event_code'] ?? ''), (int) ($b['subclass_order'] ?? 250), resultspack_normalise_key($b['subclass_code'] ?? $b['subclass'] ?? ''));
    if ($leftGroup !== $rightGroup) {
        return $leftGroup <=> $rightGroup;
    }
    if ((bool) $a['ranked'] !== (bool) $b['ranked']) {
        return $a['ranked'] ? -1 : 1;
    }
    if ($a['ranked']) {
        foreach (array('score', 'golds', 'xs', 'hits') as $field) {
            $comparison = ((int) $b[$field]) <=> ((int) $a[$field]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }
    }
    return strcasecmp($a['name'], $b['name']);
}
