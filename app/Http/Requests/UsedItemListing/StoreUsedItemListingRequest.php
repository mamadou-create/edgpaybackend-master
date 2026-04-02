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
            $saleType = (string) $this->input('sale_type');
            $price = $this->input('price');
            $startingBid = $this->input('starting_bid');
            $auctionEndsAt = $this->input('auction_ends_at');
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