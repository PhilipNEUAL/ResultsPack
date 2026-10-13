<?php

// Shared limits for editable results-pack text.
// Keys ending in * match field names, such as award recipients.
function resultspack_text_limits()
{
    return array(
        'cover_title' => array(160, 'Document title'),
        'document_title' => array(160, 'Document subtitle'),
        'event_name' => array(300, 'Event name'),
        'date' => array(160, 'Event date'),
        'venue' => array(500, 'Venue'),
        'organiser_name' => array(200, 'Organiser name'),
        'organiser_email' => array(254, 'Organiser email'),
        'weather_notes' => array(2000, 'Environmental notes'),
        'individual_note' => array(1000, 'Individual results note'),
        'did_not_shoot' => array(2000, 'Archers who did not shoot'),
        'disqualifications' => array(2000, 'Disqualifications'),
        'circumstances' => array(3000, 'Abnormal occurrences'),
        'lady_paramount' => array(200, 'Lady Paramount'),
        'lord_patron' => array(200, 'Lord Patron'),
        'cover_footer' => array(1000, 'Cover footer'),
        'revision_note' => array(500, 'Revision note'),
        'custom_award_winner_*' => array(500, 'Award recipients'),
        'award_definition_name_*' => array(200, 'Award name or group heading'),
        'award_definition_description_*' => array(1000, 'Award description'),
        'award_definition_category_*' => array(200, 'Award category'),
    );
}

function resultspack_text_limit_for($name)
{
    foreach (resultspack_text_limits() as $pattern => $rule) {
        if ($name === $pattern) {
            return $rule;
        }
        if (substr($pattern, -1) === '*') {
            $prefix = substr($pattern, 0, -1);
            if (strncmp($name, $prefix, strlen($prefix)) === 0) {
                return $rule;
            }
        }
    }
    return null;
}

// Return a helpful error, or null when all limited fields are valid.
function resultspack_text_limit_error(array $values)
{
    foreach ($values as $name => $value) {
        $rule = resultspack_text_limit_for((string) $name);
        if ($rule === null) {
            continue;
        }
        if (!is_string($value)) {
            return $rule[1] . ' must contain text.';
        }

        $count = preg_match_all('/./us', $value);
        $extra = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
        if ($count === false || $extra === false) {
            return $rule[1] . ' contains invalid text.';
        }
        if ($count + $extra > $rule[0]) {
            return $rule[1] . ' exceeds its ' . $rule[0]
                . '-character limit. Shorten it or use Advanced → Reset this form.';
        }
    }
    return null;
}
