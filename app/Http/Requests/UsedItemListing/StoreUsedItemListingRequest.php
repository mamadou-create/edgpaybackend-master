<?php

namespace App\Http\Requests\UsedItemListing;

use App\Models\UsedItemListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUsedItemListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:4000'],
            'category' => ['required', 'string', 'max:80', Rule::in(UsedItemListing::categories())],
            'condition_label' => ['required', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_methods' => ['required', 'array', 'min:1'],
            'contact_methods.*' => ['string', Rule::in(['whatsapp', 'sms', 'email', 'call'])],
            'transaction_type' => ['nullable', Rule::in(UsedItemListing::transactionTypes())],
            'accepts_barter' => ['nullable', 'boolean'],
            'wanted_object' => ['nullable', 'string', 'max:255'],
            'wanted_objects' => ['nullable', 'array'],
            'wanted_objects.*' => ['string', 'max:255'],
            'wanted_category' => ['nullable', 'string', 'max:80'],
            'wanted_value' => ['nullable', 'numeric', 'min:0'],
            'estimated_object_value' => ['nullable', 'numeric', 'min:0'],
            'accepts_topup' => ['nullable', 'boolean'],
            'topup_min_amount' => ['nullable', 'numeric', 'min:0'],
            'topup_max_amount' => ['nullable', 'numeric', 'min:0'],
            'max_distance_km' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'negotiable' => ['nullable', 'boolean'],
            'warranty' => ['nullable', 'string', 'max:255'],
            'item_condition' => ['nullable', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'sale_type' => ['required', Rule::in(UsedItemListing::saleTypes())],
            'starting_bid' => ['nullable', 'numeric', 'min:0'],
            'reserve_price' => ['nullable', 'numeric', 'min:0'],
            'auction_ends_at' => ['nullable', 'date', 'after:now'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image_urls' => ['required', 'array', 'size:3'],
            'image_urls.*' => ['required', 'url', 'max:2048'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $transactionType = (string) $this->input(
                'transaction_type',
                UsedItemListing::TRANSACTION_TYPE_SALE,
            );
            $saleType = (string) $this->input('sale_type');
            $price = $this->input('price');
            $startingBid = $this->input('starting_bid');
            $auctionEndsAt = $this->input('auction_ends_at');
            $wantedObject = trim((string) $this->input('wanted_object', ''));
            $wantedObjects = collect($this->input('wanted_objects', []))
                ->map(fn ($item) => trim((string) $item))
                ->filter(fn ($item) => $item !== '');
            $acceptsTopup = $this->boolean('accepts_topup');
            $topupMin = $this->input('topup_min_amount');
            $topupMax = $this->input('topup_max_amount');
            $contactMethods = collect($this->input('contact_methods', []));
            $contactPhone = trim((string) $this->input('contact_phone', ''));
            $contactEmail = trim((string) $this->input('contact_email', ''));

            if ($saleType === UsedItemListing::SALE_TYPE_FIXED && ($price === null || $price === '')) {
                $validator->errors()->add('price', 'Le prix de vente est requis pour une vente directe.');
            }

            if ($saleType === UsedItemListing::SALE_TYPE_AUCTION) {
                if ($startingBid === null || $startingBid === '') {
                    $validator->errors()->add('starting_bid', 'La mise de départ est requise pour une enchère.');
                }
                if ($auctionEndsAt === null || $auctionEndsAt === '') {
                    $validator->errors()->add('auction_ends_at', 'La date de fin est requise pour une enchère.');
                }
            }

            if ($contactMethods->contains(fn ($item) => in_array($item, ['whatsapp', 'sms', 'call'], true)) && $contactPhone === '') {
                $validator->errors()->add('contact_phone', 'Un numéro de téléphone est requis pour WhatsApp, SMS ou appel direct.');
            }

            if ($contactMethods->contains('email') && $contactEmail === '') {
                $validator->errors()->add('contact_email', 'Une adresse email est requise si le contact par email est activé.');
            }

            if (in_array($transactionType, [
                UsedItemListing::TRANSACTION_TYPE_BARTER,
                UsedItemListing::TRANSACTION_TYPE_SALE_OR_BARTER,
            ], true)) {
                if ($wantedObject === '' && $wantedObjects->isEmpty()) {
                    $validator->errors()->add('wanted_object', 'Indiquez au moins un objet recherché pour le troc.');
                }
            }

            if ($acceptsTopup) {
                if ($topupMin === null || $topupMin === '') {
                    $validator->errors()->add('topup_min_amount', 'Le montant minimum du complément est requis.');
                }
                if ($topupMax === null || $topupMax === '') {
                    $validator->errors()->add('topup_max_amount', 'Le montant maximum du complément est requis.');
                }

                if (($topupMin !== null && $topupMin !== '') && ($topupMax !== null && $topupMax !== '')) {
                    if ((float) $topupMax < (float) $topupMin) {
                        $validator->errors()->add('topup_max_amount', 'Le montant maximum doit être supérieur ou égal au minimum.');
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Le titre est requis.',
            'description.required' => 'La description est requise.',
            'category.required' => 'La catégorie est requise.',
            'category.in' => 'Choisissez une catégorie valide dans la liste proposée.',
            'condition_label.required' => 'L\'état de l\'objet est requis.',
            'address.required' => 'L\'adresse est requise.',
            'contact_methods.required' => 'Choisissez au moins un moyen de contact.',
            'sale_type.required' => 'Le type de vente est requis.',
            'image_url.url' => 'L\'image doit être une URL valide.',
            'image_urls.size' => 'Ajoutez exactement 3 images pour publier l\'article.',
            'auction_ends_at.after' => 'La fin de l\'enchère doit être dans le futur.',
        ];
    }
}