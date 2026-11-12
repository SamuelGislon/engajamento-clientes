<?php

namespace App\Domain\Imports;

class CompanyImportFields
{
    /** @return array<string, array{label: string, required: bool}> */
    public function options(): array
    {
        return [
            'name' => ['label' => 'Nome da empresa', 'required' => true],
            'trade_name' => ['label' => 'Nome fantasia', 'required' => false],
            'legal_name' => ['label' => 'Razão social', 'required' => false],
            'tax_id' => ['label' => 'CNPJ', 'required' => false],
            'segment' => ['label' => 'Segmento', 'required' => false],
            'phone' => ['label' => 'Telefone', 'required' => false],
            'email' => ['label' => 'E-mail', 'required' => false],
            'website' => ['label' => 'Site', 'required' => false],
            'city' => ['label' => 'Cidade', 'required' => false],
            'state' => ['label' => 'UF', 'required' => false],
            'address' => ['label' => 'Endereço', 'required' => false],
            'relationship_status' => ['label' => 'Situação', 'required' => false],
            'lead_source' => ['label' => 'Origem do lead', 'required' => false],
            'notes' => ['label' => 'Observações', 'required' => false],
        ];
    }
}
