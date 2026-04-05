<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UsedItemListing extends Model
{
    use TraitUuid;

    public const CATEGORY_PHONE = 'Téléphone';
    public const CATEGORY_COMPUTER = 'Ordinateur';
    public const CATEGORY_TABLET = 'Tablette';
    public const CATEGORY_TV = 'Télévision';
    public const CATEGORY_APPLIANCE = 'Électroménager';
    public const CATEGORY_GAMING = 'Console de jeux';
    public const CATEGORY_AUDIO = 'Audio';
    public const CATEGORY_ACCESSORIES = 'Montre & accessoires';
    public const CATEGORY_FURNITURE = 'Mobilier';
    public const CATEGORY_FASHION = 'Mode';
    public const CATEGORY_BEAUTY = 'Beauté';
    public const CATEGORY_KIDS = 'Bébé & enfants';
    public const CATEGORY_HOME = 'Maison & déco';
    public const CATEGORY_SPORT = 'Sport & loisirs';
    public const CATEGORY_AUTO = 'Auto & moto';
    public const CATEGORY_SERVICES = 'Services';
    public const CATEGORY_OTHER = 'Autres';

    public const SALE_TYPE_FIXED = 'fixed';
    public const SALE_TYPE_AUCTION = 'auction';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SOLD = 'sold';
    public const STATUS_CLOSED = 'closed';

    public const MODERATION_PENDING = 'pending';
    public const MODERATION_APPROVED = 'approved';
    public const MODERATION_REJECTED = 'rejected';

    protected $fillable = [
        'seller_id',
        'title',
        'description',
        'category',
        'condition_label',
        'city',
        'address',
        'contact_phone',
        'contact_email',
        'contact_methods',
        'price',
        'sale_type',
        'starting_bid',
        'reserve_price',
        'auction_ends_at',
        'image_url',
        'image_urls',
        'publication_fee_rate',
        'publication_fee_base_amount',
        'publication_fee_amount',
        'publication_fee_refunded_amount',
        'publication_fee_refunded_at',
        'publication_ends_at',
        'status',
        'moderation_status',
        'admin_notes',
    ];

    protected $casts = [
        'price' => 'float',
        'starting_bid' => 'float',
        'reserve_price' => 'float',
        'publication_fee_rate' => 'float',
        'publication_fee_base_amount' => 'float',
        'publication_fee_amount' => 'float',
        'publication_fee_refunded_amount' => 'float',
        'contact_methods' => 'array',
        'image_urls' => 'array',
        'auction_ends_at' => 'datetime',
        'publication_fee_refunded_at' => 'datetime',
        'publication_ends_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function saleTypes(): array
    {
        return [
            self::SALE_TYPE_FIXED,
            self::SALE_TYPE_AUCTION,
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_SOLD,
            self::STATUS_CLOSED,
        ];
    }

    public static function moderationStatuses(): array
    {
        return [
            self::MODERATION_PENDING,
            self::MODERATION_APPROVED,
            self::MODERATION_REJECTED,
        ];
    }

    public static function categories(): array
    {
        return [
            self::CATEGORY_PHONE,
            self::CATEGORY_COMPUTER,
            self::CATEGORY_TABLET,
            self::CATEGORY_TV,
            self::CATEGORY_APPLIANCE,
            self::CATEGORY_GAMING,
            self::CATEGORY_AUDIO,
            self::CATEGORY_ACCESSORIES,
            self::CATEGORY_FURNITURE,
            self::CATEGORY_FASHION,
            self::CATEGORY_BEAUTY,
            self::CATEGORY_KIDS,
            self::CATEGORY_HOME,
            self::CATEGORY_SPORT,
            self::CATEGORY_AUTO,
            self::CATEGORY_SERVICES,
            self::CATEGORY_OTHER,
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(UsedItemBid::class, 'listing_id');
    }
}