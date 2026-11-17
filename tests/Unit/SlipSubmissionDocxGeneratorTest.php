<?php

namespace Tests\Unit;

use App\Services\SlipSubmissionDocxGenerator;
use Tests\TestCase;
use ZipArchive;

class SlipSubmissionDocxGeneratorTest extends TestCase
{
    public function test_generates_a_filled_word_referral_from_its_template(): void
    {
        $document = app(SlipSubmissionDocxGenerator::class)->generate('student-referral-slip', [
            'student_id' => 'STUDENT-42',
            'student_name' => 'Sample Student',
            'program_year' => 'BSCS 1',
            'sex' => 'Female',
            'date_absent' => '2026-11-10',
            'referral_reasons' => ['class_attendance'],
            'referred_date' => '2026-11-12',
            'document_code' => 'STUDENT-REFERRAL-SLIP',
            'revision_number' => '00',
            'effectivity_date' => '2026-11-12',
            'control_number' => 'REF-2026-000001',
        ]);
        $xml = $this->documentXml($document);

        $this->assertStringContainsString('STUDENT-42', $xml);
        $this->assertStringContainsString('Sample Student', $xml);
        $this->assertStringContainsString('REF-2026-000001', $xml);
        $this->assertStringContainsString('☒ Class Attendance', $this->documentText($xml));
        $this->assertValueIsUnderlined($xml, 'Sample Student');
    }

    public function test_generates_a_filled_word_class_admission_slip_from_its_template(): void
    {
        $document = app(SlipSubmissionDocxGenerator::class)->generate('class-admission-slip', [
            'student_id' => 'STUDENT-84',
            'student_name' => 'Another Student',
            'sex' => 'Male',
            'program_year' => 'BSIT 2',
            'referral_slip_number' => 'SR-2026-000012',
            'date_received' => '2026-11-12',
            'date_absent' => '2026-11-10',
            'days_absent' => 1,
            'absence_causes' => ['health_related'],
            'actions_taken' => 'interviewed_with_guidance',
            'excused_status' => 'excused',
            'document_code' => 'CLASS-ADMISSION-SLIP',
            'revision_number' => '00',
            'effectivity_date' => '2026-11-12',
            'control_number' => 'CAS-2026-000005',
        ]);
        $xml = $this->documentXml($document);
        $text = $this->documentText($xml);

        $this->assertStringContainsString('STUDENT-84', $xml);
        $this->assertStringContainsString('Another Student', $xml);
        $this->assertStringContainsString('SR-2026-000012', $xml);
        $this->assertStringContainsString('(X ) health-related concerns', $text);
        $this->assertStringContainsString('CLASS-ADMISSION-SLIP', $text);
        $this->assertStringContainsString('00', $text);
        $this->assertStringContainsString('2026-11-12', $text);
        $this->assertStringContainsString('CAS-2026-000005', $text);
        $this->assertValueIsUnderlined($xml, 'Another Student');
        $this->assertValueIsUnderlined($xml, 'CLASS-ADMISSION-SLIP');
        $this->assertValueIsUnderlined($xml, 'CAS-2026-000005');
    }

    private function documentXml(string $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'slip-docx-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, $document);

        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        $this->assertNotFalse($xml);

        return $xml;
    }

    private function assertValueIsUnderlined(string $xml, string $value): void
    {
        $document = new \DOMDocument;
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        foreach ($xpath->query('//w:r') as $run) {
            $runText = '';
            foreach ($xpath->query('./w:t', $run) as $textNode) {
                $runText .= $textNode->nodeValue ?? '';
            }

            if (str_contains($runText, $value)) {
                $this->assertNotNull($xpath->query('./w:rPr/w:u', $run)->item(0));

                return;
            }
        }

        $this->fail("The value [$value] was not found in an underlined Word run.");
    }

    private function documentText(string $xml): string
    {
        $document = new \DOMDocument;
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $text = '';

        foreach ($xpath->query('//w:t') as $textNode) {
            $text .= $textNode->nodeValue ?? '';
        }

        return $text;
    }
}
