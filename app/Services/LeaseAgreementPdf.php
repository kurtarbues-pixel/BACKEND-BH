<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\PendingCharge;
use Carbon\CarbonInterface;

/** Small self-contained PDF generator so lease downloads need no paid service or package. */
class LeaseAgreementPdf
{
    public function generate(Lease $lease): string
    {
        $lease->loadMissing(['room', 'tenant', 'landlord', 'charges', 'invoices', 'inspections']);

        $room = $lease->room;
        $tenant = $lease->tenant;
        $landlord = $lease->landlord;
        $terms = $lease->agreementSnapshot();
        $charges = $lease->charges
            ->filter(fn (PendingCharge $charge) => ! in_array(strtolower((string) $charge->status), ['void', 'voided'], true))
            ->sortBy(['billing_period', 'id'])
            ->values();
        $invoices = $lease->invoices->sortBy(['billing_period', 'id'])->values();
        $inspections = $lease->inspections->sortBy(['inspection_date', 'id'])->values();

        $lines = [
            ['type' => 'title', 'text' => 'BoardEase Lease Record'],
            ['type' => 'subtitle', 'text' => 'Lease record #'.$lease->getKey()],
            ['type' => 'paragraph', 'text' => 'This document summarizes information currently recorded in BoardEase for this lease. It does not add terms that are absent from the system record.'],

            ['type' => 'section', 'text' => 'Parties'],
            ['type' => 'line', 'text' => 'Landlord: '.($terms['landlord_name'] ?? $landlord?->name ?? 'Not recorded')],
            ['type' => 'line', 'text' => 'Landlord email: '.($landlord?->email ?: 'Not recorded')],
            ['type' => 'line', 'text' => 'Landlord phone: '.($landlord?->phone ?: 'Not recorded')],
            ['type' => 'line', 'text' => 'Tenant: '.($terms['tenant_name'] ?? $tenant?->name ?? 'Not recorded')],
            ['type' => 'line', 'text' => 'Tenant email: '.($tenant?->email ?: 'Not recorded')],
            ['type' => 'line', 'text' => 'Tenant phone: '.($tenant?->phone ?: 'Not recorded')],

            ['type' => 'section', 'text' => 'Room and lease terms'],
            ['type' => 'line', 'text' => 'Room: '.($terms['room_number'] ?? $room?->room_number ?? 'Not recorded').' - '.($terms['room_title'] ?? $room?->title ?? 'Not recorded')],
            ['type' => 'line', 'text' => 'Room type: '.($room?->type ?: 'Not recorded')],
            ['type' => 'line', 'text' => 'Capacity: '.($room?->capacity ? (string) $room->capacity.' occupant(s)' : 'Not recorded')],
            ['type' => 'line', 'text' => 'Property/location: '.($terms['location'] ?? $room?->location ?: 'Not recorded')],
            ['type' => 'line', 'text' => 'Monthly rent: PHP '.$this->money($terms['monthly_rent'] ?? $lease->monthly_rent)],
            ['type' => 'line', 'text' => 'Lease start date: '.$this->date($terms['start_date'] ?? $lease->start_date)],
            ['type' => 'line', 'text' => 'Lease end date: '.$this->date($terms['end_date'] ?? $lease->end_date, 'Open-ended / not recorded')],
            ['type' => 'line', 'text' => 'Lease status: '.ucfirst((string) ($lease->status ?: 'Not recorded'))],

            ['type' => 'section', 'text' => 'Payment schedule'],
        ];

        if ($invoices->isNotEmpty()) {
            foreach ($invoices as $invoice) {
                $lines[] = [
                    'type' => 'line',
                    'text' => 'Invoice '.($invoice->billing_period ?: 'period not recorded').' due date: '.$this->date($invoice->due_date, 'Not recorded'),
                ];
            }
        } else {
            $lines[] = ['type' => 'paragraph', 'text' => 'The lease records a monthly rental amount. No invoice due date or specific monthly due day is currently recorded for this lease.'];
        }

        $lines[] = ['type' => 'section', 'text' => 'Additional charges recorded against this lease'];
        $lines[] = ['type' => 'paragraph', 'text' => 'Charge amounts below are ledger entries and may already appear on invoices. They are listed for reference and are not added again to the monthly rent.'];
        if ($charges->isEmpty()) {
            $lines[] = ['type' => 'line', 'text' => 'No active additional charges are recorded.'];
        } else {
            foreach ($charges as $charge) {
                $lines[] = ['type' => 'line', 'text' => $this->chargeLabel($charge)];
            }
        }

        $amenities = is_array($room?->amenities) ? implode(', ', $room->amenities) : '';
        $lines[] = ['type' => 'section', 'text' => 'Room notes and recorded terms'];
        $lines[] = ['type' => 'paragraph', 'text' => 'Room description / notes: '.($room?->description ?: 'Not recorded.')];
        $lines[] = ['type' => 'line', 'text' => 'Amenities recorded: '.($amenities ?: 'Not recorded')];
        $lines[] = ['type' => 'line', 'text' => 'Security deposit: Not recorded in the available lease data.'];
        $lines[] = ['type' => 'line', 'text' => 'House rules: No separate house-rules field is recorded. See room description / notes above.'];
        $lines[] = ['type' => 'paragraph', 'text' => 'Landlord responsibilities: No responsibility clause is recorded in the lease data.'];
        $lines[] = ['type' => 'paragraph', 'text' => 'Tenant responsibilities: No responsibility clause is recorded in the lease data.'];
        $lines[] = ['type' => 'paragraph', 'text' => 'Maintenance and damage terms: No separate clause is recorded. The inspection records below reflect condition details stored in BoardEase.'];

        $lines[] = ['type' => 'section', 'text' => 'Recorded inspections'];
        if ($inspections->isEmpty()) {
            $lines[] = ['type' => 'line', 'text' => 'No inspections are recorded for this lease.'];
        } else {
            foreach ($inspections as $inspection) {
                $lines[] = ['type' => 'line', 'text' => ucfirst(str_replace('_', ' ', (string) $inspection->kind)).' inspection · '.$this->date($inspection->inspection_date).' · '.ucfirst((string) $inspection->status)];
                $lines[] = ['type' => 'paragraph', 'text' => 'Condition: '.($inspection->condition ?: 'Not recorded')];
                if ($inspection->notes) {
                    $lines[] = ['type' => 'paragraph', 'text' => 'Notes: '.$inspection->notes];
                }
                if ((float) $inspection->damages_amount > 0 || $inspection->damage_description) {
                    $lines[] = ['type' => 'paragraph', 'text' => 'Recorded damage: PHP '.$this->money($inspection->damages_amount).' · '.($inspection->damage_description ?: 'No description recorded')];
                }
            }
        }

        $accepted = $lease->agreement_status === 'accepted';
        $acceptanceStatus = match ($lease->agreement_status) {
            'accepted' => 'Accepted by tenant account',
            'pending' => 'Awaiting tenant acceptance',
            'legacy' => 'Legacy lease; acceptance status was not recorded',
            default => ucfirst((string) ($lease->agreement_status ?: 'Not recorded')),
        };
        $acceptedAt = $lease->agreement_accepted_at;
        $lines[] = ['type' => 'section', 'text' => 'Lease acceptance'];
        $lines[] = ['type' => 'line', 'text' => 'Acceptance status: '.$acceptanceStatus];
        $lines[] = ['type' => 'line', 'text' => 'Acceptance date: '.($accepted && $acceptedAt ? $acceptedAt->copy()->setTimezone(config('app.timezone'))->format('F j, Y g:i A T') : 'Not recorded')];
        $lines[] = ['type' => 'line', 'text' => 'Acceptance information: '.($accepted ? 'The tenant account ('.($terms['tenant_name'] ?? $tenant?->name ?? 'Tenant').') recorded acceptance in BoardEase.' : 'No tenant acceptance is recorded.')];
        $lines[] = ['type' => 'paragraph', 'text' => 'This records an in-app acceptance event and is not a legally certified electronic signature.'];

        return $this->renderPdf($lines, (int) $lease->getKey());
    }

    private function chargeLabel(PendingCharge $charge): string
    {
        $category = $charge->utility_type
            ? ucfirst((string) $charge->utility_type).' utility'
            : ucfirst(strtolower((string) $charge->category));
        $description = $charge->description ?: $category;
        $period = $charge->billing_period ? ' · Billing period '.$charge->billing_period : '';

        return $description.' · '.$category.' · PHP '.$this->money($charge->amount).' · '.ucfirst((string) $charge->status).$period;
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', ',') : 'Not recorded';
    }

    private function date(mixed $value, string $fallback = 'Not recorded'): string
    {
        if (! $value) {
            return $fallback;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('F j, Y');
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? $fallback : date('F j, Y', $timestamp);
    }

    /** @param array<int, array{type:string,text?:string}> $lines */
    private function renderPdf(array $lines, int $leaseId): string
    {
        $pages = [[]];
        $pageIndex = 0;
        $y = 770;
        $pages[0][] = $this->textCommand(50, 812, 8, 'F1', 'BOARDEASE  |  LEASE RECORD #'.$leaseId, '0.34 0.39 0.22');
        $pages[0][] = "0.6 w 50 800 m 562 800 l S\n";

        foreach ($lines as $line) {
            $type = $line['type'];
            if ($type === 'spacer') {
                $y -= 8;
                continue;
            }

            $fontSize = match ($type) {
                'title' => 19,
                'subtitle' => 10,
                'section' => 11,
                default => 9,
            };
            $lineHeight = match ($type) {
                'title' => 27,
                'subtitle' => 19,
                'section' => 20,
                'paragraph' => 13,
                default => 15,
            };
            $font = in_array($type, ['title', 'subtitle', 'section'], true) ? 'F2' : 'F1';
            $color = $type === 'section' ? '0.34 0.39 0.22' : '0.16 0.18 0.14';
            $wrapped = wordwrap($this->ascii((string) ($line['text'] ?? '')), $type === 'paragraph' ? 94 : 102, "\n", true);

            if ($type === 'section') {
                $y -= 7;
            }

            foreach (explode("\n", $wrapped) as $lineNumber => $text) {
                if ($y < 55) {
                    $pages[] = [];
                    $pageIndex = array_key_last($pages);
                    $y = 770;
                    $pages[$pageIndex][] = $this->textCommand(50, 812, 8, 'F1', 'BOARDEASE  |  LEASE RECORD #'.$leaseId.'  |  CONTINUED', '0.34 0.39 0.22');
                    $pages[$pageIndex][] = "0.6 w 50 800 m 562 800 l S\n";
                }

                $indent = $lineNumber > 0 ? 14 : 0;
                $pages[$pageIndex][] = $this->textCommand(50 + $indent, $y, $fontSize, $font, $text, $color);
                $y -= $lineHeight;
            }
        }

        $pageCount = count($pages);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $index) => (5 + ($index * 2)).' 0 R', array_keys($pages))).'] /Count '.$pageCount.' >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        foreach ($pages as $index => $commands) {
            $pageNumber = $index + 1;
            $commands[] = $this->textCommand(50, 31, 7, 'F1', 'Generated from BoardEase records  |  Page '.$pageNumber.' of '.$pageCount, '0.38 0.40 0.34');
            $content = implode('', $commands);
            $pageObjectId = 5 + ($index * 2);
            $contentObjectId = $pageObjectId + 1;
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentObjectId.' 0 R >>';
            $objects[] = '<< /Length '.strlen($content).">>\nstream\n{$content}\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function textCommand(int $x, int $y, int $size, string $font, string $text, string $color): string
    {
        return $color.' rg BT /'.$font.' '.$size.' Tf 1 0 0 1 '.$x.' '.$y.' Tm ('.$this->escape($text).") Tj ET\n";
    }

    private function ascii(string $text): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: '';
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
    }
}
