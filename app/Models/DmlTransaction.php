<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DmlTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'payment_id',
        'transaction_type',
        'idempotency_key',
        'rst_value',
        'rst_code',
        'amount',
        'code',
        'customer_name',
        'phone',
        'buy_last_date',
        'device',
        'montant',
        'total_arrear',
        'reste_a_payer',
        'ref_facture',
        'trans_id',
        'trans_time',
        'ref_code',
        'kwh',
        'kwh_amt',
        'fee_amt',
        'arrear_amt',
        'vat',
        'net_amt',
        'tokens',
        'verify_code',
        'state',
        'seed',
        'reg_date',
        'buy_times',
        'buy_monthly',
        'supply_amt',
        'sign',
        'customer_bills',
        'api_response',
        'api_status',
        'error_message'
    ];


    protected function casts(): array
    {
        return [
            'api_response' => 'array',
            'customer_bills' => 'array',
            'buy_last_date' => 'datetime',
            'trans_time' => 'datetime',
            'reg_date' => 'date',
            'amount' => 'decimal:2',
            'montant' => 'decimal:2',
            'total_arrear' => 'decimal:2',
            'reste_a_payer' => 'decimal:2',
            'kwh' => 'decimal:2',
            'kwh_amt' => 'decimal:2',
            'fee_amt' => 'decimal:2',
            'arrear_amt' => 'decimal:2',
            'vat' => 'decimal:2',
            'net_amt' => 'decimal:2',
            'buy_monthly' => 'decimal:4',
            'supply_amt' => 'decimal:2'
        ];
    }


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
    
}
