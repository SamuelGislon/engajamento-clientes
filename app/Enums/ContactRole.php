<?php

namespace App\Enums;

enum ContactRole: string
{
    case Owner = 'owner';
    case Partner = 'partner';
    case BudgetManager = 'budget_manager';
    case Buyer = 'buyer';
    case DecisionMaker = 'decision_maker';
    case Influencer = 'influencer';
    case Specifier = 'specifier';
    case Finance = 'finance';
    case Operations = 'operations';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Partner => 'Sócio',
            self::BudgetManager => 'Responsável por orçamentos',
            self::Buyer => 'Comprador',
            self::DecisionMaker => 'Decisor',
            self::Influencer => 'Influenciador',
            self::Specifier => 'Especificador',
            self::Finance => 'Financeiro',
            self::Operations => 'Contato operacional',
            self::Other => 'Outro',
        };
    }
}
