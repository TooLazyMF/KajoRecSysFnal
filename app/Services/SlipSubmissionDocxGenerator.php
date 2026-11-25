<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class SlipSubmissionDocxGenerator
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function generate(string $slip, array $data): string
    {
        $template = match ($slip) {
            'student-referral-slip' => 'STUDENT-REFERRAL-SLIP.docx',
            'class-admission-slip' => 'CLASS-ADMISSION-SLIP.docx',
            default => throw new RuntimeException('This slip does not have a Word template.'),
        };
        $templatePath = public_path('slips/'.$template);
        $temporary = tempnam(sys_get_temp_dir(), 'slip-docx-');

        if ($temporary === false || ! copy($templatePath, $temporary)) {
            throw new RuntimeException('A temporary Word document could not be created.');
        }

        $zip = new ZipArchive;
        if ($zip->open($temporary) !== true) {
            @unlink($temporary);
            throw new RuntimeException('The Word slip template could not be opened.');
        }

        try {
            $documentXml = $zip->getFromName('word/document.xml');
            if ($documentXml === false) {
                throw new RuntimeException('The Word slip document body could not be found.');
            }

            $document = new DOMDocument;
            $document->preserveWhiteSpace = true;
            if (! $document->loadXML($documentXml, LIBXML_NONET)) {
                throw new RuntimeException('The Word slip document body is invalid.');
            }

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            if ($slip === 'student-referral-slip') {
                $this->fillReferral($xpath, $data);
            } else {
                $this->fillClassAdmission($xpath, $data);
            }

            if (! $zip->addFromString('word/document.xml', $document->saveXML())) {
                throw new RuntimeException('The completed Word slip could not be written.');
            }
        } finally {
            $zip->close();
        }

        $output = file_get_contents($temporary);
        @unlink($temporary);

        if ($output === false) {
            throw new RuntimeException('The completed Word slip could not be read.');
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillReferral(DOMXPath $xpath, array $data): void
    {
        foreach ([
            'Student ID No:' => $data['student_id'] ?? null,
            'Name of Student:' => $data['student_name'] ?? null,
            'Degree Program & Year Level:' => $data['program_year'] ?? null,
            'Sex:' => $data['sex'] ?? null,
            'absent/tardy on:' => $data['date_absent'] ?? null,
            'Others:' => $data['other_reason'] ?? null,
            'Referred by:' => $data['referred_by_name'] ?? null,
            'Received by:' => $data['received_by_name'] ?? null,
        ] as $label => $value) {
            $this->replaceBlankAfterLabel($xpath, $label, $value);
        }
        foreach ([
            'Document Code:' => $data['document_code'] ?? null,
            'Revision Number:' => $data['revision_number'] ?? null,
            'Effectivity Date:' => $data['effectivity_date'] ?? null,
            'Control No.:' => $data['control_number'] ?? null,
        ] as $label => $value) {
            $this->replaceBlankAfterLabel($xpath, $label, $value, '', true);
        }

        $this->replaceBlankAfterLabel($xpath, 'Date:', $data['referred_date'] ?? $data['request_date'] ?? null, 'Effectivity ');

        $this->fillCheckboxes($xpath, $data['referral_reasons'] ?? [], [
            'class_attendance' => 'Class Attendance',
            'academic_deficiencies' => 'Academic Deficiency/ies',
            'psychological_testing' => 'Psychological Testing',
            'scholarship_grant_assistance' => 'Scholarship/Grant Assistance',
            'student_housing' => 'Student Housing (Dormitory)',
            'student_employment' => 'Student Employment (SA/SPES)',
            'other' => 'Others:',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillClassAdmission(DOMXPath $xpath, array $data): void
    {
        $this->fillDocumentControlFields($xpath, $data);

        foreach ([
            'Student ID No:' => $data['student_id'] ?? null,
            'DATE:' => $data['date_received'] ?? null,
            'Name of Student:' => $data['student_name'] ?? null,
            'Sex:' => $data['sex'] ?? null,
            'Degree Program & Year Level:' => $data['program_year'] ?? null,
            'Reference Referral Slip No.:' => $data['referral_slip_number'] ?? null,
            'Date Received:' => $data['date_received'] ?? null,
            'Date/s Absent/Tardy:' => $data['date_absent'] ?? null,
            'Number of day/s absent/tardy:' => $data['days_absent'] ?? null,
            'others:' => $data['other_action_detail'] ?? null,
        ] as $label => $value) {
            $this->replaceBlankAfterLabel($xpath, $label, $value);
        }

        $this->fillCheckboxes($xpath, $data['absence_causes'] ?? [], [
            'health_related' => 'health-related concerns',
            'personal_concerns' => 'personal concerns',
            'family_responsibilities' => 'filial responsibilities',
            'socio_cultural' => 'socio-cultural concern',
            'environmental' => 'environmental',
            'behavioral' => 'behavioral',
            'extracurricular' => 'official co/extra-curricular activity:',
            'other' => 'others:',
        ]);

        $this->fillCheckboxes($xpath, [$data['actions_taken'] ?? ''], [
            'verified_documents' => 'verified supporting document/s',
            'interviewed_with_guidance' => 'interviewed with guidance',
            'life_coaching' => 'life coaching',
            'parent_conference' => 'parent conference',
            'counseling' => 'counseling',
            'other_action' => 'others:',
        ]);
        $this->fillCheckboxes($xpath, [$data['excused_status'] ?? ''], [
            'excused' => 'Excused:',
            'unexcused' => 'Unexcused:',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillDocumentControlFields(DOMXPath $xpath, array $data): void
    {
        foreach ([
            'document_code' => 'Document Code:',
            'revision_number' => 'Revision Number:',
            'effectivity_date' => 'Effectivity Date:',
            'control_number' => 'Control No.:',
        ] as $key => $label) {
            $value = $data[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            foreach ($xpath->query('//w:tc') as $labelCell) {
                if (! str_contains($this->paragraphText($xpath, $labelCell), $label)) {
                    continue;
                }

                $valueCell = $xpath->query('following-sibling::w:tc[1]', $labelCell)->item(0);
                if ($valueCell && trim($this->paragraphText($xpath, $valueCell)) === '') {
                    $paragraph = $xpath->query('./w:p', $valueCell)->item(0);
                    if ($paragraph instanceof \DOMElement) {
                        $this->appendUnderlinedText($paragraph, (string) $value);
                    }

                    continue;
                }

                if (! $valueCell) {
                    $paragraph = $xpath->query('./w:p', $labelCell)->item(0);
                    if ($paragraph instanceof \DOMElement) {
                        $paragraphText = $this->paragraphText($xpath, $paragraph);
                        $labelPosition = stripos($paragraphText, $label);
                        if ($labelPosition !== false) {
                            $this->insertUnderlinedTextAtOffset(
                                $xpath,
                                $paragraph,
                                $labelPosition + strlen($label),
                                ' '.(string) $value.' '
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  array<int, string>|string|null  $selected
     * @param  array<string, string>  $options
     */
    private function fillCheckboxes(DOMXPath $xpath, array|string|null $selected, array $options): void
    {
        $selected = is_array($selected) ? $selected : [$selected];

        foreach ($options as $key => $label) {
            $this->setCheckbox($xpath, $label, in_array($key, $selected, true));
        }
    }

    private function setCheckbox(DOMXPath $xpath, string $label, bool $checked): void
    {
        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = $this->paragraphText($xpath, $paragraph);
            $labelPosition = stripos($text, $label);
            if ($labelPosition === false) {
                continue;
            }

            $beforeLabel = substr($text, 0, $labelPosition);
            if (! preg_match_all('/☐|☒|\(\s*\)|\[\s*\]/u', $beforeLabel, $matches, PREG_OFFSET_CAPTURE) || $matches[0] === []) {
                return;
            }

            $marker = $matches[0][count($matches[0]) - 1][0];
            $offset = $matches[0][count($matches[0]) - 1][1];
            $replacement = match ($marker) {
                '☐', '☒' => $checked ? '☒' : '☐',
                default => preg_match('/^\(/', $marker) ? ($checked ? '(X )' : '(  )') : ($checked ? '[X ]' : '[  ]'),
            };
            $this->replaceTextRange($xpath, $paragraph, $offset, strlen($marker), $replacement);

            return;
        }
    }

    private function replaceBlankAfterLabel(
        DOMXPath $xpath,
        string $label,
        mixed $value,
        string $excludedPrefix = '',
        bool $appendWhenNoBlank = false
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        $replacement = is_array($value)
            ? implode(', ', array_filter(array_map(static fn ($item): string => (string) $item, $value)))
            : (string) $value;
        if ($replacement === '') {
            return;
        }

        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = $this->paragraphText($xpath, $paragraph);
            $position = stripos($text, $label);
            $prefix = $position === false ? '' : substr($text, 0, $position);
            if (
                $position === false
                || ($excludedPrefix !== '' && str_ends_with($prefix, $excludedPrefix))
                || (strtoupper($label) === 'DATE:' && preg_match('/(?:Effectivity|Received)\s$/i', $prefix))
            ) {
                continue;
            }

            $afterLabel = substr($text, $position + strlen($label));
            if (! preg_match('/_{2,}/u', $afterLabel, $match, PREG_OFFSET_CAPTURE)) {
                if ($appendWhenNoBlank) {
                    $this->insertUnderlinedTextAtOffset($xpath, $paragraph, $position + strlen($label), ' '.$replacement.' ');

                    return;
                }

                continue;
            }

            $offset = $position + strlen($label) + $match[0][1];
            $placeholderLength = strlen($match[0][0]);
            $remainingUnderscores = max(0, mb_strlen($match[0][0]) - mb_strlen($replacement) - 1);
            $filledValue = $replacement.($remainingUnderscores > 0 ? ' '.str_repeat('_', $remainingUnderscores) : '');
            $this->replaceTextRange($xpath, $paragraph, $offset, $placeholderLength, '');
            $this->insertUnderlinedTextAtOffset($xpath, $paragraph, $offset, $filledValue);

            return;
        }
    }

    private function paragraphText(DOMXPath $xpath, \DOMNode $paragraph): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t', $paragraph) as $node) {
            $text .= $node->nodeValue ?? '';
        }

        return $text;
    }

    private function replaceTextRange(
        DOMXPath $xpath,
        \DOMNode $paragraph,
        int $offset,
        int $length,
        string $replacement
    ): void {
        // Word may split one visible placeholder across several runs; map its flattened text range back to those nodes.
        $nodes = $xpath->query('.//w:t', $paragraph);
        $segments = [];
        $position = 0;
        foreach ($nodes as $node) {
            $value = $node->nodeValue ?? '';
            $segments[] = ['node' => $node, 'value' => $value, 'start' => $position, 'end' => $position + strlen($value)];
            $position += strlen($value);
        }

        $replacementInserted = false;
        foreach ($segments as $segment) {
            $overlapStart = max($offset, $segment['start']);
            $overlapEnd = min($offset + $length, $segment['end']);
            if ($overlapStart >= $overlapEnd) {
                continue;
            }

            $localStart = $overlapStart - $segment['start'];
            $localLength = $overlapEnd - $overlapStart;
            $insert = $replacementInserted ? '' : $replacement;
            $this->setTextNodeValue(
                $segment['node'],
                substr($segment['value'], 0, $localStart)
                    .$insert
                    .substr($segment['value'], $localStart + $localLength)
            );
            $replacementInserted = true;
        }
    }

    private function insertUnderlinedTextAtOffset(
        DOMXPath $xpath,
        \DOMNode $paragraph,
        int $offset,
        string $text
    ): void {
        $nodes = $xpath->query('.//w:t', $paragraph);
        $segments = [];
        $position = 0;
        foreach ($nodes as $node) {
            $value = $node->nodeValue ?? '';
            $segments[] = ['node' => $node, 'value' => $value, 'start' => $position, 'end' => $position + strlen($value)];
            $position += strlen($value);
        }

        $anchor = null;
        $runStart = 0;
        foreach ($segments as $segment) {
            if (($offset > $segment['start'] && $offset <= $segment['end'])
                || ($offset === 0 && $segment['start'] === 0 && $segment['end'] > 0)) {
                $anchor = $segment['node']->parentNode;
                foreach ($segments as $runSegment) {
                    if ($runSegment['node']->parentNode === $anchor) {
                        $runStart = $runSegment['start'];
                        break;
                    }
                }
                break;
            }
        }

        if (! $anchor instanceof \DOMElement || ! $anchor->parentNode) {
            return;
        }

        $runText = '';
        foreach ($xpath->query('.//w:t', $anchor) as $node) {
            $runText .= $node->nodeValue ?? '';
        }
        $localOffset = $offset - $runStart;
        $prefix = substr($runText, 0, $localOffset);
        $suffix = substr($runText, $localOffset);
        $parent = $anchor->parentNode;
        $nextSibling = $anchor->nextSibling;
        $runs = [];

        if ($prefix !== '') {
            $runs[] = $this->createTextRun($anchor, $prefix);
        }
        $runs[] = $this->createTextRun($anchor, $text, true);
        if ($suffix !== '') {
            $runs[] = $this->createTextRun($anchor, $suffix);
        }

        $parent->removeChild($anchor);
        foreach ($runs as $run) {
            $parent->insertBefore($run, $nextSibling);
        }
    }

    private function createTextRun(\DOMElement $sourceRun, string $text, bool $underlined = false): \DOMElement
    {
        $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $run = $sourceRun->ownerDocument->createElementNS($namespace, 'w:r');
        $properties = $sourceRun->getElementsByTagNameNS($namespace, 'rPr')->item(0);

        if ($properties) {
            $run->appendChild($properties->cloneNode(true));
        }

        if ($underlined) {
            $runProperties = $run->getElementsByTagNameNS($namespace, 'rPr')->item(0);
            if (! $runProperties) {
                $runProperties = $sourceRun->ownerDocument->createElementNS($namespace, 'w:rPr');
                $run->insertBefore($runProperties, $run->firstChild);
            }
            foreach ($runProperties->getElementsByTagNameNS($namespace, 'u') as $existingUnderline) {
                $existingUnderline->parentNode?->removeChild($existingUnderline);
            }
            $underline = $sourceRun->ownerDocument->createElementNS($namespace, 'w:u');
            $underline->setAttributeNS($namespace, 'w:val', 'single');
            $runProperties->appendChild($underline);
        }

        $textNode = $sourceRun->ownerDocument->createElementNS($namespace, 'w:t');
        $textNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $textNode->appendChild($sourceRun->ownerDocument->createTextNode($text));
        $run->appendChild($textNode);

        return $run;
    }

    private function appendUnderlinedText(\DOMElement $paragraph, string $text): void
    {
        $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $document = $paragraph->ownerDocument;
        $run = $document->createElementNS($namespace, 'w:r');
        $properties = $document->createElementNS($namespace, 'w:rPr');
        $properties->appendChild($document->createElementNS($namespace, 'w:u'));
        $properties->lastChild->setAttributeNS($namespace, 'w:val', 'single');
        $run->appendChild($properties);

        $textNode = $document->createElementNS($namespace, 'w:t');
        $textNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $textNode->appendChild($document->createTextNode($text));
        $run->appendChild($textNode);
        $paragraph->appendChild($run);
    }

    private function setTextNodeValue(\DOMNode $node, string $value): void
    {
        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }
        $node->appendChild($node->ownerDocument->createTextNode($value));
    }
}
