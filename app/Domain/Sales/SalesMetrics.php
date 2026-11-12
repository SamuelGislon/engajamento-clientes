<?php

namespace App\Domain\Sales;

use App\Models\Company;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

class SalesMetrics
{
    /** @return array{total_amount: string, purchase_count: int, average_amount: string|null, last_sold_at: Carbon|null} */
    public function forCompany(Company $company): array
    {
        $aggregate = Sale::query()
            ->where('company_id', $company->id)
            ->where('status', 'confirmed')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COUNT(*) as purchase_count')
            ->selectRaw('AVG(amount) as average_amount')
            ->selectRaw('MAX(sold_at) as last_sold_at')
            ->first();

        $purchaseCount = (int) $aggregate->purchase_count;
        $totalAmount = $this->numericString($aggregate->total_amount);

        return [
            'total_amount' => bcadd($totalAmount, '0', 2),
            'purchase_count' => $purchaseCount,
            'average_amount' => $purchaseCount > 0
                ? bcdiv($totalAmount, $this->numericString((string) $purchaseCount), 2)
                : null,
            'last_sold_at' => $aggregate->last_sold_at !== null
                ? Carbon::parse($aggregate->last_sold_at)
                : null,
        ];
    }

    /** @return numeric-string */
    private function numericString(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('O agregado financeiro retornou um valor inválido.');
        }

        return (string) $value;
    }
}
