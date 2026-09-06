<?php
require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/pdf.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !resultspack_validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'The report request could not be verified. Please reload Results Pack and try again.';
    exit;
}

$selectedIds = resultspack_selected_tournament_ids($_POST);
if (!$selectedIds) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'At least one competition is required.';
    exit;
}
$tournaments = resultspack_fetch_selected_tournaments($selectedIds);
if (!$tournaments) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'The selected competitions could not be found.';
    exit;
}

$settings = array();
$dateFormat = strtolower(resultspack_normalise_whitespace($_POST['date_format'] ?? 'long'));
if (!in_array($dateFormat, array('short', 'long'), true)) {
    $dateFormat = 'long';
}
$defaults = array(
    'cover_title' => count($tournaments) === 1 ? reset($tournaments)['name'] : 'Combined competition results',
    'document_title' => 'Results Sheet',
    'event_name' => resultspack_normalise_whitespace($_POST['event_description'] ?? '') ?: (count($tournaments) === 1 ? reset($tournaments)['name'] : 'Combined competition results'),
    'date' => resultspack_pdf_date_range($tournaments, $dateFormat),
    'venue' => resultspack_pdf_common_location($tournaments),
    'individual_note' => 'See the individual result tables below.',
    'did_not_shoot' => 'See DNS entries within the individual result tables below.',
    'disqualifications' => 'No archers disqualified.',
    'circumstances' => 'No abnormal occurrences affecting the entire shoot.',
    'lady_paramount' => '',
    'lord_patron' => '',
    'cover_footer' => '',
);
foreach ($defaults as $key => $default) {
    $value = resultspack_normalise_whitespace($_POST[$key] ?? '');
    $settings[$key] = $value !== '' ? $value : $default;
}
if (function_exists('mb_substr')) {
    $settings['cover_title'] = mb_substr($settings['cover_title'], 0, 160, 'UTF-8');
} else {
    $settings['cover_title'] = substr($settings['cover_title'], 0, 160);
}
$settings['event_name_same_as_cover'] = !empty($_POST['event_name_same_as_cover']);
if ($settings['event_name_same_as_cover']) {
    $settings['event_name'] = resultspack_normalise_whitespace($settings['cover_title']) !== '' ? $settings['cover_title'] : $defaults['event_name'];
}
$settings['date_format'] = $dateFormat;
$settings['status'] = resultspack_record_status_text($_POST['record_status'] ?? 'none', $_POST);
$settings['issue_date'] = resultspack_issue_date($_POST['issue_date'] ?? '', date('Y-m-d'));
$settings['revision'] = max(1, (int) ($_POST['revision'] ?? 1));
$settings['revision_note'] = resultspack_normalise_whitespace($_POST['revision_note'] ?? '');
$settings['issue_label'] = resultspack_issue_label($settings['issue_date'], $settings['revision']);
$settings['organiser_name'] = resultspack_normalise_whitespace($_POST['organiser_name'] ?? '');
$settings['organiser_email'] = resultspack_normalise_whitespace($_POST['organiser_email'] ?? '');
$settings['organiser'] = resultspack_organiser_text($settings['organiser_name'], $settings['organiser_email']);
$settings['weather'] = resultspack_weather_text($_POST);
$settings['include_lady_paramount'] = !empty($_POST['include_lady_paramount']);
$settings['include_lord_patron'] = !empty($_POST['include_lord_patron']);
$settings['table_colour'] = resultspack_pdf_palette_key($_POST['table_colour'] ?? 'classic');

$awardLibrary = resultspack_fetch_award_library();
$includeCustomAwards = !empty($_POST['include_custom_awards']);
$customAwards = $includeCustomAwards ? resultspack_selected_custom_awards($_POST, $awardLibrary) : array();
$hasCustomAwards = $includeCustomAwards && !empty($customAwards);

$includeCover = !empty($_POST['include_cover']);
$includeIndividuals = !empty($_POST['include_individuals']);
$includeNonRanked = !empty($_POST['include_nonranked']);
$includeDns = !empty($_POST['include_dns']);
$settings['include_dns_cover_row'] = $includeIndividuals && $includeDns;
$rankBySubclass = !empty($_POST['rank_by_subclass']);
$includeTeams = !empty($_POST['include_teams']);
$includeFinals = !empty($_POST['include_finals']);

$availableFinalEvents = resultspack_fetch_final_events($tournaments);
$allowedFinalKeys = array('individual' => array(), 'team' => array());
foreach (array('individual', 'team') as $kind) {
    foreach (($availableFinalEvents[$kind] ?? array()) as $event) {
        $allowedFinalKeys[$kind][(int) $event['tournament_id'] . '|' . $event['code']] = true;
    }
}
$filterFinalSelection = function (array $selection, $kind) use ($allowedFinalKeys) {
    $out = array();
    foreach ($selection as $item) {
        $key = (int) ($item['tournament_id'] ?? 0) . '|' . resultspack_normalise_whitespace($item['code'] ?? '');
        if (!empty($allowedFinalKeys[$kind][$key])) {
            $out[] = $item;
        }
    }
    return $out;
};
$selectedIndividualFinals = $includeFinals ? $filterFinalSelection(resultspack_selected_final_event_keys($_POST, 'individual_final_events'), 'individual') : array();
$selectedTeamFinals = $includeFinals ? $filterFinalSelection(resultspack_selected_final_event_keys($_POST, 'team_final_events'), 'team') : array();
$individualFinalGroups = resultspack_group_selected_final_events($selectedIndividualFinals);
$teamFinalGroups = resultspack_group_selected_final_events($selectedTeamFinals);
$finalOptions = array(
    'individual_rankings' => $includeFinals && !empty($_POST['include_individual_final_rankings']),
    'individual_brackets' => $includeFinals && !empty($_POST['include_individual_brackets']),
    'team_rankings' => $includeFinals && !empty($_POST['include_team_final_rankings']),
    'team_brackets' => $includeFinals && !empty($_POST['include_team_brackets']),
);
$hasIndividualFinalContent = !empty($individualFinalGroups) && ($finalOptions['individual_rankings'] || $finalOptions['individual_brackets']);
$hasTeamFinalContent = !empty($teamFinalGroups) && ($finalOptions['team_rankings'] || $finalOptions['team_brackets']);
$hasFinalContent = $hasIndividualFinalContent || $hasTeamFinalContent;

if (!$includeCover && !$includeIndividuals && !$includeTeams && !$hasFinalContent && !$hasCustomAwards) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Please select at least one PDF section.';
    exit;
}

$individualSource = strtolower((string) ($_POST['individual_source'] ?? 'events'));
if (!in_array($individualSource, array('events', 'divclass'), true)) {
    $individualSource = 'events';
}

$individualEventMode = strtolower((string) ($_POST['individual_event_mode'] ?? 'all'));
$selectedIndividualEventCodes = $individualEventMode === 'specific' ? resultspack_selected_individual_event_codes($_POST) : array();
if ($individualEventMode === 'specific' && !$selectedIndividualEventCodes) {
    $selectedIndividualEventCodes = array('__RESULTSPACK_NONE__');
}
$teamEventMode = strtolower((string) ($_POST['team_event_mode'] ?? 'all'));
$selectedTeamEventCodes = $teamEventMode === 'specific' ? resultspack_selected_team_event_codes($_POST) : array();
if ($teamEventMode === 'specific' && !$selectedTeamEventCodes) {
    $selectedTeamEventCodes = array('__RESULTSPACK_NONE__');
}

$combined = array('tournaments' => $tournaments, 'entries' => array(), 'warnings' => array());
if ($includeIndividuals && $individualSource === 'events') {
    $rows = resultspack_fetch_combined_rows($tournaments, $selectedIndividualEventCodes);
    $combined = resultspack_build_combined_results($tournaments, $rows, $rankBySubclass);
}
$teamData = array('sections' => array(), 'warnings' => array());
if ($includeTeams) {
    $teamData = resultspack_fetch_native_teams($tournaments, $selectedTeamEventCodes);
}

$teamQualificationCoversFinals = false;
if ($hasTeamFinalContent && $includeTeams && count($tournaments) === 1) {
    if ($teamEventMode !== 'specific') {
        $teamQualificationCoversFinals = true;
    } else {
        $selectedMap = array_fill_keys($selectedTeamEventCodes, true);
        $teamQualificationCoversFinals = true;
        foreach ($teamFinalGroups as $codes) {
            foreach ($codes as $code) {
                if (empty($selectedMap[$code])) {
                    $teamQualificationCoversFinals = false;
                    break 2;
                }
            }
        }
    }
}
$includeSourceTeamQualification = $hasTeamFinalContent && !$teamQualificationCoversFinals;

$sessionBackup = $_SESSION;
$headerTournamentId = (int) array_key_first($tournaments);
if (!CreateTourSession($headerTournamentId)) {
    $_SESSION = $sessionBackup;
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to prepare the PDF header from the selected competition.';
    exit;
}
$pdf = new ResultsPackPdf($settings['document_title'], true, '', false);
$_SESSION = $sessionBackup;
$pdf->Name = $settings['cover_title'];
$pdf->Oc = count($tournaments) === 1 ? reset($tournaments)['name'] : count($tournaments) . ' competitions combined';
$pdf->Code = count($tournaments) === 1 ? reset($tournaments)['code'] : '';
$pdf->Where = $settings['venue'];
$pdf->TournamentDate2String = $settings['date'];
$pdf->Titolo = $settings['document_title'];
$pdf->ResultsPackIssue = $settings['issue_label'];
$pdf->ResultsPackTableColour = $settings['table_colour'];
$pdf->setDocUpdate(date('Y-m-d H:i:s'));
$pdf->startPageGroup();

if ($includeCover) {
    $pdf->ResultsPackCover = true;
    $pdf->AddPage();
    resultspack_render_cover($pdf, $settings, $tournaments);
    $pdf->ResultsPackCover = false;
}

if ($includeIndividuals) {
    if ($individualSource === 'divclass') {
        resultspack_render_native_divclass_individuals($pdf, $tournaments, $rankBySubclass, $includeNonRanked, $includeDns);
    } else {
        $pdf->AddPage();
        if (count($tournaments) > 1) {
            $pdf->SetFont($pdf->FontStd, '', 7.5);
            $pdf->MultiCell(resultspack_pdf_content_width($pdf), 4, 'Combined qualification results: selected competition totals are added within matching IANSEO individual-event codes and ranked by score, then the two IANSEO tie-break values configured for the competition.' . ($rankBySubclass ? ' Subclasses are ranked separately.' : ''), 0, 'L', 0, 1);
            $pdf->Ln(1.5);
        }
        if ($combined['entries']) {
            resultspack_render_individual_results($pdf, $combined, $includeNonRanked, $includeDns);
        } else {
            $pdf->SetFont($pdf->FontStd, '', 9);
            $pdf->MultiCell(resultspack_pdf_content_width($pdf), 6, 'No individual qualification results were available for the selected event choice.', 1, 'L', 0, 1);
        }
    }
}

if ($includeTeams && !empty($teamData['sections'])) {
    $pdf->AddPage();
    resultspack_render_team_results($pdf, $teamData, $tournaments);
}

if ($includeSourceTeamQualification) {
    resultspack_render_native_final_team_qualification($pdf, $teamFinalGroups);
}

if ($hasFinalContent) {
    resultspack_render_native_finals($pdf, $tournaments, $individualFinalGroups, $teamFinalGroups, $finalOptions);
}

if ($hasCustomAwards) {
    resultspack_render_custom_awards($pdf, $customAwards);
}

$filename = resultspack_slug($settings['cover_title']) . '-results-pack-' . date('Ymd-His') . '.pdf';
$pdf->Output($filename, 'I');
