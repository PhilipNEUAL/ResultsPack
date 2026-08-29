<?php
require_once('Common/pdf/ResultPDF.inc.php');

class ResultsPackPdf extends ResultPDF
{
    public $ResultsPackCover = false;
    public $ResultsPackIssue = '';
    public $ResultsPackSoftwareVersion = '1.0.0';
    public $Continue = 'Continue';
    public $ResultsPackTableColour = 'classic';
    public $NumberThousandsSeparator = ',';

    public function __construct($DocTitolo, $Portrait=true, $Headers='', $StaffVisibility=true, $Options=array())
    {
        IanseoPdf::__construct($DocTitolo, $Portrait, $Headers, $StaffVisibility);
        foreach ($Options as $key => $value) {
            $this->{$key} = $value;
        }
        $this->setlinewidth(0.1);
    }

    public function SetDefaultColor()
    {
        parent::SetDefaultColor();
    }

    public function Header()
    {
        if ($this->ResultsPackCover) {
            return;
        }
        parent::Header();
    }

    public function resultsPackFooterTop()
    {
        return $this->h - $this->savedBottomMargin;
    }

    public function Footer()
    {
        $this->SetDefaultColor();
        $footerTop = $this->h - $this->savedBottomMargin;
        $this->Line(IanseoPdf::sideMargin, $footerTop, $this->w - IanseoPdf::sideMargin, $footerTop);
        if (!empty($this->ToPaths['ToBottom'])) {
            $im = @getimagesize($this->ToPaths['ToBottom']);
            if ($im) {
                $height = IanseoPdf::footerImageH;
                $width = $im[0] * $height / $im[1];
                $maximumWidth = $this->w - $this->lMargin - $this->rMargin;
                if ($width > $maximumWidth) {
                    $width = $maximumWidth;
                    $height = $im[1] * $width / $im[0];
                }
                $this->Image($this->ToPaths['ToBottom'], ($this->w - $width) / 2, $footerTop + 5, $width, $height);
            }
        }
        $this->SetFont($this->FontStd, '', 7.5);
        $this->SetXY(IanseoPdf::sideMargin, $footerTop);
        $issue = $this->ResultsPackIssue !== '' ? $this->ResultsPackIssue : $this->ResultsPackSoftwareVersion;
        $this->Cell(95, 5, $issue, 0, 0, 'L');
        $this->SetXY($this->w - 75, $footerTop);
        $this->Cell(65, 5, 'Page ' . $this->getGroupPageNo() . ' of ' . $this->getPageGroupAlias(), 0, 0, 'R');
    }
}

function resultspack_pdf_palettes()
{
    return array(
        'classic' => array('label' => 'Default grey', 'main' => array(95, 95, 95), 'fill' => array(224, 224, 224), 'accent' => array(75, 75, 75)),
        'blue'    => array('label' => 'Blue', 'main' => array(43, 91, 140), 'fill' => array(205, 222, 239), 'accent' => array(35, 72, 110)),
        'red'     => array('label' => 'Red', 'main' => array(142, 57, 57), 'fill' => array(237, 205, 205), 'accent' => array(112, 45, 45)),
        'green'   => array('label' => 'Green', 'main' => array(54, 112, 74), 'fill' => array(207, 230, 214), 'accent' => array(42, 88, 58)),
        'orange'  => array('label' => 'Orange', 'main' => array(201, 82, 0), 'fill' => array(250, 220, 190), 'accent' => array(25, 25, 25)),
        'purple'  => array('label' => 'Purple', 'main' => array(68, 0, 70), 'fill' => array(225, 207, 227), 'accent' => array(91, 21, 94)),
        'teal'    => array('label' => 'Teal', 'main' => array(40, 110, 112), 'fill' => array(203, 230, 230), 'accent' => array(31, 86, 88)),
        'gold'    => array('label' => 'Gold', 'main' => array(145, 111, 27), 'fill' => array(239, 224, 184), 'accent' => array(112, 84, 18)),
        'rainbow' => array('label' => 'Rainbow', 'main' => array(196, 38, 46), 'fill' => array(252, 226, 227), 'accent' => array(120, 24, 29)),
    );
}

function resultspack_pdf_palette_key($key)
{
    $key = strtolower(resultspack_normalise_whitespace((string) $key));
    $palettes = resultspack_pdf_palettes();
    return isset($palettes[$key]) ? $key : 'classic';
}

function resultspack_pdf_palette($key)
{
    $palettes = resultspack_pdf_palettes();
    return $palettes[resultspack_pdf_palette_key($key)];
}

function resultspack_pdf_rainbow_colours()
{
    return array(
        array(196, 38, 46),   // red
        array(224, 79, 34),   // red-orange
        array(239, 128, 31),  // orange
        array(238, 183, 38),  // yellow-gold
        array(119, 166, 45),  // yellow-green
        array(42, 151, 92),   // green
        array(35, 148, 154),  // teal
        array(45, 111, 178),  // blue
        array(72, 78, 166),   // indigo-blue
        array(107, 63, 152),  // indigo
        array(147, 58, 139),  // violet
    );
}

function resultspack_pdf_mix_with_white(array $colour, $whiteAmount = 0.82)
{
    $whiteAmount = max(0.0, min(1.0, (float) $whiteAmount));
    return array_map(function ($channel) use ($whiteAmount) {
        return (int) round(($channel * (1.0 - $whiteAmount)) + (255 * $whiteAmount));
    }, $colour);
}

function resultspack_pdf_contrast_text(array $colour)
{
    $brightness = (($colour[0] * 299) + ($colour[1] * 587) + ($colour[2] * 114)) / 1000;
    return $brightness > 155 ? array(30, 30, 30) : array(255, 255, 255);
}

function resultspack_pdf_cover_palette($key, $rowIndex = 0)
{
    if (resultspack_pdf_palette_key($key) !== 'rainbow') {
        return resultspack_pdf_palette($key);
    }
    $rainbow = resultspack_pdf_rainbow_colours();
    $main = $rainbow[max(0, (int) $rowIndex) % count($rainbow)];
    $fill = resultspack_pdf_mix_with_white($main, 0.84);
    return array(
        'label' => 'Rainbow',
        'main' => $main,
        'fill' => $fill,
        'accent' => array(max(0, $main[0] - 65), max(0, $main[1] - 65), max(0, $main[2] - 65)),
        'text' => resultspack_pdf_contrast_text($main),
    );
}

function resultspack_pdf_content_width($pdf)
{
    return $pdf->getPageWidth() - 20;
}

function resultspack_pdf_date_range(array $tournaments, $style = 'short')
{
    if (!$tournaments) return '';
    $from = array();
    $to = array();
    foreach ($tournaments as $tournament) {
        $from[] = $tournament['date_from'];
        $to[] = $tournament['date_to'] ?: $tournament['date_from'];
    }
    sort($from);
    sort($to);
    $first = reset($from);
    $last = end($to);
    $firstLabel = resultspack_event_date($first, $style);
    $lastLabel = resultspack_event_date($last, $style);
    return $first === $last ? $firstLabel : $firstLabel . ' - ' . $lastLabel;
}

function resultspack_pdf_common_location(array $tournaments)
{
    $locations = array();
    foreach ($tournaments as $tournament) {
        $value = resultspack_normalise_whitespace($tournament['where'] ?: $tournament['venue']);
        if ($value !== '') $locations[$value] = $value;
    }
    return count($locations) === 1 ? reset($locations) : 'Multiple venues';
}

function resultspack_pdf_metric_labels(array $tournaments)
{
    $firstLabels = array();
    $secondLabels = array();
    foreach ($tournaments as $tournament) {
        $first = resultspack_normalise_whitespace($tournament['golds_label'] ?? '');
        $second = resultspack_normalise_whitespace($tournament['xnine_label'] ?? '');
        if ($first !== '') $firstLabels[$first] = $first;
        if ($second !== '') $secondLabels[$second] = $second;
    }
    return array(
        count($firstLabels) === 1 ? reset($firstLabels) : 'Golds',
        count($secondLabels) === 1 ? reset($secondLabels) : 'Xs',
    );
}

function resultspack_pdf_multiline_text($value)
{
    $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
    $lines = preg_split('/\n+/u', $value);
    $clean = array();
    foreach ($lines as $line) {
        $line = resultspack_normalise_whitespace($line);
        if ($line !== '') {
            $clean[] = $line;
        }
    }
    return implode("\n", $clean);
}

function resultspack_pdf_cover_item($pdf, $letter, $heading, $value, $width, $colourIndex = 0)
{
    $letterWidth = 12;
    $contentWidth = $width - $letterWidth;
    $heading = resultspack_normalise_whitespace($heading);
    $value = resultspack_pdf_multiline_text($value);

    $pdf->SetFont($pdf->FontStd, 'B', 7.5);
    $headingLines = max(1, $pdf->getNumLines($heading, $contentWidth - 6));
    $pdf->SetFont($pdf->FontStd, '', 9.5);
    $valueLines = max(1, $pdf->getNumLines($value, $contentWidth - 6));
    $rowHeight = max(10, 2.5 + ($headingLines * 3.4) + ($valueLines * 4.4) + 2.0);

    if (!$pdf->SamePage($rowHeight + 1)) {
        $pdf->AddPage();
    }

    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $palette = resultspack_pdf_cover_palette($pdf->ResultsPackTableColour, $colourIndex);
    if (resultspack_pdf_palette_key($pdf->ResultsPackTableColour) === 'rainbow') {
        $pdf->SetFillColor($palette['main'][0], $palette['main'][1], $palette['main'][2]);
        $text = $palette['text'];
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
    } else {
        $pdf->SetFillColor($palette['fill'][0], $palette['fill'][1], $palette['fill'][2]);
        $pdf->SetTextColor($palette['accent'][0], $palette['accent'][1], $palette['accent'][2]);
    }
    $pdf->SetFont($pdf->FontStd, 'B', 9);
    $pdf->MultiCell($letterWidth, $rowHeight, '(' . $letter . ')', 1, 'C', 1, 0, $x, $y, true, 0, false, true, $rowHeight, 'M');

    if (resultspack_pdf_palette_key($pdf->ResultsPackTableColour) === 'rainbow') {
        $pdf->SetFillColor($palette['fill'][0], $palette['fill'][1], $palette['fill'][2]);
    } else {
        $pdf->SetFillColor(250, 250, 250);
    }
    $pdf->MultiCell($contentWidth, $rowHeight, '', 1, 'L', 1, 0, $x + $letterWidth, $y);
    $pdf->SetXY($x + $letterWidth + 3, $y + 1.5);
    $pdf->SetFont($pdf->FontStd, 'B', 7.5);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell($contentWidth - 6, 3.4, $heading, 0, 'L', 0, 1);
    $pdf->SetX($x + $letterWidth + 3);
    $pdf->SetFont($pdf->FontStd, '', 9.5);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell($contentWidth - 6, 4.4, $value, 0, 'L', 0, 1);
    $pdf->SetXY($x, $y + $rowHeight + 1.2);
    $pdf->SetDefaultColor();
}

function resultspack_render_cover($pdf, array $settings, array $tournaments)
{
    $width = resultspack_pdf_content_width($pdf);
    $left = 10;
    $pdf->SetY(24);
    if (!empty($pdf->ToPaths['ToLeft'])) {
        $pdf->Image($pdf->ToPaths['ToLeft'], $left, 20, 0, 25);
    }
    if (!empty($pdf->ToPaths['ToRight'])) {
        $im = @getimagesize($pdf->ToPaths['ToRight']);
        if ($im) {
            $height = 25;
            $imageWidth = $im[0] * $height / $im[1];
            $pdf->Image($pdf->ToPaths['ToRight'], $pdf->getPageWidth() - 10 - $imageWidth, 20, $imageWidth, $height);
        }
    }
    $pdf->SetFont($pdf->FontStd, 'B', 16);
    $pdf->SetXY(35, 25);
    $pdf->MultiCell($pdf->getPageWidth() - 70, 6.5, $settings['cover_title'], 0, 'C', 0, 1);
    $pdf->Ln(1.5);
    $pdf->SetFont($pdf->FontStd, 'B', 24);
    $pdf->MultiCell($width, 10, $settings['document_title'], 0, 'C', 0, 1);
    $pdf->Ln(5);

    $palette = resultspack_pdf_cover_palette($pdf->ResultsPackTableColour, 0);
    $pdf->SetFillColor($palette['main'][0], $palette['main'][1], $palette['main'][2]);
    if (resultspack_pdf_palette_key($pdf->ResultsPackTableColour) === 'rainbow') {
        $text = $palette['text'];
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
    } else {
        $pdf->SetTextColor(255, 255, 255);
    }
    $pdf->SetFont($pdf->FontStd, 'B', 9);
    $pdf->Cell($width, 6, 'Results sheet information', 0, 1, 'L', 1);
    $pdf->SetDefaultColor();
    $pdf->Ln(1.5);

    $items = array(
        'a' => array('Name of event', $settings['event_name']),
        'b' => array('Date(s) of event', $settings['date']),
        'c' => array('Record status', $settings['status']),
        'd' => array('Venue', $settings['venue']),
        'e' => array('Tournament organiser', $settings['organiser']),
        'f' => array('Weather conditions', $settings['weather']),
        'g' => array("Tabular list of each archer's performance", $settings['individual_note']),
        'h' => array('Archers who entered but did not shoot', $settings['did_not_shoot']),
        'i' => array('Disqualified archers', $settings['disqualifications']),
        'j' => array('Abnormal occurrences affecting the entire shoot', $settings['circumstances']),
    );
    $coverColourIndex = 0;
    foreach ($items as $letter => $item) {
        if ($letter === 'h' && empty($settings['include_dns_cover_row'])) {
            continue;
        }
        resultspack_pdf_cover_item($pdf, $letter, $item[0], $item[1], $width, $coverColourIndex);
        $coverColourIndex++;
    }

    $ceremonial = array();
    if (!empty($settings['include_lady_paramount']) && resultspack_normalise_whitespace($settings['lady_paramount']) !== '') {
        $ceremonial[] = 'Lady Paramount: ' . $settings['lady_paramount'];
    }
    if (!empty($settings['include_lord_patron']) && resultspack_normalise_whitespace($settings['lord_patron']) !== '') {
        $ceremonial[] = 'Lord Patron: ' . $settings['lord_patron'];
    }
    if ($ceremonial) {
        resultspack_pdf_cover_item($pdf, 'k', 'Ceremonial roles', implode("\n", $ceremonial), $width, $coverColourIndex);
    }

    $footerTop = $pdf->resultsPackFooterTop();
    $publicationLines = array();
    $customFooter = resultspack_normalise_whitespace($settings['cover_footer'] ?? '');
    if ($customFooter !== '') {
        $publicationLines[] = array($customFooter, '');
    }
    $revisionNote = resultspack_normalise_whitespace($settings['revision_note'] ?? '');
    if ($revisionNote !== '') {
        $publicationLines[] = array('Revision note: ' . $revisionNote, '');
    }
    $issueLabel = resultspack_normalise_whitespace($settings['issue_label'] ?? '');
    if ($issueLabel !== '') {
        $publicationLines[] = array($issueLabel, 'B');
    }
    $publicationLines[] = array('Results powered by I@NSEO', '');

    $lineHeight = 4.5;
    $blockHeight = count($publicationLines) * $lineHeight;
    $pdf->SetY($footerTop - $blockHeight - 2.5);
    foreach ($publicationLines as $line) {
        $pdf->SetFont($pdf->FontStd, $line[1], 8.5);
        $pdf->MultiCell($width, $lineHeight, $line[0], 0, 'C', 0, 1);
    }
}

function resultspack_pdf_individual_layout(array $tournaments, array $distanceIndices = array())
{
    if (count($tournaments) === 1) {
        $tournament = reset($tournaments);
        if (!$distanceIndices) {
            $distanceIndices = range(1, max(1, (int) $tournament['num_dist']));
        }
        $distanceIndices = array_values(array_unique(array_map('intval', $distanceIndices)));
        sort($distanceIndices);
        $numDist = max(1, count($distanceIndices));
        $distSize = 12.0;
        $addSize = 0.0;
        if ($numDist >= 4) {
            $distSize = 48.0 / $numDist;
        } else {
            $addSize = (48.0 - ($numDist * 12.0)) / 2.0;
        }
        return array(
            'single' => true,
            'num_dist' => $numDist,
            'distance_indices' => $distanceIndices,
            'add' => $addSize,
            'distance' => $distSize,
            'rank' => 8,
            'target' => 7,
            'athlete' => 37 + $addSize,
            'cat' => 8,
            'club_code' => 8,
            'club' => 42 + $addSize,
            'total' => 12,
            'metric1' => 10,
            'metric2' => 10,
        );
    }

    $count = max(1, count($tournaments));
    $eventWidth = min(18.0, max(8.0, 48.0 / $count));
    $eventTotal = $eventWidth * $count;
    $remainder = 190.0 - (8 + 8 + 12 + 10 + 10 + $eventTotal);
    $athlete = max(38.0, $remainder * 0.47);
    $club = $remainder - $athlete;
    return array(
        'single' => false,
        'rank' => 8,
        'athlete' => $athlete,
        'cat' => 8,
        'club' => $club,
        'event' => $eventWidth,
        'total' => 12,
        'metric1' => 10,
        'metric2' => 10,
    );
}

function resultspack_pdf_group_label(array $entry)
{
    $parts = array($entry['event_label'] ?? $entry['event_code'] ?? 'Individual Results');
    $subclass = resultspack_normalise_whitespace($entry['subclass'] ?? '');
    if (!empty($entry['rank_by_subclass']) && $subclass !== '') {
        $existing = resultspack_normalise_key(implode(' ', $parts));
        $subKey = resultspack_normalise_key($subclass);
        if ($subKey !== '' && strpos($existing, $subKey) === false) {
            $parts[] = $subclass;
        }
    }
    return implode(' - ', array_filter($parts, function ($value) { return resultspack_normalise_whitespace($value) !== ''; }));
}

function resultspack_pdf_individual_group_key(array $entry)
{
    return ($entry['event_code'] ?? '') . (!empty($entry['rank_by_subclass']) ? '|' . ($entry['subclass_code'] ?? '') : '');
}

function resultspack_pdf_write_individual_header($pdf, array $tournaments, array $layout, $continued, $groupLabel, array $distanceLabels = array())
{
    list($metric1, $metric2) = resultspack_pdf_metric_labels($tournaments);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell(190, 6, $groupLabel, 1, 1, 'C', 1);
    if ($continued) {
        $pdf->SetXY(170, $pdf->GetY() - 6);
        $pdf->SetFont($pdf->FontStd, '', 6);
        $pdf->Cell(30, 6, $pdf->Continue, 0, 1, 'R', 0);
    }
    $pdf->SetFont($pdf->FontStd, 'B', 7);
    $pdf->Cell($layout['rank'], 4, 'Pos.', 1, 0, 'C', 1);
    if ($layout['single']) {
        $pdf->Cell($layout['target'] + $layout['athlete'], 4, 'Athlete', 1, 0, 'L', 1);
        $pdf->Cell($layout['cat'], 4, 'Cat.', 1, 0, 'C', 1);
        $pdf->Cell($layout['club_code'] + $layout['club'], 4, 'Country', 1, 0, 'L', 1);
        $tournament = reset($tournaments);
        foreach (($layout['distance_indices'] ?? range(1, $layout['num_dist'])) as $distance) {
            $label = $distanceLabels[$distance] ?? ($tournament['distance_summaries'][$distance] ?? ('D' . $distance));
            $pdf->Cell($layout['distance'], 4, $label, 1, 0, 'C', 1, '', 1);
        }
    } else {
        $pdf->Cell($layout['athlete'], 4, 'Athlete', 1, 0, 'L', 1);
        $pdf->Cell($layout['cat'], 4, 'Cat.', 1, 0, 'C', 1);
        $pdf->Cell($layout['club'], 4, 'Country', 1, 0, 'L', 1);
        foreach ($tournaments as $tournament) {
            $label = $tournament['code'] !== '' ? $tournament['code'] : ('Event ' . $tournament['id']);
            $pdf->Cell($layout['event'], 4, $label, 1, 0, 'C', 1, '', 1);
        }
    }
    $pdf->Cell($layout['total'], 4, 'Total', 1, 0, 'C', 1);
    $pdf->Cell($layout['metric1'], 4, $metric1, 1, 0, 'C', 1, '', 1);
    $pdf->Cell($layout['metric2'], 4, $metric2, 1, 1, 'C', 1, '', 1);
    $pdf->SetFont($pdf->FontStd, '', 1);
    $pdf->Cell(190, 0.5, '', 1, 1, 'C', 0);
}

function resultspack_render_individual_results($pdf, array $combined, $includeNonRanked = true, $includeDns = false)
{
    $tournaments = $combined['tournaments'];
    $groups = array();
    foreach ($combined['entries'] as $entry) {
        $status = strtoupper(resultspack_normalise_whitespace($entry['status'] ?? ''));
        if (!$includeNonRanked && empty($entry['ranked'])) {
            continue;
        }
        if (!$includeDns && $status === 'DNS') {
            continue;
        }
        $key = resultspack_pdf_individual_group_key($entry);
        if (!isset($groups[$key])) {
            $groups[$key] = array();
        }
        $groups[$key][] = $entry;
    }

    $firstGroup = true;
    foreach ($groups as $groupEntries) {
        if (!$groupEntries) {
            continue;
        }
        $firstEntry = reset($groupEntries);
        $groupLabel = resultspack_pdf_group_label($firstEntry);
        $distanceIndices = array();
        $distanceLabels = array();
        if (count($tournaments) === 1) {
            $tournamentId = (int) array_key_first($tournaments);
            foreach ($groupEntries as $entry) {
                $event = $entry['events'][$tournamentId] ?? null;
                if (!$event) continue;
                foreach (($event['distance_labels'] ?? array()) as $distance => $label) {
                    if (resultspack_normalise_whitespace($label) !== '') {
                        $distanceIndices[(int) $distance] = (int) $distance;
                        if (empty($distanceLabels[$distance])) $distanceLabels[(int) $distance] = $label;
                    }
                }
                foreach (($event['distances'] ?? array()) as $distance => $values) {
                    if (array_sum(array_map('intval', (array) $values)) > 0) {
                        $distanceIndices[(int) $distance] = (int) $distance;
                    }
                }
            }
            ksort($distanceIndices);
            $distanceIndices = array_values($distanceIndices);
        }
        $layout = resultspack_pdf_individual_layout($tournaments, $distanceIndices);

        if (!$firstGroup) $pdf->SetY($pdf->GetY() + 5);
        if (!$pdf->SamePage(15)) $pdf->AddPage();
        resultspack_pdf_write_individual_header($pdf, $tournaments, $layout, false, $groupLabel, $distanceLabels);
        $firstGroup = false;

        foreach ($groupEntries as $entry) {
            if (!$pdf->SamePage(4)) {
                $pdf->AddPage();
                resultspack_pdf_write_individual_header($pdf, $tournaments, $layout, true, $groupLabel, $distanceLabels);
            }

            $rankText = $entry['ranked'] ? (string) $entry['rank'] : $entry['status'];
            $pdf->SetFont($pdf->FontStd, 'B', 8);
            $pdf->Cell($layout['rank'], 4, $rankText, 1, 0, 'R');

            if ($layout['single']) {
                $tournamentId = (int) array_key_first($tournaments);
                $event = $entry['events'][$tournamentId] ?? null;
                $pdf->SetFont($pdf->FontStd, '', 7);
                $pdf->Cell($layout['target'], 4, $event['target'] ?? '', 'TLB', 0, 'R', 0, '', 1);
                $pdf->Cell($layout['athlete'], 4, $entry['name'], 'TRB', 0, 'L', 0, '', 1);
                $age = isset($entry['age_class_codes'][0]) && $entry['age_class_codes'][0] !== $entry['class_code'] ? $entry['age_class_codes'][0] : '';
                $subclass = $entry['subclass_code'] !== '' ? $entry['subclass_code'] : implode('/', $entry['subclass_codes']);
                $pdf->SetFont($pdf->FontStd, '', 6);
                $pdf->Cell($layout['cat'] / 2, 4, $age, 'TLB', 0, 'C', 0, '', 1);
                $pdf->Cell($layout['cat'] / 2, 4, $subclass, 'TBR', 0, 'C', 0, '', 1);
                $pdf->SetFont($pdf->FontStd, '', 7);
                $clubCode = $entry['club_codes'][0] ?? '';
                $pdf->Cell($layout['club_code'], 4, $clubCode, 'LTB', 0, 'L', 0, '', 1);
                $pdf->Cell($layout['club'], 4, $entry['club'], 'RTB', 0, 'L', 0, '', 1);
                $pdf->SetFont($pdf->FontFix, '', 7);
                foreach (($layout['distance_indices'] ?? range(1, $layout['num_dist'])) as $distance) {
                    $value = $event ? (int) ($event['distances'][$distance]['score'] ?? 0) : 0;
                    $pdf->Cell($layout['distance'], 4, $value ?: '', 1, 0, 'R');
                }
            } else {
                $pdf->SetFont($pdf->FontStd, '', 7);
                $pdf->Cell($layout['athlete'], 4, $entry['name'], 1, 0, 'L', 0, '', 1);
                $subclass = $entry['subclass_code'] !== '' ? $entry['subclass_code'] : implode('/', $entry['subclass_codes']);
                $pdf->SetFont($pdf->FontStd, '', 6);
                $pdf->Cell($layout['cat'], 4, $subclass, 1, 0, 'C', 0, '', 1);
                $club = trim((isset($entry['club_codes'][0]) ? $entry['club_codes'][0] . ' ' : '') . $entry['club']);
                $pdf->SetFont($pdf->FontStd, '', 7);
                $pdf->Cell($layout['club'], 4, $club, 1, 0, 'L', 0, '', 1);
                $pdf->SetFont($pdf->FontFix, '', 7);
                foreach ($tournaments as $tournamentId => $tournament) {
                    $event = $entry['events'][$tournamentId] ?? null;
                    $value = $event ? (int) $event['score'] : 0;
                    $text = $value ? (string) $value : ($event && $event['status'] !== '' ? $event['status'] : '-');
                    $pdf->Cell($layout['event'], 4, $text, 1, 0, 'R', 0, '', 1);
                }
            }

            $pdf->SetFont($pdf->FontFix, 'B', 8);
            $pdf->Cell($layout['total'], 4, $entry['score'] ?: '', 1, 0, 'R');
            $pdf->SetFont($pdf->FontFix, '', 8);
            $pdf->Cell($layout['metric1'], 4, $entry['golds'] ?: '', 1, 0, 'R');
            $pdf->Cell($layout['metric2'], 4, $entry['xs'] ?: '', 1, 1, 'R');
        }
    }
}

function resultspack_render_divclass_rankdata_body($pdf, $PdfData)
{
    $pdf->HideCols = $PdfData->HideCols;
    $pdf->NumberThousandsSeparator = $PdfData->NumberThousandsSeparator;
    $pdf->Continue = $PdfData->Continue;
    $pdf->TotalShort = $PdfData->TotalShort;
    $rankData = $PdfData->rankData;
    if (empty($rankData['sections'])) return;
    $hideGolds = $PdfData->hideGolds;
    $pdf->setDocUpdate($rankData['meta']['lastUpdate']);
    foreach ($rankData['sections'] as $section) {
        if (empty($section['items'])) continue;
        $distSize = 12;
        $addSize = 0;
        if ($section['meta']['numDist'] >= 4) {
            if (empty($rankData['meta']['double'])) {
                $distSize = (48 + ($hideGolds ? 10 : 0)) / $section['meta']['numDist'];
            } else {
                $distSize = (48 + ($hideGolds ? 10 : 0)) / (($section['meta']['numDist'] / 2) + 1);
            }
        } else {
            $addSize = ((48 + ($hideGolds ? 10 : 0)) - ($section['meta']['numDist'] * (48 + ($hideGolds ? 10 : 0)) / 4)) / 2;
        }
        if (!$pdf->SamePage((!empty($rankData['meta']['double']) ? 2 : 1) * 9 + 6.5 + (!empty($section['meta']['sesArrows']) ? 7.5 : 0))) {
            $pdf->AddPage();
        }
        writeGroupHeaderPrnIndividual($pdf, $section['meta'], $distSize, $addSize, $section['meta']['numDist'], !empty($rankData['meta']['double']), false, $hideGolds);
        foreach ($section['items'] as $item) {
            if (!$pdf->SamePage(5 * (!empty($rankData['meta']['double']) ? 2 : 1))) {
                $pdf->AddPage();
                writeGroupHeaderPrnIndividual($pdf, $section['meta'], $distSize, $addSize, $section['meta']['numDist'], !empty($rankData['meta']['double']), true, $hideGolds);
            }
            writeDataRowPrnIndividual($pdf, $item, $distSize, $addSize, $section['meta']['numDist'], !empty($rankData['meta']['double']), ($PdfData->family === 'Snapshot' ? $section['meta']['snapDistance'] : 0), $hideGolds);
        }
        $pdf->SetY($pdf->GetY() + 5);
    }
}

function resultspack_render_native_divclass_individuals($pdf, array $tournaments, $rankBySubclass = false, $includeNonRanked = true, $includeDns = false)
{
    $sessionBackup = $_SESSION;
    $requestBackup = $_REQUEST;
    require_once('Common/OrisFunctions.php');
    require_once('Common/Lib/Obj_RankFactory.php');
    require_once('Common/pdf/PdfChunkLoader.php');

    $chunkLoaded = function_exists('writeDataRowPrnIndividual');
    foreach ($tournaments as $tourId => $tournament) {
        if (!CreateTourSession((int) $tourId)) continue;
        $_REQUEST = array();
        if ($rankBySubclass) $_REQUEST['SubClassRank'] = 1;
        $PdfData = getDivClasIndividual();
        if (!empty($PdfData->rankData['sections'])) {
            foreach ($PdfData->rankData['sections'] as &$section) {
                if (empty($section['items'])) continue;
                $section['items'] = array_values(array_filter($section['items'], function ($item) use ($includeNonRanked, $includeDns) {
                    $rank = strtoupper(resultspack_normalise_whitespace($item['rank'] ?? ''));
                    if (!$includeDns && $rank === 'DNS') return false;
                    if (!$includeNonRanked && ($rank === '' || !is_numeric($rank))) return false;
                    return true;
                }));
            }
            unset($section);
            $PdfData->rankData['sections'] = array_values(array_filter($PdfData->rankData['sections'], function ($section) {
                return !empty($section['items']);
            }));
        }
        if (!empty($PdfData->rankData['sections'])) {
            $pdf->AddPage();
            if (count($tournaments) > 1) {
                $pdf->SetFont($pdf->FontStd, 'B', 9);
                $pdf->Cell(resultspack_pdf_content_width($pdf), 5, $tournament['name'] ?? ('Competition ' . $tourId), 0, 1, 'L');
                $pdf->Ln(1);
            }
            if (!$chunkLoaded && !function_exists('writeDataRowPrnIndividual')) {
                require(PdfChunkLoader('DivClasIndividual.inc.php'));
                $chunkLoaded = true;
            } else {
                resultspack_render_divclass_rankdata_body($pdf, $PdfData);
            }
        }
        EraseTourSession();
        $_SESSION = $sessionBackup;
    }
    $_REQUEST = $requestBackup;
    $_SESSION = $sessionBackup;
}

function resultspack_pdf_team_header($pdf, $sectionLabel, array $tournaments, $continued = false)
{
    list($metric1, $metric2) = resultspack_pdf_metric_labels($tournaments);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell(190, 6, $sectionLabel, 1, 1, 'C', 1);
    if ($continued) {
        $pdf->SetXY(170, $pdf->GetY() - 6);
        $pdf->SetFont($pdf->FontStd, '', 6);
        $pdf->Cell(30, 6, $pdf->Continue, 0, 1, 'R', 0);
    }
    $pdf->SetFont($pdf->FontStd, 'B', 7);
    $pdf->Cell(9, 4, 'Pos.', 1, 0, 'C', 1);
    $pdf->Cell(54, 4, 'Country', 1, 0, 'L', 1);
    $pdf->Cell(44, 4, 'Athletes', 1, 0, 'L', 1);
    $pdf->Cell(12, 4, 'Division', 1, 0, 'C', 1);
    $pdf->Cell(11, 4, 'Age Cl.', 1, 0, 'C', 1);
    $pdf->Cell(11, 4, 'Cl.', 1, 0, 'C', 1);
    $pdf->Cell(8, 4, 'Cat.', 1, 0, 'C', 1);
    $pdf->Cell(21, 4, 'Total', 1, 0, 'C', 1);
    $pdf->Cell(10, 4, $metric1, 1, 0, 'C', 1, '', 1);
    $pdf->Cell(10, 4, $metric2, 1, 1, 'C', 1, '', 1);
    $pdf->SetFont($pdf->FontStd, '', 1);
    $pdf->Cell(190, 0.5, '', 1, 1, 'C', 0);
}

function resultspack_render_team_results($pdf, array $teamData, array $tournaments)
{
    $firstSection = true;
    foreach ($teamData['sections'] as $section) {
        if (!$firstSection) $pdf->SetY($pdf->GetY() + 5);
        $firstTeamMembers = isset($section['teams'][0]['members']) ? count($section['teams'][0]['members']) : 1;
        if (!$pdf->SamePage((4 * max(1, $firstTeamMembers)) + 16)) $pdf->AddPage();
        resultspack_pdf_team_header($pdf, $section['label'], $tournaments, false);
        foreach ($section['teams'] as $team) {
            $memberCount = max(1, count($team['members']));
            $height = 4 * $memberCount;
            if (!$pdf->SamePage($height)) {
                $pdf->AddPage();
                resultspack_pdf_team_header($pdf, $section['label'], $tournaments, true);
            }
            $pdf->SetFont($pdf->FontStd, 'B', 8);
            $pdf->Cell(9, $height, $team['rank'], 1, 0, 'R', 0);
            $pdf->SetFont($pdf->FontStd, '', 7);
            $clubCode = (string) ($team['club_code'] ?? '');
            $clubName = (string) ($team['club'] ?? '');
            if (!empty($team['subteam']) && (int) $team['subteam'] > 1) $clubName .= ' (' . (int) $team['subteam'] . ')';
            $pdf->Cell(8, $height, $clubCode, 'LTB', 0, 'C', 0, '', 1);
            $pdf->Cell(46, $height, $clubName, 'RTB', 0, 'L', 0, '', 1);

            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->SetX(168);
            $pdf->SetFont($pdf->FontFix, 'B', 8);
            $pdf->Cell(12, $height, is_numeric($team['score']) ? number_format($team['score'], 0, '', $pdf->NumberThousandsSeparator) : $team['score'], 1, 0, 'R', 0);
            $pdf->SetFont($pdf->FontFix, '', 8);
            $pdf->Cell(10, $height, $team['golds'], 1, 0, 'R', 0);
            $pdf->Cell(10, $height, $team['xs'], 1, 0, 'R', 0);
            $pdf->SetXY($x, $y);

            if ($team['members']) {
                foreach ($team['members'] as $member) {
                    $pdf->SetFont($pdf->FontStd, '', 7);
                    $pdf->Cell(44, 4, $member['name'] ?? '', 1, 0, 'L', 0, '', 1);
                    $pdf->Cell(12, 4, $member['division'] ?? '', 1, 0, 'C', 0, '', 1);
                    $pdf->Cell(11, 4, $member['ageclass'] ?? '', 1, 0, 'C', 0, '', 1);
                    $pdf->Cell(11, 4, $member['class'] ?? '', 1, 0, 'C', 0, '', 1);
                    $pdf->Cell(8, 4, $member['subclass'] ?? '', 1, 0, 'C', 0, '', 1);
                    $pdf->SetFont($pdf->FontFix, '', 7);
                    $score = $member['score'] ?? '';
                    $pdf->Cell(9, 4, is_numeric($score) ? number_format($score, 0, '', $pdf->NumberThousandsSeparator) : $score, 1, 1, 'R', 0);
                    $pdf->SetX(73);
                }
            } else {
                $pdf->Cell(95, $height, '', 1, 1, 'L');
            }
            $pdf->SetX(10);
        }
        $firstSection = false;
    }
}

function resultspack_render_native_finals($pdf, array $tournaments, array $individualGroups, array $teamGroups, array $options)
{
    $sessionBackup = $_SESSION;
    $requestBackup = $_REQUEST;

    $renderGroup = function ($tourId, array $codes, $kind, $part) use ($pdf, $sessionBackup) {
        if (!$codes) {
            return;
        }
        if (!CreateTourSession((int) $tourId)) {
            return;
        }
        require_once('Common/OrisFunctions.php');
        require_once('Common/pdf/PdfChunkLoader.php');
        if (function_exists('DefineForcePrintouts')) {
            DefineForcePrintouts((int) $tourId);
        }

        $_REQUEST = array();
        if ($kind === 'individual' && $part === 'ranking') {
            $PdfData = getRankingIndividual($codes);
            if (!empty($PdfData->rankData['sections'])) {
                $pdf->AddPage();
                require(PdfChunkLoader('RankIndividual.inc.php'));
            }
        } elseif ($kind === 'individual' && $part === 'bracket') {
            global $CFG;
            $_REQUEST = array(
                'Event' => $codes,
                'ShowTargetNo' => 1,
                'ShowSchedule' => 1,
            );
            $isCompleteResultBook = true;
            $pdf->AddPage();
            include($CFG->DOCUMENT_PATH . 'Final/Individual/PrnBracket.php');
        } elseif ($kind === 'team' && $part === 'ranking') {
            $PdfData = getRankingTeams($codes);
            if (!empty($PdfData->rankData['sections'])) {
                $pdf->AddPage();
                require(PdfChunkLoader('RankTeam.inc.php'));
            }
        } elseif ($kind === 'team' && $part === 'bracket') {
            global $CFG;
            $_REQUEST = array(
                'Event' => $codes,
                'ShowTargetNo' => 1,
                'ShowSchedule' => 1,
            );
            $isCompleteResultBook = true;
            $pdf->AddPage();
            include($CFG->DOCUMENT_PATH . 'Final/Team/PrnBracket.php');
        }
        $_SESSION = $sessionBackup;
    };

    if (!empty($options['individual_rankings'])) {
        foreach ($individualGroups as $tourId => $codes) {
            $renderGroup($tourId, $codes, 'individual', 'ranking');
        }
    }
    if (!empty($options['individual_brackets'])) {
        foreach ($individualGroups as $tourId => $codes) {
            $renderGroup($tourId, $codes, 'individual', 'bracket');
        }
    }
    if (!empty($options['team_rankings'])) {
        foreach ($teamGroups as $tourId => $codes) {
            $renderGroup($tourId, $codes, 'team', 'ranking');
        }
    }
    if (!empty($options['team_brackets'])) {
        foreach ($teamGroups as $tourId => $codes) {
            $renderGroup($tourId, $codes, 'team', 'bracket');
        }
    }

    $_SESSION = $sessionBackup;
    $_REQUEST = $requestBackup;
}

function resultspack_render_native_final_team_qualification($pdf, array $teamGroups)
{
    if (!$teamGroups) {
        return;
    }
    $sessionBackup = $_SESSION;
    $requestBackup = $_REQUEST;
    foreach ($teamGroups as $tourId => $codes) {
        if (!$codes || !CreateTourSession((int) $tourId)) {
            continue;
        }
        require_once('Common/OrisFunctions.php');
        require_once('Common/pdf/PdfChunkLoader.php');
        if (function_exists('DefineForcePrintouts')) {
            DefineForcePrintouts((int) $tourId);
        }
        $_REQUEST = array();
        $PdfData = getQualificationTeam($codes);
        $rankData = $PdfData->rankData;
        if (!empty($rankData['sections'])) {
            $pdf->AddPage();
            require(PdfChunkLoader('QualTeam.inc.php'));
        }
        $_SESSION = $sessionBackup;
    }
    $_SESSION = $sessionBackup;
    $_REQUEST = $requestBackup;
}


function resultspack_render_custom_awards($pdf, array $awards)
{
    if (!$awards) return;

    $pdf->AddPage();
    $pdf->SetDefaultColor();
    $width = resultspack_pdf_content_width($pdf);
    $pdf->SetFont($pdf->FontStd, 'B', 12);
    $pdf->SetFillColor(218, 218, 218);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($width, 8, 'Additional Awards', 1, 1, 'L', 1);
    $pdf->Ln(3);

    $columns = array(43, 72, 30, $width - 145);
    $currentGroup = null;

    $drawHeader = function () use ($pdf, $columns) {
        $pdf->SetFont($pdf->FontStd, 'B', 7.5);
        $pdf->SetFillColor(225, 225, 225);
        $pdf->SetTextColor(0, 0, 0);
        foreach (array('Award', 'Description', 'Category', 'Winner') as $i => $heading) {
            $pdf->Cell($columns[$i], 6, $heading, 1, $i === 3 ? 1 : 0, 'L', 1);
        }
    };

    foreach ($awards as $award) {
        $group = resultspack_normalise_whitespace($award['group'] ?? '');
        if ($group !== $currentGroup) {
            $groupGap = ($currentGroup !== null && $group !== '') ? 3.0 : 0.0;
            $needed = $groupGap + ($group !== '' ? 7 : 0) + 8;
            if (!$pdf->SamePage($needed)) {
                $pdf->AddPage();
                $groupGap = 0.0;
            }
            if ($groupGap > 0) {
                $pdf->Ln($groupGap);
            }
            if ($group !== '') {
                $pdf->SetFont($pdf->FontStd, 'B', 9);
                $pdf->SetFillColor(238, 238, 238);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Cell($width, 6.5, $group, 'B', 1, 'L', 1);
                $pdf->Ln(1);
            }
            $drawHeader();
            $currentGroup = $group;
        }

        $values = array(
            resultspack_normalise_whitespace($award['name'] ?? ''),
            resultspack_normalise_whitespace($award['description'] ?? ''),
            resultspack_normalise_whitespace($award['category'] ?? ''),
            resultspack_normalise_whitespace($award['winner'] ?? ''),
        );
        $pdf->SetFont($pdf->FontStd, '', 8);
        $lines = 1;
        foreach ($values as $i => $value) {
            $lines = max($lines, $pdf->getNumLines($value !== '' ? $value : '-', $columns[$i] - 3));
        }
        $rowHeight = max(6, ($lines * 4) + 2);
        if (!$pdf->SamePage($rowHeight + 1)) {
            $pdf->AddPage();
            if ($group !== '') {
                $pdf->SetFont($pdf->FontStd, 'B', 9);
                $pdf->SetFillColor(238, 238, 238);
                $pdf->Cell($width, 6.5, $group . ' - Continue', 'B', 1, 'L', 1);
                $pdf->Ln(1);
            }
            $drawHeader();
        }

        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $cursor = $x;
        foreach ($values as $i => $value) {
            $pdf->SetXY($cursor, $y);
            $pdf->SetFont($pdf->FontStd, $i === 3 ? 'B' : '', 8);
            $pdf->MultiCell($columns[$i], $rowHeight, $value !== '' ? $value : '-', 1, 'L', 0, 0, $cursor, $y, true, 0, false, true, $rowHeight, 'M');
            $cursor += $columns[$i];
        }
        $pdf->SetXY($x, $y + $rowHeight);
    }
}
