<?php

namespace App\Domain\Imports;

class ContactImportFields
{
    /** @return array<string, array{label: string, required: bool}> */
    public function options(): array
    {
        return [
            'name' => ['label' => 'Nome completo', 'required' => true],
            'email' => ['label' => 'E-mail', 'required' => false],
            'phone' => ['label' => 'Telefone', 'required' => false],
            'whatsapp' => ['label' => 'WhatsApp', 'required' => false],
            'job_title' => ['label' => 'Cargo ou função', 'required' => false],
            'professional_interests' => ['label' => 'Interesses profissionais', 'required' => false],
            'notes' => ['label' => 'Observações profissionais', 'required' => false],
            'company_tax_id' => ['label' => 'CNPJ da empresa', 'required' => false],
            'company_name' => ['label' => 'Nome da empresa', 'required' => false],
            'company_roles' => ['label' => 'Papéis na empresa (; separados)', 'required' => false],
            'is_primary' => ['label' => 'Contato principal (sim/não)', 'required' => false],
            'relationship_notes' => ['label' => 'Observações do vínculo', 'required' => false],
        ];
    }
}
