<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Services\AdminDocumentDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminDocumentDispatchController extends Controller
{
    public function __construct(
        private readonly AdminDocumentDispatchService $dispatchService,
    ) {}

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        $validator = Validator::make($request->all(), [
            'channel' => ['required', 'string', 'in:email,whatsapp'],
            'document_type' => ['required', 'string', 'max:60'],
            'document_title' => ['required', 'string', 'max:255'],
            'document_number' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'pdf_name' => ['required', 'string', 'max:255'],
            'pdf_b64' => ['required', 'string'],
            'attachment_name' => ['nullable', 'string', 'max:255'],
            'attachment_mime' => ['nullable', 'string', 'max:120'],
            'attachment_b64' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $channel = (string) $request->input('channel', '');
            $pdfB64 = trim((string) $request->input('pdf_b64', ''));
            $attachmentB64 = trim((string) $request->input('attachment_b64', ''));

            if ($channel === 'email' && trim((string) $request->input('recipient_email', '')) === '') {
                $validator->errors()->add('recipient_email', 'L\'email destinataire est requis.');
            }

            if ($channel === 'whatsapp' && trim((string) $request->input('recipient_phone', '')) === '') {
                $validator->errors()->add('recipient_phone', 'Le numéro WhatsApp destinataire est requis.');
            }

            if ($pdfB64 === '' || base64_decode($pdfB64, true) === false) {
                $validator->errors()->add('pdf_b64', 'Le PDF doit être encodé en base64 valide.');
            }

            if ($attachmentB64 !== '' && base64_decode($attachmentB64, true) === false) {
                $validator->errors()->add('attachment_b64', 'La pièce jointe doit être encodée en base64 valide.');
            }
        });

        if ($validator->fails()) {
            return ApiResponseClass::validationError($validator->errors(), 'Validation Error');
        }

        $result = $this->dispatchService->send(
            sender: $user,
            payload: $validator->validated(),
        );

        if (!($result['success'] ?? false)) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Envoi impossible.',
                $result['errors'] ?? null,
                (int) ($result['status'] ?? 422),
            );
        }

        return ApiResponseClass::sendResponse(
            $result,
            $result['message'] ?? 'Document envoyé avec succès.',
        );
    }
}