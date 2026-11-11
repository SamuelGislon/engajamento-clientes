<?php

namespace App\Enums;

enum ActivityType: string
{
    case Call = 'call';
    case Message = 'message';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case Visit = 'visit';
    case Presentation = 'presentation';
    case Material = 'material';
    case QuoteFollowUp = 'quote_follow_up';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Ligação',
            self::Message => 'Mensagem',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'E-mail',
            self::Meeting => 'Reunião',
            self::Visit => 'Visita presencial',
            self::Presentation => 'Apresentação comercial',
            self::Material => 'Envio de material',
            self::QuoteFollowUp => 'Acompanhamento de orçamento',
            self::Other => 'Outro',
        };
    }
}
