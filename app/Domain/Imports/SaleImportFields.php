<?php

namespace App\Domain\Imports;

class SaleImportFields
{
    /** @return array<string, array{label: string, required: bool}> */
    public function options(): array
    {
        return [
            'external_id' => ['label' => 'Identificador externo', 'required' => false],
            'company_tax_id' => ['label' => 'CNPJ da empresa', 'required' => false],
            'company_name' => ['label' => 'Nome da empresa', 'required' => false],
            'seller_email' => ['label' => 'E-mail do vendedor', 'required' => true],
            'description' => ['label' => 'Descrição da venda', 'required' => true],
            'amount' => ['label' => 'Valor decimal', 'required' => true],
            'currency' => ['label' => 'Moeda', 'required' => true],
            'sold_at' => ['label' => 'Data e hora da venda', 'required' => true],
            'status' => ['label' => 'Situação (confirmed/cancelled)', 'required' => true],
            'notes' => ['label' => 'Observações', 'required' => false],
        ];
    }
}
