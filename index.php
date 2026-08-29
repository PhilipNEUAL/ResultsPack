<?php
require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/pdf.php');

function resultspack_builder_textarea($name, $value, $resettable = false, $compact = false)
{
    $default = resultspack_h($value);
    $compactAttrs = $compact ? ' rows="1" class="resultspack-autogrow"' : '';
    echo '<textarea name="' . resultspack_h($name) . '"' . $compactAttrs . ($resettable ? ' data-default="' . $default . '"' : '') . '>' . $default . '</textarea>';
    if ($resettable) {
        echo '<div class="resultspack-field-actions"><button type="button" class="Button resultspack-reset-default" data-field="' . resultspack_h($name) . '">Restore default text</button></div>';
    }
}

$tournamentList = resultspack_fetch_tournament_list();
$selectedIds = resultspack_selected_tournament_ids($_GET);
$selectedTournaments = $selectedIds ? resultspack_fetch_selected_tournaments($selectedIds) : array();
$first = $selectedTournaments ? reset($selectedTournaments) : null;
$count = count($selectedTournaments);
$nativeIndividualEvents = $selectedTournaments ? resultspack_fetch_native_individual_events($selectedTournaments) : array();
$nativeTeamEvents = $selectedTournaments ? resultspack_fetch_native_team_events($selectedTournaments) : array();
$finalEvents = $selectedTournaments ? resultspack_fetch_final_events($selectedTournaments) : array('individual' => array(), 'team' => array(), 'counts' => array('individual' => 0, 'team' => 0));
$awardLibrary = $selectedTournaments ? resultspack_fetch_award_library() : array();
$defaultCoverTitle = $first ? ($count === 1 ? $first['name'] : 'Combined competition results') : '';
$defaultDateShort = $selectedTournaments ? resultspack_pdf_date_range($selectedTournaments, 'short') : '';
$defaultDateLong = $selectedTournaments ? resultspack_pdf_date_range($selectedTournaments, 'long') : '';
$defaultVenue = $selectedTournaments ? resultspack_pdf_common_location($selectedTournaments) : '';
$defaultEventName = $first ? ($count === 1 ? $first['name'] : 'Combined results for ' . implode(', ', array_map(function ($t) { return $t['name']; }, $selectedTournaments))) : '';
$defaultOrganiserName = $first ? $first['organiser'] : '';
$defaultIndividualSource = $nativeIndividualEvents ? 'events' : 'divclass';
$detectedFinalCount = (int) (($finalEvents['counts']['individual'] ?? 0) + ($finalEvents['counts']['team'] ?? 0));
$detectedIndoor = resultspack_is_indoor_selection($selectedTournaments);
$storageKey = 'ianseo-results-pack-v1-' . implode('-', array_keys($selectedTournaments));
$legacyStorageKey = 'ianseo-results-pack-draft6-2-' . implode('-', array_keys($selectedTournaments));
$settingsKey = $selectedTournaments ? resultspack_settings_key(array_keys($selectedTournaments)) : '';
$serverSettings = $settingsKey !== '' ? resultspack_load_settings($settingsKey) : array();
$serverSettingsEncoded = $serverSettings ? base64_encode(json_encode($serverSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '';
$defaultIssueDate = date('Y-m-d');

$PAGE_TITLE = 'Complete Results Pack';
$IncludeJquery = true;
$JS_SCRIPT = array(
    '<link rel="stylesheet" href="assets/results-pack.css?v=1.0.0" type="text/css">',
    '<script src="assets/results-pack.js?v=1.0.0"></script>',
);
include('Common/Templates/head.php');

echo '<table class="Tabella freeWidth resultspack-intro">';
echo '<tr><th class="Main">Complete Results Pack - 1.0.0</th></tr>';
echo '</table>';

echo '<form method="get" action="index.php">';
echo '<table class="Tabella freeWidth resultspack-selector">';
echo '<tr><th class="Main" colspan="5">1. Select source competitions</th></tr>';
echo '<tr><th class="Title">Use</th><th class="Title">Date</th><th class="Title">Code</th><th class="Title">Competition</th><th class="Title">Distances</th></tr>';
$selectedMap = array_fill_keys($selectedIds, true);
foreach ($tournamentList as $tournament) {
    echo '<tr>';
    echo '<td class="Center"><input type="checkbox" name="ToIds[]" value="' . (int) $tournament['id'] . '"' . (isset($selectedMap[$tournament['id']]) ? ' checked="checked"' : '') . '></td>';
    echo '<td class="Center">' . resultspack_h(resultspack_format_date($tournament['date_from'])) . '</td>';
    echo '<td class="Center">' . resultspack_h($tournament['code']) . '</td>';
    echo '<td>' . resultspack_h($tournament['name']) . '<div class="resultspack-muted">' . resultspack_h($tournament['where'] ?: $tournament['venue']) . '</div></td>';
    echo '<td class="Center">' . (int) $tournament['num_dist'] . '</td>';
    echo '</tr>';
}
echo '<tr><td colspan="5" class="resultspack-actions"><button type="button" class="Button" id="resultspack-select-all">Select all</button> <button type="button" class="Button" id="resultspack-deselect-all">Deselect all</button> <input type="submit" value="Load report builder"></td></tr>';
echo '</table></form>';

if ($selectedTournaments) {
    echo '<form method="post" action="Generate.php" target="resultspack-pdf-preview" id="resultspack-generator" data-storage-key="' . resultspack_h($storageKey) . '" data-legacy-storage-key="' . resultspack_h($legacyStorageKey) . '" data-settings-key="' . resultspack_h($settingsKey) . '" data-server-settings="' . resultspack_h($serverSettingsEncoded) . '">';
    foreach (array_keys($selectedTournaments) as $id) {
        echo '<input type="hidden" name="ToIds[]" value="' . (int) $id . '">';
    }
    echo '<input type="hidden" name="csrf_token" value="' . resultspack_h(resultspack_csrf_token()) . '">';
    echo '<table class="Tabella freeWidth resultspack-form">';
    echo '<tr><th class="Main" colspan="2">2. Cover page and report contents</th></tr>';
    echo '<tr><th class="Title" style="width:28%">Field</th><th class="Title">Value</th></tr>';

    echo '<tr><td class="Bold">Cover page</td><td><label class="resultspack-section-master"><input type="checkbox" name="include_cover" value="1" checked> Include statutory Results Sheet cover page</label></td></tr>';

    echo '<tr><td class="Bold">Document title</td><td><input type="text" name="cover_title" value="' . resultspack_h($defaultCoverTitle) . '"></td></tr>';
    echo '<tr><td class="Bold">Document subtitle</td><td><input type="text" name="document_title" value="Results Sheet"></td></tr>';
    echo '<tr><td class="Bold">(a) Name of event</td><td>';
    echo '<label class="resultspack-link-toggle"><input type="checkbox" name="event_name_same_as_cover" value="1" checked> Use document title as event name</label>';
    echo '<div id="resultspack-event-name-wrap">';
    resultspack_builder_textarea('event_name', $defaultEventName);
    echo '</div></td></tr>';
    echo '<tr><td class="Bold">(b) Date(s) of event</td><td>';
    echo '<div class="resultspack-date-format"><label><input type="radio" name="date_format" value="long" checked> Long date</label><label><input type="radio" name="date_format" value="short"> Short date</label></div>';
    echo '<input type="text" name="date" id="resultspack-event-date" data-short-date="' . resultspack_h($defaultDateShort) . '" data-long-date="' . resultspack_h($defaultDateLong) . '" value="' . resultspack_h($defaultDateLong) . '">';
    echo '</td></tr>';

    echo '<tr><td class="Bold">(c) Record status</td><td><div class="resultspack-radio-row">';
    echo '<label><input type="radio" name="record_status" value="none" checked> No record status</label>';
    echo '<label><input type="radio" name="record_status" value="uk"> UK Record Status</label>';
    echo '<label><input type="radio" name="record_status" value="world"> World Record Status</label>';
    echo '</div>';
    echo '<div class="resultspack-record-qualifiers" id="resultspack-record-qualifiers">';
    echo '<label data-record-status="all"><input type="checkbox" name="record_status_h2h" value="1"' . ($detectedFinalCount ? ' checked' : '') . '> Head-to-Head (H2H)</label>';
    echo '<label data-record-status="uk"><input type="checkbox" name="record_status_arrowhead" value="1"> Arrowhead</label>';
    echo '<label data-record-status="uk"><input type="checkbox" name="record_status_tassel" value="1"> Tassel</label>';
    echo '<label data-record-status="world"><input type="checkbox" name="record_status_target_awards" value="1"> World Archery Target Awards</label>';
    echo '<label data-record-status="world"><input type="checkbox" name="record_status_star_awards" value="1"> World Archery Star Awards</label>';
    echo '</div></td></tr>';

    echo '<tr><td class="Bold">(d) Venue</td><td>';
    resultspack_builder_textarea('venue', $defaultVenue, false, true);
    echo '</td></tr>';

    echo '<tr><td class="Bold">(e) Tournament organiser</td><td><div class="resultspack-two-col">';
    echo '<label>Name<input type="text" name="organiser_name" value="' . resultspack_h($defaultOrganiserName) . '"></label>';
    echo '<label>E-mail address<input type="email" name="organiser_email" value=""></label>';
    echo '</div></td></tr>';

    echo '<tr><td class="Bold">(f) Weather conditions</td><td>';
    echo '<label class="resultspack-indoor-toggle"><input type="checkbox" name="weather_indoor" value="1" id="resultspack-weather-indoor"' . ($detectedIndoor ? ' checked' : '') . '> Indoor event - print “N/A - indoors.”</label>';
    echo '<div class="resultspack-outdoor-weather">';
    echo '<div class="resultspack-weather-conditions">';
    foreach (array('Sunny', 'Overcast', 'Windy', 'Rain', 'Snow') as $condition) {
        echo '<label><input type="checkbox" name="weather_conditions[]" value="' . resultspack_h($condition) . '"> ' . resultspack_h($condition) . '</label>';
    }
    echo '</div>';
    echo '<div class="resultspack-weather-grid">';
    echo '<label>Temperature (°C)<input type="number" step="0.1" name="weather_temperature"></label>';
    echo '<label>Humidity (%)<input type="number" min="0" max="100" step="1" name="weather_humidity"></label>';
    echo '<label>Wind speed<input type="number" min="0" step="0.1" name="weather_wind_speed"></label>';
    echo '<label>Wind unit<select name="weather_wind_unit"><option value="mph" selected>mph</option><option value="kmh">km/h</option><option value="ms">m/s</option></select></label>';
    echo '</div>';
    echo '<label>Additional weather notes<textarea name="weather_notes" rows="1" class="resultspack-autogrow" placeholder="Optional free-text observations"></textarea></label>';
    echo '</div></td></tr>';

    echo '<tr><td class="Bold">(g) Tabular list of each archer’s performance</td><td>';
    resultspack_builder_textarea('individual_note', 'See the individual result tables below.', false, true);
    echo '</td></tr>';
    echo '<tr data-depends-dns="include_dns"><td class="Bold">(h) Archers who entered but did not shoot</td><td>';
    resultspack_builder_textarea('did_not_shoot', 'See DNS entries within the individual result tables below.', true, true);
    echo '</td></tr>';
    echo '<tr><td class="Bold">(i) Disqualified archers</td><td>';
    resultspack_builder_textarea('disqualifications', 'No archers disqualified.', true, true);
    echo '</td></tr>';
    echo '<tr><td class="Bold">(j) Abnormal occurrences affecting the entire shoot</td><td>';
    resultspack_builder_textarea('circumstances', 'No abnormal occurrences affecting the entire shoot.', true, true);
    echo '</td></tr>';

    echo '<tr><td class="Bold">(k) Ceremonial roles</td><td><div class="resultspack-role-grid">';
    echo '<label><input type="checkbox" name="include_lady_paramount" value="1"> Lady Paramount</label><input type="text" name="lady_paramount" placeholder="Name of Lady Paramount">';
    echo '<label><input type="checkbox" name="include_lord_patron" value="1"> Lord Patron</label><input type="text" name="lord_patron" placeholder="Name of Lord Patron">';
    echo '</div></td></tr>';

    echo '<tr><td class="Bold">Cover page footer</td><td><textarea name="cover_footer" rows="1" class="resultspack-autogrow" placeholder="Optional association, website, sponsor acknowledgement or contact line"></textarea></td></tr>';
    echo '<tr><td class="Bold">Version</td><td><div class="resultspack-issue-grid">';
    echo '<label>Version date<input type="date" name="issue_date" value="' . resultspack_h($defaultIssueDate) . '"></label>';
    echo '<label>Revision<input type="number" name="revision" min="1" step="1" value="1"></label>';
    echo '</div><label>Revision note <span class="resultspack-muted">(optional; cover page only)</span><input type="text" name="revision_note" placeholder="e.g. Corrected Recurve Women result"></label>';
    echo '</td></tr>';
    echo '<tr><td class="Bold">Cover colour</td><td><select name="table_colour" id="resultspack-table-colour">';
    echo '<option value="classic" selected>Default grey</option>';
    echo '<option value="blue">Blue</option>';
    echo '<option value="gold">Gold</option>';
    echo '<option value="green">Green</option>';
    echo '<option value="purple">Purple</option>';
    echo '<option value="orange">Orange</option>';
    echo '<option value="rainbow">Rainbow</option>';
    echo '<option value="red">Red</option>';
    echo '<option value="teal">Teal</option>';
    echo '</select></td></tr>';

    echo '<tr><td class="Bold">Individual qualification results</td><td>';
    echo '<label class="resultspack-section-master"><input type="checkbox" name="include_individuals" value="1" checked> Include individual qualification results</label>';
    echo '<div class="resultspack-section-dependent" data-depends-section="include_individuals">';
    echo '<label class="resultspack-suboption"><input type="checkbox" name="rank_by_subclass" value="1" checked> Rank subclasses separately (for example Experienced and Novice)</label>';
    echo '<label class="resultspack-suboption"><input type="checkbox" name="include_nonranked" value="1" checked> Include DNF, DSQ and other non-ranking entries</label>';
    echo '<label class="resultspack-suboption"><input type="checkbox" name="include_dns" value="1"> Include DNS entries</label>';
    echo '</div>';
    echo '</td></tr>';

    echo '<tr data-depends-section="include_individuals"><td class="Bold">Individual events</td><td><div class="resultspack-radio-row">';
    echo '<label><input type="radio" name="individual_source" value="events"' . ($defaultIndividualSource === 'events' ? ' checked' : '') . ($nativeIndividualEvents ? '' : ' disabled') . '> Configured individual events</label>';
    echo '<label><input type="radio" name="individual_source" value="divclass"' . ($defaultIndividualSource === 'divclass' ? ' checked' : '') . '> IANSEO class / division results</label>';
    echo '</div>';
    if (!$nativeIndividualEvents) {
        echo '<div class="resultspack-muted">No configured individual events were found, so class/division results have been selected.</div>';
    } elseif ($count > 1) {
        echo '<div class="resultspack-muted">Configured events can be combined across competitions. Class/division results are printed separately for each source competition.</div>';
    }
    echo '</td></tr>';

    echo '<tr id="resultspack-individual-event-row" data-depends-section="include_individuals"><td class="Bold">Event selection</td><td>'; 
    echo '<select name="individual_event_mode" class="resultspack-mode-select" data-target="resultspack-individual-events"><option value="all" selected>Include all configured individual events</option><option value="specific">Choose specific individual events</option></select>';
    if ($nativeIndividualEvents) {
        echo '<div id="resultspack-individual-events" class="resultspack-event-chooser resultspack-hidden">';
        echo '<div class="resultspack-inline-actions"><button type="button" class="Button resultspack-check-all" data-name="individual_event_codes[]">Select all</button> <button type="button" class="Button resultspack-uncheck-all" data-name="individual_event_codes[]">Deselect all</button></div>';
        echo '<div class="resultspack-event-grid">';
        foreach ($nativeIndividualEvents as $event) {
            $coverage = $event['tournament_count'] . '/' . $count . ' competition' . ($count === 1 ? '' : 's');
            echo '<label class="resultspack-event-option"><input type="checkbox" name="individual_event_codes[]" value="' . resultspack_h($event['code']) . '" checked> <b>' . resultspack_h($event['label']) . '</b> <span class="resultspack-muted">(' . resultspack_h($event['code']) . '; ' . resultspack_h($coverage) . '; ' . (int) $event['entry_count'] . ' entries)</span></label>';
        }
        echo '</div></div>';
    } else {
        echo '<div class="resultspack-muted">No configured individual qualification events were found in the selected competition file(s).</div>';
    }
    echo '</td></tr>';

    echo '<tr><td class="Bold">Team qualification results</td><td><label class="resultspack-section-master"><input type="checkbox" name="include_teams" value="1"' . ($nativeTeamEvents ? ' checked' : '') . '> Include team qualification results</label></td></tr>';

    echo '<tr data-depends-section="include_teams"><td class="Bold">Team events</td><td>'; 
    echo '<select name="team_event_mode" class="resultspack-mode-select" data-target="resultspack-team-events"><option value="all" selected>Include all configured team events</option><option value="specific">Choose specific team events</option></select>';
    if ($nativeTeamEvents) {
        echo '<div id="resultspack-team-events" class="resultspack-event-chooser resultspack-hidden">';
        echo '<div class="resultspack-inline-actions"><button type="button" class="Button resultspack-check-all" data-name="team_event_codes[]">Select all</button> <button type="button" class="Button resultspack-uncheck-all" data-name="team_event_codes[]">Deselect all</button></div>';
        echo '<div class="resultspack-event-grid">';
        foreach ($nativeTeamEvents as $event) {
            $coverage = $event['tournament_count'] . '/' . $count . ' competition' . ($count === 1 ? '' : 's');
            echo '<label class="resultspack-event-option"><input type="checkbox" name="team_event_codes[]" value="' . resultspack_h($event['code']) . '" checked> <b>' . resultspack_h($event['label']) . '</b> <span class="resultspack-muted">(' . resultspack_h($event['code']) . '; ' . resultspack_h($coverage) . '; ' . (int) $event['team_count'] . ' teams)</span></label>';
        }
        echo '</div></div>';
    } else {
        echo '<div class="resultspack-muted">No qualification team events were found in the selected competition file(s).</div>';
    }
    echo '</td></tr>';

    $indFinalCount = (int) ($finalEvents['counts']['individual'] ?? 0);
    $teamFinalCount = (int) ($finalEvents['counts']['team'] ?? 0);
    $finalCount = $indFinalCount + $teamFinalCount;
    echo '<tr><td class="Bold">Finals / Head-to-Head results</td><td>';
    if ($finalCount) {
        echo '<details class="resultspack-finals-panel"><summary><b>' . $finalCount . ' final event' . ($finalCount === 1 ? '' : 's') . ' detected</b> - ' . $indFinalCount . ' individual, ' . $teamFinalCount . ' team. Expand to include them.</summary>';
        echo '<div class="resultspack-finals-body">';
        echo '<label class="resultspack-finals-master"><input type="checkbox" name="include_finals" value="1" checked> <b>Include Finals / Head-to-Head results in this pack</b></label>';
        echo '<div class="resultspack-muted">Finals are never combined mathematically. Each ranking and bracket is taken from the actual IANSEO competition in which it was shot. Qualification results remain first, followed by individual finals, then team finals.</div>';
        if ($indFinalCount) {
            echo '<div class="resultspack-finals-group"><b>Individual finals</b>';
            echo '<div class="resultspack-inline-actions"><button type="button" class="Button resultspack-check-all" data-name="individual_final_events[]">Select all</button> <button type="button" class="Button resultspack-uncheck-all" data-name="individual_final_events[]">Deselect all</button></div>';
            foreach ($finalEvents['individual'] as $event) {
                $status = $event['has_result_rows'] ? ((int) $event['result_rows'] . ' final rows present') : 'configured; no final rows yet';
                echo '<label class="resultspack-event-option"><input type="checkbox" name="individual_final_events[]" value="' . resultspack_h($event['tournament_id'] . '|' . $event['code']) . '" checked> <b>' . resultspack_h($event['label']) . '</b> <span class="resultspack-muted">(' . resultspack_h($event['tournament_code']) . '; ' . resultspack_h($event['code']) . '; ' . resultspack_h($status) . ')</span></label>';
            }
            echo '<div class="resultspack-finals-output-options"><label><input type="checkbox" name="include_individual_final_rankings" value="1" checked> Final rankings</label><label><input type="checkbox" name="include_individual_brackets" value="1" checked> Brackets</label></div></div>';
        }
        if ($teamFinalCount) {
            echo '<div class="resultspack-finals-group"><b>Team finals</b>';
            echo '<div class="resultspack-inline-actions"><button type="button" class="Button resultspack-check-all" data-name="team_final_events[]">Select all</button> <button type="button" class="Button resultspack-uncheck-all" data-name="team_final_events[]">Deselect all</button></div>';
            foreach ($finalEvents['team'] as $event) {
                $status = $event['has_result_rows'] ? ((int) $event['result_rows'] . ' final rows present') : 'configured; no final rows yet';
                echo '<label class="resultspack-event-option"><input type="checkbox" name="team_final_events[]" value="' . resultspack_h($event['tournament_id'] . '|' . $event['code']) . '" checked> <b>' . resultspack_h($event['label']) . '</b> <span class="resultspack-muted">(' . resultspack_h($event['tournament_code']) . '; ' . resultspack_h($event['code']) . '; ' . resultspack_h($status) . ')</span></label>';
            }
            echo '<div class="resultspack-finals-output-options"><label><input type="checkbox" name="include_team_final_rankings" value="1" checked> Final rankings</label><label><input type="checkbox" name="include_team_brackets" value="1" checked> Brackets</label></div>';
            echo '<div class="resultspack-muted">If team finals are included, Results Pack also includes the matching qualification team event where available, even if the general team qualification section was deselected.</div></div>';
        }
        echo '</div></details>';
    } else {
        echo '<div class="resultspack-muted">No individual or team finals are configured in the selected competition file(s).</div>';
    }
    echo '</td></tr>';

    echo '<tr><td class="Bold">Custom awards</td><td>';
    echo '<label class="resultspack-section-master"><input type="checkbox" name="include_custom_awards" value="1"> Include custom awards section</label>';
    echo '<div id="resultspack-custom-awards-body" style="display:none">';
    echo '<div class="resultspack-award-library" id="resultspack-award-library">';
    foreach ($awardLibrary as $award) {
        $field = $award['field_key'];
        if ($award['kind'] === 'divider') {
            echo '<div class="resultspack-award-divider" data-award-key="' . resultspack_h($award['key']) . '">';
            echo '<div class="resultspack-award-use"><label><input type="checkbox" name="custom_award_group_include_' . resultspack_h($field) . '" value="1" checked title="Include this group" aria-label="Include this group"></label></div>';
            echo '<span class="resultspack-award-order"><button type="button" class="Button resultspack-award-move" data-direction="up" title="Move up">&#8593;</button> <button type="button" class="Button resultspack-award-move" data-direction="down" title="Move down">&#8595;</button></span>';
            echo '<label>Group heading<input type="text" name="award_definition_name_' . resultspack_h($field) . '" value="' . resultspack_h($award['name']) . '" data-award-definition="name" data-resultspack-no-save="1"></label>';
            echo '<button type="button" class="Button resultspack-award-delete">Remove divider</button>';
            echo '</div>';
            continue;
        }
        $system = !empty($award['system']);
        echo '<div class="resultspack-award-row' . ($system ? ' resultspack-award-system' : '') . '" data-award-key="' . resultspack_h($award['key']) . '">';
        echo '<div class="resultspack-award-use"><label><input type="checkbox" name="custom_award_include_' . resultspack_h($field) . '" value="1"' . ($system ? ' checked' : '') . ' title="Include this award" aria-label="Include this award"></label></div>';
        echo '<div class="resultspack-award-order"><button type="button" class="Button resultspack-award-move" data-direction="up" title="Move up">&#8593;</button> <button type="button" class="Button resultspack-award-move" data-direction="down" title="Move down">&#8595;</button></div>';
        echo '<label>Award<input type="text" name="award_definition_name_' . resultspack_h($field) . '" value="' . resultspack_h($award['name']) . '" data-award-definition="name" data-resultspack-no-save="1"' . ($system ? ' readonly' : '') . '></label>';
        echo '<label>Description<input type="text" name="award_definition_description_' . resultspack_h($field) . '" value="' . resultspack_h($award['description']) . '" data-award-definition="description" data-resultspack-no-save="1"' . ($system ? ' readonly' : '') . '></label>';
        echo '<label>Category<input type="text" name="award_definition_category_' . resultspack_h($field) . '" value="' . resultspack_h($award['category']) . '" data-award-definition="category" data-resultspack-no-save="1" placeholder="Optional, e.g. Ladies / Juniors"' . ($system ? ' readonly' : '') . '></label>';
        echo '<label>Winner<input type="text" name="custom_award_winner_' . resultspack_h($field) . '" placeholder="Name of winner"></label>';
        if (!$system) {
            echo '<button type="button" class="Button resultspack-award-delete">Remove</button>';
        } else {
            echo '<span class="resultspack-muted resultspack-award-built-in">Built-in reminder</span>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '<div class="resultspack-inline-actions"><button type="button" class="Button" id="resultspack-award-add">Add award</button> <button type="button" class="Button" id="resultspack-award-add-divider">Add dividing line</button></div>';
    echo '</div>';
    echo '</td></tr>';

    echo '<tr><td colspan="2" class="resultspack-actions"><input type="submit" value="Generate PDF"></td></tr>';
    echo '</table></form>';
}
include('Common/Templates/tail.php');
