<?php

namespace App\Http\Controllers;

use App\Models\SlipAssignment;
use App\Models\SlipInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SlipInformationController extends Controller
{
    public function show(Request $request, SlipInformation $slipInformation): View
    {
        $this->authorizeSlipInformation($request, $slipInformation);
        $assignment = SlipAssignment::query()
            ->where('slip_information_id', $slipInformation->id)
            ->first();

        return view('slips.informations.submitted', compact('slipInformation', 'assignment'));
    }

    public function pdf(Request $request, SlipInformation $slipInformation): StreamedResponse
    {
        $this->authorizeSlipInformation($request, $slipInformation);
        abort_unless(Storage::disk('local')->exists($slipInformation->pdf_path), 404);

        return Storage::disk('local')->response(
            $slipInformation->pdf_path,
            "slip-information-{$slipInformation->id}.pdf",
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="slip-information-'.$slipInformation->id.'.pdf"']
        );
    }

    public function systemPreview(Request $request, SlipInformation $slipInformation): View
    {
        $this->authorizeSlipInformation($request, $slipInformation);

        return view('slips.informations.system-preview', compact('slipInformation'));
    }

    private function authorizeSlipInformation(Request $request, SlipInformation $slipInformation): void
    {
        abort_unless(
            $slipInformation->user_id === $request->user()->id
                || $request->user()->role === 'guidance_counselor',
            403
        );
    }
}
