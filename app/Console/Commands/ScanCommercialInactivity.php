<?php

namespace App\Console\Commands;

use App\Domain\Commercial\CommercialInactivityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('commercial:scan-inactivity')]
#[Description('Identifica inatividade comercial e de relacionamento por organização.')]
class ScanCommercialInactivity extends Command
{
    public function handle(CommercialInactivityService $inactivity): int
    {
        $result = $inactivity->scanAllOrganizations();
        $this->info("Organizações: {$result['organizations']}; empresas avaliadas: {$result['companies']}; tarefas criadas: {$result['tasks_created']}.");

        return self::SUCCESS;
    }
}
